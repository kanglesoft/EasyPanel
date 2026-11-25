<?php
/**
 * healthprobe.api.php —— 源站/辅节点健康探测 + 故障摘除 + 通知事件（1 分钟 cron）
 *
 * 单元：origin-node-health-probe。模型照抄 dnsmgr app/service/TaskRunner.php:45-147
 * 的 dmtask 判抖逻辑（errcount 连续计数 + cycle 阈值翻转 + 翻转才写事件/发通知），
 * 落到本面板的 SQLite health_node/health_event 两张表上。
 *
 * 调用通道：
 *   1) cron：/etc/cron.d/ep_healthprobe 每分钟直接执行本文件（CLI 自举块，见文件头），
 *      不经过 shell.api.php（该文件无主，禁止改动），也不依赖 http 入口的会话。
 *   2) web：admin/control/health.ctl.php 通过 apicall('healthprobe', ...) 调用。
 *   3) 容器重建后由 framework/cronresync.php（admin-system-cron 单元）调 install_cron() 重建。
 *
 * 通知联动是旁路：apicall('notify','send',...) 一律 try/catch，失败不影响
 * health_event 落行（notify.api 缺失时 class_exists 兜底直接跳过）。
 */

/* ── CLI 自举：cron 直接跑本文件时，先搭 runtime 再走 apicall('healthprobe','run') ──
 * 为什么要放在类声明之前：`class HealthprobeApi extends API` 依赖 runtime.php 里
 * __load_core('core:api') 载入的 API 基类；用户态父类在 PHP 7 是运行到声明处才绑定，
 * 类体之后的自举代码永远执行不到（第一步就 fatal "Class 'API' not found"）。
 * 因此：先定义常量→include runtime→再 require 本文件（此时 BOOT 已定义，第二次
 * 进入直接落到类声明），类可用后再调 run()。
 * include_once/require_once 对「入口脚本自身」会判重跳过，所以必须用裸 require。
 * 与 shell.php / crontab.php 同样的 SYS_ROOT/APPLICATON_ROOT 口径。 */
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0])
	&& realpath(strval($_SERVER['argv'][0])) === realpath(__FILE__)
	&& !defined('EP_HEALTHPROBE_BOOT')) {
	define('EP_HEALTHPROBE_BOOT', 1);
	@set_time_limit(0);
	date_default_timezone_set('Asia/Shanghai');
	define('SYS_ROOT', dirname(dirname(__FILE__)));
	define('APPLICATON_ROOT', '');
	include_once SYS_ROOT . '/runtime.php';
	require __FILE__;

	$ret = apicall('healthprobe', 'run', array());

	if (is_array($ret) || is_object($ret)) {
		$ret = json_encode($ret, JSON_UNESCAPED_UNICODE);
	}

	echo '[healthprobe] ' . strval($ret) . "\n";
	exit(0);
}

class HealthprobeApi extends API
{
	const CRON_FILE = '/etc/cron.d/ep_healthprobe';
	const LOCK_FILE = '/tmp/ep_healthprobe.lock';
	const LOG_DIR = '/vhs/kangle/var/log';
	const LOG_FILE = '/vhs/kangle/var/log/healthprobe.log';
	/* 类型白名单与范围钳制 */
	const TYPES = array('tcp', 'http', 'ping');
	const DEFAULT_TIMEOUT = 3;
	const MAX_TIMEOUT = 15;
	const DEFAULT_CYCLE = 1;
	const MAX_CYCLE = 10;
	const MAX_EVENT_KEEP = 5000;

	/**
	 * 总开关是否打开（setting.healthprobe_enabled，缺省视为开）
	 */
	public function enabled()
	{
		$v = daocall('setting', 'get', array('healthprobe_enabled'));
		return $v === null || $v === '' ? true : (intval($v) === 1);
	}

	/**
	 * 设置总开关并同步 cron 文件。
	 * 关闭 = 停 cron + （可选）把因探测被摘除联动关闭；探测数据保留。
	 *
	 * @param bool $on
	 * @return bool cron 文件落盘是否成功
	 */
	public function setEnabled($on)
	{
		$on = $on ? 1 : 0;
		daocall('setting', 'add', array('healthprobe_enabled', $on));
		return $this->install_cron($on === 1);
	}

