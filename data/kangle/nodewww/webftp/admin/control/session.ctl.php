<?php
class SessionControl extends Control
{
	public function __construct()
	{
		parent::__construct();
	}

	public function __destruct()
	{
		parent::__destruct();
	}

	/**
	 * 管理员登陆界面
	 * @return Ambigous <string, void, unknown>
	 *2013-5-21
	 */
	public function loginForm()
	{
		if (daocall('setting', 'get', array('admin_login_img')) || !empty($_SESSION['login_force_captcha'])) {
			$this->_tpl->assign('img', 1);
		}

		return $this->fetch('login.html');
	}

	/**
	 * 管理员修改密码界面
	 *
	 *2013-5-21
	 */
	public function changePasswordForm()
	{
		needRole('admin');
		$this->_tpl->display('changePassword.html');
	}

	/**
	 * Enter description here ...
	 */
	public function changePassword()
	{
		needRole('admin');

		if ($GLOBALS['demo']) {
			$this->_tpl->assign('msg', '密码不能修改');
			return $this->_tpl->display('msg.html');
		}

		if ($_REQUEST['oldpasswd'] != $GLOBALS['node_cfg']['localhost']['passwd']) {
			$this->_tpl->assign('msg', '原密码不对!');
		}
		else {
			$ret = apicall('nodes', 'changeAdminInfo', array('localhost', $_REQUEST['admin'], $_REQUEST['passwd']));

			if ($ret) {
				$this->resetAdminInfo($_REQUEST['admin'], $_REQUEST['passwd']);
				$this->_tpl->assign('msg', '修改管理信息成功');
			}
			else {
				$this->_tpl->assign('msg', '修改管理信息失败');
			}
		}

		return $this->_tpl->display('msg.html');
	}

	/**
	 *2013-5-21
	 *生成验证图片
	 */
	public function initImg()
	{
		$img_height = 40;
		$setting = daocall('setting', 'getAll', array());
		$number = $setting['admin_login_img_sum'] ? $setting['admin_login_img_sum'] : 6;
		$img_width = $number * 25;
		$display_str = '123456789abcdefghijkmnpqrstuvwxyz';

		if ($_REQUEST['action'] == 'init') {
			$i = 0;
			$str = '';

			while ($i < $number) {
				$str .= $display_str[rand() % strlen($display_str)];
				++$i;
			}

			$_SESSION['admin_img_number'] = $str;
			$aimg = imageCreate($img_width, $img_height);
			$i = 1;

			while ($i <= 100) {
				imageString($aimg, 1, mt_rand(1, $img_width), mt_rand(1, $img_height), '*', imageColorAllocate($aimg, mt_rand(200, 255), mt_rand(200, 255), mt_rand(200, 255)));
				++$i;
			}

			$i = 0;

			while ($i < $number) {
				imageString($aimg, mt_rand(3, 5), $i * $img_width / $number + mt_rand(1, 10), mt_rand(1, $img_height / 2), $_SESSION['admin_img_number'][$i], imageColorAllocate($aimg, mt_rand(0, 100), mt_rand(0, 150), mt_rand(0, 200)));
				++$i;
			}

			Header('Content-type: image/png');
			ImagePng($aimg);
			ImageDestroy($aimg);
		}
	}

	public function upgrade()
	{
		$config_file = dirname(dirname(dirname(__FILE__))) . '/config.php';

		if (!file_exists($config_file)) {
			return $this->loginForm();
		}

		$easypanel = daocall('setting', 'get', array('EASYPANEL_VERSION'));

		if (!$easypanel) {
			return $this->loginForm();
		}

		$installVersion = apicall('install', 'getInstallVersion');

		if ($easypanel != EASYPANEL_VERSION) {
			$default_db = db_connect(null);

			if (!$default_db) {
				exit('不能打开数据库,请联系管理员');
			}

			if ($this->upgrade_sql($default_db)) {
				if (!apicall('install', 'writeVersion')) {
					exit('未能写入版本信息');
				}

				daocall('setting', 'add', array('EASYPANEL_VERSION', EASYPANEL_VERSION));
			}

			$this->_tpl->assign('msg', '升级完成,<a href=\'?c=session&a=loginForm\'>点这里登录</a>');
			return $this->display('msg.html');
		}

		return $this->loginForm();
	}

