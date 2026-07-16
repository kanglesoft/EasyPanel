<?php
needRole('admin');
class IndexControl extends Control
{
	public function __construct()
	{
		parent::__construct();
	}

	public function __destruct()
	{
		parent::__destruct();
	}

	public function index()
	{
		$this->main();
	}

	public function foot()
	{
		$this->assign('EASYPANEL_VERSION', EASYPANEL_VERSION);
		return $this->display('common/foot.html');
	}

	public function top()
	{
		return $this->display('top.html');
	}

	public function controltop()
	{
		$this->display('controltop.html');
	}

	public function left()
	{
		$php_ini = urlencode($GLOBALS['safe_dir'] . '../ext/tpl_php74/php-templete.ini');

		$dbadmin_url = 'http://' . $_SERVER['SERVER_NAME'] . ':3313/';
		if(is_https()) $dbadmin_url = 'https://' . $_SERVER['SERVER_NAME'] . ':4413/';
		$this->_tpl->assign('dbadmin_url', $dbadmin_url);

		if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
			$os = 'win';
		}
		else {
			$os = 'lin';
		}

		$this->assign('node', $GLOBALS['node_cfg']['localhost']);
		$this->assign('os', $os);
		$this->assign('php_ini', $php_ini);
		$this->display('left.html');
	}

	public function controlleft()
	{
		$this->display('controlleft.html');
	}

	public function rebootSystem()
	{
		apicall('utils', 'reboot_system', array());
	}

	public function getSysInfo()
	{
		load_lib('pub:sysinfo');
		$sysinfo = sys_info();
		$sysinfo['os'] = is_win() ? 'windows' : 'linux';
		exit(json_encode($sysinfo));
	}

	public function checkBind()
	{
		$json['code'] = 201;
		$bind_dir = apicall('bind', 'getBindDir', array());
		if (!file_exists($bind_dir) || is_win()) {
			$json['code'] = 400;
			exit(json_encode($json));
		}

		if (apicall('bind', 'checkBind', array())) {
			$json['code'] = 200;
		}

		exit(json_encode($json));
	}

	public function main()
	{
		if ($_SESSION['setup_wizard'] == 1) {
			header('Location: ?c=nodes&a=editForm&name=localhost');
			exit();
		}

		$dbisok = daocall('vhost', 'isok', null);
		$this->assign('dbisok', $dbisok['integrity_check']);
		$info = apicall('nodes', 'getKangleInfo', array('localhost'));
		$this->assign('info', $info);
		$this->assign('EASYPANEL_VERSION', EASYPANEL_VERSION);
		$kangle_console_url = 'http://' . $_SERVER['SERVER_NAME'] . ':3311/';
		$this->assign('kangle_console_url', $kangle_console_url);

		load_lib('pub:sysinfo');
		$sysinfo = sys_info();
		if (!$sysinfo) {
			$sysinfo = array();
		}
		$sysinfo['os'] = is_win() ? 'windows' : 'linux';
		$this->assign('sysinfo', $sysinfo);

		$memPct = 0;
		if (!empty($sysinfo['memTotal']) && $sysinfo['memTotal'] > 0) {
			if (!empty($sysinfo['os']) && $sysinfo['os'] == 'windows') {
				$memPct = round(100 * ($sysinfo['memUsed'] / $sysinfo['memTotal']));
			}
			else {
				$memPct = round(100 * (($sysinfo['memRealUsed'] + $sysinfo['memCached']) / $sysinfo['memTotal']));
			}
		}
		$memPct = max(0, min(100, $memPct));
		$this->assign('memPct', $memPct);

		$diskPct = 0;
		if (!empty($sysinfo['diskPct'])) {
			$diskPct = round($sysinfo['diskPct']);
		}
		elseif (!empty($sysinfo['diskTotal']) && $sysinfo['diskTotal'] > 0 && isset($sysinfo['diskUsed'])) {
			$diskPct = round(100 * $sysinfo['diskUsed'] / $sysinfo['diskTotal']);
		}
		$diskPct = max(0, min(100, $diskPct));
		$this->assign('diskPct', $diskPct);

		$this->display('main.html');
	}
}

?>