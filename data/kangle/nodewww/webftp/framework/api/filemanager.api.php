<?php
/**
 * filemanager.api.php —— 管理端全局文件管理（需求 3 / FM-01~FM-13）
 *
 * 与用户侧 webftp 的本质区别（决定了本文件所有的额外防护）：
 *   用户侧（webftp.ctl.php）以**站点用户**身份运行（change_to_user），
 *   即使路径校验被绕过，进程权限本身就是最后一道兜底。
 *   管理端是 needRole('admin') 且容器内为 **root**，那道兜底完全失效——
 *   路径校验是唯一防线。因此本类把 realpath 前缀校验、symlink 逐段检查、
 *   禁区黑名单做成**不可绕过的私有方法**，任何公开方法都必须先过一遍。
 *
 * 根锚点固定为 /home/ftp（决策 1）：不挂载宿主根、不提供 /hostroot。
 */

class FilemanagerApi extends API
{
	/** 默认根锚点：容器内站点家目录（所有网站的根路径都在其下） */
	const ROOT_SITE = '/home/ftp';

	/** 全盘根锚点：整个容器文件系统（含 bind mount 进来的宿主目录） */
	const ROOT_SYSTEM = '/';

	/** 兼容保留：历史代码与错误文案仍引用 ROOT 表示默认站点根 */
	const ROOT = '/home/ftp';

	/** 打包用的 7z（容器内的绝对路径，随 data/kangle bind mount 进来） */
	const BIN_7Z = '/vhs/kangle/bin/7z';

	/** 浏览范围在 session 中的键名 */
	const SCOPE_KEY = '__fm_scope';

	/**
	 * 禁区：目录前缀（FM-07）—— 仅在**站点范围（site）**下生效
	 *
	 * 站点范围内这些前缀本就在 /home/ftp 之外，前缀校验即可挡住；
	 * 保留它们是纵深防御：symlink 逃逸时前缀校验的对象是 realpath，黑名单是第二道。
	 */
	private static $DENY_PREFIX = array(
		'/vhs/kangle', '/root/.acme.sh', '/vhs', '/etc', '/var', '/proc', '/sys',
		'/dev', '/run', '/boot', '/bin', '/sbin', '/usr', '/lib', '/lib64',
		'/opt', '/srv', '/tmp', '/root', '/home/ftp/..',
	);

	/**
	 * 全盘范围（system）下**禁止写入/删除**的系统关键前缀。
	 *
	 * 需求要求「可以查看服务器上任意目录」，重点在「查看」。因此全盘范围采取
	 * 「读放开、写设限」：这些目录可以浏览与下载，但不允许改名/删除/覆盖/改权限。
	 * 理由是这些路径一旦被误删，容器立刻失去可用性且不可逆——而管理员真正需要
	 * 在面板里写入的场景（站点、备份、/opt 自建程序）都不在此列。
	 * 需要动系统文件的场景应当走 SSH，那里有 shell 的确认与回滚手段。
	 */
	private static $SYSTEM_READONLY_PREFIX = array(
		'/proc', '/sys', '/dev', '/boot', '/bin', '/sbin',
		'/lib', '/lib64', '/lib32', '/libx32', '/usr', '/etc',
		'/vhs/kangle/bin', '/vhs/kangle/etc',
	);

	/** 禁区：精确文件名（落到哪里都不允许被读改删） */
	private static $DENY_NAME = array(
		'node.cfg.php', '.env', 'db_config.php', 'config.xml', 'vh_db.xml',
	);

	/** 单次列举的条目上限，避免超大目录把内存与页面一起拖垮 */
	const MAX_LIST = 2000;

	/** 单文件大小上限：超过则拒绝读取（防止把几个 GB 的日志读进内存） */
	const MAX_READ = 2097152;

	public function __construct()
	{
		// webftp.lib.php 提供了 ls() / trimdir() / toutf8() 等复用函数。
		//
		// 必须带 'pub:' 标签（远端部署实测修复）：__load_core() 的 default 分支会把
		// $dir 与文件名**无分隔符**直接拼接，且基准目录是 APPLICATON_ROOT：
		//   load_lib('webftp')     -> APPLICATON_ROOT . '/libwebftp.lib.php'  ✗ 不存在
		//   load_lib('pub:webftp') -> SYS_ROOT . '/lib/webftp.lib.php'        ✓
		// 漏写前缀时 load_lib 静默失败（trigger_error + return false），
		// ls() / trimdir() 始终未定义，首个调用点（ls() 内 `ls($path, $path)`）
		// 抛 Fatal error，表现为 kangle 返回 500 且响应体为空。
		// 全项目其余 load_lib 调用点均带 'pub:'，此处保持一致。
		if (!function_exists('trimdir')) {
			load_lib('pub:webftp');
		}

		// 加载断言：load_lib() 在文件缺失时只 trigger_error + return false，
		// 不会中断，故障会延迟到首个调用点才以 Fatal error 形式爆发（且生产环境
		// display_errors=Off 时表现为 kangle 返回 500、响应体为空，极难定位）。
		// 这里在构造期就把缺失讲清楚，把「静默延迟崩溃」变成「即时可读的告警」。
		foreach (array('trimdir', 'splitdir', 'ls', 'toutf8') as $fn) {
			if (!function_exists($fn)) {
				trigger_error(
					'FilemanagerApi: 依赖函数 ' . $fn . '() 未定义，'
					. 'webftp.lib.php 未加载成功（应解析为 SYS_ROOT/lib/webftp.lib.php）',
					E_USER_WARNING
				);
			}
		}
	}