	public function login()
	{
		global $default_db;
		$setting = daocall('setting', 'getAll', array());

		/* T05-H3：登录防暴破。连续失败 10 次且在 60s 窗口内则临时锁定。 */
		if (!empty($_SESSION['login_fail']) && $_SESSION['login_fail'] >= 10 && (time() - (isset($_SESSION['login_fail_time']) ? $_SESSION['login_fail_time'] : 0)) < 60) {
			$this->_tpl->assign('msg', '登录尝试过于频繁，请 60 秒后再试');
			return $this->_tpl->display('login_error.html');
		}

		/* 验证码：开启 admin_login_img，或触发暴破防护时强制校验（避免验证码“裸奔”可被绕过）。 */
		$need_captcha = !empty($setting['admin_login_img']) || !empty($_SESSION['login_force_captcha']);
		if ($need_captcha) {
			if (empty($_SESSION['admin_img_number']) || $_REQUEST['imgnumber'] != $_SESSION['admin_img_number']) {
				$this->_tpl->assign('msg', '验证码错误');
				return $this->_tpl->display('login_error.html');
			}
		}

		load_lib('pub:db');
		$info = apicall('nodes', 'getWhmInfo', array($_REQUEST['username'], $_REQUEST['passwd']));

		if (!$info) {
			// 登录失败统一提示账号或密码错误，避免“kangle 通信失败”误导
			$this->_noteLoginFail();
			$this->_tpl->assign('msg', '用户名或密码错误');
			return $this->_tpl->display('login_error.html');
		}

		$kangle_type = $info->get('type');
		$kangle_home = $info->get('kangle_home');
		$config_file = dirname(dirname(dirname(__FILE__))) . '/config.php';

		if (!file_exists($config_file)) {
			$str = "<?php\r\n";
			$str .= '$GLOBALS[\'safe_dir\'] = ';

			if ($kangle_home) {
				$str .= '\'' . $kangle_home . 'etc/\'';
			}
			else {
				$str .= 'dirname(__FILE__).\'/../../etc/\'';
			}

			$str .= ";\r\n";
			$str .= "\$GLOBALS['db_cfg']['default']=array('dsn'=>'sqlite:'.\$GLOBALS['safe_dir'].'vhs.db');\r\n";
			$str .= "\$GLOBALS['node_db']='sqlite';\r\n";
			$str .= "define(DAO_SQLITE_DRIVER,1);\r\n";
			$str .= "@include_once \$GLOBALS['safe_dir'].'node.cfg.php';\r\n";
			$fp = fopen($config_file, 'w');

			if (false === $fp) {
				trigger_error('不能写入配置文件:' . $config_file);
			}
			else {
				fwrite($fp, $str);
				fclose($fp);
				include $config_file;
			}
		}

		$easypanel = daocall('setting', 'get', array('EASYPANEL_VERSION'));

		if (!is_array($GLOBALS['node_cfg']['localhost'])) {
			$GLOBALS['node_cfg'] = null;
			$GLOBALS['node_cfg']['localhost'] = array('name' => 'localhost', 'host' => 'localhost', 'port' => '3311', 'user' => '', 'passwd' => '', 'db_type' => 'mysql', 'db_user' => '', 'db_passwd' => '', 'win' => '', 'dev' => '');
			$_SESSION['setup_wizard'] = 1;
		}

		if ($_REQUEST['username'] != $GLOBALS['node_cfg']['localhost']['user'] || $_REQUEST['passwd'] != $GLOBALS['node_cfg']['localhost']['passwd']) {
			$GLOBALS['node_cfg']['localhost']['win'] = strcasecmp($info->get('os'), 'windows') == 0 ? 1 : 0;
			$this->resetAdminInfo($_REQUEST['username'], $_REQUEST['passwd']);
		}

		$default_db = db_connect();

		if (!$default_db) {
			exit('不能打开数据库,请联系管理员');
		}

		if (!$easypanel) {
			$this->install_sql($default_db);

			if (!apicall('install', 'writeVersion')) {
				exit('未能写入版本信息');
			}

			$this->upgrade_sql($default_db);
		}
		else {
			if ($easypanel != EASYPANEL_VERSION) {
				if ($this->upgrade_sql($default_db)) {
					if (!apicall('install', 'writeVersion')) {
						exit('未能写入版本信息');
					}
				}
			}
		}

		daocall('setting', 'add', array('kangle_type', $kangle_type));
		if (!$easypanel || $easypanel != EASYPANEL_VERSION) {
			daocall('setting', 'add', array('EASYPANEL_VERSION', EASYPANEL_VERSION));
		}

		/* T05-H4：登录成功后立即轮换会话 ID，消除 Session Fixation。 */
		session_regenerate_id(true);
		registerRole('admin', $_REQUEST['username']);
		unset($_SESSION['login_fail'], $_SESSION['login_force_captcha'], $_SESSION['login_fail_time']);
		session_write_close();
		header('Location: index.php');
	}

