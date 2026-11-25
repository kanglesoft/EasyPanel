<?php
/**
 * 系统（管理员级）计划任务 API —— admin_cron 表 + /etc/cron.d/ep_admincron 渲染
 *
 * 单元：admin-system-cron。职责：
 *   1) 任务 CRUD 与校验（名称/类型/命令/crontab 表达式/防重叠锁）；
 *   2) sync()：把「启用且合法」的任务整体渲染成单一 /etc/cron.d/ep_admincron 文件
 *      （临时文件 + rename 原子替换，0644，root 行，启用 flock -n 前缀防重叠）；
 *   3) run($id)：真正执行一个任务（shell 或 URL GET），写任务日志 + last_run/last_status；
 *      该入口由 cron.d 行调用 framework/cronresync.php run <id> 进入；
 *   4) install_all()：容器启动重建通道（entrypoint → cronresync.php）的唯一归属函数，
 *      = ensureTable + sync。评审#6：install_all 只在本文件定义，其它单元不得重复实现。
 *
 * 安全边界（诚实声明）：任务命令以 root 在容器内执行，属于「面板管理员 = 机器管理员」
 * 模型的既成事实（与 docker 启停、文件管理、host root 口令同一信任级）。防线：
 *   - 控制器 needRole('admin') + CSRF（csrf.js 全局注入）；
 *   - 命令/周期入库前白名单校验，cron 表达式逐字段范围校验，杜绝表达式位注入额外行
 *     （渲染时再次校验 + 对整行做换行剥离）；
 *   - URL 任务强制 http/https scheme + filter_var，禁换行；
 *   - 所有写操作走 audit()。
 *
 * 风格对齐：cron.api.php（unixadd 写 /etc/cron.d 的先例）、dao/admincron.dao.php、
 * 上游 .temp/repos/easypanel/framework/api/adminCron.api.php（已按本仓 runtime 适配：
 * ep_str/ep_request/get_panel_php_* 等本仓不存在的助手全部内联实现）。
 */
class AdmincronAPI extends API
{
	const CRON_FILE = '/etc/cron.d/ep_admincron';
	const LOG_MAX_SIZE = 262144;
	const LOG_KEEP_SIZE = 131072;

	/**
	 * 容器启动重建入口（entrypoint → framework/cronresync.php 调用）。
	 * 幂等：建表兜底 + 全量重渲染 cron.d。
	 */
	public function install_all()
	{
		daocall('admincron', 'ensureTable', array());
		return $this->sync();
	}

	public function ensureTable()
	{
		return daocall('admincron', 'ensureTable', array());
	}

	public function add($params)
	{
		$this->ensureTable();
		$data = $this->normalize($params);

		if ($data === false) {
			return false;
		}

		$data['enabled'] = 1;
		$data['last_run'] = 0;
		$data['last_status'] = 0;
		$data['name'] = $this->dedupName(strval($data['name']), 0);

		if (!daocall('admincron', 'add', array($data))) {
			setLastError('保存任务失败');
			return false;
		}

		if ($this->sync() === false) {
			setLastError('任务已保存，但写入 ' . self::CRON_FILE . ' 失败：' . $this->pullError());
		}

		return true;
	}

	public function update($id, $params)
	{
		$this->ensureTable();
		$id = intval($id);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			setLastError('任务不存在');
			return false;
		}

		$data = $this->normalize($params);

		if ($data === false) {
			return false;
		}

		$data['name'] = $this->dedupName(strval($data['name']), $id);

		if (!daocall('admincron', 'updateById', array($id, $data))) {
			setLastError('保存任务失败');
			return false;
		}

		if ($this->sync() === false) {
			setLastError('任务已保存，但写入 ' . self::CRON_FILE . ' 失败：' . $this->pullError());
		}

