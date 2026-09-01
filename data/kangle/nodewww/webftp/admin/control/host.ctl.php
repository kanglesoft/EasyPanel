<?php
needRole('admin');
class HostControl extends Control
{
	/**
	 * 主机安全页（SSH 端口 / 服务状态 / root 密码）
	 */
	public function index()
	{
		$ssh = apicall('host', 'getSshPort', array());
		$status = apicall('host', 'getSshStatus', array());

		$this->_tpl->assign('ssh_port', isset($ssh['port']) ? $ssh['port'] : '');
		$this->_tpl->assign('ssh_running', !empty($status['running']));
		$this->_tpl->assign('breadcrumb', '主机安全');
		return $this->_tpl->fetch('host/index.html');
	}

	/**
	 * 修改 SSH 端口（ajax）
	 */
	public function setSshPort()
	{
		$port = intval($_REQUEST['port']);
		$r = apicall('host', 'setSshPort', array($port));
		exit(json_encode($r));
	}

	/**
	 * 一键开启 / 关闭 SSH（ajax）
	 */
	public function toggleSsh()
	{
		$enabled = !empty($_REQUEST['enabled']) ? true : false;
		$r = apicall('host', 'setSshEnabled', array($enabled));
		exit(json_encode($r));
	}

	/**
	 * 修改宿主机 root 密码（ajax）
	 */
	public function changeRootPassword()
	{
		$pass = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
		$r = apicall('host', 'changeRootPassword', array($pass));
		exit(json_encode($r));
	}
}
?>