	public function logout()
	{
		session_start();
		session_unset();
		session_destroy();
		return $this->loginForm();
	}

	private function _noteLoginFail()
	{
		if (empty($_SESSION['login_fail'])) {
			$_SESSION['login_fail'] = 0;
		}

		$_SESSION['login_fail'] = (int) $_SESSION['login_fail'] + 1;
		/* 滑动窗口：每次失败都刷新计时，使“10 次失败且 60s 内”始终指最近一次失败，
		 * 避免锁定在首次失败后 60s 即永久失效。停止尝试 60s 后自动解锁，不会误锁正常用户。 */
		$_SESSION['login_fail_time'] = time();
		/* 连续失败 5 次后强制开启验证码，避免无验证码时被暴力破解。 */
		if ($_SESSION['login_fail'] >= 5) {
			$_SESSION['login_force_captcha'] = 1;
		}
	}

	private function checkPassword($username, $passwd)
	{
		$user = daocall('admin_user', 'getUser', array($username));

		if (!$user) {
			return false;
		}

		if (strtolower($user['passwd']) != strtolower(md5($passwd))) {
			return false;
		}

		$attr['last_login'] = 'NOW()';
		$attr['last_ip'] = $_SERVER['REMOTE_ADDR'];
		daocall('admin_user', 'updateUser', array($username, $attr));
		return $user;
	}

	private function upgrade_sql($pdo)
	{
		apicall('cron', 'install_system_cron');
		$file = dirname(__FILE__) . '/upgrade.sql';
		apicall('utils', 'createDnsDb', array());
		return apicall('install', 'executeSql', array($pdo, $file));
	}

	private function install_sql($pdo)
	{
		$this->writeAllInOne();
		apicall('cron', 'install_system_cron');
		$file = dirname(__FILE__) . '/kangle.sql';
		apicall('utils', 'createDnsDb', array());
		return apicall('install', 'executeSql', array($pdo, $file));
	}

	private function resetAdminInfo($user, $passwd)
	{
		$GLOBALS['node_cfg']['localhost']['user'] = $user;
		$GLOBALS['node_cfg']['localhost']['passwd'] = $passwd;
		return apicall('utils', 'writeConfig', array($GLOBALS['node_cfg'], 'name', 'node', $GLOBALS['safe_dir']));
	}

	private function writeAllInOne()
	{
		$str = "<!--#start 10 merge-->\r\n<config><vhs><vh_templete name='all_in_one' inherit='1' fflow='1' log_mkdir='1' logs_day='60' log_rotate_time='0 0 * * *' log_file='/nolog' app_share='0'>";
		$str .= '<init_event event=\'vhost.whm:init_vh\' />';
		$str .= '<destroy_event event=\'vhost.whm:destroy_vh\'/>';
		$str .= '</vh_templete></vhs></config>';
		$fp = fopen($GLOBALS['safe_dir'] . '../ext/all_in_one.xml', 'wb');

		if ($fp) {
			fwrite($fp, $str);
			fclose($fp);
			return NULL;
		}

		trigger_error('cann\'t write all_in_one.xml');
	}
}

?>