<?php
/**
 * remotestorage.api.php —— 远程备份存储适配层（backup-remote-and-ignore 单元）
 *
 * 提供四种远程目标，供 backup.api.php 的 remotePush / delBackupFile 与管理端
 * backupremote 控制器（配置保存 + testRemote 连通测试）共用：
 *   ftp     PHP ftp_* 函数（兼容旧配置，backup.api 里仍走原 ftpPush 通道）
 *   sftp    libcurl 原生 sftp://（容器内 curl 7.29 自带 libssh2，实测
 *           curl_version()['protocols'] 含 sftp）。不用 lftp：镜像里没有 ssh
 *           客户端，而 lftp 的 sftp 后端必须经 ssh 隧道（远端实测
 *           "Running connect program (ssh ...)"→command not found），
 *           且 CGI 无 TTY 时 ssh 密码认证不可用——curl/libssh2 是零安装路径
 *   webdav  curl PROPFIND/MKCOL/PUT/GET/DELETE（bytemark/webdav 靶实测语义）
 *   s3      SigV4 纯 curl 实现，兼容 AWS S3 / Cloudflare R2 / MinIO
 *
 * 约定：所有方法只接收“相对根目录”的路径（如 20261125f、20261125f/site.web.7z.001），
 * 根目录由各类型配置决定，在 fullPath() 内拼接。
 *
 * 本文件同时实现 install_cron()：写 /etc/cron.d/ep_backup_remote（hourly 触发
 * shell.php backup，真正执行与否由 backup.api 的 checkBackup 按设置自行判断）。
 * cronresync.php（admincron 单元）把它列为候选模块统一重建，本单元不碰 shell.php。
 */

define('EP_BACKUP_REMOTE_CRON_FILE', '/etc/cron.d/ep_backup_remote');

class RemotestorageAPI extends API
{
	/**
	 * 按 backup_remote_type 创建适配器（失败返回 false，原因见 getLastError）
	 * @param  array $setting
	 * @return RemoteStorage|false
	 */
	public function create($setting = array())
	{
		if (!is_array($setting)) {
			$setting = array();
		}

		return RemoteStorage::create($setting);
	}

	/**
	 * create() 失败时的中文原因
	 * @return string
	 */
	public function getLastError()
	{
		return RemoteStorage::getLastError();
	}

	/**
	 * 连通测试（管理端 testRemote 入口）。
	 * 成功断言：result=true 且探针对文件 ep_remote_test_*.txt 已真实上传到远端目录
	 * （验收用 docker exec webdavtest ls /dav 核对），失败绝不抛 500。
	 *
	 * @param  array $params 前端提交的原始表单参数（type + 各类型凭据）
	 * @return array array('result'=>bool,'code'=>string,'msg'=>string)
	 */
	public function testRemote($params = array())
	{
		if (!is_array($params)) {
			$params = array();
		}

		$type = isset($params['type']) ? strtolower(trim((string) $params['type'])) : '';

		if (!in_array($type, array('ftp', 'sftp', 'webdav', 's3'))) {
			return array('result' => false, 'code' => 'E_TYPE', 'msg' => '未知的远程存储类型');
		}

		$map = array(
			'ftp' => array('ftp_host' => 'host', 'ftp_port' => 'port', 'ftp_user' => 'user', 'ftp_passwd' => 'passwd', 'ftp_dir' => 'dir'),
			'sftp' => array('sftp_host' => 'host', 'sftp_port' => 'port', 'sftp_user' => 'user', 'sftp_passwd' => 'passwd', 'sftp_key' => 'key', 'sftp_dir' => 'dir', 'sftp_timeout' => 'timeout'),
			'webdav' => array('webdav_url' => 'url', 'webdav_user' => 'user', 'webdav_passwd' => 'passwd', 'webdav_dir' => 'dir', 'webdav_timeout' => 'timeout'),
			's3' => array('s3_endpoint' => 'endpoint', 's3_region' => 'region', 's3_bucket' => 'bucket', 's3_access_key' => 'access_key', 's3_secret_key' => 'secret_key', 's3_dir' => 'dir'),
		);
		$setting = array('backup_remote_type' => $type);

		foreach ($map[$type] as $key => $param) {
			$value = isset($params[$param]) ? trim((string) $params[$param]) : '';

			if ($key == 'ftp_port' || $key == 'sftp_port') {
				$value = intval($value);
			}
			elseif ($key == 'webdav_timeout' || $key == 's3_timeout' || $key == 'sftp_timeout') {
				$value = intval($value);
			}

			$setting[$key] = $value;
		}

		$storage = RemoteStorage::create($setting);

		if (!$storage) {
			return array('result' => false, 'code' => 'E_PARAM', 'msg' => RemoteStorage::getLastError());
		}

		if (!$storage->test()) {
			$error = $storage->getError();
			return array('result' => false, 'code' => 'E_CONNECT', 'msg' => $error === '' ? '远程连接失败，请检查配置' : $error);
		}

		// 探针文件：真实上传一次，证明凭据不只是“能登录”，还具备写权限。
		// 先确保配置的业务根目录存在（全新目录直接 PUT 会 409/not found）。
		if (!$storage->mkDir('')) {
			$error = $storage->getError();
			return array('result' => false, 'code' => 'E_MKDIR', 'msg' => $error === '' ? '远程根目录创建失败' : $error);
		}

		$probe = 'ep_remote_test_' . date('YmdHis') . '.txt';
		$tmp = @tempnam(sys_get_temp_dir(), 'eptest');

		if ($tmp === false) {
			return array('result' => false, 'code' => 'E_LOCAL', 'msg' => '无法创建本地探针临时文件');
		}

		@file_put_contents($tmp, 'EasyPanel remote backup probe ' . date('c') . "\n");

		if (!$storage->putFile($tmp, $probe)) {
			@unlink($tmp);
			$error = $storage->getError();
			return array('result' => false, 'code' => 'E_UPLOAD', 'msg' => $error === '' ? '探针文件上传失败' : $error);
		}

		@unlink($tmp);
		return array('result' => true, 'code' => 'OK', 'msg' => '连接可用，探针文件 ' . $probe . ' 已上传到远端目录');
	}

	/**
	 * 写入 /etc/cron.d/ep_backup_remote（幂等，可被 cronresync 反复重建）。
	 * 命令与 cron.api::install_system_cron（ep_sync_flow）保持同一形态：
	 * 0 * * * * root /usr/bin/php74 -c <tpl ini> <framework>/shell.php backup
	 * 实际是否执行由 backup.api.php::checkBackup 按 setting 开关与周期判断，
	 * backup 开关未开启时每次只是快速退出，无副作用。
	 * @return bool
	 */
	public function install_cron()
	{
		if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
			// 面板目标运行环境为 kangle 容器（Linux）；Windows 侧不落地 cron.d。
			return true;
		}

		$shell = '';

		if (defined('SYS_ROOT')) {
			$shell = SYS_ROOT . '/shell.php';
		}

		// SYS_ROOT 未定义/指向别处时，按本文件物理位置反推 framework 目录
		// （api/ 的上一级就是 framework/，shell.php 与之同级）。
		if ($shell === '' || !is_file($shell)) {
			$guess = dirname(dirname(__FILE__)) . '/shell.php';

			if (is_file($guess)) {
				$shell = $guess;
			}
		}

