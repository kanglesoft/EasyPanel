<?php
/**
 * http3.ctl.php —— HTTP/3 (QUIC) 开关（空间用户侧）
 *
 * 入口策略（评审 2e / hide 说明）：本单元不往 left.html/kfinfo.html 加
 * 导航项（共享文件改动以片段形式写在 .temp/integration/http3-quic-toggle.md，
 * 交集成步统一合并）；内核不支持 HTTP/3 时 ?c=http3&a=show 直接返回
 * 「不支持」提示页，不显示可点坏的表单；setting 键 http3_force_show=1
 * 可强制显示表单（远端验收链路用）。
 *
 * 存储：开关状态 = setting 表键 http3_flag_<vhost>，不给 vhost 表加列、
 * 不写 config.xml，细节见 framework/api/http3.api.php 头注释。
 */
needRole('vhost');
class Http3Control extends Control
{
	public function __construct()
	{
		parent::__construct();
		load_api('http3');
	}

	/**
	 * 开关页：支持则渲染表单，不支持则返回提示页
	 */
	public function show()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			$this->_tpl->assign('msg', $check_result);
			return $this->_tpl->fetch('msg.html');
		}

		$vhost = getRole('vhost');
		$supported = apicall('http3', 'supportsHttp3', array(false)) ? 1 : 0;
		$this->_tpl->assign('supported', $supported);
		$this->_tpl->assign('vhost', $vhost);

		if ($supported) {
			$this->_tpl->assign('status', apicall('http3', 'getStatus', array($vhost)));
		}

		$this->_tpl->assign('probe_info', apicall('http3', 'getProbeInfo', array()));
		return $this->_tpl->fetch('http3/form.html');
	}

	/**
	 * 保存开关（ajax，成功/失败返回「成功」「失败」，配合 public.js 的
	 * functoin_checkon 使用，与 ssl.http2 一致）
	 */
	public function save()
	{
		$vhost = getRole('vhost');
		$status = isset($_REQUEST['status']) && intval($_REQUEST['status']) === 1 ? 1 : 0;

		if (!apicall('http3', 'supportsHttp3', array(false))) {
			audit('http3', 'save', $vhost, 'deny', array('reason' => 'kernel_not_support', 'status' => $status));
			exit('本节点内核不支持 HTTP/3，设置未生效');
		}

		$result = apicall('http3', 'setStatus', array($vhost, $status));

		if ($result) {
			audit('http3', 'save', $vhost, 'success', array('status' => $status));
			exit('成功');
		}

		audit('http3', 'save', $vhost, 'error', array('status' => $status, 'reason' => 'setting_write_failed'));
		exit('失败');
	}

	/**
	 * 重新探测内核支持（ajax，刷新缓存后由前端 reload）
	 */
	public function recheck()
	{
		$vhost = getRole('vhost');
		$supported = apicall('http3', 'supportsHttp3', array(true)) ? 1 : 0;
		audit('http3', 'recheck', $vhost, 'success', array('supported' => $supported));
		exit('成功');
	}
}

?>
