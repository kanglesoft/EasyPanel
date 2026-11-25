<?php
/**
 * backupremote.ctl.php —— 远程备份存储配置与连通测试（backup-remote-and-ignore 单元）
 *
 * 职责边界：
 *   - 本页只负责「远程类型 + SFTP/WebDAV/S3 凭据」的读写与 testRemote；
 *     FTP 四个字段（ftp_host/ftp_port/ftp_user/ftp_passwd/ftp_dir）沿用
 *     「备份恢复」页（backup.ctl.php，本单元不改），此处只读展示并提供测试。
 *   - 配置落 setting 表（与 backup.api.php 读取的键一致，见其 remotePush/getRemoteType），
 *     同时 best-effort 写 remote_storage 表（DDL 见 .temp/integration 文档，由集成步进 upgrade.sql）。
 *   - 保存后立即 apicall('remotestorage','install_cron') 生成 /etc/cron.d/ep_backup_remote；
 *     容器重建后由 framework/cronresync.php（admincron 单元）重新调用同一入口，本单元不碰 shell.php。
 *
 * testRemote 输出约定（验收断言口径）：
 *   HTTP 200 + {"result":true|false,"code":"...","msg":"..."}；
 *   假凭据/黑洞主机一律 result:false 且 code 非空，绝不 500。
 */
needRole('admin');
class BackupremoteControl extends Control
{
	/**
	 * 配置表单页
	 */
	public function index()
	{
		return $this->addForm();
	}

	/**
	 * 渲染表单（save 成功后也回到这里，带 $msg 提示）
	 */
	public function addForm($msg = '')
	{
		$setting = daocall('setting', 'getAll', array());
		$type = isset($setting['backup_remote_type']) ? strtolower(trim($setting['backup_remote_type'])) : 'ftp';

		if (!in_array($type, array('ftp', 'sftp', 'webdav', 's3'))) {
			$type = 'ftp';
		}

		$cron_installed = apicall('remotestorage', 'cron_exists', array());
		$this->_tpl->assign('setting', $setting);
		$this->_tpl->assign('remote_type', $type);
		$this->_tpl->assign('backup_save_place', isset($setting['backup_save_place']) ? $setting['backup_save_place'] : 'l');
		$this->_tpl->assign('cron_installed', $cron_installed ? 1 : 0);
		$this->_tpl->assign('msg', $msg);
		$this->_tpl->assign('breadcrumb', '远程备份');
		return $this->fetch('backup/remote.html');
	}