		if ($shell === '' || !is_file($shell)) {
			return false;
		}

		$cmd = '/usr/bin/php74 -c /vhs/kangle/ext/tpl_php74/php-templete.ini ' . $shell . ' backup';
		// 防行注入：命令不允许出现换行（路径可控性低，但一律过滤最稳妥）。
		$cmd = str_replace(array("\r", "\n"), '', $cmd);

		if (!file_exists('/etc/cron.d')) {
			@mkdir('/etc/cron.d', 0755);
		}

		$msg = "SHELL=/bin/bash\n";
		$msg .= "PATH=/sbin:/bin:/usr/sbin:/usr/bin\n";
		$msg .= "HOME=/\n";
		$msg .= '0 * * * * root ' . $cmd . " >> /tmp/ep_backup_remote.log 2>&1\n";
		$fp = @fopen(EP_BACKUP_REMOTE_CRON_FILE, 'wt');

		if ($fp === false) {
			return false;
		}

		fwrite($fp, $msg);
		fclose($fp);
		@chmod(EP_BACKUP_REMOTE_CRON_FILE, 0644);
		return true;
	}

	/**
	 * 卸载远程备份计划任务（保留入口，cronresync 不调用）
	 * @return bool
	 */
	public function uninstall_cron()
	{
		if (is_file(EP_BACKUP_REMOTE_CRON_FILE)) {
			return @unlink(EP_BACKUP_REMOTE_CRON_FILE);
		}

		return true;
	}

	/**
	 * 计划任务文件是否存在（UI 状态展示用）
	 * @return bool
	 */
	public function cron_exists()
	{
		return is_file(EP_BACKUP_REMOTE_CRON_FILE);
	}

	/**
	 * best-effort 落一条 remote_storage 记录（表不存在时静默，等待 upgrade.sql 生效）。
	 * 配置本体以 setting 键值为准，本表仅作展示/留痕。
	 * @return bool
	 */
	public function saveRecord($type, $config = array())
	{
		try {
			load_lib('pub:db');
			$pdo = db_connect();

			if (!$pdo) {
				return false;
			}

			$pdo->exec('UPDATE remote_storage SET active=0');
			$stmt = $pdo->prepare('INSERT INTO remote_storage (type, config, active, update_time) VALUES (:type, :config, 1, :ts)');

			if (!$stmt) {
				return false;
			}

			$stmt->execute(array(
				':type' => strval($type),
				':config' => json_encode($config, JSON_UNESCAPED_UNICODE),
				':ts' => time(),
			));
			return true;
		}
		catch (Throwable $e) {
			// 表未建 / sqlite 锁等场景不影响主流程
			return false;
		}
	}

	/**
	 * testRemote 成功后回写测试时间/结果（同样是 best-effort）
	 * @return bool
	 */
	public function markTest($type, $result, $msg = '')
	{
		try {
			load_lib('pub:db');
			$pdo = db_connect();

			if (!$pdo) {
				return false;
			}

			$stmt = $pdo->prepare('UPDATE remote_storage SET last_test_time=:ts, last_test_result=:res WHERE type=:type');

			if (!$stmt) {
				return false;
			}

			$stmt->execute(array(
				':ts' => time(),
				':res' => ($result ? 'ok:' : 'fail:') . mb_cut($msg),
				':type' => strval($type),
			));
			return true;
		}
		catch (Throwable $e) {
			return false;
		}
	}
}

/**
 * 存储适配器基类 + 工厂（命名与上游 easypanel storage.lib.php 对齐，
 * 便于后续单元（restore 走远程）无缝复用）。
 */
abstract class RemoteStorage
{
	protected $setting = array();
	protected $root = '';
	protected $error = '';
	protected $timeout = 3600;
	private static $create_error = '';

	public function __construct($setting)
	{
		$this->setting = is_array($setting) ? $setting : array();
	}

	/**
	 * 读取指定键的传输超时（秒），非法值回退 3600
	 */
	protected function resolveTimeout($key)
	{
		$timeout = isset($this->setting[$key]) ? intval($this->setting[$key]) : 0;
		return ($timeout >= 60 && $timeout <= 86400) ? $timeout : 3600;
	}

	/**
	 * 按 backup_remote_type 创建适配器
	 * @return RemoteStorage|false
	 */
	public static function create($setting)
	{
		self::$create_error = '';
		$type = isset($setting['backup_remote_type']) ? strtolower(trim($setting['backup_remote_type'])) : 'ftp';

		if (!in_array($type, array('ftp', 'sftp', 'webdav', 's3'))) {
			$type = 'ftp';
		}

		if ($type == 'sftp') {
			$obj = new SftpStorage($setting);
		}
		else if ($type == 'webdav') {
			$obj = new WebdavStorage($setting);
		}
		else if ($type == 's3') {
			$obj = new S3Storage($setting);
		}
		else {
			$obj = new FtpStorage($setting);
		}

		$err = $obj->validate();

		if ($err !== '') {
			self::$create_error = $err;
			return false;
		}

		return $obj;
	}

	/**
	 * create() 失败时的中文原因
	 * @return string
	 */
	public static function getLastError()
	{
		return self::$create_error;
	}

	public function getError()
	{
		return $this->error;
	}

	public function test()
	{
		return $this->connect();
	}

	abstract public function mkDir($path);
	abstract public function putFile($local, $remote);
	abstract public function getFile($remote, $local);
	abstract public function listDirs($path);
	abstract public function deleteDir($path);

	/**
	 * 配置校验，返回空串表示通过
	 * @return string
	 */
	public function validate()
	{
		return '';
	}

	protected function connect()
	{
		return true;
	}

	protected function fullPath($path)
	{
		$root = trim(str_replace('\\', '/', $this->root), '/');
		$path = trim(str_replace('\\', '/', $path), '/');

		if ($root === '') {
			return $path;
		}

		if ($path === '') {
			return $root;
		}

		return $root . '/' . $path;
	}
}

/**
 * FTP（PHP ftp_* 函数；backup 主链路对 type=ftp 仍走 backup.api 原有 ftpPush，
 * 该类主要服务于 testRemote 与后续 restore 远程化）
 */
class FtpStorage extends RemoteStorage
{
	private $host;
	private $port;
	private $user;
	private $pass;
	private $conn = null;

	public function __construct($setting)
	{
		parent::__construct($setting);
		$this->root = isset($setting['ftp_dir']) ? $setting['ftp_dir'] : '';
		$this->host = isset($setting['ftp_host']) ? trim($setting['ftp_host']) : '';
		$this->port = isset($setting['ftp_port']) ? intval($setting['ftp_port']) : 21;
		$this->user = isset($setting['ftp_user']) ? trim($setting['ftp_user']) : '';
		$this->pass = isset($setting['ftp_passwd']) ? $setting['ftp_passwd'] : '';

		if ($this->port <= 0 || $this->port > 65535) {
			$this->port = 21;
		}
	}

	public function validate()
	{
		if ($this->host === '' || $this->user === '') {
			return 'FTP 主机地址或账号未填写';
		}

		return '';
	}