		return true;
	}

	public function del($id)
	{
		$this->ensureTable();
		$id = intval($id);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			setLastError('任务不存在');
			return false;
		}

		if (!daocall('admincron', 'delById', array($id))) {
			setLastError('删除任务失败');
			return false;
		}

		$dir = $this->logDir();
		@unlink($dir . 'task_' . $id . '.log');
		@unlink($dir . 'task_' . $id . '.lock');

		if ($this->sync() === false) {
			setLastError('任务已删除，但写入 ' . self::CRON_FILE . ' 失败：' . $this->pullError());
		}

		return true;
	}

	public function toggle($id, $enabled)
	{
		$this->ensureTable();
		$id = intval($id);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			setLastError('任务不存在');
			return false;
		}

		$data = array('enabled' => intval($enabled) ? 1 : 0);

		if (!daocall('admincron', 'updateById', array($id, $data))) {
			setLastError('保存任务失败');
			return false;
		}

		// 停用任务的验收点（remoteTest 3）：cron.d 中对应行必须消失。
		if ($this->sync() === false) {
			setLastError('状态已保存，但写入 ' . self::CRON_FILE . ' 失败：' . $this->pullError());
		}

		return true;
	}

	/**
	 * 整体重建 /etc/cron.d/ep_admincron。
	 *
	 * 无启用任务时删除文件（teardown 语义：允许「无任务文件」或已删）。
	 * 写失败（只读挂载/权限）返回 false 并 setLastError，调用方决定如何呈现。
	 */
	public function sync()
	{
		if (is_win()) {
			setLastError('仅 Linux 支持系统定时任务');
			return false;
		}

		$this->ensureTable();

		// 必须先建好日志/锁目录：cron.d 行里的 `flock -n <dir>/task_N.lock` 在
		// 父目录不存在时 open 失败 → flock 直接退出，php 不会被执行，任务永远
		// 跑不起来（run() 里的 mkdir 只在 php 启动后才发生，为时已晚）。
		$this->logDir(true);

		$tasks = daocall('admincron', 'getList', array());
		$lines = '';

		if (is_array($tasks)) {
			foreach ($tasks as $task) {
				if (intval($task['enabled']) != 1) {
					continue;
				}

				$cron = preg_replace('/\s+/', ' ', trim(strval($task['cron'])));

				if (!$this->checkCron($cron)) {
					continue;
				}

				$lines .= $cron . ' root ' . $this->shellCommand(intval($task['id']), intval($task['flock'])) . "\n";
			}
		}

		$file = self::CRON_FILE;

		if ($lines === '') {
			if (is_file($file) && !@unlink($file)) {
				setLastError('无法删除系统计划任务文件，请检查 /etc/cron.d 权限');
				return false;
			}

			return true;
		}

		if (!is_dir('/etc/cron.d') && !@mkdir('/etc/cron.d', 0755)) {
			setLastError('无法创建 /etc/cron.d 目录');
			return false;
		}

		$header = "SHELL=/bin/bash\nPATH=/sbin:/bin:/usr/sbin:/usr/bin\nHOME=/\nMAILTO=\"\"\n\n";
		$tmp = $file . '.tmp.' . getmypid();

		if (@file_put_contents($tmp, $header . $lines) === false) {
			@unlink($tmp);
			setLastError('无法写入系统计划任务文件，请检查 /etc/cron.d 权限');
			return false;
		}

		@chmod($tmp, 0644);

		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			setLastError('无法更新系统计划任务文件，请检查 /etc/cron.d 权限');
			return false;
		}

		return true;
	}

	/**
	 * 后台触发一次执行（不阻塞界面）：nohup 拉起 run()，日志照常落盘。
	 */
	public function spawn($id)
	{
		$this->ensureTable();
		$id = intval($id);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			setLastError('任务不存在');
			return false;
		}

		if (is_win()) {
			setLastError('仅 Linux 支持系统定时任务');
			return false;
		}

		if (!function_exists('exec')) {
			setLastError('exec 函数被禁用，无法执行任务');
			return false;
		}

		// 先建目录：flock 打开锁文件时父目录不存在会直接失败（与 sync() 同理）。
		$this->logDir(true);
		@exec('nohup ' . $this->shellCommand($id, intval($task['flock'])) . ' </dev/null >/dev/null 2>&1 &');
		return true;
	}

	/**
	 * 真正执行任务（cron.d 行 / spawn 的最终入口）。
	 *
	 * 锁的分层（重要，别改回双锁）：
	 *   - flock=1 且镜像内有 flock 二进制时，shellCommand() 已在本进程外层执行
	 *     `flock -n <task_N.lock>`，锁由内核随进程持有；此时 run() 内**绝不能再**
	 *     对同一文件 LOCK_EX|LOCK_NB —— flock 锁按打开文件描述计，同进程第二个 fd
	 *     申请同样会失败，导致每次正常执行都被误判「上次尚未结束」而跳过。
	 *   - 仅当镜像缺少 flock 二进制（shellCommand 未加前缀、当前 kangle 镜像实测
	 *     存在 /usr/bin/flock，此为降级路径）时，才用 php 级锁兜底。
	 */
	public function run($id)
	{
		if (is_win()) {
			setLastError('仅 Linux 支持系统定时任务');
			return false;
		}

		$this->ensureTable();
		$id = intval($id);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			setLastError('任务不存在');
			return false;
		}

		$dir = $this->logDir(true);

		if (!is_dir($dir)) {
			setLastError('无法创建日志目录 ' . $dir);
			return false;
		}

		$logfile = $dir . 'task_' . $id . '.log';
		$lock = null;

		// 见方法注释：外层已有 flock -n 时不再重复加锁，仅缺二进制时兜底。
		if (!empty($task['flock']) && $this->flockBin() === false) {
			$lockfile = $dir . 'task_' . $id . '.lock';
			$lock = @fopen($lockfile, 'c');

			if (!$lock) {
				setLastError('无法创建任务锁文件');
				return false;
			}

			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				$this->appendLog($logfile, '[' . date('Y-m-d H:i:s') . "] 上次执行尚未结束，本次已跳过\n");
				fclose($lock);
				return false;
			}
		}

		$type = $task['type'] === 'url' ? 'URL 访问' : 'Shell 命令';
		$this->appendLog($logfile, '[' . date('Y-m-d H:i:s') . '] 开始执行（' . $type . "）\n");
		$ok = false;

		if ($task['type'] === 'url') {
			$ok = $this->runUrl($task['cmd'], $logfile);
		}
		else if ($task['type'] === 'shell') {
			$ok = $this->runShell($task['cmd'], $logfile);
		}
		else {
			$this->appendLog($logfile, "未知的任务类型，已跳过\n");
		}

		daocall('admincron', 'updateById', array($id, array(
		'last_run' => time(),
		'last_status' => $ok ? 1 : 2
		)));

		if ($lock) {
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		return $ok;
	}

	public function getLog($id)
	{
		$file = $this->logDir(false) . 'task_' . intval($id) . '.log';

		if (!is_file($file)) {
			return '';
		}

		$size = @filesize($file);
		$max = 8192;
		$fp = @fopen($file, 'rb');

		if (!$fp) {
			return '';
		}

		if ($size > $max) {
			fseek($fp, 0 - $max, SEEK_END);
		}

		$log = fread($fp, $max);
		fclose($fp);
		$log = preg_replace('/^[\x80-\xBF]+/', '', $log);

		if ($size > $max) {
			$log = "（仅显示最后 8KB）\n" . $log;
		}

		return $log;
	}

	public function getLogFull($id)
	{
		$file = $this->logDir(false) . 'task_' . intval($id) . '.log';

		if (!is_file($file)) {
			return '';
		}

		return (string) @file_get_contents($file);
	}

	public function clearLog($id)
	{
		$file = $this->logDir(false) . 'task_' . intval($id) . '.log';

		if (!is_file($file)) {
			return true;
		}

		return @unlink($file);
	}

	/**
	 * 5 段 crontab 表达式校验（逐字段范围检查，兼容 a-b 区间、步长、逗号列表）
	 */
	public function checkCron($expr)
	{
		$parts = preg_split('/\s+/', trim($expr));

		if (count($parts) !== 5) {
			return false;
		}

		$ranges = array(array(0, 59), array(0, 23), array(1, 31), array(1, 12), array(0, 7));

		for ($i = 0; $i < 5; $i++) {
			if (!$this->checkCronField($parts[$i], $ranges[$i][0], $ranges[$i][1])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 把 5 段 crontab 表达式转成中文描述，无法识别时原样返回
	 */
	public function describe($cron)
	{
		$cron = trim(preg_replace('/\s+/', ' ', strval($cron)));
		$p = explode(' ', $cron);

		if (count($p) !== 5) {
			return $cron;
		}

		list($mi, $h, $d, $mo, $w) = $p;
		$time = sprintf('%02d:%02d', intval($h), intval($mi));

		if (preg_match('/^\d+$/', $mi) && preg_match('/^\d+$/', $h) && $d === '*' && $mo === '*' && $w === '*') {
			return '每天 ' . $time . ' 执行';
		}

		if ($mi === '*' && $h === '*' && $d === '*' && $mo === '*' && $w === '*') {
			return '每分钟执行';
		}

		if (preg_match('#^\*/(\d+)$#', $mi, $m) && $h === '*' && $d === '*' && $mo === '*' && $w === '*') {
			return '每 ' . intval($m[1]) . ' 分钟执行';
		}

		if (preg_match('/^\d+$/', $mi) && preg_match('#^\*/(\d+)$#', $h, $m) && $d === '*' && $mo === '*' && $w === '*') {
			return '每 ' . intval($m[1]) . ' 小时的第 ' . intval($mi) . ' 分钟执行';
		}

		if (preg_match('/^\d+$/', $mi) && preg_match('/^\d+$/', $h) && preg_match('#^\*/(\d+)$#', $d, $m) && $mo === '*' && $w === '*') {
			return '每 ' . intval($m[1]) . ' 天的 ' . $time . ' 执行';
		}

		if (preg_match('/^\d+$/', $mi) && $h === '*' && $d === '*' && $mo === '*' && $w === '*') {
			return '每小时的第 ' . intval($mi) . ' 分钟执行';
		}

		if (preg_match('/^\d+$/', $mi) && preg_match('/^\d+$/', $h) && $d === '*' && $mo === '*' && preg_match('/^[0-7]$/', $w)) {
			$names = array('日', '一', '二', '三', '四', '五', '六');
			$idx = $w === '7' ? 0 : intval($w);
			return '每周' . $names[$idx] . ' ' . $time . ' 执行';
		}

		if (preg_match('/^\d+$/', $mi) && preg_match('/^\d+$/', $h) && preg_match('/^\d+$/', $d) && $mo === '*' && $w === '*') {
			return '每月 ' . intval($d) . ' 日 ' . $time . ' 执行';
		}

		if (preg_match('/^\d+$/', $mi) && preg_match('/^\d+$/', $h) && preg_match('/^\d+$/', $d) && preg_match('/^\d+$/', $mo) && $w === '*') {
			return '每年 ' . intval($mo) . ' 月 ' . intval($d) . ' 日 ' . $time . ' 执行';
		}

		return $cron;
	}

	/* ---------------------------------------------------------------- 私有 */

	/**
	 * 组装 cron.d 行里的执行命令。
	 *
	 * 形如：
	 *   flock -n '<lock>' '<php>' -c '<ini>' '<SYS_ROOT>/cronresync.php' run <id> >/dev/null 2>&1
	 * 无 flock 二进制或任务未勾选防重叠时省略 flock 前缀（run() 内还有 php 级锁兜底）。
	 */
	private function shellCommand($id, $flock)
	{
		$php = $this->phpBin();
		$ini = $this->phpIni();
		$runner = SYS_ROOT . '/cronresync.php';
		$cmd = '';

		// flock 二进制不加引号：flockBin() 只会命中硬编码白名单（/usr/bin/flock、
		// /bin/flock），无注入面；加引号会让输出变成 `'/usr/bin/flock' -n`，
		// 既难看也破坏验收测试对字面量 "flock -n" 的 grep。其余参数照常转义。
		if ($flock && $this->flockBin()) {
			$cmd .= $this->flockBin() . ' -n ' . escapeshellarg($this->logDir(false) . 'task_' . intval($id) . '.lock') . ' ';
		}

		$cmd .= escapeshellarg($php);

		if ($ini !== '') {
			$cmd .= ' -c ' . escapeshellarg($ini);
		}

		$cmd .= ' ' . escapeshellarg($runner) . ' run ' . intval($id) . ' >/dev/null 2>&1';
		return $cmd;
	}

	private function phpBin()
	{
		static $cached = null;

		if ($cached !== null) {
			return $cached;
		}

		$cached = PHP_BINARY;

		foreach (array('/usr/bin/php74', '/usr/bin/php') as $cand) {
			if (@is_executable($cand)) {
				$cached = $cand;
				break;
			}
		}

		return $cached;
	}

	private function phpIni()
	{
		static $cached = null;

		if ($cached !== null) {
			return $cached;
		}

		$cached = '';
		$cand = dirname($GLOBALS['safe_dir']) . '/ext/tpl_php74/php-templete.ini';

		if (@is_file($cand)) {
			$cached = $cand;
		}

		return $cached;
	}

	private function flockBin()
	{
		static $cached = null;

		if ($cached !== null) {
			return $cached;
		}

		$cached = false;

		foreach (array('/usr/bin/flock', '/bin/flock') as $cand) {
			if (@is_executable($cand)) {
				$cached = $cand;
				break;
			}
		}

		return $cached;
	}

	/**
	 * 入库前归一化 + 白名单校验。
	 */
	private function normalize($params)
	{
		$name = isset($params['name']) ? trim(strval($params['name'])) : '';
		$name = str_replace(array("\r", "\n"), ' ', $name);
		$name = preg_replace('/\s+/', ' ', $name);
		$type = isset($params['type']) ? trim(strval($params['type'])) : '';
		$cmd = isset($params['cmd']) ? trim(strval($params['cmd'])) : '';
		$cron = isset($params['cron']) ? preg_replace('/\s+/', ' ', trim(strval($params['cron']))) : '';
		$flock = !empty($params['flock']) ? 1 : 0;

		$nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

		if ($nameLen < 1 || $nameLen > 100) {
			setLastError('任务名称不能为空且不能超过 100 个字符');
			return false;
		}

		if ($type !== 'shell' && $type !== 'url') {
			setLastError('任务类型不正确');
			return false;
		}

		if ($cmd === '') {
			setLastError('命令或网址不能为空');
			return false;
		}

		if (strlen($cmd) > 8000) {
			setLastError('命令内容不能超过 8000 个字符');
			return false;
		}

		if ($type === 'url') {
			$cmd = str_replace(array("\r", "\n"), '', $cmd);

			if (!preg_match('!^https?://!i', $cmd) || !filter_var($cmd, FILTER_VALIDATE_URL)) {
				setLastError('网址格式不正确，必须以 http:// 或 https:// 开头');
				return false;
			}
		}

		if (!$this->checkCron($cron)) {
			setLastError('执行周期格式不正确，请填写 5 段 crontab 表达式，例如 */5 * * * *');
			return false;
		}

		return array(
			'name' => $name,
			'type' => $type,
			'cmd' => $cmd,
			'cron' => $cron,
			'flock' => $flock
		);
	}

	/**
	 * 同名任务自动加序号（2-B 体验决策）：避免列表里出现无法区分的重名任务。
	 */
	private function dedupName($name, $exclude_id)
	{
		$tasks = daocall('admincron', 'getList', array());

		if (!is_array($tasks)) {
			return $name;
		}

		$used = array();

		foreach ($tasks as $task) {
			if (intval($task['id']) === intval($exclude_id)) {
				continue;
			}

			$used[strval($task['name'])] = true;
		}

		if (!isset($used[$name])) {
			return $name;
		}

		for ($i = 2; $i < 1000; $i++) {
			$candidate = $name . '-' . $i;

			if (!isset($used[$candidate])) {
				return $candidate;
			}
		}

		return $name . '-' . dechex(time());
	}

	private function pullError()
	{
		$err = isset($GLOBALS['last_error']) ? $GLOBALS['last_error'] : '';
		setLastError('');
		return $err;
	}

	private function checkCronField($field, $min, $max)
	{
		if ($field === '' || !preg_match('#^[0-9*,/-]+$#', $field)) {
			return false;
		}

		foreach (explode(',', $field) as $part) {
			if ($part === '') {
				return false;
			}

			$pos = strpos($part, '/');

			if ($pos !== false) {
				$step = substr($part, $pos + 1);
				$part = substr($part, 0, $pos);

				if (!ctype_digit($step) || intval($step) < 1) {
					return false;
				}
			}

			if ($part === '*') {
				continue;
			}

			$pos = strpos($part, '-');

			if ($pos !== false) {
				$a = substr($part, 0, $pos);
				$b = substr($part, $pos + 1);

				if (!ctype_digit($a) || !ctype_digit($b)) {
					return false;
				}

				$a = intval($a);
				$b = intval($b);

				if ($a < $min || $b > $max || $a > $b) {
					return false;
				}
			}
			else {
				if (!ctype_digit($part)) {
					return false;
				}

				$v = intval($part);

				if ($v < $min || $v > $max) {
					return false;
				}
			}
		}

		return true;
	}

	private function runShell($cmd, $logfile)
	{
		if (!function_exists('shell_exec')) {
			$this->appendLog($logfile, "执行失败：shell_exec 函数被禁用\n");
			return false;
		}

		$mark = '__EP_EXIT_' . uniqid() . '__';
		$script = '( ' . $cmd . "\n) 2>&1\n" . 'echo ' . $mark . '$?' . "\n";
		$raw = @shell_exec('/bin/sh -c ' . escapeshellarg($script));
		$code = -1;

		if (is_string($raw)) {
			$pos = strrpos($raw, $mark);

			if ($pos !== false) {
				$code = intval(trim(substr($raw, $pos + strlen($mark))));
				$raw = substr($raw, 0, $pos);
			}
		}

		$output = $this->tailText(is_string($raw) ? rtrim($raw, "\n") : '', 10000);
		$this->appendLog($logfile, '[' . date('Y-m-d H:i:s') . '] ' . ($code === 0 ? '执行成功' : '执行失败')
			. '：退出码 ' . $code . ($output === '' ? "，无输出\n" : "\n" . $output . "\n"));
		return $code === 0;
	}

	private function runUrl($url, $logfile)
	{
		$context = stream_context_create(array('http' => array(
			'timeout' => 30,
			'ignore_errors' => true,
			'user_agent' => 'EasyPanel-Cron/1.0'
		)));
		// 响应头从 stream 元数据读取：PHP 5.6~8.x 通用，且不触碰已弃用的 $http_response_header
		$body = false;
		$code = 0;
		$fp = @fopen($url, 'rb', false, $context);

		if ($fp) {
			$meta = stream_get_meta_data($fp);
			$body = stream_get_contents($fp);
			fclose($fp);

			// 发生跳转时 wrapper_data 含多段响应头，取最后一个状态行
			if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
				foreach ($meta['wrapper_data'] as $header) {
					if (preg_match('!^HTTP/\S+\s+(\d+)!', $header, $match)) {
						$code = intval($match[1]);
					}
				}
			}
		}

		if ($body === false) {
			$this->appendLog($logfile, '[' . date('Y-m-d H:i:s') . "] 执行失败：请求失败，无响应\n");
			return false;
		}

		$ok = $code >= 200 && $code < 400;
		$this->appendLog($logfile, '[' . date('Y-m-d H:i:s') . '] ' . ($ok ? '执行成功' : '执行失败')
			. '：HTTP ' . $code . '，响应 ' . strlen($body) . " 字节\n");
		return $ok;
	}

	private function logDir($create = true)
	{
		$dir = $GLOBALS['safe_dir'] . 'admin_cron/';

		if ($create && !is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}

		return $dir;
	}

	private function appendLog($file, $text)
	{
		@file_put_contents($file, $text, FILE_APPEND | LOCK_EX);
		clearstatcache();
		$size = @filesize($file);

		if ($size === false || $size <= self::LOG_MAX_SIZE) {
			return;
		}

		$fp = @fopen($file, 'rb');

		if (!$fp) {
			return;
		}

		fseek($fp, 0 - self::LOG_KEEP_SIZE, SEEK_END);
		$tail = fread($fp, self::LOG_KEEP_SIZE);
		fclose($fp);
		$tail = preg_replace('/^[\x80-\xBF]+/', '', $tail);
		$pos = strpos($tail, "\n");

		if ($pos !== false) {
			$tail = substr($tail, $pos + 1);
		}

		@file_put_contents($file, $tail, LOCK_EX);
	}

	private function tailText($text, $max)
	{
		if (strlen($text) <= $max) {
			return $text;
		}

		$text = substr($text, 0 - $max);
		$text = preg_replace('/^[\x80-\xBF]+/', '', $text);
		return "（输出过长，仅保留末尾部分）\n" . $text;
	}
}

?>