	/**
	 * 保存远程备份配置
	 */
	public function save()
	{
		$type = strtolower(trim($this->req('backup_remote_type')));

		if (!in_array($type, array('ftp', 'sftp', 'webdav', 's3'))) {
			$type = 'ftp';
		}

		// ---- FTP：仅端口做兜底修正，其余字段以备份恢复页为准 ----
		$ftp_port = intval($this->req('ftp_port'));

		if ($ftp_port <= 0 || $ftp_port > 65535) {
			$ftp_port = 21;
		}

		// ---- SFTP ----
		$sftp_host = trim($this->req('sftp_host'));
		$sftp_port = intval($this->req('sftp_port'));

		if ($sftp_port <= 0 || $sftp_port > 65535) {
			$sftp_port = 22;
		}

		$sftp_user = trim($this->req('sftp_user'));
		$sftp_passwd = trim($this->req('sftp_passwd'));
		$sftp_key = trim($this->req('sftp_key'));
		$sftp_dir = $this->cleanDir('sftp_dir', $err);

		if ($err !== '') {
			return $this->addForm($err);
		}

		$sftp_timeout = $this->cleanTimeout('sftp_timeout');

		// ---- WebDAV ----
		$webdav_url = rtrim(trim($this->req('webdav_url')), '/');
		$webdav_user = trim($this->req('webdav_user'));
		$webdav_passwd = trim($this->req('webdav_passwd'));
		$webdav_dir = $this->cleanDir('webdav_dir', $err);

		if ($err !== '') {
			return $this->addForm($err);
		}

		$webdav_timeout = $this->cleanTimeout('webdav_timeout');

		// ---- S3 ----
		$s3_endpoint = rtrim(trim($this->req('s3_endpoint')), '/');
		$s3_region = trim($this->req('s3_region'));

		if ($s3_region === '') {
			$s3_region = 'auto';
		}

		$s3_bucket = trim($this->req('s3_bucket'));
		$s3_access_key = trim($this->req('s3_access_key'));
		$s3_secret_key = trim($this->req('s3_secret_key'));
		$s3_dir = $this->cleanDir('s3_dir', $err);

		if ($err !== '') {
			return $this->addForm($err);
		}

		$s3_timeout = $this->cleanTimeout('s3_timeout');

		// ---- 按类型做必填校验（仅当备份会用到远程时才强制，本地保存允许留空） ----
		$save_place = isset($_REQUEST['backup_save_place']) ? trim($_REQUEST['backup_save_place']) : '';

		if ($save_place === 'l' || $save_place === 'r' || $save_place === 'lr') {
			$need_remote = ($save_place !== 'l');
		}
		else {
			// 未提交该项时沿用库里现值（保存位置本体归备份恢复页管）
			$cur = daocall('setting', 'get', array('backup_save_place'));
			$need_remote = ($cur !== 'l');
		}

		if ($need_remote) {
			$err = $this->validateType($type, $ftp_port, $sftp_host, $sftp_user, $sftp_passwd, $sftp_key, $webdav_url, $s3_endpoint, $s3_bucket, $s3_access_key, $s3_secret_key);

			if ($err !== '') {
				audit('backupremote', 'save', $type, 'deny', array('reason' => $err));
				return $this->addForm($err);
			}
		}

		daocall('setting', 'add', array('backup_remote_type', $type));
		daocall('setting', 'add', array('ftp_port', $ftp_port));
		daocall('setting', 'add', array('sftp_host', $sftp_host));
		daocall('setting', 'add', array('sftp_port', $sftp_port));
		daocall('setting', 'add', array('sftp_user', $sftp_user));
		daocall('setting', 'add', array('sftp_passwd', $sftp_passwd));
		daocall('setting', 'add', array('sftp_key', $sftp_key));
		daocall('setting', 'add', array('sftp_dir', $sftp_dir));
		daocall('setting', 'add', array('sftp_timeout', $sftp_timeout));
		daocall('setting', 'add', array('webdav_url', $webdav_url));
		daocall('setting', 'add', array('webdav_user', $webdav_user));
		daocall('setting', 'add', array('webdav_passwd', $webdav_passwd));
		daocall('setting', 'add', array('webdav_dir', $webdav_dir));
		daocall('setting', 'add', array('webdav_timeout', $webdav_timeout));
		daocall('setting', 'add', array('s3_endpoint', $s3_endpoint));
		daocall('setting', 'add', array('s3_region', $s3_region));
		daocall('setting', 'add', array('s3_bucket', $s3_bucket));
		daocall('setting', 'add', array('s3_access_key', $s3_access_key));
		daocall('setting', 'add', array('s3_secret_key', $s3_secret_key));
		daocall('setting', 'add', array('s3_dir', $s3_dir));
		daocall('setting', 'add', array('s3_timeout', $s3_timeout));

		// remote_storage 留痕（表未建时静默）
		apicall('remotestorage', 'saveRecord', array($type, array(
			'sftp_host' => $sftp_host,
			'sftp_port' => $sftp_port,
			'sftp_user' => $sftp_user,
			'sftp_dir' => $sftp_dir,
			'webdav_url' => $webdav_url,
			'webdav_user' => $webdav_user,
			'webdav_dir' => $webdav_dir,
			's3_endpoint' => $s3_endpoint,
			's3_region' => $s3_region,
			's3_bucket' => $s3_bucket,
			's3_dir' => $s3_dir,
		)));

		// 计划任务：立即生效；容器重建后由 cronresync.php 统一重建
		$cron_ok = apicall('remotestorage', 'install_cron', array());
		audit('backupremote', 'save', $type, 'success', array(
			'cron' => $cron_ok === false ? 'FAILED' : 'ok',
		));

		$msg = '远程备份配置已保存。';

		if ($cron_ok === false) {
			$msg .= ' 计划任务 /etc/cron.d/ep_backup_remote 写入失败（权限或只读目录），请检查容器内 /etc/cron.d 是否可写。';
		}
		else {
			$msg .= ' 已注册每小时远程备份检查任务（/etc/cron.d/ep_backup_remote）。';
		}

		return $this->addForm($msg);
	}

	/**
	 * 连通测试（AJAX，JSON 输出）
	 */
	public function testRemote()
	{
		$params = array(
			'type' => $this->req('type'),
			'host' => $this->req('host'),
			'port' => $this->req('port'),
			'user' => $this->req('user'),
			'passwd' => $this->req('passwd'),
			'dir' => $this->req('dir'),
			'key' => $this->req('key'),
			'url' => $this->req('url'),
			'endpoint' => $this->req('endpoint'),
			'region' => $this->req('region'),
			'bucket' => $this->req('bucket'),
			'access_key' => $this->req('access_key'),
			'secret_key' => $this->req('secret_key'),
			'timeout' => $this->req('timeout'),
		);

		// 表单未带凭据时回退用库里已保存的值，便于「保存后复测」
		$fallback = array(
			'ftp' => array('host' => 'ftp_host', 'port' => 'ftp_port', 'user' => 'ftp_user', 'passwd' => 'ftp_passwd', 'dir' => 'ftp_dir'),
			'sftp' => array('host' => 'sftp_host', 'port' => 'sftp_port', 'user' => 'sftp_user', 'passwd' => 'sftp_passwd', 'key' => 'sftp_key', 'dir' => 'sftp_dir', 'timeout' => 'sftp_timeout'),
			'webdav' => array('url' => 'webdav_url', 'user' => 'webdav_user', 'passwd' => 'webdav_passwd', 'dir' => 'webdav_dir', 'timeout' => 'webdav_timeout'),
			's3' => array('endpoint' => 's3_endpoint', 'region' => 's3_region', 'bucket' => 's3_bucket', 'access_key' => 's3_access_key', 'secret_key' => 's3_secret_key', 'dir' => 's3_dir'),
		);
		$type = strtolower(trim($params['type']));

		if (isset($fallback[$type])) {
			foreach ($fallback[$type] as $param => $key) {
				if ($params[$param] === '') {
					$saved = daocall('setting', 'get', array($key));
					$params[$param] = $saved === null ? '' : $saved;
				}
			}
		}

		$ret = apicall('remotestorage', 'testRemote', array($params));

		if (!is_array($ret)) {
			$ret = array('result' => false, 'code' => 'E_INTERNAL', 'msg' => '远程测试未能执行');
		}

		$ret['type'] = $type;
		apicall('remotestorage', 'markTest', array($type, $ret['result'], isset($ret['msg']) ? $ret['msg'] : ''));
		audit('backupremote', 'testRemote', $type, $ret['result'] ? 'success' : 'error', array(
			'code' => $ret['code'],
			'msg' => $ret['msg'],
		));
		header('Content-Type: application/json; charset=utf-8');
		exit(json_encode($ret));
	}