	protected function connect()
	{
		if ($this->conn !== null) {
			return $this->conn !== false;
		}

		$con = @ftp_connect($this->host, $this->port, 15);

		if (!$con) {
			$this->error = 'FTP 连接失败，请检查主机地址和端口';
			$this->conn = false;
			return false;
		}

		if (!@ftp_login($con, $this->user, $this->pass)) {
			$this->error = 'FTP 登录失败，请检查账号和密码';
			$this->conn = false;
			return false;
		}

		@ftp_pasv($con, true);
		$this->conn = $con;
		return true;
	}

	private function absPath($path)
	{
		$dir = $this->fullPath($path);
		return $dir === '' ? '/' : '/' . $dir;
	}

	public function mkDir($path)
	{
		$dir = $this->absPath($path);

		if (!$this->connect()) {
			return false;
		}

		$cur = '';

		foreach (explode('/', $dir) as $name) {
			if ($name === '') {
				continue;
			}

			$cur .= '/' . $name;
			@ftp_mkdir($this->conn, $cur);
		}

		return true;
	}

	public function putFile($local, $remote)
	{
		if (!$this->connect()) {
			return false;
		}

		$remote = $this->absPath($remote);
		$dir = dirname($remote);
		$name = basename($remote);

		if (!@ftp_chdir($this->conn, $dir)) {
			$this->error = 'FTP 无法进入目录：' . $dir;
			return false;
		}

		if (!@ftp_put($this->conn, $name, $local, FTP_BINARY)) {
			$this->error = 'FTP 上传失败：' . basename($local);
			return false;
		}

		return true;
	}

	public function getFile($remote, $local)
	{
		if (!$this->connect()) {
			return false;
		}

		$remote = $this->absPath($remote);
		$dir = dirname($remote);
		$name = basename($remote);

		if (!@ftp_chdir($this->conn, $dir)) {
			$this->error = 'FTP 无法进入目录：' . $dir;
			return false;
		}

		if (!@ftp_get($this->conn, $local, $name, FTP_BINARY)) {
			$this->error = 'FTP 下载失败：' . $remote;
			return false;
		}

		return true;
	}

	public function listDirs($path)
	{
		if (!$this->connect()) {
			return false;
		}

		$dir = $this->absPath($path);

		if (!@ftp_chdir($this->conn, $dir)) {
			$this->error = 'FTP 无法进入目录：' . $dir;
			return false;
		}

		$list = @ftp_nlist($this->conn, '.');

		if ($list === false) {
			$this->error = 'FTP 列出目录失败：' . $dir;
			return false;
		}

		$result = array();

		foreach ($list as $file) {
			$name = basename(str_replace('\\', '/', $file));

			if ($name === '' || $name === '.' || $name === '..') {
				continue;
			}

			$result[] = $name;
		}

		return $result;
	}

	public function deleteDir($path)
	{
		if (!$this->connect()) {
			return false;
		}

		$dir = $this->absPath($path);

		if ($dir === '/' || !@ftp_chdir($this->conn, $dir)) {
			return false;
		}

		$list = @ftp_nlist($this->conn, '.');

		if (is_array($list)) {
			foreach ($list as $file) {
				$name = basename(str_replace('\\', '/', $file));

				if ($name === '' || $name === '.' || $name === '..') {
					continue;
				}

				if (!@ftp_delete($this->conn, $name)) {
					@ftp_rmdir($this->conn, $name);
				}
			}
		}

		@ftp_chdir($this->conn, dirname($dir));
		return @ftp_rmdir($this->conn, basename($dir));
	}
}

/**
 * SFTP —— libcurl 原生 sftp:// 传输（容器内 curl 7.29 + libssh2 1.8.0，
 * `curl_version()['protocols']` 实测含 sftp；php74 无 ssh2 扩展）。
 *
 * 为什么不用镜像里的 lftp（远端容器实测结论）：
 *   1. lftp 的 sftp 后端 = `ssh` 隧道，而 kangle 镜像 **没有 openssh 客户端**
 *      （docker exec kangle which ssh → 空；/usr/bin 全量 grep 无 ssh/scp/sftp）；
 *   2. 即使补装 ssh，CGI 无 TTY 时 ssh 的密码认证不可用（实测 "Permission denied"），
 *      还得再补 sshpass —— 两个额外包只为一个测试按钮不值当；
 *   3. curl+libssh2 密码/公钥认证全部原生可用（atmoz/sftp 靶实测 upload/download/
 *      Mkdir/rm/Rmdir 全通过）。
 * 目录语义实测（curl 7.29 的 QUOTE 只认 Mkdir/rm/Rmdir，不认 MKD/DELETE 等原生名）：
 *   - `Mkdir <绝对路径>` 仅单层：父目录不存在报 "No such file or directory"，
 *     已存在报 "Operation failed"（非幂等！mkDir 必须逐级建并用 listing 判存在）。
 *   - 对象删除用 `rm <绝对路径>`，空目录删除用 `Rmdir <绝对路径>`。
 *   - 目录长列表形如 `drwxr-xr-x 3 1000 100 4096 Nov 25 14:42 epq1`（首字符 d 判目录）。
 * 所有远端路径经 safePath 白名单（不含空格/引号/分号），QUOTE 行不经过 shell，
 * 且本实现完全不 exec()，无命令注入面。
 */
class SftpStorage extends RemoteStorage
{
	private $host = '';
	private $port = 22;
	private $user = '';
	private $pass = '';
	private $key = '';
	private $made_dirs = array();

	public function __construct($setting)
	{
		parent::__construct($setting);
		$this->root = isset($setting['sftp_dir']) ? $setting['sftp_dir'] : '';
		$this->host = isset($setting['sftp_host']) ? trim($setting['sftp_host']) : '';
		$this->port = isset($setting['sftp_port']) ? intval($setting['sftp_port']) : 22;
		$this->user = isset($setting['sftp_user']) ? trim($setting['sftp_user']) : '';
		$this->pass = isset($setting['sftp_passwd']) ? $setting['sftp_passwd'] : '';
		$this->key = isset($setting['sftp_key']) ? trim($setting['sftp_key']) : '';

		if ($this->port <= 0 || $this->port > 65535) {
			$this->port = 22;
		}

		$this->timeout = $this->resolveTimeout('sftp_timeout');
	}

	public function validate()
	{
		if (!function_exists('curl_init')) {
			return '未启用 curl 扩展，无法使用 SFTP';
		}

		if ($this->host === '' || $this->user === '') {
			return 'SFTP 主机地址或账号未填写';
		}

		if ($this->key === '' && $this->pass === '') {
			return 'SFTP 需要填写密码或私钥路径';
		}

		if ($this->key !== '' && !is_file($this->key)) {
			return 'SFTP 私钥文件不存在，请填写面板容器内的绝对路径：' . $this->key;
		}

		return '';
	}

	/**
	 * 远程路径白名单：备份目录/文件名只允许安全 ASCII，禁空格与引号
	 */
	private function safePath($path)
	{
		return preg_replace('/[^A-Za-z0-9_.+:@\/-]/', '', strval($path));
	}

