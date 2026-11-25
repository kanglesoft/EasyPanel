<?php
/**
 * hsts.ctl.php —— HSTS 开关（全站 + 单域名）
 *
 * 界面挂 vhost 侧「响应头」旁（common/head.html 集成片段锚），实际机制是
 * kangle response access 链的 mark_add_header 节点（framework/api/
 * hsts.api.php 是唯一写入口；链节点名 !ep_hsts / !ep_hsts_xxxxxx）。
 *
 * 风格对齐：开关/规则同 cache.ctl.php + cache/cachefrom.html（ep-switch +
 * POST ajax + location.reload）；单域名按归属校验（vhostinfo getInfo /
 * getDomain，同 ssl.ctl.php domainSsl* 口径）；写后统一 notice_cdn_changed
 * （api 层内部执行，控制器不重复调用）。
 *
 * 审计：每次切换写 audit('ssl', 动作, 目标, 结果)，audit() 全局函数
 * 失败静默（runtime.php:1464），不阻断业务。
 */
needRole('vhost');
class HstsControl extends Control
{
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * 设置页：全站开关 + 各域名开关一览
	 */
	public function hstsFrom()
	{
		$vhost = getRole('vhost');

		// 域名列表（type=0），与 domain.ctl.php:26 getDomain 同源
		$domains = daocall('vhostinfo', 'getDomain', array($vhost));
		if (!is_array($domains)) {
			$domains = array();
		}

		$overview = apicall('hsts', 'overview', array($domains));
		if (!is_array($overview)) {
			$overview = array(
				'default_max_age' => 31536000,
				'global' => array('enabled' => false, 'value' => ''),
				'domains' => array(),
			);
		}

		// 全站开关需要端口含 's'；只作为提示位，不拦截页面（关闭动作永远可用）
		$port_ok = apicall('hsts', 'checkSslPort', array($vhost));
		$this->_tpl->assign('port_ok', is_array($port_ok) && $port_ok['ok']);
		$this->_tpl->assign('enabled', $overview['global']['enabled'] ? 1 : 0);
		$this->_tpl->assign('current_value', $overview['global']['value']);

		// 回显以链内真实值为准（链即配置）；无链时用默认
		$parsed = $this->parseHeaderValue($overview['global']['value'], $overview['default_max_age']);
		$this->_tpl->assign('max_age', $parsed['max_age']);
		$this->_tpl->assign('include_subdomains', $parsed['include_subdomains'] ? 1 : 0);
		$this->_tpl->assign('preload', $parsed['preload'] ? 1 : 0);
		$this->_tpl->assign('rows', $overview['domains']);

		return $this->_tpl->fetch('hsts/form.html');
	}

	/**
	 * ajax：全站开关（status=1 开 / 0 关；开关同时落参数）
	 */
	public function setHsts()
	{
		$status = $this->requestStatus();
		if ($status === false) {
			exit('参数错误');
		}

		$opts = $this->requestOpts();
		$vhost = getRole('vhost');

		// 域名开关走 setDomainHsts，这里只接全站（domain 参数强制为空）。
		// 服务端强制端口校验（UI 只是隐藏开关，不能当访问控制）：
		// port 不含 's' 的账号开 HSTS 等于给纯明文站发 STS 头，无意义且误导。
		if ($status === 1) {
			$port = apicall('hsts', 'checkSslPort', array($vhost));
			if (!is_array($port) || empty($port['ok'])) {
				$msg = is_array($port) && isset($port['msg']) ? $port['msg'] : '您的账号未开启SSL端口';
				audit('ssl', 'setHsts', $vhost, 'deny', array('status' => $status, 'reason' => $msg));
				exit($msg);
			}
		}

		$result = apicall('hsts', 'toggle', array($status === 1, $opts, ''));
		$ok = is_array($result) && !empty($result['success']);
		$msg = is_array($result) && isset($result['msg']) ? $result['msg'] : '失败';

		audit('ssl', 'setHsts', $vhost, $ok ? 'success' : 'error', array(
			'scope' => 'global',
			'status' => $status,
			'max_age' => $opts['max_age'],
			'include_subdomains' => $opts['include_subdomains'],
			'preload' => $opts['preload'],
			'msg' => $msg,
		));

		exit($ok ? '成功' : $msg);
	}