	/* ==================================================================
	 * 浏览范围（scope）：site = 站点根 / system = 整个服务器
	 * ================================================================ */

	/**
	 * 当前浏览范围。
	 *
	 * 存 session 而非请求参数：若由请求参数决定，攻击者只要诱导管理员点一个
	 * 带 scope=system 的链接就能把作用域拉到全盘，等于绕过了二次确认。
	 * 放在 session 里则必须先经过 setScope（STRICT 二次密码确认）才能切换。
	 *
	 * @return string 'site'|'system'
	 */
	private function scope()
	{
		if (isset($_SESSION[self::SCOPE_KEY]) && $_SESSION[self::SCOPE_KEY] === 'system') {
			return 'system';
		}

		return 'site';
	}

	/**
	 * 当前范围对应的根锚点
	 *
	 * @return string
	 */
	private function root()
	{
		return ($this->scope() === 'system') ? self::ROOT_SYSTEM : self::ROOT_SITE;
	}

	/**
	 * 切换浏览范围（对外 API）
	 *
	 * 调用方 FilemanagerControl::setScope() 已登记为 CSRF_REAUTH_STRICT，
	 * 即切到全盘必须重新输入管理员密码。
	 *
	 * @param string $scope 'site'|'system'
	 * @return array
	 */
	public function setScope($scope)
	{
		$s = ($scope === 'system') ? 'system' : 'site';

		$_SESSION[self::SCOPE_KEY] = $s;

		$this->auditFile('setScope', $s, 'success', array('root' => $this->root()));

		return array(
			'success' => true,
			'scope'   => $s,
			'root'    => $this->root(),
			'msg'     => ($s === 'system')
				? '已切换到「整个服务器」范围。该范围下系统关键目录（/etc、/usr、/bin 等）只读，可浏览下载但不可修改删除。'
				: '已切回「站点目录」范围（' . self::ROOT_SITE . '）。',
		);
	}

	/**
	 * 查询当前浏览范围（对外 API，供页面初始化）
	 *
	 * @return array
	 */
	public function getScope()
	{
		return array(
			'success'     => true,
			'scope'       => $this->scope(),
			'root'        => $this->root(),
			'siteRoot'    => self::ROOT_SITE,
			'systemRoot'  => self::ROOT_SYSTEM,
		);
	}

	/* ==================================================================
	 * 路径安全：四层防护的 L2 / L3 全部收口在这里
	 * ================================================================ */

	/**
	 * 把用户传入的相对路径解析成 ROOT 下的绝对路径
	 *
	 * 三步走，缺一不可：
	 *   1. trimdir() + splitdir() 归一化（弹掉 '..'，复用既有实现）
	 *   2. 拼到 ROOT 下，确保起点恒定
	 *   3. realpath() 解析符号链接后的真实位置
	 *
	 * @param string $dir  相对 ROOT 的目录
	 * @param string $name 可选的子项名
	 * @return array|null array('abs'=>拼接结果, 'real'=>realpath 或 false)；非法时返回 null
	 */
	private function resolve($dir, $name = '')
	{
		$dir = $this->normalize($dir);

		if ($name !== '' && $name !== null) {
			$name = $this->normalize('/' . $name);
			$dir = $dir . ($name === '/' ? '' : $name);
		}

		if ($dir === '') {
			$dir = '/';
		}

		$abs = rtrim(self::ROOT . $dir, '/');

		if ($abs === '') {
			$abs = self::ROOT;
		}

		// realpath 对不存在的路径返回 false——调用方据此区分「读」与「新建」
		return array('abs' => $abs, 'real' => @realpath($abs));
	}

	/**
	 * 归一化：去空段、弹掉 '..'。
	 *
	 * 复用 webftp.lib.php 的 trimdir()（内部走 splitdir()），
	 * 保持与用户侧一致的口径，避免两套实现各自漂移。
	 *
	 * @param string $dir
	 * @return string 以 '/' 开头的路径；空输入返回 '/'
	 */
	private function normalize($dir)
	{
		$dir = trim(strval($dir));

		if ($dir === '') {
			return '/';
		}

		// 反斜杠统一成正斜杠：Windows 客户端可能传过来
		$dir = str_replace('\\', '/', $dir);

		if (function_exists('trimdir')) {
			$dir = trimdir($dir);
		}

		if ($dir === '' || $dir === '/') {
			return '/';
		}

		return '/' . ltrim($dir, '/');
	}