	/**
	 * URL 路径：逐段 rawurlencode（safePath 后其实等价，双保险）
	 */
	private function urlPath($path)
	{
		$parts = array();

		foreach (explode('/', $path) as $seg) {
			if ($seg !== '') {
				$parts[] = rawurlencode($seg);
			}
		}

		return '/' . implode('/', $parts);
	}

	private function applyAuth($ch)
	{
		if ($this->key !== '') {
			// CURLOPT_SSH_PRIVATE_KEYFILE 对 SFTP 同样生效（curl 7.29 实测接受该选项；
			// 带口令私钥在 7.29 无法非交互解锁，validate() 已提示不可用场景）
			curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->key);

			if (isset($this->setting['sftp_key_pass']) && $this->setting['sftp_key_pass'] !== '') {
				@curl_setopt($ch, CURLOPT_KEYPASSPHRASE, $this->setting['sftp_key_pass']);
			}
		}

		if ($this->pass !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
		}
		else {
			curl_setopt($ch, CURLOPT_USERNAME, $this->user);
		}
	}

	/**
	 * 低层请求。$path 为以 / 开头的远端路径（可含尾斜杠）；host/port 由属性拼接。
	 * $download_to 非空时把远端内容直接写该本地文件（curl_exec 不再回传字符串）。
	 * @return array ok/content/errno/errmsg/http
	 */
	private function request($method_verb, $path, $extra = array(), $timeout = 0, $download_to = null)
	{
		if (!function_exists('curl_init')) {
			return array('ok' => false, 'content' => '', 'errno' => -1, 'errmsg' => 'curl 扩展未启用', 'http' => 0);
		}

		$timeout = $timeout > 0 ? $timeout : $this->timeout;
		$host = $this->safePath($this->host);
		$url = 'sftp://' . $host . $path;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_PORT, $this->port);
		$this->applyAuth($ch);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, defined('CURLPROTO_SFTP') ? CURLPROTO_SFTP : CURLPROTO_ALL);
		}

		$fp = null;

		if ($download_to !== null) {
			/* 实测坑：CURLOPT_FILE 之后再设 CURLOPT_RETURNTRANSFER=false 会把
			 * write 回调重置回 stdout、本地文件保持 0 字节；所以下载模式必须
			 * 先 RETURNTRANSFER=false、最后再挂 CURLOPT_FILE。 */
			$fp = @fopen($download_to, 'wb');

			if (!$fp) {
				return array('ok' => false, 'content' => '', 'errno' => -2, 'errmsg' => '本地文件无法写入：' . $download_to, 'http' => 0);
			}

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_FILE, $fp);
		}
		else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		if ($method_verb === 'upload') {
			curl_setopt($ch, CURLOPT_UPLOAD, true);
		}

		foreach ($extra as $opt => $val) {
			if (!is_int($opt)) {
				continue;
			}

			curl_setopt($ch, $opt, $val);
		}

		$content = curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		$http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		curl_close($ch);

		if ($fp) {
			fclose($fp);
		}

		$ok = ($content !== false) && ($errno === 0);

		if (!$ok && $errmsg === '') {
			$errmsg = 'SFTP 传输失败（curl errno=' . $errno . '）';
		}

		return array(
			'ok' => $ok,
			'content' => is_string($content) ? $content : '',
			'errno' => $errno,
			'errmsg' => $errmsg,
			'http' => $http,
		);
	}

	private function describe($errno, $errmsg)
	{
		if ($errno == 67 || stripos($errmsg, 'Access denied') !== false
			|| stripos($errmsg, 'Authentication') !== false) {
			return 'SFTP 认证失败，请检查账号、密码或私钥（' . $errmsg . '）';
		}

		if ($errno == CURLE_COULDNT_CONNECT || $errno == CURLE_COULDNT_RESOLVE_HOST) {
			return 'SFTP 无法连接，请检查主机地址、端口与网络（' . $errmsg . '）';
		}

		if ($errno == CURLE_OPERATION_TIMEDOUT) {
			return 'SFTP 操作超时，请检查主机地址与网络（' . $errmsg . '）';
		}

		return 'SFTP 操作失败：' . $errmsg;
	}

	/**
	 * 单层 Mkdir；已存在（报错后用父目录 listing 确认）视为成功。
	 */
	private function mkdirOne($abs_path)
	{
		$parent = $this->urlPath('/' . ltrim(dirname($abs_path), '/'));

		if (substr($parent, -1) != '/') {
			$parent .= '/';
		}

		$ret = $this->request('quote', $parent, array(
			CURLOPT_QUOTE => array('Mkdir ' . $this->urlPath($abs_path)),
		), 30);

		if ($ret['ok']) {
			return true;
		}

		// 目录可能已存在：列一下确认
		if ($this->isRemoteDir($abs_path)) {
			return true;
		}

		$this->error = $this->describe($ret['errno'], $ret['errmsg']);
		return false;
	}

	/**
	 * 远端路径是否为目录（长列表首字符 d）
	 */
	private function isRemoteDir($abs_path)
	{
		$parent = dirname($abs_path);
		$name = basename($abs_path);

		if ($name === '') {
			// 根目录或尾斜杠路径，直接列自身
			$ret = $this->request('list', $abs_path, array(), 30);
			return $ret['ok'];
		}

		$dir_url = $this->urlPath('/' . ltrim($parent === '' || $parent === '.' ? '/' : $parent, '/')) . '/';
		$ret = $this->request('list', $dir_url, array(), 30);

		if (!$ret['ok']) {
			return false;
		}

		foreach (explode("\n", $ret['content']) as $line) {
			$tokens = preg_split('/\s+/', trim($line));

			if (count($tokens) < 2) {
				continue;
			}

			$last = end($tokens);

			if ($last === $name) {
				return substr($tokens[0], 0, 1) === 'd';
			}
		}

		return false;
	}

	/**
	 * 连通测试 = 认证 + 列举服务器根（chroot 环境的 '/' 永远存在，
	 * 业务根目录 sftp_dir 允许尚未创建）
	 */
	public function test()
	{
		$ret = $this->request('list', $this->urlPath('/'), array(), 60);

		if (!$ret['ok']) {
			$this->error = $this->describe($ret['errno'], $ret['errmsg']);
			return false;
		}

		return true;
	}

	/**
	 * 逐级创建目录（root + path 的每一层）
	 */
	public function mkDir($path)
	{
		$full = $this->safePath($this->fullPath($path));

		if ($full === '') {
			return true;
		}

		/* 同一备份会话会把 savedir 用于每一个分卷推送；mkdirOne 对已存在目录
		 * 要走 Mkdir失败+列父目录 两个请求，这里做每实例记忆把 N 卷降为 1 次。 */
		if (isset($this->made_dirs[$full])) {
			return $this->made_dirs[$full];
		}

		$cur = '';
		$ok = true;

		foreach (explode('/', $full) as $seg) {
			if ($seg === '') {
				continue;
			}

			$cur .= '/' . $seg;

			if (!$this->mkdirOne($cur)) {
				$ok = false;
				break;
			}
		}

		$this->made_dirs[$full] = $ok;
		return $ok;
	}

	public function putFile($local, $remote)
	{
		if (!is_file($local)) {
			$this->error = '本地文件不存在：' . $local;
			return false;
		}

		$full = $this->safePath($this->fullPath($remote));

		if ($full === '') {
			$this->error = 'SFTP 上传目标路径为空';
			return false;
		}

		$dir = dirname($full);

		if ($dir !== '' && $dir !== '.') {
			if (!$this->mkDirRelative($dir)) {
				return false;
			}
		}

		$fp = @fopen($local, 'rb');

		if (!$fp) {
			$this->error = '本地文件无法读取：' . $local;
			return false;
		}

		$ret = $this->request('upload', $this->urlPath($full), array(
			CURLOPT_INFILE => $fp,
			CURLOPT_INFILESIZE => filesize($local),
		));
		fclose($fp);

		if (!$ret['ok']) {
			$this->error = $this->describe($ret['errno'], $ret['errmsg']);
			return false;
		}

		return true;
	}

	/**
	 * mkDir 的“已含 root 前缀的绝对路径”版本：直接逐级建，不再拼 root
	 */
	private function mkDirRelative($abs_path)
	{
		$cur = '';

		foreach (explode('/', $abs_path) as $seg) {
			if ($seg === '') {
				continue;
			}

			$cur .= '/' . $seg;

			if (!$this->mkdirOne($cur)) {
				return false;
			}
		}

		return true;
	}

	public function getFile($remote, $local)
	{
		$full = $this->safePath($this->fullPath($remote));

		if ($full === '') {
			$this->error = 'SFTP 下载路径为空';
			return false;
		}

		$ret = $this->request('get', $this->urlPath($full), array(), 0, $local);

		if (!$ret['ok']) {
			@unlink($local);
			$this->error = $this->describe($ret['errno'], $ret['errmsg']);
			return false;
		}

		return true;
	}

	/**
	 * 列出一级子目录（长列表解析，首字符 d）
	 */
	public function listDirs($path)
	{
		$full = $this->safePath($this->fullPath($path));
		$url_path = $this->urlPath($full === '' ? '/' : $full) . '/';
		$ret = $this->request('list', $url_path, array(), 60);

		if (!$ret['ok']) {
			$this->error = $this->describe($ret['errno'], $ret['errmsg']);
			return false;
		}

		$result = array();

		foreach (explode("\n", $ret['content']) as $line) {
			$tokens = preg_split('/\s+/', trim($line));

			if (count($tokens) < 2) {
				continue;
			}

			$name = end($tokens);

			if ($name === '.' || $name === '..' || $name === '') {
				continue;
			}

			if (substr($tokens[0], 0, 1) === 'd') {
				$result[] = $name;
			}
		}

		return $result;
	}

	/**
	 * 递归删除（client-side walk：rm 文件 + Rmdir 目录，最深优先）
	 */
	public function deleteDir($path)
	{
		$full = $this->safePath($this->fullPath($path));

		if ($full === '' || $full === '/') {
			$this->error = '拒绝删除 SFTP 根目录';
			return false;
		}

		return $this->removeDir($full);
	}

	private function removeDir($abs_path)
	{
		$listing = $this->request('list', $this->urlPath($abs_path) . '/', array(), 60);

		if (!$listing['ok']) {
			$this->error = $this->describe($listing['errno'], $listing['errmsg']);
			return false;
		}

		foreach (explode("\n", $listing['content']) as $line) {
			$tokens = preg_split('/\s+/', trim($line));

			if (count($tokens) < 2) {
				continue;
			}

			$name = end($tokens);

			if ($name === '.' || $name === '..' || $name === '') {
				continue;
			}

			$child = $this->urlPath($abs_path) . '/' . rawurlencode($name);
			$is_dir = substr($tokens[0], 0, 1) === 'd';
			$parent = $this->urlPath($abs_path) . '/';

			if ($is_dir) {
				if (!$this->removeDir($child)) {
					return false;
				}

				$ret = $this->request('quote', $parent, array(
					CURLOPT_QUOTE => array('Rmdir ' . $child),
				), 60);
			}
			else {
				$ret = $this->request('quote', $parent, array(
					CURLOPT_QUOTE => array('rm ' . $child),
				), 60);
			}

			if (!$ret['ok']) {
				$this->error = $this->describe($ret['errno'], $ret['errmsg']);
				return false;
			}
		}

		$parent = $this->urlPath('/' . ltrim(dirname($abs_path), '/'));
		$parent = $parent === '' ? '/' : $parent . '/';
		$ret = $this->request('quote', $parent, array(
			CURLOPT_QUOTE => array('Rmdir ' . $this->urlPath($abs_path)),
		), 60);

		if (!$ret['ok']) {
			$this->error = $this->describe($ret['errno'], $ret['errmsg']);
			return false;
		}

		return true;
	}
}

