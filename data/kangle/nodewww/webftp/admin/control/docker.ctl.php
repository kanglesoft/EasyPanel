<?php
needRole('admin');
class DockerControl extends Control
{
	/**
	 * 容器管理列表页
	 */
	public function pageList()
	{
		$data = apicall('docker', 'listContainers', array());
		$this->_tpl->assign('docker', $data);
		$this->_tpl->assign('breadcrumb', '容器管理');
		return $this->_tpl->fetch('docker/list.html');
	}

	/**
	 * 启动容器（ajax）
	 */
	public function start()
	{
		$id = trim($_REQUEST['id']);
		$r = apicall('docker', 'startContainer', array($id));
		exit(json_encode($r));
	}

	/**
	 * 停止容器（ajax）
	 */
	public function stop()
	{
		$id = trim($_REQUEST['id']);
		$r = apicall('docker', 'stopContainer', array($id));
		exit(json_encode($r));
	}

	/**
	 * 重启容器（ajax，2-B）
	 *
	 * 与 start/stop 共用同一套参数与返回结构，前端无需区分；
	 * 安全能力（CSRF 守卫、二次密码确认）由 csrf_enforce_level 的
	 * docker.restart 条目自动覆盖，此处不重复实现。
	 */
	public function restart()
	{
		$id = isset($_REQUEST['id']) ? trim($_REQUEST['id']) : '';
		$r = apicall('docker', 'restartContainer', array($id));
		exit(json_encode($r));
	}
}
?>
