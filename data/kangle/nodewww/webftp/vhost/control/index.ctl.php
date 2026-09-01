<?php
needRole('vhost');
class IndexControl extends Control
{
	private $access;

	public function __construct()
	{
		parent::__construct();
		load_lib('pub:access');
		$this->access = new Access(getRole('vhost'));
	}

	public function __destruct()
	{
		parent::__destruct();
	}

	public function tt()
	{
	}

	/**
	 * sync前,ajax取得多节点CDN节点数。
	 */
	public function getNode()
	{
		$nodes = daocall('manynode', 'get', array());

		if ($nodes) {
			exit('200');
			return NULL;
		}

		exit('404');
	}

	public function module()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$module = $user['module'];

		if (!$module) {
			$this->_tpl->assign('msg', 'module错误');
			return $this->main();
		}

		$msg = modcall($module, $module . '_call', array($user));

		if ($msg) {
			$this->_tpl->assign('msg', $msg);
		}

		return $this->main();
	}

	public function phpset()
	{
		$vhost = getRole('vhost');
		$versions = modcall('php', 'php_get_version');
		if ($_REQUEST['op'] == 'change') {
			$v = trim($_REQUEST['v']);
			if(empty($v) || !array_key_exists($v, $versions))exit('参数错误');

			$arr['value'] = '1,cmd:' . $v . ',*';

			if (!apicall('vhost', 'updateInfo', array($vhost, '1,php', $arr, 3))) {
				exit('修改失败');
			}

			if (!apicall('vhostinfo', 'set2', array($vhost, 'moduleversion', 101, $v))) {
				exit('修改失败');
			}
			exit('修改成功');
		}

		$vhostinfo = apicall('vhostinfo', 'get2', array(getRole('vhost'), 'moduleversion', 101));
		$value = $vhostinfo['value'];
		$value = 'PHP-'.substr($value,-2,1).'.'.substr($value,-1,1);

		$this->_tpl->assign('versions', $versions);
		$this->_tpl->assign('version', $value);
		return $this->_tpl->fetch('phpset.html');
	}

	public function index()
	{
		$this->main();
	}

	public function rebootProcess()
	{
		$vh = getRole('vhost');
		$result = apicall('vhost', 'rebootProcess', array($vh));

		if ($result) {
			exit('重启成功');
		}

		exit('重启失败');
	}

	public function sync()
	{
		//apicall('cdn', 'sync_vhost_all', array());
		exit();
	}

	public function top()
	{
		$vhost = getRole('vhost');
		$this->assign('vhost', $vhost);
		$user = $_SESSION['user'][$vhost];
		$node = $user['node'];
		$hasEnv = apicall('tplenv', 'hasEnv', array($user['templete'], $user['subtemplete']));
		$this->_tpl->assign('hasEnv', $hasEnv);
		$webftp_url = '?c=index&a=webftp';
		$this->assign('webftp_url', $webftp_url);
		$quota = $_SESSION['quota'][$vhost];
		$ssl = 0;
		if (strchr($user['port'], 's')) {
			$ssl = 1;
		}
		$this->_tpl->assign('ssl', $ssl);
		return $this->_tpl->fetch('top.html');
	}

	public function left()
	{
		$this->_tpl->display('left.html');
	}

	public function controltop()
	{
		$this->_tpl->display('controltop.html');
	}

	public function controlleft()
	{
		$this->_tpl->display('controlleft.html');
	}

	public function main()
	{
		$vhost = getRole('vhost');
		$admin = getRole('admin');
		$user = daocall('vhost', 'getVhost', array($vhost));
		$vhost_domain = daocall('setting', 'get', array('vhost_domain'));
		$quota = $_SESSION['quota'][$vhost];

		if ($vhost_domain) {
			$this->_tpl->assign('vhost_domain', $vhost_domain);
		}

		$webftp_url = '?c=index&a=webftp';
		$this->assign('webftp_url', $webftp_url);
		$user['node'] = 'localhost';

		if ($user) {
			$info = apicall('nodes', 'getKangleInfo', array('localhost'));
			$_SESSION['kangle_info']['kangle_version'] = (string) $info->get('version');
			$_SESSION['kangle_info']['kangle_type'] = (string) $info->get('type');
			if (0 < $user['db_quota'] && $user['db_type'] != 'sqlsrv') {
				/*
				 * 统一走面板内的 SSO 入口（?c=index&a=dbadmin），不再直连 :3313。
				 *
				 * 原实现直接给出 http://host:3313/?db=xxx，跳过了 dbadmin() 里的
				 * 「授权 PMA 专用账号 + 签发 pma_sso_token」两步。结果是：只有在
				 * 用户此前恰好点过一次 SSO 入口、cookie 尚未过期的情况下才免密；
				 * 首次点击或 token 过期后会掉到 phpMyAdmin 自带登录页，而空间用户
				 * 并不知道 PMA 专用账号的密码，等于入口失效。
				 *
				 * 改为相对地址后，端口/协议(4413)与令牌签发全部由 dbadmin() 统一
				 * 决定，避免此处再维护一份 http/https 分支。
				 */
				$this->_tpl->assign('dbadmin_url', '?c=index&a=dbadmin');
			}

			$_SESSION['user'][$vhost] = $user;
			$node_info = apicall('nodes', 'getInfo', array($user['node']));

			if ($node_info) {
				$this->_tpl->assign('node_host', $node_info['host']);
			}

			$this->_tpl->assign('node', $node_info);
			$this->_tpl->assign('product', $user);
			//$user['product_name'] = $product_info['name'];
			$quota = apicall('vhost', 'getQuota', array($user));

			if ($quota) {
				$_SESSION['quota'][$vhost] = $quota;
				$this->_tpl->assign('quota', $quota);
			}

			$flow = apicall('flow', 'getCurrentMonthFlow', array($vhost));
			$this->_tpl->assign('flow', $flow);
			$subtempletes = apicall('nodes', 'listSubTemplete', array($user['node'], $user['templete']));
			$this->_tpl->assign('subtempletes', $subtempletes);
			$ssl = 0;

			if (strchr($user['port'], 's')) {
				$ssl = 1;
			}

			$this->_tpl->assign('ssl', $ssl);
			$module = $user['module'];

			if ($module) {
				$module_link = modcall($module, $module . '_link', $user);

				if ($module_link) {
					$this->_tpl->assign('module_link', $module_link);
				}
			}
		}

		if ($admin) {
			$this->_tpl->assign('admin', $admin);
		}

		$this->_tpl->assign('user', $user);
		return $this->_tpl->fetch('kfinfo.html');
	}

	public function changeSubtemplete()
	{
		$vhost = getRole('vhost');
		apicall('vhost', 'changeSubtemplete', array('localhost', $vhost, filterParam($_REQUEST['subtemplete'])));
		return $this->main();
	}

	public function webftp()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$_SESSION['webftp_docroot'] = $user['doc_root'];
		$_SESSION['webftp_user'] = $user['uid'];
		$_SESSION['webftp_group'] = $user['gid'];
		ob_clean();
		header('Location: ?c=webftp&a=enter');
	}

	public function dbadmin()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$dbName = $user['db_name'];

		$ssoConfig = '/vhs/kangle/nodewww/dbadmin_sso_config.php';
		$ssoLib = '/vhs/kangle/nodewww/dbadmin_sso_lib.php';

		if (!file_exists($ssoConfig) || !file_exists($ssoLib)) {
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MySQL 管理</title>'
			     . '<style>body{font-family:Arial,sans-serif;padding:40px;text-align:center;background:#f5f5f5}'
			     . 'h2{color:#333}.err{color:#e74c3c;font-size:16px;margin:20px 0}'
			     . 'a{color:#3498db;text-decoration:none;border:1px solid #3498db;padding:8px 20px;border-radius:4px;display:inline-block;margin-top:15px}'
			     . 'a:hover{background:#3498db;color:#fff}</style></head>'
			     . '<body><h2>MySQL 数据库管理</h2>'
			     . '<p class="err">phpMyAdmin 未安装或未配置，无法使用在线 MySQL 管理功能。</p>'
			     . '<p>请联系管理员安装 phpMyAdmin 后再试。</p>'
			     . '<a href="?c=index&a=main">返回主机信息</a></body></html>';
			exit();
		}

		/* 确保 phpMyAdmin 单点登录账号已获得该 vhost 数据库的授权（幂等） */
		$ok = $this->ensurePmaSso($dbName);
		if (!$ok) {
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MySQL 管理</title>'
			     . '<style>body{font-family:Arial,sans-serif;padding:40px;text-align:center;background:#f5f5f5}'
			     . 'h2{color:#333}.err{color:#e74c3c;font-size:16px;margin:20px 0}'
			     . 'a{color:#3498db;text-decoration:none;border:1px solid #3498db;padding:8px 20px;border-radius:4px;display:inline-block;margin-top:15px}'
			     . 'a:hover{background:#3498db;color:#fff}</style></head>'
			     . '<body><h2>MySQL 数据库管理</h2>'
			     . '<p class="err">phpMyAdmin 授权失败，无法使用在线 MySQL 管理功能。</p>'
			     . '<p>请确认 MySQL 服务正常运行后重试。</p>'
			     . '<a href="?c=index&a=main">返回主机信息</a></body></html>';
			exit();
		}

		/* 生成带签名、短时效的登录令牌（仅含 db 名与过期时间，密码不下发） */
		$token = $this->makePmaToken($dbName);

		/*
		 * 将令牌写入 HttpOnly Cookie（同主机、跨端口共享：vhost 面板 :3312 与
		 * phpMyAdmin :3313 同属一个主机名）。phpMyAdmin 内部 reload 重定向会剥离
		 * 查询参数，故令牌走 Cookie 才能在自动登录时存活。
		 */
		setcookie('pma_sso_token', $token, array(
			'path' => '/',
			'httponly' => true,
			'samesite' => 'Lax',
		));

		$proto = is_https() ? 'https' : 'http';
		$port = is_https() ? '4413' : '3313';
		$dbadmin_url = $proto . '://' . $_SERVER['SERVER_NAME'] . ':' . $port
			. '/?db=' . urlencode($dbName);

		ob_clean();
		header('Location: ' . $dbadmin_url);
		exit();
	}

	/**
	 * 为 phpMyAdmin 单点登录准备专用账号，并授予该 vhost 数据库的完整权限。
	 * 使用 easypanel 节点特权 MySQL 连接（root），幂等、可重复调用。
	 *
	 * @param string $dbName vhost 数据库名
	 * @return bool
	 */
	private function ensurePmaSso($dbName)
	{
		if (!$dbName || $dbName === 'mysql' || $dbName === 'root') {
			return false;
		}
		$ssoConfig = '/vhs/kangle/nodewww/dbadmin_sso_config.php';
		$ssoLib = '/vhs/kangle/nodewww/dbadmin_sso_lib.php';
		if (!file_exists($ssoConfig) || !file_exists($ssoLib)) {
			return false;
		}
		include_once $ssoConfig;
		include_once $ssoLib;

		$db = apicall('nodes', 'makeDbProduct', array('localhost'));
		if (!$db) {
			return false;
		}
		return $db->grantPma($dbName, PMA_SSO_USER, PMA_SSO_PASS);
	}

	/**
	 * 生成 phpMyAdmin 单点登录令牌（HMAC 签名，含 db 名与过期时间）。
	 *
	 * @param string $dbName vhost 数据库名
	 * @return string
	 */
	private function makePmaToken($dbName)
	{
		$ssoConfig = '/vhs/kangle/nodewww/dbadmin_sso_config.php';
		$ssoLib = '/vhs/kangle/nodewww/dbadmin_sso_lib.php';
		if (!file_exists($ssoConfig) || !file_exists($ssoLib)) {
			return '';
		}
		include_once $ssoConfig;
		include_once $ssoLib;
		return pma_sso_sign($dbName);
	}

	public function ftp()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$ftp_subdir = $_REQUEST['ftp_subdir'];

		if (strstr($ftp_subdir, '..')) {
			exit('ftp目录设置错误');
		}

		$ftp_subdir = str_replace('\\', '/', $ftp_subdir);
		daocall('vhost', 'updateFtp', array(
	$vhost,
	array('ftp' => intval($_REQUEST['ftp']), 'ftp_subdir' => filterParam($ftp_subdir))
	));
		return $this->ftpForm();
	}

	public function ftpForm()
	{
		$vhost = getRole('vhost');
		$user = $user = daocall('vhost', 'getVhost', array($vhost));
		$this->_tpl->assign('user', $user);
		return $this->_tpl->fetch('ftp.html');
	}

	public function refreshDbUsed()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		if($user['status']!=3 || $user['db_quota']==0) exit('无需刷新');

		$db = apicall('nodes', 'makeDbProduct', array('localhost'));
		$db_used = $db->used($vhost, true);

		if($db_used!==false){
			if($db_used < $user['db_quota']){
				apicall('vhost', 'changeStatus', array('localhost', $vhost, 0));
				exit('网站状态恢复成功');
			}else{
				exit('请先清理数据或升级容量后再刷新');
			}
		}
		exit('刷新失败');
	}
}

?>