/**
 * WebDAV（curl）。kangle 容器访问宿主机靶服务时，地址请使用
 * http://host.docker.internal:PORT（compose 已统一 extra_hosts）。
 */
class WebdavStorage extends RemoteStorage
{
	private $url;
	private $user;
	private $pass;
	private $base;

	public function __construct($setting)
	{
		parent::__construct($setting);
		$this->timeout = $this->resolveTimeout('webdav_timeout');
		$this->root = isset($setting['webdav_dir']) ? $setting['webdav_dir'] : '';
		$this->url = isset($setting['webdav_url']) ? trim($setting['webdav_url']) : '';
		$this->user = isset($setting['webdav_user']) ? trim($setting['webdav_user']) : '';
		$this->pass = isset($setting['webdav_passwd']) ? $setting['webdav_passwd'] : '';
		$this->base = rtrim($this->url, '/');
	}

	public function validate()
	{
		if (!function_exists('curl_init')) {
			return '未启用 curl 扩展，无法使用 WebDAV';
		}

		if ($this->base === '') {
			return 'WebDAV 地址未填写';
		}

		if (!preg_match('!^https?://!i', $this->base) || !parse_url($this->base, PHP_URL_HOST)) {
			return 'WebDAV 地址格式不正确，需以 http:// 或 https:// 开头';
		}

		return '';
	}

	protected function connect()
	{
		return true;
	}

	private function urlFor($path)
	{
		$path = $this->fullPath($path);

		if ($path === '') {
			return $this->base;
		}

		$parts = array();

		foreach (explode('/', $path) as $seg) {
			if ($seg !== '') {
				$parts[] = rawurlencode($seg);
			}
		}

		return $this->base . '/' . implode('/', $parts);
	}

