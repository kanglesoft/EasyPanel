<?php
needRole('vhost');
class RecordControl extends Control
{
	/**
	 * 显示添加解析记录表单。
	 * 表单所需的域名优先取请求参数(domain/name)，其次取当前登录站点，
	 * 若仍无法确定则给出友好提示，避免直接渲染不存在的模板导致 500。
	 *
	 * @return string 渲染后的表单 HTML
	 */
	public function recordAddFrom()
	{
		$domain = trim($_REQUEST['domain']);

		if (!$domain) {
			$domain = trim($_REQUEST['name']);
		}

		if (!$domain) {
			$domain = getRole('vhost');
		}

		if (!$domain) {
			exit('请先选择站点/域名');
		}

		$this->_tpl->assign('domain', $domain);
		return $this->_tpl->fetch('record/from.html');
	}

	public function recordAdd()
	{
		$domain = trim($_REQUEST['domain']);
		$name = trim($_REQUEST['name']);

		if (!$name) {
			$name = '@';
		}

		$type = trim($_REQUEST['type']);
		$value = trim($_REQUEST['value']);
		$view = trim($_REQUEST['view']);
		$ttl = intval($_REQUEST['ttl']);
	}

	public function recordDel()
	{
	}

	public function recordUpdate()
	{
	}
}
?>