	/**
	 * 写/删 /etc/cron.d/ep_healthprobe。$on 为 null 时按当前总开关决定。
	 * 该方法是 admin-system-cron 单元的 cronresync.php 在每次容器启动后调用的
	 * 重建入口（entrypoint.sh 已预留 healthprobe.api.php install_cron 钩子）。
	 *
	 * @param bool|null $on
	 * @return bool
	 */
	public function install_cron($on = null)
	{
		if (is_win()) {
			setLastError('健康探测 cron 仅支持 Linux');
			return false;
		}

		if ($on === null) {
			$on = $this->enabled();
		}

		if (!$on) {
			if (is_file(self::CRON_FILE)) {
				if (!@unlink(self::CRON_FILE)) {
					setLastError('无法删除 ' . self::CRON_FILE);
					return false;
				}
			}

			return true;
		}

		$php = $this->phpBin();

		if (!$php) {
			setLastError('容器内未找到 php74/php，无法安装健康探测 cron');
			return false;
		}

		$script = realpath(__FILE__);

		if (!$script) {
			$script = SYS_ROOT . '/api/healthprobe.api.php';
		}

		/* 每分钟一次；mkdir 兜底日志目录；输出落 healthprobe.log 便于面板外排查。
		 * 路径全部 escapeshellarg；本行内容由面板生成，不含用户输入。 */
		$line = '* * * * * root mkdir -p ' . self::LOG_DIR . ' && ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >> ' . self::LOG_FILE . " 2>&1\n";
		$msg = "SHELL=/bin/bash\nPATH=/sbin:/bin:/usr/sbin:/usr/bin\nHOME=/\nMAILTO=\"\"\n" . $line;

		if (!is_dir('/etc/cron.d') && !@mkdir('/etc/cron.d', 0755)) {
			setLastError('无法创建 /etc/cron.d 目录');
			return false;
		}

		$tmp = self::CRON_FILE . '.tmp.' . getmypid();

		if (@file_put_contents($tmp, $msg) === false) {
			@unlink($tmp);
			setLastError('无法写入 ' . self::CRON_FILE);
			return false;
		}

		@chmod($tmp, 0644);

		if (!@rename($tmp, self::CRON_FILE)) {
			@unlink($tmp);
			setLastError('无法更新 ' . self::CRON_FILE);
			return false;
		}

		return true;
	}

	/**
	 * cron.d 文件当前是否存在（管理页展示用）
	 */
	public function cronInstalled()
	{
		return is_file(self::CRON_FILE);
	}

	/**
	 * 一轮完整探测（cron 与「立即全部探测」共用）。
	 * 单目标异常绝不影响其余目标；返回汇总数组。
	 *
	 * @return array|string
	 */
	public function run()
	{
		// 防重入：上一轮还在超时等待时本轮直接跳过（1 分钟粒度必备）
		$lock = @fopen(self::LOCK_FILE, 'c');

		if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
			fclose($lock);
			return 'skipped: previous run still in progress';
		}

		if (!$this->enabled()) {
			if ($lock) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}