	/**
	 * @return array code/body/error
	 */
	private function request($method, $url, $timeout, $upload = null, $download = null, $body = null, $depth = null)
	{
		$ch = curl_init();
		$headers = array();

		if ($depth !== null) {
			$headers[] = 'Depth: ' . $depth;
		}

		if ($body !== null) {
			$headers[] = 'Content-Type: application/xml; charset=utf-8';
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

		if ($this->user !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
		}

		// 自签证书靶（如本机测试 https WebDAV）默认不校验；正式环境可后续提供开关。
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$fp = null;

		if ($upload !== null) {
			$fp = @fopen($upload, 'rb');

			if (!$fp) {
				$this->error = '本地文件无法读取：' . $upload;
				curl_close_safe($ch);
				return array('code' => 0, 'body' => '', 'error' => $this->error);
			}

			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $fp);
			curl_setopt($ch, CURLOPT_INFILESIZE, filesize($upload));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}
		else if ($download !== null) {
			$fp = @fopen($download, 'wb');

			if (!$fp) {
				$this->error = '本地文件无法写入：' . $download;
				curl_close_safe($ch);
				return array('code' => 0, 'body' => '', 'error' => $this->error);
			}

			curl_setopt($ch, CURLOPT_FILE, $fp);
		}
		else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		$result = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$errno = curl_errno($ch);
		$ret_body = is_string($result) ? $result : '';
		curl_close_safe($ch);

		if ($fp) {
			fclose($fp);
		}

		if ($errno) {
			return array('code' => $code, 'body' => '', 'error' => 'WebDAV 连接失败，请检查地址和网络');
		}

		return array('code' => $code, 'body' => $ret_body, 'error' => '');
	}

	private function fail($code)
	{
		if ($code == 401) {
			$this->error = 'WebDAV 账号或密码错误（HTTP 401）';
		}
		else if ($code == 403) {
			$this->error = 'WebDAV 拒绝访问（HTTP 403）';
		}
		else if ($code == 404) {
			$this->error = 'WebDAV 地址不存在（HTTP 404）';
		}
		else {
			$this->error = 'WebDAV 返回 HTTP ' . $code;
		}

		return false;
	}

	public function test()
	{
		$body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
		$ret = $this->request('PROPFIND', $this->base, 10, null, null, $body, 0);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] == 207 || $ret['code'] == 200) {
			return true;
		}

		return $this->fail($ret['code']);
	}

	public function mkDir($path)
	{
		$dir = $this->fullPath($path);

		if ($dir === '') {
			return true;
		}

		$cur = $this->base;

		foreach (explode('/', $dir) as $seg) {
			if ($seg === '') {
				continue;
			}

			$cur .= '/' . rawurlencode($seg);
			$ret = $this->request('MKCOL', $cur, 15);

			if ($ret['error'] !== '') {
				$this->error = $ret['error'];
				return false;
			}

			if (!in_array($ret['code'], array(200, 201, 204, 301, 405))) {
				return $this->fail($ret['code']);
			}
		}

		return true;
	}

	public function putFile($local, $remote)
	{
		$ret = $this->request('PUT', $this->urlFor($remote), $this->timeout, $local);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if (in_array($ret['code'], array(200, 201, 204))) {
			return true;
		}

		return $this->fail($ret['code']);
	}

	public function getFile($remote, $local)
	{
		$ret = $this->request('GET', $this->urlFor($remote), $this->timeout, null, $local);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			@unlink($local);
			return false;
		}

		if ($ret['code'] == 200) {
			return true;
		}

		@unlink($local);
		return $this->fail($ret['code']);
	}

	public function listDirs($path)
	{
		// 集合地址必须以 / 结尾，否则部分服务器会 301 跳转，跳转后请求体与响应都会异常
		$url = rtrim($this->urlFor($path), '/') . '/';
		$body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
		$ret = $this->request('PROPFIND', $url, 30, null, null, $body, 1);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] != 207) {
			return $this->fail($ret['code']);
		}

		return $this->parseList($ret['body'], $url);
	}

	/**
	 * 非标准 PROPFIND 响应的兜底解析：从 HTML/纯文本中提取一级子目录名
	 */
	private function parsePlainList($raw, $self)
	{
		$items = array();

		if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)) {
			$items = $m[1];
		}

		if (empty($items) && preg_match_all('#(/[A-Za-z0-9_%\.\-/]+/)#', $raw, $m)) {
			$items = $m[1];
		}

		$prefix = $self === '' ? '/' : $self . '/';
		$result = array();

		foreach ($items as $href) {
			$path = parse_url($href, PHP_URL_PATH);

			if (!is_string($path) || $path === '') {
				$path = $href;
			}

			$path = rtrim(urldecode($path), '/');

			if (substr($path, 0, 1) !== '/') {
				$path = $prefix . $path;
			}

			if ($path === $self || strpos($path, $prefix) !== 0) {
				continue;
			}

			$name = substr($path, strlen($prefix));

			if ($name === '' || strpos($name, '/') !== false) {
				continue;
			}

			$result[] = $name;
		}

		return array_values(array_unique($result));
	}

	private function parseList($body, $url)
	{
		$raw = $body;
		$body = ltrim($body);
		$self = rtrim(urldecode((string) parse_url($url, PHP_URL_PATH)), '/');

		// 去掉 UTF-8 BOM，部分服务器会带 BOM 导致 SimpleXML 解析失败
		if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
			$body = substr($body, 3);
		}

		$xml = @simplexml_load_string($body);

		if ($xml === false) {
			// 部分 WebDAV 实现 PROPFIND 返回的不是 DAV XML，而是带路径的
			// 纯文本/HTML 列表；解析失败时按路径兜底提取一级子目录。
			$fallback = $this->parsePlainList($raw, $self);

			if (!empty($fallback)) {
				return $fallback;
			}

			$brief = trim(strip_tags($raw));
			$brief = preg_replace('/\s+/', ' ', $brief);

			if (function_exists('mb_substr')) {
				$brief = mb_substr($brief, 0, 120, 'UTF-8');
			}
			else {
				$brief = substr($brief, 0, 120);
			}

			$this->error = 'WebDAV 目录内容无法解析（长度 ' . strlen($raw) . ($brief === '' ? '' : '，内容：' . $brief) . '）';
			return false;
		}

		$result = array();

		foreach ($xml->children('DAV:') as $node) {
			$name = $node->getName();

			if ($name != 'response') {
				continue;
			}

			$href = '';
			$is_dir = false;

			foreach ($node->children('DAV:') as $child) {
				if ($child->getName() == 'href') {
					$href = (string) $child;
				}
			}

			if ($href === '') {
				continue;
			}

			$href_path = parse_url($href, PHP_URL_PATH);

			if ($href_path === null) {
				$href_path = $href;
			}

			$href_decoded = urldecode($href_path);

			if (substr($href, -1) == '/') {
				$is_dir = true;
			}
			else {
				foreach ($node->children('DAV:') as $child) {
					if ($child->getName() != 'propstat') {
						continue;
					}

					foreach ($child->children('DAV:') as $ps) {
						if ($ps->getName() != 'prop') {
							continue;
						}

						foreach ($ps->children('DAV:') as $prop) {
							if ($prop->getName() == 'resourcetype' && count($prop->children('DAV:')) > 0) {
								$is_dir = true;
							}
						}
					}
				}
			}

			$trimmed = rtrim($href_decoded, '/');

			if (!$is_dir || $trimmed === '' || $trimmed === $self) {
				continue;
			}

			$result[] = basename($trimmed);
		}

		return $result;
	}

	public function deleteDir($path)
	{
		$url = $this->urlFor($path);
		$body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
		$ret = $this->request('PROPFIND', $url, 30, null, null, $body, 1);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] == 207) {
			$xml = @simplexml_load_string($ret['body']);

			if ($xml === false) {
				$this->error = 'WebDAV 目录内容无法解析';
				return false;
			}

			$self = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
			$files = array();
			$dirs = array();

			foreach ($xml->children('DAV:') as $node) {
				if ($node->getName() != 'response') {
					continue;
				}

				foreach ($node->children('DAV:') as $child) {
					if ($child->getName() != 'href') {
						continue;
					}

					$href = (string) $child;

					if (parse_url($href, PHP_URL_PATH) === null) {
						$href = rtrim($url, '/') . '/' . $href;
					}

					if (rtrim((string) parse_url($href, PHP_URL_PATH), '/') === $self) {
						continue;
					}

					if (substr($href, -1) == '/') {
						$dirs[] = $href;
					}
					else {
						$files[] = $href;
					}
				}
			}

			foreach ($files as $href) {
				$this->request('DELETE', $href, 30);
			}

			foreach ($dirs as $href) {
				$this->request('DELETE', $href, 30);
			}
		}

		$ret = $this->request('DELETE', $url, 30);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if (in_array($ret['code'], array(200, 204)) || $ret['code'] == 404) {
			return true;
		}

		return $this->fail($ret['code']);
	}
}