	/**
	 * ajax：单域名开关（domain 必须归属本 vhost 且非 tcp 回源）
	 */
	public function setDomainHsts()
	{
		$status = $this->requestStatus();
		if ($status === false) {
			exit('参数错误');
		}

		$domain = strtolower(trim(strval(isset($_REQUEST['domain']) ? $_REQUEST['domain'] : '')));

		$owned = apicall('hsts', 'checkDomainOwned', array($domain));
		if (!is_array($owned) || empty($owned['ok'])) {
			$msg = is_array($owned) && isset($owned['msg']) ? $owned['msg'] : '域名不存在';
			audit('ssl', 'setDomainHsts', $domain, 'deny', array('status' => $status, 'reason' => $msg));
			exit($msg);
		}

		$domain = $owned['domain'];

		// TCP 回源域名没有 HTTPS 信道可加固，拒绝（口径同 ssl.ctl.php:189）
		$vhost = getRole('vhost');
		$info = daocall('vhostinfo', 'getInfo', array($vhost, 0, $domain));
		if ($info && strpos(strval($info[0]['value']), 'proto=tcp') !== false) {
			audit('ssl', 'setDomainHsts', $domain, 'deny', array('status' => $status, 'reason' => 'tcp upstream'));
			exit('TCP回源协议的域名不支持设置HSTS');
		}

		$opts = $this->requestOpts();
		$result = apicall('hsts', 'toggle', array($status === 1, $opts, $domain));
		$ok = is_array($result) && !empty($result['success']);
		$msg = is_array($result) && isset($result['msg']) ? $result['msg'] : '失败';

		audit('ssl', 'setDomainHsts', $domain, $ok ? 'success' : 'error', array(
			'scope' => 'domain',
			'status' => $status,
			'max_age' => $opts['max_age'],
			'msg' => $msg,
		));

		exit($ok ? '成功' : $msg);
	}

	/* ---------- 内部工具 ---------- */

	/**
	 * status 参数：'1'/'0' 之外的输入一律 false（调用方 exit 参数错误）
	 * @return int|false
	 */
	private function requestStatus()
	{
		$status = isset($_REQUEST['status']) ? trim(strval($_REQUEST['status'])) : '';

		if ($status !== '0' && $status !== '1') {
			return false;
		}

		return intval($status);
	}

	/**
	 * 可选参数收集（空白 = 走 api 默认；非法值交由 api 层白名单校验拒绝）
	 */
	private function requestOpts()
	{
		$opts = array(
			'max_age' => isset($_REQUEST['max_age']) ? trim(strval($_REQUEST['max_age'])) : '',
			'include_subdomains' => !empty($_REQUEST['include_subdomains']) ? 1 : 0,
			'preload' => !empty($_REQUEST['preload']) ? 1 : 0,
		);

		return $opts;
	}

	/**
	 * 解析链内真实头值用于回显：max-age / includeSubDomains / preload
	 * @return array
	 */
	private function parseHeaderValue($value, $default_max_age)
	{
		$ret = array(
			'max_age' => intval($default_max_age),
			'include_subdomains' => false,
			'preload' => false,
		);

		$value = strval($value);

		if ($value === '') {
			return $ret;
		}

		if (preg_match('/max-age=(\d+)/i', $value, $m)) {
			$ret['max_age'] = intval($m[1]);
		}

		if (stripos($value, 'includesubdomains') !== false) {
			$ret['include_subdomains'] = true;
		}

		if (stripos($value, 'preload') !== false) {
			$ret['preload'] = true;
		}

		return $ret;
	}
}

?>