			return 'disabled';
		}

		$targets = daocall('healthnode', 'listEnabled', array());

		if (!is_array($targets) || empty($targets)) {
			if ($lock) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}

			return 'no target';
		}

		$sum = array('checked' => 0, 'down' => 0, 'up' => 0, 'events' => 0, 'errors' => 0);

		foreach ($targets as $t) {
			if (!is_array($t) || !isset($t['name']) || $t['name'] === '') {
				continue;
			}

			++$sum['checked'];
			$result = null;

			try {
				$result = $this->checkTarget($t);
			}
			catch (Throwable $e) {
				$result = array('ok' => false, 'latency' => 0, 'reason' => 'exception: ' . $e->getMessage());
				++$sum['errors'];
			}

			try {
				$flipped = $this->applyResult($t, $result);
			}
			catch (Throwable $e) {
				$flipped = false;
				++$sum['errors'];
			}

			if ($result['ok']) {
				++$sum['up'];
			}
			else {
				++$sum['down'];
			}

			if ($flipped) {
				++$sum['events'];
			}
		}

		// 事件表规模控制：抽样裁剪，与 audit.api 同一思路
		if (mt_rand(1, 100) === 1) {
			try {
				daocall('healthnode', 'pruneEvents', array(self::MAX_EVENT_KEEP));
			}
			catch (Throwable $e) {
			}
		}

		if ($lock) {
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		return $sum;
	}

	/**
	 * 探测单个目标（不改状态）。
	 * 返回 array('ok'=>bool, 'latency'=>int ms, 'reason'=>string, 'detail'=>string)
	 *
	 * @param array $t health_node 行
	 * @return array
	 */
	public function checkTarget($t)
	{
		$type = isset($t['type']) ? strtolower(trim(strval($t['type']))) : 'tcp';

		if (!in_array($type, self::TYPES)) {
			$type = 'tcp';
		}

		$timeout = intval(isset($t['timeout']) ? $t['timeout'] : self::DEFAULT_TIMEOUT);

		if ($timeout < 1) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		if ($timeout > self::MAX_TIMEOUT) {
			$timeout = self::MAX_TIMEOUT;
		}

		if ($type === 'http') {
			return $this->probeHttp($t, $timeout);
		}

		if ($type === 'ping') {
			return $this->probePing($t, $timeout);
		}

		return $this->probeTcp($t, $timeout);
	}

	/**
	 * 按探测结果推进状态机：判抖计数、翻转时写 health_event + 摘除/回补联动 + 通知。
	 *
	 * @param array $t     health_node 行（检查前读到的快照）
	 * @param array $result checkTarget 的返回
	 * @return bool 本次是否发生状态翻转（即新写了一条事件）
	 */
	public function applyResult($t, $result)
	{
		$now = time();
		$ok = !empty($result['ok']);
		$latency = intval(isset($result['latency']) ? $result['latency'] : 0);
		$reason = isset($result['reason']) ? strval($result['reason']) : '';
		$status = intval(isset($t['status']) ? $t['status'] : 1);
		$errcount = intval(isset($t['errcount']) ? $t['errcount'] : 0);
		$cycle = intval(isset($t['cycle']) ? $t['cycle'] : self::DEFAULT_CYCLE);

		if ($cycle < 1) {
			$cycle = 1;
		}

		if ($cycle > self::MAX_CYCLE) {
			$cycle = self::MAX_CYCLE;
		}

		$save = array(
			'lastcheck' => $now,
			'latency' => $latency,
		);
		$flipped = false;

		if ($ok) {
			if ($status === 0) {
				// 目前 down：连续成功计数到阈值才回补，单次成功不翻转
				if ($errcount + 1 >= $cycle) {
					$status = 1;
					$errcount = 0;
					$flipped = true;
					$save['status'] = 1;
					$save['errcount'] = 0;
					$save['lastswitch'] = $now;
					$save['lasterr'] = '';
				}
				else {
					++$errcount;
					$save['errcount'] = $errcount;
				}
			}
			else {
				// up 且探测成功：清零反向计数即可
				if ($errcount > 0) {
					$save['errcount'] = 0;
				}

				$save['lasterr'] = '';
			}
		}
		else {
			$save['lasterr'] = $reason;

			if ($status === 1) {
				// 目前 up：连续失败到阈值才摘除，单次失败不翻转
				if ($errcount + 1 >= $cycle) {
					$status = 0;
					$errcount = 0;
					$flipped = true;
					$save['status'] = 0;
					$save['errcount'] = 0;
					$save['lastswitch'] = $now;
				}
				else {
					++$errcount;
					$save['errcount'] = $errcount;
				}
			}
			else {
				if ($errcount > 0) {
					$save['errcount'] = 0;
				}
			}
		}

		daocall('healthnode', 'save', array($t['name'], $save));

		if ($flipped) {
			$ejected = 0;

			// 状态机联动：down 尝试摘除，up 尝试回补。失败只影响 ejected 标记，绝不回滚事件。
			if ($status === 0) {
				$ejected = $this->eject($t);
			}
			else {
				$ejected = $this->restore($t) ? 2 : 0;
			}

			$event = array(
				'node' => $t['name'],
				'action' => $status === 0 ? 'down' : 'up',
				'reason' => $reason,
				'detail' => $this->describeTarget($t) . ' latency=' . $latency . 'ms',
				'ejected' => $ejected,
				'ts' => $now,
			);
			daocall('healthnode', 'addEvent', array($event));
			$this->notify($t, $status, $reason, $ejected);
		}

		return $flipped;
	}

	/**
	 * 摘除：把故障目标移出上游池。
	 *  - 关联了辅节点（node 列）时先尽力通知该节点清掉自己的 CDN（节点若真死会失败，无妨）；
	 *  - 若 setting.healthprobe_auto_del=1，再把该辅节点从 manynode 表移除——此后
	 *    cdnPrimary 的全量同步不会再推向它，这就是本架构下「移出上游池」的实际开关。
	 *    默认 0（不删数据），回补靠面板手动重新同步，避免自动删除造成配置丢失。
	 *
	 * @return int 0=未摘除 1=del_node 成功 3=已从 manynode 移除
	 */
	private function eject($t)
	{
		$nodename = isset($t['node']) ? trim(strval($t['node'])) : '';

		if ($nodename === '') {
			return 0;
		}

		$node = daocall('manynode', 'get', array($nodename));

		if (!$node) {
			return 0;
		}

		$ejected = 0;

		try {
			if (apicall('cdn', 'del_node', array($node))) {
				$ejected = 1;
			}
		}
		catch (Throwable $e) {
		}

		$auto = daocall('setting', 'get', array('healthprobe_auto_del'));

		if (intval($auto) === 1) {
			try {
				if (daocall('manynode', 'del', array($nodename))) {
					$ejected = 3;
				}
			}
			catch (Throwable $e) {
			}
		}

		return $ejected;
	}

	/**
	 * 回补：节点恢复后把全量 CDN 重新推给它（节点仍在 manynode 表时）。
	 *
	 * @return bool
	 */
	private function restore($t)
	{
		$nodename = isset($t['node']) ? trim(strval($t['node'])) : '';

		if ($nodename === '') {
			return false;
		}

		$node = daocall('manynode', 'get', array($nodename));

		if (!$node) {
			return false;
		}

		try {
			return (bool) apicall('cdnPrimary', 'sync_node', array($nodename));
		}
		catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * 翻转通知。notify.api 属 notification-channels 单元；不存在或发送失败
	 * 一律静默（事件已经先落库，通知只是旁路）。
	 */
	private function notify($t, $status, $reason, $ejected)
	{
		$v = daocall('setting', 'get', array('healthprobe_notify'));

		if ($v !== null && $v !== '' && intval($v) === 0) {
			return;
		}

		try {
			load_api('notify');

			if (!class_exists('NotifyApi')) {
				return;
			}

			$name = strval($t['name']);
			$desc = $this->describeTarget($t);

			if ($status === 0) {
				$title = 'EasyPanel 健康探测：节点摘除 ' . $name;
				$text = '探测目标 ' . $name . '（' . $desc . '）连续失败，判定 down。原因：' . $reason . ($ejected > 0 ? '；已执行摘除联动(ejected=' . $ejected . ')' : '；未执行摘除联动。');
			}
			else {
				$title = 'EasyPanel 健康探测：节点恢复 ' . $name;
				$text = '探测目标 ' . $name . '（' . $desc . '）恢复，判定 up。' . ($ejected === 2 ? '已尝试重新同步回补。' : '');
			}

			apicall('notify', 'send', array($title, $text));
		}
		catch (Throwable $e) {
		}
	}

	/* ---------- 三种探测实现 ---------- */

	private function probeTcp($t, $timeout)
	{
		$host = $this->targetHost($t);
		$port = $this->targetPort($t);

		if ($host === '') {
			return array('ok' => false, 'latency' => 0, 'reason' => 'empty host');
		}

		$err_no = 0;
		$err_str = '';
		$start = microtime(true);
		$fp = @fsockopen($host, $port, $err_no, $err_str, $timeout);
		$ms = intval((microtime(true) - $start) * 1000);

		if (!$fp) {
			return array('ok' => false, 'latency' => $ms, 'reason' => 'tcp connect failed: [' . $err_no . '] ' . ($err_str !== '' ? $err_str : 'timeout/unreachable'));
		}

		@fclose($fp);
		return array('ok' => true, 'latency' => $ms, 'reason' => 'tcp ok');
	}

	private function probeHttp($t, $timeout)
	{
		$url = isset($t['url']) ? trim(strval($t['url'])) : '';

		if ($url === '') {
			$host = $this->targetHost($t);
			$port = $this->targetPort($t);

			if ($host === '') {
				return array('ok' => false, 'latency' => 0, 'reason' => 'empty url/host');
			}

			$scheme = ($port === 443) ? 'https' : 'http';
			$url = $scheme . '://' . $host . ':' . $port . '/';
		}

		if (!preg_match('#^https?://#i', $url)) {
			$url = 'http://' . $url;
		}

		if (strlen($url) > 512) {
			return array('ok' => false, 'latency' => 0, 'reason' => 'url too long');
		}

		$expect = isset($t['expect']) ? trim(strval($t['expect'])) : '';
		$start = microtime(true);
		$code = 0;
		$body = '';
		$err = '';

		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_USERAGENT, 'EasyPanel-HealthProbe/1.0');
			$body = curl_exec($ch);

			if ($body === false) {
				$err = curl_error($ch);
			}

			$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
			curl_close($ch);
		}
		else {
			$ctx = stream_context_create(array(
				'http' => array(
					'method' => 'GET',
					'timeout' => $timeout,
					'follow_location' => 1,
					'max_redirects' => 3,
					'user_agent' => 'EasyPanel-HealthProbe/1.0',
					'ignore_errors' => true,
				),
				'ssl' => array(
					'verify_peer' => false,
					'verify_peer_name' => false,
				),
			));
			$body = @file_get_contents($url, false, $ctx);

			if ($body === false) {
				$err = 'request failed';
			}
			else {
				foreach (isset($http_response_header) ? $http_response_header : array() as $h) {
					if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
						$code = intval($m[1]);
					}
				}
			}
		}

		$ms = intval((microtime(true) - $start) * 1000);

		if ($err !== '') {
			return array('ok' => false, 'latency' => $ms, 'reason' => 'http failed: ' . $err);
		}

		if ($code < 200 || $code >= 400) {
			return array('ok' => false, 'latency' => $ms, 'reason' => 'http code=' . $code);
		}

		if ($expect !== '' && stripos($body, $expect) === false) {
			return array('ok' => false, 'latency' => $ms, 'reason' => 'body missing expect "' . $expect . '"');
		}

		return array('ok' => true, 'latency' => $ms, 'reason' => 'http code=' . $code);
	}

	private function probePing($t, $timeout)
	{
		$host = $this->targetHost($t);

		if ($host === '') {
			return array('ok' => false, 'latency' => 0, 'reason' => 'empty host');
		}

		if (!preg_match('/^[A-Za-z0-9_.:\-\x80-\xBF]+$/', $host)) {
			return array('ok' => false, 'latency' => 0, 'reason' => 'invalid host chars');
		}

		if (!function_exists('exec')) {
			return array('ok' => false, 'latency' => 0, 'reason' => 'exec disabled, ping not available');
		}

		$cmd = 'ping -n -c 1 -W ' . intval($timeout) . ' ' . escapeshellarg($host) . ' >/dev/null 2>&1';
		$start = microtime(true);
		$status = -1;
		@exec($cmd, $out, $status);
		$ms = intval((microtime(true) - $start) * 1000);

		if ($status === 0) {
			return array('ok' => true, 'latency' => $ms, 'reason' => 'ping ok');
		}

		return array('ok' => false, 'latency' => $ms, 'reason' => 'ping failed status=' . $status);
	}

	/* ---------- 校验与工具（add 表单与 cron 共用口径） ---------- */

	/**
	 * 校验/归一化一条 health_node 记录。返回归一化后的行；非法返回 false
	 * 并 setLastError。$old 传入既有时用于保留状态字段。
	 */
	public function normalize($row, $old = null)
	{
		$out = array();
		$name = isset($row['name']) ? trim(strval($row['name'])) : '';

		if (!preg_match('/^[0-9a-zA-Z_.\-]{2,64}$/', $name)) {
			setLastError('探测名称限 2-64 位字母/数字/._-');
			return false;
		}

		$out['name'] = $name;

		$type = isset($row['type']) ? strtolower(trim(strval($row['type']))) : 'tcp';

		if (!in_array($type, self::TYPES)) {
			setLastError('探测类型仅支持 tcp/http/ping');
			return false;
		}

		$out['type'] = $type;

		$host = isset($row['host']) ? trim(strval($row['host'])) : '';

		if ($type !== 'http' && $host === '') {
			setLastError('tcp/ping 探测必须填写主机');
			return false;
		}

		if ($host !== '' && !preg_match('/^[A-Za-z0-9_.\-:]+$/', $host)) {
			setLastError('主机地址字符非法');
			return false;
		}

		if (strlen($host) > 255) {
			setLastError('主机地址过长');
			return false;
		}

		$out['host'] = $host;

		$port = intval(isset($row['port']) ? $row['port'] : 80);

		if ($port < 1 || $port > 65535) {
			$port = 80;
		}

		$out['port'] = $port;

		$url = isset($row['url']) ? trim(strval($row['url'])) : '';

		if (strlen($url) > 512) {
			setLastError('url 过长（>512）');
			return false;
		}

		if ($type === 'http' && $url === '' && $host === '') {
			setLastError('http 探测需填写 url 或主机');
			return false;
		}

		$out['url'] = $url;

		$expect = isset($row['expect']) ? trim(strval($row['expect'])) : '';

		if (strlen($expect) > 128) {
			$expect = substr($expect, 0, 128);
		}

		$out['expect'] = $expect;

		$node = isset($row['node']) ? trim(strval($row['node'])) : '';

		if ($node !== '' && !preg_match('/^[0-9a-z]{2,15}$/', $node)) {
			setLastError('关联节点名称非法');
			return false;
		}

		$out['node'] = $node;

		$cycle = intval(isset($row['cycle']) ? $row['cycle'] : self::DEFAULT_CYCLE);
		$out['cycle'] = max(1, min(self::MAX_CYCLE, $cycle));

		$timeout = intval(isset($row['timeout']) ? $row['timeout'] : self::DEFAULT_TIMEOUT);
		$out['timeout'] = max(1, min(self::MAX_TIMEOUT, $timeout));

		$out['enabled'] = (isset($row['enabled']) && intval($row['enabled']) === 0) ? 0 : 1;

		// 状态字段：新建默认 up/0；编辑保留原值（编辑目标不该洗掉判抖历史）
		if (is_array($old)) {
			$out['status'] = intval(isset($old['status']) ? $old['status'] : 1);
			$out['errcount'] = intval(isset($old['errcount']) ? $old['errcount'] : 0);
			$out['latency'] = intval(isset($old['latency']) ? $old['latency'] : 0);
			$out['lasterr'] = isset($old['lasterr']) ? strval($old['lasterr']) : '';
			$out['lastcheck'] = intval(isset($old['lastcheck']) ? $old['lastcheck'] : 0);
			$out['lastswitch'] = intval(isset($old['lastswitch']) ? $old['lastswitch'] : 0);
		}
		else {
			$out['status'] = 1;
			$out['errcount'] = 0;
			$out['latency'] = 0;
			$out['lasterr'] = '';
			$out['lastcheck'] = 0;
			$out['lastswitch'] = 0;
		}

		return $out;
	}

	/**
	 * 保存一条探测目标（面板 addFrom 表单入口共用）
	 */
	public function saveTarget($row)
	{
		$old = null;
		$name = isset($row['name']) ? trim(strval($row['name'])) : '';

		if ($name !== '') {
			$found = daocall('healthnode', 'get', array($name));

			if (is_array($found) && isset($found['name'])) {
				$old = $found;
			}
		}

		$data = $this->normalize($row, $old);

		if ($data === false) {
			return false;
		}

		if (!daocall('healthnode', 'add', array($data))) {
			setLastError('保存探测目标失败（health_node 表不可写？）');
			return false;
		}

		return true;
	}

	private function targetHost($t)
	{
		return isset($t['host']) ? trim(strval($t['host'])) : '';
	}

	private function targetPort($t)
	{
		$port = intval(isset($t['port']) ? $t['port'] : 80);

		if ($port < 1 || $port > 65535) {
			$port = 80;
		}

		return $port;
	}

	private function describeTarget($t)
	{
		$bits = array();
		$bits[] = isset($t['type']) ? strval($t['type']) : 'tcp';

		if (isset($t['url']) && $t['url'] !== '') {
			$bits[] = strval($t['url']);
		}
		else if (isset($t['host']) && $t['host'] !== '') {
			$bits[] = strval($t['host']) . ':' . $this->targetPort($t);
		}

		return implode(' ', $bits);
	}

	private function phpBin()
	{
		foreach (array('/usr/bin/php74', '/usr/bin/php') as $cand) {
			if (is_executable($cand)) {
				return $cand;
			}
		}

		return null;
	}
}

?>