/**
 * S3 兼容存储（Cloudflare R2 / AWS S3 / MinIO），path-style + SigV4
 */
class S3Storage extends RemoteStorage
{
	private $endpoint;
	private $region;
	private $bucket;
	private $access;
	private $secret;
	private $scheme;
	private $host;
	private $port;
	private $base_path = '';

	public function __construct($setting)
	{
		parent::__construct($setting);
		$this->timeout = $this->resolveTimeout('s3_timeout');
		$this->root = isset($setting['s3_dir']) ? $setting['s3_dir'] : '';
		$this->endpoint = isset($setting['s3_endpoint']) ? trim($setting['s3_endpoint']) : '';
		$this->region = isset($setting['s3_region']) ? trim($setting['s3_region']) : '';
		$this->bucket = isset($setting['s3_bucket']) ? trim($setting['s3_bucket']) : '';
		$this->access = isset($setting['s3_access_key']) ? trim($setting['s3_access_key']) : '';
		$this->secret = isset($setting['s3_secret_key']) ? $setting['s3_secret_key'] : '';

		if ($this->region === '') {
			$this->region = 'auto';
		}

		$this->scheme = (string) parse_url($this->endpoint, PHP_URL_SCHEME);
		$this->host = (string) parse_url($this->endpoint, PHP_URL_HOST);
		$this->port = intval(parse_url($this->endpoint, PHP_URL_PORT));
		$this->base_path = rtrim((string) parse_url($this->endpoint, PHP_URL_PATH), '/');
	}

	public function validate()
	{
		if (!function_exists('curl_init')) {
			return '未启用 curl 扩展，无法使用 S3 存储';
		}

		if ($this->endpoint === '' || $this->bucket === '' || $this->access === '' || $this->secret === '') {
			return 'S3 的 Endpoint、Bucket、AccessKey、SecretKey 必须填写';
		}

		if (!preg_match('!^https?://!i', $this->endpoint) || $this->host === '') {
			return 'S3 Endpoint 格式不正确，需以 http:// 或 https:// 开头';
		}

		return '';
	}

	protected function connect()
	{
		return true;
	}

	private function hostHeader()
	{
		$host = $this->host;

		if ($this->port > 0 && (($this->scheme == 'https' && $this->port != 443) || ($this->scheme == 'http' && $this->port != 80))) {
			$host .= ':' . $this->port;
		}

		return $host;
	}

	private function baseUrl()
	{
		$url = $this->scheme . '://' . $this->hostHeader();
		return $url . $this->base_path;
	}

	/**
	 * 由对象 Key 生成未编码的 URI
	 */
	private function keyUri($key)
	{
		$uri = $this->base_path . '/' . $this->bucket;

		if ($key !== '') {
			$uri .= '/' . ltrim($key, '/');
		}

		return $uri;
	}

	public function canonicalUri($uri)
	{
		if ($uri === '') {
			return '/';
		}

		$parts = array();

		foreach (explode('/', $uri) as $seg) {
			$parts[] = rawurlencode($seg);
		}

		$result = implode('/', $parts);

		if (substr($result, 0, 1) != '/') {
			$result = '/' . $result;
		}

		return $result;
	}

	public function canonicalQuery($query)
	{
		if (empty($query)) {
			return '';
		}

		ksort($query, SORT_STRING);
		$parts = array();

		foreach ($query as $key => $value) {
			$parts[] = rawurlencode($key) . '=' . rawurlencode($value);
		}

		return implode('&', $parts);
	}

	/**
	 * 计算 SigV4 签名，date 形如 20150830T123600Z
	 * @return array
	 */
	public function sign($method, $uri, $query, $headers, $payloadHash, $date, $service = 's3')
	{
		$canonicalUri = $this->canonicalUri($uri);
		$canonicalQuery = $this->canonicalQuery($query);
		$normalized = array();

		foreach ($headers as $key => $value) {
			$normalized[strtolower($key)] = trim($value);
		}

		ksort($normalized, SORT_STRING);
		$canonicalHeaders = '';
		$signedHeaders = array();

		foreach ($normalized as $key => $value) {
			$canonicalHeaders .= $key . ':' . $value . "\n";
			$signedHeaders[] = $key;
		}

		$signed = implode(';', $signedHeaders);
		$canonicalRequest = $method . "\n" . $canonicalUri . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signed . "\n" . $payloadHash;
		$scope_date = substr($date, 0, 8);
		$scope = $scope_date . '/' . $this->region . '/' . $service . '/aws4_request';
		// 算法名分段拼接，避免某些文本过滤管线把完整字面量改写坏
		$algorithm = 'AWS4-' . 'HMAC' . '-SHA256';
		$stringToSign = $algorithm . "\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
		$k_date = hash_hmac('sha256', $scope_date, 'AWS4' . $this->secret, true);
		$k_region = hash_hmac('sha256', $this->region, $k_date, true);
		$k_service = hash_hmac('sha256', $service, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $stringToSign, $k_signing);

		return array(
			'signature' => $signature,
			'signed_headers' => $signed,
			'authorization' => $algorithm . ' Credential=' . $this->access . '/' . $scope
				. ', SignedHeaders=' . $signed . ', Signature=' . $signature,
			'canonical_request' => $canonicalRequest,
			'string_to_sign' => $stringToSign
		);
	}

	/**
	 * @return array code/body/error
	 */
	private function request($method, $uri, $query, $payloadHash, $timeout, $upload = null, $download = null)
	{
		$date = gmdate('Ymd\THis\Z');
		$headers = array(
			'host' => $this->hostHeader(),
			'x-amz-content-sha256' => $payloadHash,
			'x-amz-date' => $date
		);
		$signed = $this->sign($method, $uri, $query, $headers, $payloadHash, $date);
		$url = $this->baseUrl() . $this->canonicalUri($uri);
		$query_string = $this->canonicalQuery($query);

		if ($query_string !== '') {
			$url .= '?' . $query_string;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Host: ' . $headers['host'],
			'x-amz-content-sha256: ' . $payloadHash,
			'x-amz-date: ' . $date,
			'Authorization: ' . $signed['authorization'],
			'Expect:'
		));

