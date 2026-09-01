<?php
define('EASYPANEL_SHELL_WHM', 'easypanel_shell');
class ProcessApi extends API
{
	/**
	 * 通过whmshell以daemon的方式异步执行。
	 * 支持合并执行模式，合并执行模式特点:
	 * 1.后台最多执行一个php进程。
	 * 2.参数由stdin传入。
	 * 3.多个执行请求将会合并stdin数据，只执行一个调用。
	 * @param shell_call shell调用名字,最后对应到shell.api.php的函数名
	 * @param array $args(arg1,arg2,arg3,arg4)
	 * @param $stdin 输入数据，此参数设置了，将以合并执行模式。
	 * @param $worker 保留,工作队列，格式(队列名称:最大工作者)
	 */
	public function daemon($shell_call, array $args = null, $stdin = null, $worker = null)
	{
		$this->check_whm_file();
		$whm = apicall('nodes', 'makeWhm');

		if (!$whm) {
			trigger_error('cann\'t makeWhm');
			return false;
		}

		if (!$shell_call) {
			trigger_error('shell_call is not set');
			return false;
		}

		/* T05-H6：shell_call 白名单。daemon() 经 framework/shell.php 派发到
		 * shell.api.php 的同名函数，必须限制在已知函数集合内，拒绝任意调用。 */
		static $shell_whitelist = array(
			'cdn_sync', 'changeEtcPermissions', 'check_db_run', 'check_db_used',
			'list_cdn_vh', 'sync_expire', 'check_flow', 'sync_flow',
			'sync_host_flow', 'sync_localhost_flow', 'sync_all_vhost',
			'restore', 'backup', 'cron_add', 'whmshell', 'query', 'terminate'
		);
		if (!in_array($shell_call, $shell_whitelist, true)) {
			trigger_error('shell_call not allowed: ' . $shell_call);
			return false;
		}

		$call = 'daemon';

		if ($stdin) {
			$call = 'merge';
		}

		$whmCall = new WhmCall(EASYPANEL_SHELL_WHM . '.whm', $call);
		$whmCall->addParam('shell_call', $shell_call);

		if ($stdin) {
			$whmCall->addParam('-', $stdin);
		}

		if (is_array($args)) {
			foreach ($args as $k => $v) {
				$whmCall->addParam($k, $v);
			}
		}

		return $whm->call($whmCall);
	}

	/**
	 * 得到easypanel的shell命令
	 * @return string
	 */
	public function get_easypanel_shell()
	{
		$cmd = '/usr/bin/php74';

		$cmd .= ' -c "/vhs/kangle/ext/tpl_php74/php-templete.ini"';

		$cmd .= ' -f "';
		$cmd .= dirname(dirname(dirname(__FILE__))) . '/framework/shell.php"';
		return $cmd;
	}

	private function check_whm_file()
	{
		$whm_file_name = $GLOBALS['safe_dir'] . '../webadmin/' . EASYPANEL_SHELL_WHM . '.whm';

		if (!file_exists($whm_file_name)) {
			$cmd = $this->get_easypanel_shell();
			$fp = fopen($whm_file_name, 'wb');
			$str = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n";
			$str .= "<whm version=\"1.0\">\r\n";
			$str .= "<extend type='shell' name='daemon' async='1' merge='0'>\r\n";
			$str .= "<commands runas='system'>\r\n";
			$str .= '<command>' . $cmd . ' ${shell_call} ${arg1} ${arg2} ${arg3} ${arg4} ${arg5}';
			$str .= "</command>\r\n</commands>\r\n</extend>\r\n";
			$str .= "<extend type='shell' name='merge' async='1' merge='1'>\r\n";
			$str .= "<commands runas='system'>\r\n";
			$str .= '<command>' . $cmd . ' ${shell_call} ${arg1} ${arg2} ${arg3} ${arg4} ${arg5}';
			$str .= "</command>\r\n</commands>\r\n</extend>\r\n";
			$str .= "<call name='daemon' extend='daemon'></call>\r\n";
			$str .= "<call name='merge' extend='merge'></call>\r\n";
			$str .= '</whm>';
			fwrite($fp, $str);
			fclose($fp);
		}
	}
}

?>