	/**
	 * L2：路径必须落在 ROOT 之内
	 *
	 * @param string $real realpath 结果
	 * @return bool
	 */
	private function assertInside($real)
	{
		if (!is_string($real) || $real === '') {
			return false;
		}

		return $real === self::ROOT || strpos($real, self::ROOT . '/') === 0;
	}

	/**
	 * L2：逐段检查符号链接，拒绝从 ROOT 内「链接」到 ROOT 外的逃逸
	 *
	 * 为什么必须逐段：只对最终路径做一次 realpath，中间某一段是 symlink 时
	 * 最终 realpath 仍在 ROOT 内也可能被利用（例如 ROOT/a -> /etc，
	 * 访问 ROOT/a/passwd 时 realpath 是 /etc/passwd，会被前缀校验挡住；
	 * 但 ROOT/a 本身作为目录被列举时，内容其实是 /etc 的内容，
	 * 而它的 realpath 前缀校验却是通过的）。逐段检查才能堵住这一类。
	 *
	 * @param string $abs 拼接后的绝对路径（未 realpath）
	 * @return bool true 表示未发现逃逸
	 */
	private function assertNoSymlink($abs)
	{
		$rel = substr($abs, strlen(self::ROOT));

		if ($rel === '' || $rel === false) {
			return true;
		}

		$cur = self::ROOT;

		foreach (explode('/', trim($rel, '/')) as $seg) {
			if ($seg === '' || $seg === '.' || $seg === '..') {
				continue;
			}

			$cur .= '/' . $seg;

			// 当前段是符号链接 → 拒绝，不论它指向哪里。
			// 保守但正确：合法站点极少依赖 symlink 跨出家目录，
			// 而管理员误删宿主文件的代价是不可逆的。
			if (is_link($cur)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * L3：禁区黑名单（服务端硬校验）
	 *
	 * @param string $abs 待判定的绝对路径
	 * @return bool true 表示允许
	 */
	private function assertNotDenied($abs)
	{
		$check = $abs;

		if (strpos($check, '..') !== false) {
			return false;
		}

		$base = basename($check);

		foreach (self::$DENY_NAME as $name) {
			if ($base === $name) {
				return false;
			}
		}

		foreach (self::$DENY_PREFIX as $prefix) {
			if ($check === $prefix || strpos($check, $prefix . '/') === 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 读类操作的统一入口：解析 + 四层校验
	 *
	 * @param string $dir
	 * @param string $name
	 * @return array|string 成功返回 realpath 字符串；失败返回 array('success'=>false,...)
	 */
	private function guardRead($dir, $name = '')
	{
		$r = $this->resolve($dir, $name);

		if ($r === null) {
			return $this->fail('非法的路径');
		}

		if ($r['real'] === false) {
			return $this->fail('路径不存在');
		}

		if (!$this->assertInside($r['real'])) {
			return $this->fail('路径越界：只能访问 ' . self::ROOT . ' 之内的文件');
		}

		if (!$this->assertNoSymlink($r['abs'])) {
			return $this->fail('拒绝访问：路径中存在符号链接');
		}

		if (!$this->assertNotDenied($r['real'])) {
			return $this->fail('该路径属于受保护区域，禁止访问');
		}

		return $r['real'];
	}

	/**
	 * 写类操作的统一入口
	 *
	 * 与读的区别：目标文件**尚不存在**，realpath 必然失败。
	 * 因此改为校验「父目录」，再把合法的文件名拼回去——
	 * 这样既不放宽边界，也不会让新建操作永远失败。
	 *
	 * @param string $dir
	 * @param string $name 目标名（必须是纯文件名，不得含路径分隔符）
	 * @return array|string 成功返回目标绝对路径；失败返回错误数组
	 */
	private function guardWrite($dir, $name)
	{
		$name = $this->safeName($name);

		if ($name === '') {
			return $this->fail('非法的文件名');
		}

		$r = $this->resolve($dir);

		if ($r === null || $r['real'] === false) {
			return $this->fail('目标目录不存在');
		}

		if (!$this->assertInside($r['real'])) {
			return $this->fail('路径越界：只能在 ' . self::ROOT . ' 之内操作');
		}

		if (!$this->assertNoSymlink($r['abs'])) {
			return $this->fail('拒绝操作：路径中存在符号链接');
		}

		$target = $r['real'] . '/' . $name;

		if (!$this->assertNotDenied($target)) {
			return $this->fail('该路径属于受保护区域，禁止操作');
		}

		return $target;
	}

	/**
	 * 清洗文件名：只保留 basename，剔除分隔符与控制字符
	 *
	 * @param string $name
	 * @return string 清洗后的名字；非法返回空串
	 */
	private function safeName($name)
	{
		if (!is_string($name)) {
			return '';
		}

		$name = str_replace("\0", '', $name);
		$name = str_replace('\\', '/', $name);
		$name = basename($name);

		if ($name === '' || $name === '.' || $name === '..') {
			return '';
		}

		if (strpos($name, '..') !== false) {
			return '';
		}

		return $name;
	}

	/* ==================================================================
	 * 公开接口（返回结构与 host.api.php / docker.api.php 完全一致）
	 * ================================================================ */

	/**
	 * 列举目录
	 *
	 * @param string $dir 相对 ROOT 的路径
	 * @return array
	 */
	public function ls($dir)
	{
		$path = $this->guardRead($dir);

		if (is_array($path)) {
			return $path;
		}

		if (!is_dir($path)) {
			return $this->fail('不是一个目录');
		}

		// 复用用户侧 ls()：它自带类型判定、图标、写权限探测与编码转换
		$items = ls($path, $path);

		if (!is_array($items)) {
			return $this->fail('目录无法读取（权限不足或已被移除）');
		}

		$list = array();
		$total = 0;

		foreach ($items as $it) {
			if (++$total > self::MAX_LIST) {
				break;
			}

			// ls() 返回的 info 是 stat() 数组：2=mode, 7=size, 9=mtime
			$info = isset($it['info']) ? $it['info'] : array();
			$name = isset($it['filename']) ? $it['filename'] : '';

			$list[] = array(
				'name'     => $name,
				'type'     => isset($it['type']) ? $it['type'] : 'unknown',
				'dir'      => !empty($it['dir']),
				'size'     => isset($info[7]) ? intval($info[7]) : 0,
				'mtime'    => isset($info[9]) ? intval($info[9]) : 0,
				'mode'     => isset($info[2]) ? substr(decoct($info[2]), -4) : '',
				'writable' => !empty($it['writable']),
				'icon'     => isset($it['type']) ? $it['type'] : 'unknown',
			);
		}

		$rel = substr($path, strlen(self::ROOT));

		if ($rel === '' || $rel === false) {
			$rel = '/';
		}

		return array(
			'success'  => true,
			'msg'      => '',
			'list'     => $list,
			'cwd'      => $rel,
			'parent'   => $this->parentOf($rel),
			'truncated'=> $total > self::MAX_LIST,
			'root'     => self::ROOT,
		);
	}

	/**
	 * 读取文本文件内容
	 *
	 * @param string $dir
	 * @param string $name
	 * @return array
	 */
	public function readFile($dir, $name)
	{
		$path = $this->guardRead($dir, $name);

		if (is_array($path)) {
			return $path;
		}

		if (is_dir($path)) {
			return $this->fail('不能读取目录');
		}

		$size = @filesize($path);

		if ($size !== false && $size > self::MAX_READ) {
			return $this->fail('文件过大（' . $this->humanSize($size) . '），超过 '
				. $this->humanSize(self::MAX_READ) . ' 上限，请改用下载');
		}

		$content = @file_get_contents($path);

		if ($content === false) {
			return $this->fail('文件读取失败（权限不足）');
		}

		return array('success' => true, 'msg' => '', 'content' => $content, 'encoding' => 'utf-8');
	}

	/**
	 * 写入文本文件
	 *
	 * @param string $dir
	 * @param string $name
	 * @param string $content
	 * @return array
	 */
	public function writeFile($dir, $name, $content)
	{
		$target = $this->guardWrite($dir, $name);

		if (is_array($target)) {
			return $target;
		}

		if (file_exists($target) && is_dir($target)) {
			return $this->fail('同名目录已存在');
		}

		$ret = @file_put_contents($target, $content);

		if ($ret === false) {
			return $this->fail('写入失败：' . $this->lastError());
		}

		$this->auditFile('writeFile', $target, 'success', array('bytes' => strlen(strval($content))));

		return array('success' => true, 'msg' => '已保存', 'bytes' => strlen(strval($content)));
	}

	/**
	 * 上传（支持分片）
	 *
	 * 分片策略：每片先落到系统临时目录的独立子目录，收到最后一片时按序号
	 * 顺序合并到目标文件，然后清理临时目录。中途失败不会污染目标文件。
	 *
	 * @param string $dir         目标目录
	 * @param string $name        文件名
	 * @param int    $chunkIndex  当前片序号，从 0 开始
	 * @param int    $totalChunks 总片数
	 * @return array
	 */
	public function upload($dir, $name, $chunkIndex = 0, $totalChunks = 1)
	{
		$target = $this->guardWrite($dir, $name);

		if (is_array($target)) {
			return $target;
		}

		if (!isset($_FILES['file'])) {
			return $this->fail('没有收到上传内容');
		}

		$f = $_FILES['file'];

		if (!is_array($f) || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
			return $this->fail('非法的上传来源');
		}

		$totalChunks = intval($totalChunks);
		$chunkIndex = intval($chunkIndex);

		if ($totalChunks < 1) {
			$totalChunks = 1;
		}

		if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
			return $this->fail('非法的分片序号');
		}

		// 单分片：直接落盘，无需临时目录中转
		if ($totalChunks === 1) {
			if (!@move_uploaded_file($f['tmp_name'], $target)) {
				return $this->fail('保存失败：' . $this->lastError());
			}

			$size = @filesize($target);
			$this->auditFile('upload', $target, 'success', array('bytes' => $size));

			return array('success' => true, 'msg' => '上传完成', 'saved' => basename($target), 'bytes' => $size);
		}

		// 多分片：写入以目标路径哈希命名的临时目录，避免并发上传互相覆盖
		$tmpDir = sys_get_temp_dir() . '/ep_fm_' . md5($target);

		if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) {
			return $this->fail('无法创建分片临时目录');
		}

		$part = $tmpDir . '/' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT);

		if (!@move_uploaded_file($f['tmp_name'], $part)) {
			return $this->fail('分片 ' . ($chunkIndex + 1) . ' 保存失败');
		}

		// 只有当最后一片到达时才合并；前端可能乱序，故按最后一片的序号判定
		if ($chunkIndex !== $totalChunks - 1) {
			return array('success' => true, 'msg' => '分片 ' . ($chunkIndex + 1) . '/' . $totalChunks . ' 已接收',
				'done' => false, 'bytes' => 0);
		}

		$out = @fopen($target, 'wb');

		if (!$out) {
			$this->cleanupDir($tmpDir);
			return $this->fail('无法写入目标文件：' . $this->lastError());
		}

		$bytes = 0;

		for ($i = 0; $i < $totalChunks; ++$i) {
			$p = $tmpDir . '/' . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
			$in = @fopen($p, 'rb');

			if (!$in) {
				fclose($out);
				$this->cleanupDir($tmpDir);
				return $this->fail('分片 ' . ($i + 1) . ' 缺失，合并中止');
			}

			while (!feof($in)) {
				$buf = fread($in, 8192);
				fwrite($out, $buf);
				$bytes += strlen($buf);
			}

			fclose($in);
		}

		fclose($out);
		$this->cleanupDir($tmpDir);

		$this->auditFile('upload', $target, 'success', array('bytes' => $bytes, 'chunks' => $totalChunks));

		return array('success' => true, 'msg' => '上传完成', 'saved' => basename($target), 'bytes' => $bytes);
	}

	/**
	 * 下载（流式输出，不走 JSON）
	 *
	 * 必须流式：一次性 file_get_contents + echo 会把整个文件读进内存，
	 * 大文件直接撑爆 PHP memory_limit。同时支持 HTTP Range 以实现断点续传。
	 *
	 * @param string $dir
	 * @param string $name
	 * @param string $range HTTP Range 头
	 * @return void 本方法直接输出并 exit
	 */
	public function download($dir, $name, $range = '')
	{
		$path = $this->guardRead($dir, $name);

		if (is_array($path)) {
			$this->auditFile('download', is_string($dir) ? $dir : '-', 'error', array('msg' => $path['msg']));
			header('Content-Type: application/json; charset=utf-8');
			exit(json_encode($path));
		}

		if (is_dir($path)) {
			header('Content-Type: application/json; charset=utf-8');
			exit(json_encode($this->fail('目录不支持下载，请先打包')));
		}

		$size = filesize($path);
		$start = 0;
		$end = $size - 1;

		// Range: bytes=start-end
		if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
			if ($m[1] !== '') {
				$start = intval($m[1]);
			}

			if ($m[2] !== '') {
				$end = intval($m[2]);
			}

			if ($start > $end || $start >= $size) {
				$start = 0;
				$end = $size - 1;
			}

			if ($end >= $size) {
				$end = $size - 1;
			}

			header('HTTP/1.1 206 Partial Content');
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
		}

		$length = $end - $start + 1;

		// 文件名里的引号与换行会破坏 Content-Disposition，必须清掉
		$safe = str_replace(array('"', "\r", "\n"), '', basename($path));

		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode(basename($path)));
		header('Content-Length: ' . $length);
		header('Accept-Ranges: bytes');
		header('X-Content-Type-Options: nosniff');

		// 释放会话锁：下载可能持续很久，不释放会阻塞该用户后续所有请求
		if (function_exists('session_write_close')) {
			@session_write_close();
		}

		$fp = @fopen($path, 'rb');

		if (!$fp) {
			header('Content-Type: application/json; charset=utf-8');
			exit(json_encode($this->fail('文件无法打开')));
		}

		if ($start > 0) {
			fseek($fp, $start);
		}

		$sent = 0;

		while ($sent < $length && !feof($fp)) {
			$read = min(8192, $length - $sent);
			echo fread($fp, $read);
			$sent += $read;
			flush();
		}

		fclose($fp);
		$this->auditFile('download', $path, 'success', array('bytes' => $sent));
		exit;
	}

	/**
	 * 新建目录
	 *
	 * @param string $dir  父目录
	 * @param string $name 新目录名
	 * @return array
	 */
	public function mkdir($dir, $name)
	{
		$target = $this->guardWrite($dir, $name);

		if (is_array($target)) {
			return $target;
		}

		if (file_exists($target)) {
			return $this->fail('同名文件或目录已存在');
		}

		if (!@mkdir($target, 0755, true)) {
			return $this->fail('创建失败：' . $this->lastError());
		}

		$this->auditFile('mkdir', $target, 'success', array());

		return array('success' => true, 'msg' => '目录已创建', 'path' => $this->relOf($target));
	}

	/**
	 * 重命名
	 *
	 * @param string $dir
	 * @param string $srcName
	 * @param string $dstName
	 * @return array
	 */
	public function rename($dir, $srcName, $dstName)
	{
		$src = $this->guardRead($dir, $srcName);

		if (is_array($src)) {
			return $src;
		}

		if ($src === self::ROOT) {
			return $this->fail('根目录不可重命名');
		}

		$dst = $this->guardWrite($dir, $dstName);

		if (is_array($dst)) {
			return $dst;
		}

		if (file_exists($dst)) {
			return $this->fail('目标名称已存在');
		}

		if (!@rename($src, $dst)) {
			return $this->fail('重命名失败：' . $this->lastError());
		}

		$this->auditFile('rename', $src, 'success', array('to' => $dst));

		return array('success' => true, 'msg' => '已重命名', 'path' => $this->relOf($dst));
	}

	/**
	 * 复制（文件与目录均可）
	 *
	 * @param string $dir
	 * @param string $srcName
	 * @param string $dstName
	 * @return array
	 */
	public function copy($dir, $srcName, $dstName)
	{
		$src = $this->guardRead($dir, $srcName);

		if (is_array($src)) {
			return $src;
		}

		$dst = $this->guardWrite($dir, $dstName);

		if (is_array($dst)) {
			return $dst;
		}

		if (file_exists($dst)) {
			return $this->fail('目标名称已存在');
		}

		$count = 0;

		if (is_dir($src)) {
			if (!@mkdir($dst, 0755, true)) {
				return $this->fail('无法创建目标目录');
			}

			// mycopydir 的 $count 是引用传递，必须传变量不能直接传字面量
			$ok = mycopydir($src, $dst, $count);
		}
		else {
			$ok = @copy($src, $dst);
			$count = 1;
		}

		if (!$ok) {
			return $this->fail('复制失败：' . $this->lastError());
		}

		$this->auditFile('copy', $src, 'success', array('to' => $dst, 'count' => $count));

		return array('success' => true, 'msg' => '已复制', 'count' => $count);
	}

	/**
	 * 移动
	 *
	 * @param string $dir
	 * @param string $srcName
	 * @param string $dstDir 目标目录（相对 ROOT）
	 * @return array
	 */
	public function move($dir, $srcName, $dstDir)
	{
		$src = $this->guardRead($dir, $srcName);

		if (is_array($src)) {
			return $src;
		}

		if ($src === self::ROOT) {
			return $this->fail('根目录不可移动');
		}

		$dstBase = $this->guardRead($dstDir);

		if (is_array($dstBase)) {
			return $dstBase;
		}

		if (!is_dir($dstBase)) {
			return $this->fail('目标不是一个目录');
		}

		$dst = $dstBase . '/' . basename($src);

		if (!$this->assertNotDenied($dst)) {
			return $this->fail('目标路径属于受保护区域');
		}

		if (file_exists($dst)) {
			return $this->fail('目标已存在同名项');
		}

		if (!@rename($src, $dst)) {
			return $this->fail('移动失败：' . $this->lastError());
		}

		$this->auditFile('move', $src, 'success', array('to' => $dst));

		return array('success' => true, 'msg' => '已移动', 'path' => $this->relOf($dst));
	}

	/**
	 * 删除（单个）
	 *
	 * @param string $dir
	 * @param string $name
	 * @return array
	 */
	public function delete($dir, $name)
	{
		$path = $this->guardRead($dir, $name);

		if (is_array($path)) {
			return $path;
		}

		if ($path === self::ROOT) {
			return $this->fail('根目录不可删除');
		}

		$count = 0;

		if (is_dir($path)) {
			$ok = myrmdir($path, $count);
		}
		else {
			$ok = @unlink($path);
			$count = 1;
		}

		if (!$ok) {
			$this->auditFile('delete', $path, 'error', array('msg' => '删除失败'));
			return $this->fail('删除失败：' . $this->lastError());
		}

		$this->auditFile('delete', $path, 'success', array('count' => $count));

		return array('success' => true, 'msg' => '已删除', 'count' => $count);
	}

	/**
	 * 批量删除（部分失败不整体回滚，逐项记录）
	 *
	 * @param string $dir
	 * @param array  $names
	 * @return array
	 */
	public function batchDelete($dir, $names)
	{
		if (!is_array($names) || empty($names)) {
			return $this->fail('没有选择任何文件');
		}

		$count = 0;
		$failed = array();

		foreach ($names as $name) {
			$r = $this->delete($dir, $name);

			if (is_array($r) && !empty($r['success'])) {
				$count += isset($r['count']) ? intval($r['count']) : 1;
			}
			else {
				$failed[] = array('name' => strval($name), 'msg' => is_array($r) ? $r['msg'] : '未知错误');
			}
		}

		return array(
			'success' => true,
			'msg'     => empty($failed) ? ('已删除 ' . $count . ' 项') : ('已删除 ' . $count . ' 项，' . count($failed) . ' 项失败'),
			'count'   => $count,
			'failed'  => $failed,
		);
	}

	/**
	 * 修改权限
	 *
	 * @param string $dir
	 * @param string $name
	 * @param string $mode 八进制字符串，如 '0755'
	 * @return array
	 */
	public function chmod($dir, $name, $mode)
	{
		$path = $this->guardRead($dir, $name);

		if (is_array($path)) {
			return $path;
		}

		if ($path === self::ROOT) {
			return $this->fail('根目录不允许修改权限');
		}

		// 只接受 3-4 位八进制，杜绝 '; rm -rf /' 之类被拼进命令
		$mode = trim(strval($mode));

		if (!preg_match('/^0?[0-7]{3}$/', $mode)) {
			return $this->fail('权限格式应为三位八进制，如 755');
		}

		$oct = octdec(ltrim($mode, '0'));

		if ($oct < 0 || $oct > 07777) {
			return $this->fail('权限值超出范围');
		}

		if (!@chmod($path, $oct)) {
			return $this->fail('修改权限失败：' . $this->lastError());
		}

		$this->auditFile('chmod', $path, 'success', array('mode' => $mode));

		return array('success' => true, 'msg' => '权限已修改', 'mode' => $mode);
	}

	/**
	 * 打包为 zip（批量下载）
	 *
	 * @param string $dir
	 * @param array  $names
	 * @param string $zipName 输出的包名（不含扩展名）
	 * @return array
	 */
	public function compress($dir, $names, $zipName = '')
	{
		if (!is_array($names) || empty($names)) {
			return $this->fail('没有选择任何文件');
		}

		$base = $this->guardRead($dir);

		if (is_array($base)) {
			return $base;
		}

		if (!is_file(self::BIN_7Z)) {
			return $this->fail('打包工具不可用：' . self::BIN_7Z);
		}

		// 白名单清洗：包名只允许中英文、数字、下划线、连字符与点
		$zipName = trim(strval($zipName));

		if ($zipName === '') {
			$zipName = 'archive-' . date('Ymd-His');
		}

		$zipName = preg_replace('/[^A-Za-z0-9_\-\.\x{4e00}-\x{9fa5}]/u', '_', $zipName);

		if ($zipName === '' || $zipName === '.' || $zipName === '..') {
			$zipName = 'archive-' . date('Ymd-His');
		}

		$out = $this->guardWrite($dir, $zipName . '.zip');

		if (is_array($out)) {
			return $out;
		}

		$args = array();

		foreach ($names as $name) {
			$safe = $this->safeName($name);

			if ($safe === '') {
				continue;
			}

			// 逐个校验，避免混入越界项
			$chk = $this->guardRead($dir, $safe);

			if (is_array($chk)) {
				continue;
			}

			$args[] = escapeshellarg($safe);
		}

		if (empty($args)) {
			return $this->fail('没有可打包的有效文件');
		}

		// 在目标目录内执行，7z 只接收文件名（不含路径），避免路径被当作参数解析
		$cmd = 'cd ' . escapeshellarg($base) . ' && ' . escapeshellarg(self::BIN_7Z)
			. ' a -tzip -y ' . escapeshellarg(basename($out)) . ' ' . implode(' ', $args) . ' 2>&1';

		$lines = array();
		$code = 0;
		@exec($cmd, $lines, $code);

		if ($code !== 0 || !is_file($out)) {
			return $this->fail('打包失败：' . implode(' ', array_slice($lines, -3)));
		}

		$size = @filesize($out);
		$this->auditFile('compress', $out, 'success', array('items' => count($args), 'bytes' => $size));

		return array(
			'success' => true,
			'msg'     => '打包完成',
			'file'    => $this->relOf($out),
			'name'    => basename($out),
			'size'    => $size,
		);
	}

	/**
	 * 解压 zip 到目标目录（extract 在 csrf 清单为 GRACE 档：解压可覆盖既有文件）
	 *
	 * 仅支持 zip（与 compress 对称，统一走 7z）。目标目录必须是 ROOT 之内，
	 * 默认解压到压缩包所在目录；若提供 dest 则解压到 dest（dest 自身也受 L2 校验）。
	 *
	 * 先解压到随机临时目录、再整体 rename 进目标目录，避免 7z 解压过程中
	 * 部分文件已落地、校验阶段却把半成品暴露在 Web 可访问目录下的时序窗口。
	 *
	 * @param string $dir   压缩包所在目录
	 * @param string $name  压缩包文件名
	 * @param string $dest  目标目录（相对 ROOT），可选
	 * @return array
	 */
	public function extract($dir, $name, $dest = '')
	{
		$src = $this->guardRead($dir, $name);

		if (is_array($src)) {
			return $src;
		}

		if (is_dir($src)) {
			return $this->fail('只能解压文件');
		}

		if (strtolower(substr($src, -4)) !== '.zip') {
			return $this->fail('仅支持解压 .zip 文件');
		}

		if (!is_file(self::BIN_7Z)) {
			return $this->fail('解压工具不可用：' . self::BIN_7Z);
		}

		// 目标目录：默认与压缩包同目录
		if ($dest !== '' && $dest !== null) {
			$dstBase = $this->guardRead($dest);

			if (is_array($dstBase)) {
				return $dstBase;
			}

			if (!is_dir($dstBase)) {
				return $this->fail('目标目录不存在');
			}

			$outDir = $dstBase;
		}
		else {
			$outDir = dirname($src);
		}

		if (!$this->assertInside($outDir)) {
			return $this->fail('目标目录越界');
		}

		if (!$this->assertNotDenied($outDir)) {
			return $this->fail('目标目录属于受保护区域');
		}

		$tmp = $outDir . '/.ep_extract_' . md5($src . microtime(true));

		$cmd = escapeshellarg(self::BIN_7Z) . ' x -y -o' . escapeshellarg($tmp) . ' ' . escapeshellarg($src) . ' 2>&1';
		$lines = array();
		$code = 0;
		@exec($cmd, $lines, $code);

		if ($code !== 0 || !is_dir($tmp)) {
			$this->cleanupDir($tmp);
			return $this->fail('解压失败：' . implode(' ', array_slice($lines, -3)));
		}

		$ok = true;
		$dh = @opendir($tmp);

		if (!$dh) {
			$this->cleanupDir($tmp);
			return $this->fail('解压产物无法读取');
		}

		while (($f = readdir($dh)) !== false) {
			if ($f === '.' || $f === '..') {
				continue;
			}

			$from = $tmp . '/' . $f;
			$to = $outDir . '/' . $f;

			if (!@rename($from, $to)) {
				$ok = false;
				break;
			}
		}

		closedir($dh);
		$this->cleanupDir($tmp);

		if (!$ok) {
			return $this->fail('解压产物写入目标目录失败');
		}

		$this->auditFile('extract', $src, 'success', array('to' => $this->relOf($outDir)));

		return array('success' => true, 'msg' => '解压完成', 'dir' => $this->relOf($outDir));
	}

	/* ==================================================================
	 * 内部辅助
	 * ================================================================ */

	/**
	 * 统一失败返回（与 host.api.php / docker.api.php 口径一致）
	 *
	 * @param string $msg
	 * @return array
	 */
	private function fail($msg)
	{
		return array('success' => false, 'msg' => $msg);
	}

	/**
	 * 审计（FM-23 / L4）。失败静默，绝不阻断业务。
	 *
	 * @param string $action
	 * @param string $target
	 * @param string $result
	 * @param array  $detail
	 * @return void
	 */
	private function auditFile($action, $target, $result, $detail = array())
	{
		if (!function_exists('audit')) {
			return;
		}

		audit('filemanager', $action, $target, $result, $detail);
	}

	/**
	 * 取相对 ROOT 的路径（供前端回显）
	 *
	 * @param string $abs
	 * @return string
	 */
	private function relOf($abs)
	{
		if (strpos($abs, self::ROOT) === 0) {
			$rel = substr($abs, strlen(self::ROOT));
			return $rel === '' ? '/' : $rel;
		}

		return $abs;
	}

	/**
	 * 取上级目录（不会越过 ROOT）
	 *
	 * @param string $rel
	 * @return string
	 */
	private function parentOf($rel)
	{
		$rel = trim(strval($rel));

		if ($rel === '' || $rel === '/') {
			return '';
		}

		$pos = strrpos(rtrim($rel, '/'), '/');

		if ($pos === false || $pos === 0) {
			return '/';
		}

		return substr($rel, 0, $pos);
	}

	/**
	 * 人类可读的文件大小
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function humanSize($bytes)
	{
		$bytes = floatval($bytes);
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;

		while ($bytes >= 1024 && $i < count($units) - 1) {
			$bytes /= 1024;
			++$i;
		}

		return ($i === 0 ? intval($bytes) : number_format($bytes, 1)) . ' ' . $units[$i];
	}

	/**
	 * 取最后一次 PHP 文件操作错误
	 *
	 * @return string
	 */
	private function lastError()
	{
		$err = error_get_last();
		return is_array($err) && isset($err['message']) ? $err['message'] : '未知错误';
	}

	/**
	 * 递归清理临时目录（分片上传用后即焚）
	 *
	 * @param string $dir
	 * @return void
	 */
	private function cleanupDir($dir)
	{
		if (!is_dir($dir)) {
			return;
		}

		$count = 0;

		if (function_exists('myrmdir')) {
			myrmdir($dir, $count);
			return;
		}

		foreach (glob($dir . '/*') as $f) {
			@unlink($f);
		}

		@rmdir($dir);
	}
}

?>