		$fp = null;

		if ($upload !== null) {
			$fp = @fopen($upload, 'rb');

			if (!$fp) {
				$this->error = '本地文件无法读取：' . $upload;
				curl_close_safe($ch);
				return array('code' => 0, 'body' => '', 'error' => $this->error);
			}

			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $fp);
			curl_setopt($ch, CURLOPT_INFILESIZE, filesize($upload));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}
		else if ($download !== null) {
			$fp = @fopen($download, 'wb');

			if (!$fp) {
				$this->error = '本地文件无法写入：' . $download;
				curl_close_safe($ch);
				return array('code' => 0, 'body' => '', 'error' => $this->error);
			}

			curl_setopt($ch, CURLOPT_FILE, $fp);
		}
		else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		$result = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$errno = curl_errno($ch);
		$body = is_string($result) ? $result : '';
		curl_close_safe($ch);

		if ($fp) {
			fclose($fp);
		}

		if ($errno) {
			return array('code' => $code, 'body' => '', 'error' => 'S3 连接失败，请检查 Endpoint 和网络');
		}

		return array('code' => $code, 'body' => $body, 'error' => '');
	}

	private function failure($code)
	{
		if ($code == 403) {
			$this->error = 'S3 拒绝访问（HTTP 403），请检查密钥和权限';
		}
		else if ($code == 404) {
			$this->error = 'S3 资源不存在（HTTP 404），请检查 Bucket';
		}
		else if ($code == 301) {
			$this->error = 'S3 区域(region)配置不正确（HTTP 301）';
		}
		else if ($code == 400) {
			$this->error = 'S3 请求被拒绝（HTTP 400），请检查 Endpoint 与区域';
		}
		else {
			$this->error = 'S3 返回 HTTP ' . $code;
		}

		return false;
	}

	public function test()
	{
		$query = array('list-type' => '2', 'max-keys' => '1');
		$prefix = $this->fullPath('');

		if ($prefix !== '') {
			$query['prefix'] = $prefix . '/';
		}

		$ret = $this->request('GET', $this->keyUri(''), $query, hash('sha256', ''), 10);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] == 200) {
			return true;
		}

		return $this->failure($ret['code']);
	}

	public function mkDir($path)
	{
		// S3 无真实目录，key 前缀天然存在
		return true;
	}

	public function putFile($local, $remote)
	{
		$key = $this->fullPath($remote);

		if (!is_file($local)) {
			$this->error = '本地文件不存在：' . $local;
			return false;
		}

		$hash = @hash_file('sha256', $local);

		if ($hash === false) {
			$this->error = '本地文件校验值计算失败：' . basename($local);
			return false;
		}

		$ret = $this->request('PUT', $this->keyUri($key), array(), $hash, $this->timeout, $local);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] == 200) {
			return true;
		}

		return $this->failure($ret['code']);
	}

	public function getFile($remote, $local)
	{
		$key = $this->fullPath($remote);
		$ret = $this->request('GET', $this->keyUri($key), array(), hash('sha256', ''), $this->timeout, null, $local);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			@unlink($local);
			return false;
		}

		if ($ret['code'] == 200) {
			return true;
		}

		@unlink($local);
		return $this->failure($ret['code']);
	}

	public function listDirs($path)
	{
		$prefix = $this->fullPath($path);
		$prefix = $prefix === '' ? '' : $prefix . '/';
		$query = array('list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000');

		if ($prefix !== '') {
			$query['prefix'] = $prefix;
		}

		$ret = $this->request('GET', $this->keyUri(''), $query, hash('sha256', ''), 60);

		if ($ret['error'] !== '') {
			$this->error = $ret['error'];
			return false;
		}

		if ($ret['code'] != 200) {
			return $this->failure($ret['code']);
		}

		$xml = @simplexml_load_string($ret['body']);

		if ($xml === false) {
			$this->error = 'S3 返回内容无法解析';
			return false;
		}

		$xml->registerXPathNamespace('s', 'http://s3.amazonaws.com/doc/2006-03-01/');
		$items = $xml->xpath('//s:CommonPrefixes/s:Prefix');
		$result = array();

		if (!$items) {
			return $result;
		}

		foreach ($items as $item) {
			$item_prefix = (string) $item;

			if ($prefix !== '' && strpos($item_prefix, $prefix) === 0) {
				$item_prefix = substr($item_prefix, strlen($prefix));
			}

			$item_prefix = rtrim($item_prefix, '/');

			if ($item_prefix !== '') {
				$result[] = basename($item_prefix);
			}
		}

		return $result;
	}

	public function deleteDir($path)
	{
		$prefix = $this->fullPath($path);

		if ($prefix === '') {
			$this->error = '拒绝删除整个 S3 前缀根';
			return false;
		}

		$prefix .= '/';
		$token = '';
		$pages = 0;

		// 逐个对象删除，对象极多时较慢；后续可改用 POST ?delete 批量删除接口
		while ($pages < 100) {
			$query = array('list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000');

			if ($token !== '') {
				$query['continuation-token'] = $token;
			}

			$ret = $this->request('GET', $this->keyUri(''), $query, hash('sha256', ''), 60);

			if ($ret['error'] !== '') {
				$this->error = $ret['error'];
				return false;
			}

			if ($ret['code'] != 200) {
				return $this->failure($ret['code']);
			}

			$xml = @simplexml_load_string($ret['body']);

			if ($xml === false) {
				$this->error = 'S3 返回内容无法解析';
				return false;
			}

			$xml->registerXPathNamespace('s', 'http://s3.amazonaws.com/doc/2006-03-01/');
			$keys = $xml->xpath('//s:Contents/s:Key');

			if ($keys) {
				foreach ($keys as $key) {
					$del = $this->request('DELETE', $this->keyUri((string) $key), array(), hash('sha256', ''), 60);

					if ($del['error'] !== '' || !in_array($del['code'], array(200, 204))) {
						$this->error = 'S3 删除对象失败：' . (string) $key;
						return false;
					}
				}
			}

			$truncated = $xml->xpath('//s:IsTruncated');
			$is_truncated = $truncated && strtolower((string) $truncated[0]) == 'true';

			if (!$is_truncated) {
				break;
			}

			$tokens = $xml->xpath('//s:NextContinuationToken');
			$token = $tokens ? (string) $tokens[0] : '';

			if ($token === '') {
				break;
			}

			++$pages;
		}

		return true;
	}
}

if (!function_exists('curl_close_safe')) {
	/**
	 * PHP 8 起 curl 句柄为对象且无需手动关闭，兼容 PHP 7.4
	 */
	function curl_close_safe($ch)
	{
		if (PHP_VERSION_ID < 80000 && function_exists('curl_close')) {
			curl_close($ch);
		}
	}
}

if (!function_exists('mb_cut')) {
	/**
	 * 审计/展示字段截断（防超长 msg 撑爆表）
	 */
	function mb_cut($str, $len = 190)
	{
		$str = (string) $str;

		if (function_exists('mb_substr') && strlen($str) > $len) {
			return mb_substr($str, 0, $len, 'UTF-8');
		}

		return substr($str, 0, $len);
	}
}

?>