	/**
	 * 手动触发一次备份（沿用备份恢复页的命令行提示形态，不阻塞请求）
	 */
	public function backupNow()
	{
		$cmd = apicall('process', 'get_easypanel_shell');
		$cmd .= ' backup all';
		$cmd .= ' &';
		exit(json_encode(array(
			'result' => true,
			'code' => 'OK',
			'msg' => '请在容器内命令行执行以下命令开始一次全量备份：<pre>' . htmlspecialchars($cmd) . '</pre>',
		)));
	}

	/**
	 * 按类型必填校验，返回空串表示通过
	 */
	private function validateType($type, $ftp_port, $sftp_host, $sftp_user, $sftp_passwd, $sftp_key, $webdav_url, $s3_endpoint, $s3_bucket, $s3_access_key, $s3_secret_key)
	{
		if ($type == 'ftp') {
			$host = trim($this->req('ftp_host'));
			$user = trim($this->req('ftp_user'));

			if ($host === '') {
				$host = daocall('setting', 'get', array('ftp_host'));
			}

			if ($user === '') {
				$user = daocall('setting', 'get', array('ftp_user'));
			}

			if ($host === '' || $user === '' || $host === null || $user === null) {
				return 'FTP 主机地址和账号不能为空（请在「备份恢复」页填写 FTP 配置）';
			}

			return '';
		}

		if ($type == 'sftp') {
			if ($sftp_host === '' || $sftp_user === '') {
				return 'SFTP 主机地址和账号不能为空';
			}

			if ($sftp_key === '' && $sftp_passwd === '') {
				return 'SFTP 需要填写密码或私钥路径';
			}

			if ($sftp_key !== '' && !is_file($sftp_key)) {
				return 'SFTP 私钥文件不存在，请填写面板容器内的绝对路径';
			}

			return '';
		}

		if ($type == 'webdav') {
			if ($webdav_url === '') {
				return 'WebDAV 地址不能为空';
			}

			if (!preg_match('!^https?://!i', $webdav_url)) {
				return 'WebDAV 地址需以 http:// 或 https:// 开头';
			}

			return '';
		}

		if ($type == 's3') {
			if ($s3_endpoint === '' || $s3_bucket === '' || $s3_access_key === '' || $s3_secret_key === '') {
				return 'S3 的 Endpoint、Bucket、AccessKey、SecretKey 必须填写';
			}

			if (!preg_match('!^https?://!i', $s3_endpoint)) {
				return 'S3 Endpoint 需以 http:// 或 https:// 开头';
			}

			return '';
		}

		return '未知的远程存储类型';
	}

	/**
	 * 目录配置归一：去首尾斜杠，禁止空格
	 * @param string $key   请求参数名
	 * @param string $err   出参：错误信息（空串表示通过）
	 * @return string
	 */
	private function cleanDir($key, &$err)
	{
		$err = '';
		$dir = trim($this->req($key));
		$dir = trim($dir, '/');
		$dir = trim($dir, '\\');

		if (stripos($dir, ' ') !== false) {
			$err = '目录中不能包含空格（' . $key . '）';
			return '';
		}

		return $dir;
	}

	/**
	 * 超时归一：60~86400 秒，非法回退 3600
	 * @return int
	 */
	private function cleanTimeout($key)
	{
		$timeout = intval($this->req($key));

		if ($timeout < 60 || $timeout > 86400) {
			$timeout = 3600;
		}

		return $timeout;
	}

	/**
	 * 统一取参：数组型输入（?x[]=y）按空串处理，绝不拼进命令/URL
	 * @return string
	 */
	private function req($key)
	{
		if (!isset($_REQUEST[$key])) {
			return '';
		}

		$value = $_REQUEST[$key];

		if (is_array($value)) {
			return '';
		}

		return strval($value);
	}
}

?>
