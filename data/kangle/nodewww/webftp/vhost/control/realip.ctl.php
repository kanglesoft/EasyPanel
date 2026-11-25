<?php
/**
 * realip.ctl.php —— 真实 IP 还原（CDN/反代场景 REMOTE_ADDR 还原）
 *
 * 页面入口：?c=realip&a=realipForm（远端验收步骤 1 的 URL）。
 * 状态唯一事实源 = kangle request BEGIN 表中的 !ep_realip 链（见
 * framework/api/realip.api.php 头注释），不落面板 sqlite，无 DDL。
 * 上游参考实现：.temp/repos/easypanel/vhost/control/realip.ctl.php；
 * 本仓适配点：action 改名 realipForm/save（对齐 banip/capability 的
 * XxxFrom 命名与验收 URL）、链名 !ep_realip（对齐 !ep_hsts 命名空间）、
 * 写链逻辑下沉 realip.api 并补 beginOrderFixup 链序约束（规格「链顺序
 * 约束」条）与 audit 审计（webwaf.ctl.php 同款模式）。
 */
needRole('vhost');
class RealipControl extends Control
{
	public function __construct()
	{
		parent::__construct();
		load_api('realip');
	}

	/**
	 * 开关表单页：回显当前 Header 与开关状态（realip.api getStatus 读链）
	 */
	public function realipForm()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			return $this->show_msg($check_result);
		}

		$state = apicall('realip', 'getStatus', array());

		if ($state === null || !isset($state['success']) || $state['success'] !== true) {
			return $this->show_msg(isset($state['msg']) && $state['msg'] !== '' ? $state['msg'] : '读取真实IP设置失败，请重试');
		}

		$this->assign('realip', $state);
		$this->assign('realip_headers', apicall('realip', 'knownHeaders', array()));
		return $this->_tpl->fetch('realip/form.html');
	}

	/**
	 * 保存（ajax）：status=0|1；status=1 时 header 生效
	 *
	 * header=__custom__ 时改读 custom_header 字段（与上游 realip.html 表单
	 * 约定一致）。校验统一交给 realip.api.save 的 RFC7230 token 白名单。
	 */
	public function save()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			audit('realip', 'save', getRole('vhost'), 'deny', array('reason' => 'access_denied'));
			exit(strval($check_result));
		}

		$vhost = getRole('vhost');
		$status = isset($_REQUEST['status']) ? strval($_REQUEST['status']) : '';

		if ($status !== '0' && $status !== '1') {
			exit('参数错误');
		}

		$enabled = $status === '1';
		$header = isset($_REQUEST['header']) ? strval($_REQUEST['header']) : '';

		if ($header === '__custom__') {
			$header = isset($_REQUEST['custom_header']) ? strval($_REQUEST['custom_header']) : '';
		}

		$result = apicall('realip', 'save', array($enabled, $header));

		if (!is_array($result) || !isset($result['success']) || $result['success'] !== true) {
			$msg = is_array($result) && isset($result['msg']) ? strval($result['msg']) : '保存设置失败，请重试';
			audit('realip', 'save', $vhost, 'error', array('status' => $status, 'header' => $header, 'msg' => $msg));
			exit($msg);
		}

		audit('realip', 'save', $vhost, 'success', array(
			'status' => $enabled ? 1 : 0,
			'header' => $enabled ? strval($result['header']) : '',
			'changed' => empty($result['changed']) ? 0 : 1,
			'order_fixed' => empty($result['order_fixed']) ? 0 : 1,
		));
		exit('成功');
	}

	private function show_msg($msg)
	{
		$this->_tpl->assign('msg', $msg);
		return $this->_tpl->fetch('msg.html');
	}
}

?>
