<?php
needRole('admin');

/**
 * acme.sh 免费 SSL 证书管理（HTTP-01 + DNS-01 泛域名 + 多 CA + 失败节流）
 *
 * 通过 acme.sh（已随 install.sh 集成在 kangle 容器 /root/.acme.sh）：
 *   - HTTP-01：webroot 验证，签单域名证书（历史行为，保留）；
 *   - DNS-01：经 framework/api/dnsapi.api.php 的 11 家 DNS 平台适配
 *     （aliyun/dnspod/huaweicloud/baidu/xinnet/volcengine/dnsla/
 *      cloudflare/route53/namesilo/powerdns）自动加/删 _acme-challenge
 *     TXT 记录，支持 *.example.com 泛域名；
 *   - 多 CA：letsencrypt / zerossl / google / letsencrypt-staging
 *     （即 acme.sh --server）；
 *   - 节流：framework/api/acmethrottle.api.php，同域名连续失败 3 次
 *     后在冷却窗口内拒绝再次发起（防 Let's Encrypt 速率限制封禁）。
 */
class AcmesslControl extends control
{
	private $acmeHome = '/root/.acme.sh';
	private $acmeBin;

	/** 允许选择的 CA（acme.sh --server 取值；staging 用完整 URL 便于联调） */
	private $caList = array(
		'letsencrypt' => 'Let\'s Encrypt（默认）',
		'zerossl'     => 'ZeroSSL（需账户邮箱）',
		'google'      => 'Google Trust Services',
		'staging'     => 'LE Staging（测试专用，不计生产配额）',
	);

	public function __construct()
	{
		parent::__construct();
		$this->acmeBin = $this->acmeHome . '/acme.sh';
		if (isset($_REQUEST['a']) && $_REQUEST['a'] === 'apply') {
			$this->ctllog('boot a=apply');
			@register_shutdown_function(array($this, 'logShutdown'));
		}
	}

	/** 诊断：请求结束时打点（区分“代码跑完但响应没送出”与“代码卡住”） */
	public function logShutdown()
	{
		$this->ctllog('shutdown');
	}

	// ───────────────────────── 基础工具 ─────────────────────────

	/**
	 * 执行 acme.sh 命令并返回输出
	 * @param string $args 命令参数
	 * @param array  $env  额外注入的环境变量（DNS 凭据 / bridge token）
	 */
	private function execAcme($args, $env = array())
	{
		if (!file_exists($this->acmeBin)) {
			return array('code' => -1, 'output' => 'acme.sh 未安装，请检查 install.sh 是否执行成功。');
		}
		// shell 前置赋值形式：VAR='esc'，变量名先经白名单校验，值 escapeshellarg
		$envstr = '';
		foreach ($env as $k => $v) {
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
				continue;
			}
			$envstr .= $k . '=' . escapeshellarg(strval($v)) . ' ';
		}
		$cmd = 'HOME=' . escapeshellarg($this->acmeHome) . ' ' . $envstr
			. escapeshellcmd($this->acmeBin) . ' ' . $args . ' 2>&1';
		@set_time_limit(3600);
		exec($cmd, $output, $code);
		return array('code' => $code, 'output' => implode("\n", $output));
	}

	/**
	 * 查找 acme.sh 为某个域名生成的证书目录（兼容 ECC 后缀 _ecc）
	 */
	private function getCertDir($domain)
	{
		$domain = trim($domain);
		$candidates = array(
			$this->acmeHome . '/' . $domain,
			$this->acmeHome . '/' . $domain . '_ecc',
		);
		foreach ($candidates as $dir) {
			if (is_dir($dir) && file_exists($dir . '/fullchain.cer')) {
				return $dir;
			}
		}
		return false;
	}

	/**
	 * 统一解析证书目录（兼容 ECC 后缀 _ecc）下的真实域名与证书文件。
	 * acme.sh 的 ECC 目录名为 <domain>_ecc，但目录内 conf/key 文件名是真实域名（不带 _ecc）。
	 */
	private function getRealDomainFromCertDir($certDir)
	{
		$dirName = basename(rtrim($certDir, '/'));
		$realDomain = preg_replace('/_ecc$/', '', $dirName);
		$confFile = $certDir . '/' . $realDomain . '.conf';
		if (file_exists($confFile)) {
			$conf = @parse_ini_file($confFile);
			if (!empty($conf['Le_Domain'])) {
				return $conf['Le_Domain'];
			}
		}
		foreach (glob($certDir . '/*.conf') as $cf) {
			$conf = @parse_ini_file($cf);
			if (!empty($conf['Le_Domain'])) {
				return $conf['Le_Domain'];
			}
		}
		return $realDomain;
	}

	/**
	 * 获取所有 acme.sh 已申请证书列表
	 */
	private function getCertList()
	{
		$certs = array();
		$dir = $this->acmeHome;
		if (!is_dir($dir)) {
			return $certs;
		}
		$dh = opendir($dir);
		if (!$dh) {
			return $certs;
		}
		while (($entry = readdir($dh)) !== false) {
			if ($entry == '.' || $entry == '..' || $entry == 'account.conf' || $entry == 'ca') {
				continue;
			}
			if (in_array($entry, array('dnsapi', 'deploy', 'notify', 'log', 'src'), true)) {
				continue;
			}
			$domainDir = $dir . '/' . $entry;
			if (!is_dir($domainDir)) {
				continue;
			}
			$realDomain = $this->getRealDomainFromCertDir($domainDir);
			$confFile   = $domainDir . '/' . $realDomain . '.conf';
			$crtFile    = $domainDir . '/fullchain.cer';
			$keyFile    = $domainDir . '/' . $realDomain . '.key';
			if (!file_exists($confFile) || !file_exists($crtFile) || !file_exists($keyFile)) {
				continue;
			}
			$conf = @parse_ini_file($confFile);
			$domain = $realDomain;
			$certs[] = array(
				'domain'    => $domain,
				'base_domain' => preg_replace('/^\*\./', '', $domain),
				'is_wildcard' => strpos($domain, '*') === 0 ? 1 : 0,
				'crt_file'  => $crtFile,
				'key_file'  => $keyFile,
				'server'    => isset($conf['Le_API']) ? $this->caLabelFromApi($conf['Le_API']) : '',
				'dns_plugin' => isset($conf['Le_DNS']) ? $conf['Le_DNS'] : '',
				'Le_CertCreateTimeStr' => isset($conf['Le_CertCreateTimeStr']) ? $conf['Le_CertCreateTimeStr'] : '',
				'Le_NextRenewTimeStr'  => isset($conf['Le_NextRenewTimeStr']) ? $conf['Le_NextRenewTimeStr'] : '',
			);
		}
		closedir($dh);
		return $certs;
	}

	private function caLabelFromApi($api)
	{
		if (strpos($api, 'letsencrypt.org') !== false) {
			return strpos($api, 'acme-staging') !== false ? 'LE Staging' : 'Let\'s Encrypt';
		}
		if (strpos($api, 'zerossl.com') !== false) {
			return 'ZeroSSL';
		}
		if (strpos($api, 'pki.goog') !== false) {
			return 'Google';
		}
		if (strpos($api, 'ssl.com') !== false) {
			return 'SSL.com';
		}
		return $api;
	}

	/**
	 * 获取虚拟主机列表（含绑定的域名），用于前端选择
	 */
	private function getVhostList()
	{
		$vhosts = daocall('vhost', 'listVhostNotcdn', array());
		if (empty($vhosts) || !is_array($vhosts)) {
			return array();
		}
		foreach ($vhosts as $k => $v) {
			$domains = daocall('vhostinfo', 'getDomain', array($v['name']));
			$domainNames = array();
			if (is_array($domains)) {
				foreach ($domains as $d) {
					$domainNames[] = $d['name'];
				}
			}
			$vhosts[$k]['domains'] = $domainNames;
		}
		return $vhosts;
	}

	/**
	 * 根据域名查找绑定的 vhost
	 */
	private function findVhostByDomain($domain)
	{
		$domain = strtolower(trim($domain));
		$vhosts = $this->getVhostList();
		if (empty($vhosts)) {
			return null;
		}
		foreach ($vhosts as $v) {
			if (in_array($domain, $v['domains'])) {
				return $v;
			}
		}
		return null;
	}

	/** 期望 JSON 响应？（AJAX 或显式 format=json；节流拦截必须回结构化 JSON） */
	private function wantJson()
	{
		return (function_exists('isAjaxRequest') && isAjaxRequest())
			|| (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json');
	}

	private function outJson($arr)
	{
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}
		exit(json_encode($arr));
	}

	/** alert 风格回显（保留旧交互），msg 需已转义单引号 */
	private function alertGo($msg, $goto)
	{
		$safe = str_replace(array("'", "\r", "\n"), array("\\'", '', '\\n'), $msg);
		if ($goto === 'back') {
			exit("<script>alert('{$safe}');history.go(-1);</script>");
		}
		exit("<script>alert('{$safe}');location.href='" . $goto . "';</script>");
	}

	/** 桥接平台长效续签 token 落盘（root 私有文件；失败静默） */
	private function writePermToken($credId)
	{
		$pt = apicall('dnsapi', 'permHookToken', array(intval($credId)));
		if (!$pt) {
			return;
		}
		$f = $this->acmeHome . '/dnsapi/ep_renew_token';
		if (@file_put_contents($f, $pt) !== false) {
			@chmod($f, 0600);
		}
	}

	/** 控制器诊断日志（写 safe_dir/dnsapi.log，与 dnsapi.apilog 同文件；静默失败） */
	private function ctllog($msg)
	{
		$f = rtrim($GLOBALS['safe_dir'], '/') . '/dnsapi.log';
		@file_put_contents($f, date('Y-m-d H:i:s') . ' acmessl[' . $msg . "]\n", FILE_APPEND | LOCK_EX);
	}

	/** 域名/泛域名格式校验 */
	private function checkDomainInput($domain)
	{
		return (bool) preg_match('/^(\*\.)?[a-z0-9\-\.\x80-\xff]+\.[a-z]{2,}$/iu', strtolower(trim($domain, '.')));
	}

	// ───────────────────────── 页面 ─────────────────────────

	/**
	 * 证书列表页（含 DNS 凭据入口与节流状态横幅）
	 */
	public function index()
	{
		$blocked = apicall('acmethrottle', 'listBlocked', array());
		$this->_tpl->assign('certs', $this->getCertList());
		$this->_tpl->assign('blocked', is_array($blocked) ? $blocked : array());
		$this->_tpl->assign('cred_count', count(apicall('dnsapi', 'listCredentials', array())));
		return $this->_tpl->fetch('acmessl/list.html');
	}

	/**
	 * 申请证书表单页（HTTP-01 旧入口，保留）
	 */
	public function applyForm()
	{
		$this->_tpl->assign('vhosts', $this->getVhostList());
		return $this->_tpl->fetch('acmessl/apply.html');
	}

	/**
	 * DNS 凭据管理页：11 家 provider 表单 + 凭据列表 + 节流解除
	 */
	public function dnsconfig()
	{
		$this->_tpl->assign('providers', apicall('dnsapi', 'providers', array()));
		$this->_tpl->assign('creds', apicall('dnsapi', 'listCredentials', array()));
		$this->_tpl->assign('blocked', apicall('acmethrottle', 'listBlocked', array()));
		$this->_tpl->assign('fail_limit', apicall('acmethrottle', 'failLimit', array()));
		$this->_tpl->assign('vhosts', $this->getVhostList());
		return $this->_tpl->fetch('acmessl/dnsconfig.html');
	}

	/**
	 * DNS TXT 记录管理页
	 */
	public function dns()
	{
		$this->_tpl->assign('creds', apicall('dnsapi', 'listCredentials', array()));
		return $this->_tpl->fetch('acmessl/dns.html');
	}

	// ───────────────────────── DNS 凭据 AJAX ─────────────────────────

	public function dnsconfigSave()
	{
		$data = array(
			'id'         => intval($_REQUEST['id']),
			'provider'   => trim($_REQUEST['provider']),
			'name'       => isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '',
			'domain'     => isset($_REQUEST['domain']) ? trim($_REQUEST['domain']) : '',
			'remark'     => isset($_REQUEST['remark']) ? trim($_REQUEST['remark']) : '',
			'credential' => isset($_REQUEST['fields']) && is_array($_REQUEST['fields']) ? $_REQUEST['fields'] : array(),
		);
		$ret = apicall('dnsapi', 'saveCredential', array($data));
		if ($ret['code'] == 0) {
			audit('acmessl', 'dnsconfigSave', $data['provider'] . ':' . $data['name'], 'success', array('id' => $ret['id'], 'edit' => $data['id'] > 0));
		}
		$this->outJson($ret);
	}

	public function dnsconfigDelete()
	{
		$ret = apicall('dnsapi', 'deleteCredential', array(intval($_REQUEST['id'])));
		$this->outJson($ret);
	}

	public function dnsconfigTest()
	{
		$ret = apicall('dnsapi', 'testCredential', array(intval($_REQUEST['id'])));
		$this->outJson($ret);
	}

	public function dnsconfigDefault()
	{
		$ret = apicall('dnsapi', 'setDefault', array(intval($_REQUEST['id'])));
		$this->outJson($ret);
	}

	/** provider 表单字段定义（JSON，供前端动态渲染） */
	public function dnsconfigProviders()
	{
		$this->outJson(array('code' => 0, 'providers' => apicall('dnsapi', 'providers', array())));
	}

	// ───────────────────────── TXT 管理 AJAX ─────────────────────────

	public function dnsList()
	{
		$cred = intval($_REQUEST['cred']);
		$domain = trim($_REQUEST['domain']);
		$filter = isset($_REQUEST['host']) ? trim($_REQUEST['host']) : '';
		if ($cred <= 0) {
			$this->outJson(array('code' => 1, 'msg' => '请选择 DNS 凭据', 'rows' => array()));
		}
		$ret = apicall('dnsapi', 'listRecords', array($cred, $domain, $filter));
		$this->outJson($ret);
	}

	public function dnsRecordDel()
	{
		$cred = intval($_REQUEST['cred']);
		$zone = trim($_REQUEST['zone']);
		$rid = trim($_REQUEST['record_id']);
		$ret = apicall('dnsapi', 'deleteRecord', array($cred, $zone, $rid));
		if ($ret['code'] == 0) {
			audit('acmessl', 'dnsRecordDel', $zone . '#' . $rid, 'success', array('cred' => $cred));
		}
		$this->outJson($ret);
	}

	// ───────────────────────── 节流 AJAX ─────────────────────────

	public function throttleStatus()
	{
		$domain = isset($_REQUEST['domain']) ? trim($_REQUEST['domain']) : '';
		if ($domain === '') {
			$this->outJson(array('code' => 0, 'blocked' => apicall('acmethrottle', 'listBlocked', array())));
		}
		$s = apicall('acmethrottle', 'status', array($domain));
		$s['code'] = 0;
		$this->outJson($s);
	}

	public function throttleClear()
	{
		$domain = isset($_REQUEST['domain']) ? trim($_REQUEST['domain']) : '';
		$ret = apicall('acmethrottle', 'clear', array($domain));
		$this->outJson($ret);
	}

	// ───────────────────────── 申请 / 续签 ─────────────────────────

	/**
	 * CA 参数解析：白名单值直接进 --server，staging 映射为测试目录 URL
	 */
	private function caServerArg($ca)
	{
		$ca = strtolower(trim($ca));
		switch ($ca) {
		case 'zerossl': return 'zerossl';
		case 'google':  return 'google';
		case 'staging': return 'https://acme-staging-v02.api.letsencrypt.org/directory';
		case 'letsencrypt':
		case '':
		default:        return 'letsencrypt';
		}
	}

	/**
	 * 执行证书申请
	 *
	 * 模式：
	 *   mode=http（默认，兼容旧行为）：webroot HTTP-01，单域名；
	 *   mode=dns：DNS-01，支持 *.泛域名；凭据来自 dns_credential；
	 *             签发前先做 DNS 连通性预检（防把 CA 配额烧在坏凭据上），
	 *             同域连续失败 3 次触发 acme_order_guard 节流。
	 */
	public function apply()
	{
		$this->ctllog('apply enter');
		$domain = strtolower(trim($_REQUEST['domain']));
		$vhostName = trim($_REQUEST['vhost']);
		$mode = isset($_REQUEST['mode']) && $_REQUEST['mode'] === 'dns' ? 'dns' : 'http';
		$ca = $this->caServerArg(isset($_REQUEST['ca']) ? $_REQUEST['ca'] : '');
		$email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
		$keytype = isset($_REQUEST['keytype']) && $_REQUEST['keytype'] === 'rsa' ? 'rsa' : 'ecc';
		$include_root = !isset($_REQUEST['no_root']) || $_REQUEST['no_root'] != '1';
		$cred_id = intval($_REQUEST['cred']);

		if (empty($domain) || !$this->checkDomainInput($domain)) {
			$this->failOut('域名格式不正确（支持 *.example.com 泛域名）');
		}
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$this->failOut('邮箱格式不正确');
		}

		// 节流判定（泛域名按裸域计，与 guard 行主键一致）
		$guard_domain = preg_replace('/^\*\./', '', $domain);
		$this->ctllog('guard-check ' . $guard_domain);
		$gate = apicall('acmethrottle', 'guard', array($guard_domain));
		if ($gate !== true) {
			audit('acmessl', 'applyThrottled', $guard_domain, 'deny', array('fail_count' => $gate['fail_count'], 'wait' => $gate['wait']));
			$this->outJson($gate); // 验收点：第 4 次 apply 返回 throttle JSON
		}

		if ($mode === 'http') {
			$this->applyHttp($domain, $vhostName, $ca, $keytype, $email);
			return; // 不会到达
		}

		$this->applyDns($domain, $vhostName, $ca, $keytype, $email, $cred_id, $include_root, $guard_domain);
	}

	private function failOut($msg)
	{
		if ($this->wantJson()) {
			$this->outJson(array('code' => 1, 'msg' => $msg));
		}
		$this->alertGo($msg, 'back');
	}

	/** 失败记录（申请未完成 CA 流程也算一次失败尝试，进入节流计数） */
	private function failAttempt($guard_domain, $msg, $provider = '', $ca = '')
	{
		apicall('acmethrottle', 'recordFail', array($guard_domain, $msg, $provider, $ca));
		$s = apicall('acmethrottle', 'status', array($guard_domain));
		if ($this->wantJson()) {
			$this->outJson(array(
				'code' => 1,
				'msg' => $msg,
				'fail_count' => intval($s['fail_count']),
				'limit' => apicall('acmethrottle', 'failLimit', array()),
				'blocked' => !empty($s['blocked']),
			));
		}
		$this->alertGo($msg, 'back');
	}

	/**
	 * HTTP-01 申请（历史路径，行为对齐旧版；补齐节流计数）
	 */
	private function applyHttp($domain, $vhostName, $ca, $keytype, $email)
	{
		$guard_domain = preg_replace('/^\*\./', '', $domain);
		if (strpos($domain, '*') !== false) {
			$this->failAttempt($guard_domain, 'HTTP-01 不支持泛域名，请改用 DNS-01 验证');
		}
		$user = array();
		if (!empty($vhostName)) {
			$user = daocall('vhost', 'getVhost', array($vhostName));
		}
		if (empty($user) || empty($user['doc_root'])) {
			$user = $this->findVhostByDomain($domain);
		}
		if (empty($user) || empty($user['doc_root'])) {
			$this->failAttempt($guard_domain, '未找到该域名绑定的虚拟主机，请先在域名绑定中添加该域名。', 'http', $ca);
		}

		// HTTP-01 webroot 必须是站点实际托管的 web 根目录：doc_root + subdir（默认 /wwwroot）。
		// 否则 acme.sh 会把 challenge 写到 doc_root/.well-known/acme-challenge，
		// 而 :80 虚拟主机实际托管的是 doc_root/wwwroot，Let's Encrypt 去取时返回 404。
		$subdir = (!empty($user['subdir'])) ? $user['subdir'] : '/wwwroot';
		$webroot = rtrim($user['doc_root'], '/') . '/' . ltrim($subdir, '/');
		if (!$this->ensureDocRoot($webroot, $user['uid'], $user['gid'])) {
			$this->failAttempt($guard_domain, '无法创建网站目录：' . $webroot, 'http', $ca);
		}

		$args = '--issue -d ' . escapeshellarg($domain)
			. ' --webroot ' . escapeshellarg($webroot)
			. ' --home ' . escapeshellarg($this->acmeHome)
			. ' --server ' . escapeshellarg($ca);
		if ($keytype === 'ecc') {
			$args .= ' --keylength ec-256';
		}
		if ($email !== '') {
			$args .= ' --accountemail ' . escapeshellarg($email);
		}
		$result = $this->execAcme($args);
		$certDir = $this->getCertDir($domain);
		if (empty($certDir)) {
			$this->failAttempt($guard_domain, '证书申请失败：' . substr(preg_replace('/\s+/u', ' ', $result['output']), -300), 'http', $ca);
		}
		apicall('acmethrottle', 'recordSuccess', array($guard_domain));
		$deployMsg = '';
		if (!empty($vhostName)) {
			$dr = $this->deployCertToVhost($domain, $vhostName);
			$deployMsg = ($dr === true) ? '，并已自动应用到网站 ' . $vhostName : '，但自动部署失败：' . $dr;
		}
		audit('acmessl', 'apply', $domain, 'success', array('mode' => 'http', 'ca' => $ca));
		if ($this->wantJson()) {
			$this->outJson(array('code' => 0, 'msg' => '证书申请成功' . $deployMsg, 'domain' => $domain));
		}
		$this->alertGo('证书申请成功' . $deployMsg, '?c=acmessl&a=index');
	}

	/**
	 * DNS-01 申请（含泛域名）
	 */
	private function applyDns($domain, $vhostName, $ca, $keytype, $email, $cred_id, $include_root, $guard_domain)
	{
		$provider = '';
		$t_all = microtime(true);
		$step = function ($name) use (&$t_all) {
			$n = round((microtime(true) - $t_all) * 1000);
			@error_log("acmessl[applyDns] {$name} +{$n}ms");
			$f = rtrim($GLOBALS['safe_dir'], '/') . '/dnsapi.log';
			@file_put_contents($f, date('Y-m-d H:i:s') . " acmessl[applyDns] {$name} +{$n}ms\n", FILE_APPEND | LOCK_EX);
		};
		$step('enter');
		// 1) 选凭据
		$cred = apicall('dnsapi', 'pickCredentialForDomain', array($guard_domain, $cred_id));
		if (!$cred) {
			$this->failAttempt($guard_domain, '未找到可用的 DNS 凭据，请先在「DNS 凭据」页添加（' . $guard_domain . ' 无匹配且无默认凭据）。', '', $ca);
		}
		$step('pickCredential');
		$provider = $cred['provider'];

		// 2) DNS 连通性预检：坏凭据直接失败，不消耗 CA 订单配额。
		//    这一步会真实组装并发起服务商 API 签名请求（面板日志可见端点），
		//    假 key 时返回结构化失败（验收点）。
		$probe = apicall('dnsapi', 'testCredential', array(intval($cred['id'])));
		$step('probe');
		if ($probe['code'] != 0) {
			$this->failAttempt($guard_domain, 'DNS 预检失败：' . $probe['msg'], $provider, $ca);
		}

		// 3) 组装 --dns 插件与环境变量
		$providers = apicall('dnsapi', 'providers', array());
		if (!isset($providers[$provider])) {
			$this->failAttempt($guard_domain, '凭据平台不受支持：' . $provider, $provider, $ca);
		}
		$plugin = $providers[$provider]['plugin'];
		$env = array();
		$bridge = in_array($provider, array('xinnet', 'dnsla'), true) || !$providers[$provider]['env'];
		if ($bridge) {
			// acme.sh 3.1.4 无 dns_xinnet / dns_dnsla 原生插件（远端容器实测），
			// 用面板 bridge：写插件脚本 + 一次性 hook token 回调 dnsapi.api.php
			if (!apicall('dnsapi', 'installBridgePlugins', array($this->acmeHome))) {
				$this->failAttempt($guard_domain, '无法写入 acme.sh bridge 插件目录（' . $this->acmeHome . '/dnsapi）', $provider, $ca);
			}
			$token = apicall('dnsapi', 'issueHookToken', array(intval($cred['id']), $guard_domain));
			if (!$token) {
				$this->failAttempt($guard_domain, '无法生成 DNS hook token', $provider, $ca);
			}
			// 长效续签 token 落盘（供 acme.sh 每日 cron --cron 桥接续签，
			// 等价原生插件把 key 持久化进 account.conf）
			$this->writePermToken($cred['id']);
			$env = array(
				'EP_ACME_API'   => $this->dnsapiFile(),
				'EP_ACME_PHP'   => '/usr/bin/php74',
				'EP_ACME_HOOK_TOKEN' => $token,
				'EP_ACME_HOOK_LOG'   => $this->acmeHome . '/acme_hook.log',
			);
		}
		else {
			$nenv = apicall('dnsapi', 'credentialEnv', array(intval($cred['id'])));
			if ($nenv === false) {
				$this->failAttempt($guard_domain, '凭据解密失败或必填项缺失', $provider, $ca);
			}
			$env = $nenv;
		}

		// 4) issue 命令（泛域名：*.x 为主 + 可选裸域 SAN；TXT 都落在 _acme-challenge.x）
		$args = '--issue -d ' . escapeshellarg($domain);
		$root = preg_replace('/^\*\./', '', $domain);
		if ($root !== $domain && $include_root) {
			$args .= ' -d ' . escapeshellarg($root);
		}
		$args .= ' --dns ' . escapeshellarg($plugin)
			. ' --home ' . escapeshellarg($this->acmeHome)
			. ' --server ' . escapeshellarg($ca);
		if ($keytype === 'ecc') {
			$args .= ' --keylength ec-256';
		}
		if ($email !== '') {
			$args .= ' --accountemail ' . escapeshellarg($email);
		}
		$result = $this->execAcme($args, $env);

		// 5) 判定与善后
		$certDir = $this->getCertDir($domain);
		if ($certDir === false && $root !== $domain && $include_root) {
			$certDir = $this->getCertDir($root);
		}
		if (empty($certDir)) {
			// bridge 场景收尾丢弃 token
			if ($bridge && !empty($env['EP_ACME_HOOK_TOKEN'])) {
				apicall('dnsapi', 'dropHookToken', array($env['EP_ACME_HOOK_TOKEN']));
			}
			$this->failAttempt($guard_domain, 'DNS-01 证书申请失败（' . $provider . '）：'
				. substr(preg_replace('/\s+/u', ' ', $result['output']), -300), $provider, $ca);
		}
		if ($bridge && !empty($env['EP_ACME_HOOK_TOKEN'])) {
			apicall('dnsapi', 'dropHookToken', array($env['EP_ACME_HOOK_TOKEN']));
		}
		apicall('acmethrottle', 'recordSuccess', array($guard_domain));
		$issued_domain = $this->getRealDomainFromCertDir($certDir);

		$deployMsg = '';
		$deploy_target = $vhostName != '' ? $vhostName : '';
		if ($deploy_target === '') {
			// 泛域名自动匹配裸域 vhost
			$uv = $this->findVhostByDomain($root);
			if ($uv) {
				$deploy_target = $uv['name'];
			}
		}
		if ($deploy_target !== '') {
			$dr = $this->deployCertToVhost($issued_domain, $deploy_target);
			$deployMsg = ($dr === true) ? '，并已自动应用到网站 ' . $deploy_target : '，但自动部署失败：' . $dr;
		}
		audit('acmessl', 'apply', $issued_domain, 'success', array('mode' => 'dns', 'ca' => $ca, 'provider' => $provider, 'cred' => intval($cred['id'])));
		if ($this->wantJson()) {
			$this->outJson(array('code' => 0, 'msg' => '证书申请成功' . $deployMsg, 'domain' => $issued_domain));
		}
		$this->alertGo('证书申请成功' . $deployMsg, '?c=acmessl&a=index');
	}

	private function dnsapiFile()
	{
		// 容器内绝对路径：framework/api/dnsapi.api.php（SYS_ROOT=admin 入口定义）
		if (defined('SYS_ROOT')) {
			return SYS_ROOT . '/api/dnsapi.api.php';
		}
		return '/vhs/kangle/nodewww/webftp/framework/api/dnsapi.api.php';
	}

	/**
	 * 续签（列表页按钮）：优先按证书目录里记录的 DNS 插件走 DNS-01，
	 * 凭据按域名重新选取；HTTP-01 证书则 --renew 用旧 webroot。
	 */
	public function renew()
	{
		$domain = strtolower(trim($_REQUEST['domain']));
		if (empty($domain) || !$this->checkDomainInput($domain)) {
			$this->failOut('参数错误');
		}
		$guard_domain = preg_replace('/^\*\./', '', $domain);
		$this->ctllog('guard-check ' . $guard_domain);
		$gate = apicall('acmethrottle', 'guard', array($guard_domain));
		if ($gate !== true) {
			$this->outJson($gate);
		}
		$certDir = $this->getCertDir($domain);
		if (!$certDir) {
			$this->failOut('证书不存在：' . $domain);
		}
		$real = $this->getRealDomainFromCertDir($certDir);
		$conf = @parse_ini_file($certDir . '/' . $real . '.conf');
		$api = isset($conf['Le_API']) ? $conf['Le_API'] : 'letsencrypt';
		$server = escapeshellarg($api);
		$args = '--renew -d ' . escapeshellarg($real) . ' --home ' . escapeshellarg($this->acmeHome)
			. ' --server ' . $server . ' --force';
		$env = array();
		// DNS-01 证书（目录存在且曾以 --dns 签发）需要重新注入凭据环境；
		// 原生插件的 key 已存 acme.sh account.conf，但面板仍注入最新凭据防轮换失效。
		$cred = apicall('dnsapi', 'pickCredentialForDomain', array($guard_domain, intval($_REQUEST['cred'])));
		if ($cred) {
			$providers = apicall('dnsapi', 'providers', array());
			$p = $cred['provider'];
			$bridge = !isset($providers[$p]) || empty($providers[$p]['env']);
			if ($bridge) {
				apicall('dnsapi', 'installBridgePlugins', array($this->acmeHome));
				$token = apicall('dnsapi', 'issueHookToken', array(intval($cred['id']), $guard_domain));
				$this->writePermToken($cred['id']);
				if ($token) {
					$env = array(
						'EP_ACME_API' => $this->dnsapiFile(),
						'EP_ACME_PHP' => '/usr/bin/php74',
						'EP_ACME_HOOK_TOKEN' => $token,
						'EP_ACME_HOOK_LOG' => $this->acmeHome . '/acme_hook.log',
					);
				}
			}
			else {
				$nenv = apicall('dnsapi', 'credentialEnv', array(intval($cred['id'])));
				if (is_array($nenv)) {
					$env = $nenv;
				}
			}
		}
		$result = $this->execAcme($args, $env);
		if ($result['code'] !== 0) {
			if (!empty($env['EP_ACME_HOOK_TOKEN'])) {
				apicall('dnsapi', 'dropHookToken', array($env['EP_ACME_HOOK_TOKEN']));
			}
			$this->failAttempt($guard_domain, '续签失败：' . substr(preg_replace('/\s+/u', ' ', $result['output']), -300), $cred ? $cred['provider'] : '', $api);
		}
		if (!empty($env['EP_ACME_HOOK_TOKEN'])) {
			apicall('dnsapi', 'dropHookToken', array($env['EP_ACME_HOOK_TOKEN']));
		}
		apicall('acmethrottle', 'recordSuccess', array($guard_domain));
		// 续签成功后重新部署到原绑定 vhost（若有）
		$msg = '续签成功';
		$uv = $this->findVhostByDomain(preg_replace('/^\*\./', '', $real));
		if ($uv) {
			$dr = $this->deployCertToVhost($real, $uv['name']);
			$msg .= ($dr === true) ? '，已重新部署到 ' . $uv['name'] : '，但部署失败：' . $dr;
		}
		audit('acmessl', 'renew', $real, 'success', array('ca' => $api));
		if ($this->wantJson()) {
			$this->outJson(array('code' => 0, 'msg' => $msg));
		}
		$this->alertGo($msg, '?c=acmessl&a=index');
	}

	/**
	 * 查看证书详情（可复制公钥/私钥）
	 */
	public function view()
	{
		$domain = trim($_GET['domain']);
		$certDir = $this->getCertDir($domain);
		if (!$certDir) {
			$this->alertGo('证书不存在', '?c=acmessl&a=index');
		}

		$realDomain = $this->getRealDomainFromCertDir($certDir);
		$confFile = $certDir . '/' . $realDomain . '.conf';
		$crtFile = $certDir . '/fullchain.cer';
		$keyFile = $certDir . '/' . $realDomain . '.key';

		$crt = @file_get_contents($crtFile);
		$key = @file_get_contents($keyFile);

		$this->_tpl->assign('domain', $realDomain);
		$this->_tpl->assign('certificate', $crt);
		$this->_tpl->assign('certificate_key', $key);
		$this->_tpl->assign('vhosts', $this->getVhostList());
		return $this->_tpl->fetch('acmessl/view.html');
	}

	/**
	 * 确保网站目录存在并拥有正确属主
	 */
	private function ensureDocRoot($docRoot, $uid, $gid)
	{
		$uid = intval($uid);
		$gid = intval($gid);
		if (!is_dir($docRoot)) {
			if (!@mkdir($docRoot, 0750, true)) {
				return false;
			}
		}
		@chown($docRoot, $uid);
		@chgrp($docRoot, $gid);
		return true;
	}

	/**
	 * 将证书部署到指定 vhost（写 ssl.crt/ssl.key、登记数据库、重载 kangle）
	 *
	 * @return bool|string 成功返回 true，失败返回错误描述字符串
	 */
	private function deployCertToVhost($domain, $vhostName)
	{
		$user = daocall('vhost', 'getVhost', array($vhostName));
		if (empty($user) || empty($user['doc_root'])) {
			return '虚拟主机不存在';
		}
		$certDir = $this->getCertDir($domain);
		if (!$certDir) {
			// 泛域名证书按裸域再找一次目录
			$alt = preg_replace('/^\*\./', '', $domain);
			$certDir = $alt !== $domain ? $this->getCertDir($alt) : false;
			if (!$certDir) {
				return '证书文件不存在';
			}
		}
		$realDomain = $this->getRealDomainFromCertDir($certDir);
		$crtFile = $certDir . '/fullchain.cer';
		$keyFile = $certDir . '/' . $realDomain . '.key';
		$certificate = @file_get_contents($crtFile);
		$certificate_key = @file_get_contents($keyFile);
		if (!$certificate || !$certificate_key) {
			return '读取证书失败';
		}
		if (!openssl_x509_read($certificate)) {
			return '证书文件无效';
		}
		if (!openssl_get_privatekey($certificate_key)) {
			return '证书密钥无效';
		}
		if (!openssl_x509_check_private_key($certificate, $certificate_key)) {
			return '证书与密钥不匹配';
		}
		$docRoot = rtrim($user['doc_root'], '/');
		if (!$this->ensureDocRoot($docRoot, $user['uid'], $user['gid'])) {
			return '无法创建网站目录：' . $docRoot;
		}
		change_to_user($user['uid'], $user['gid']);
		$crt_target = $docRoot . '/ssl.crt';
		$key_target = $docRoot . '/ssl.key';
		if (is_file($crt_target) || is_link($crt_target)) @unlink($crt_target);
		if (is_file($key_target) || is_link($key_target)) @unlink($key_target);
		if (false === file_put_contents($crt_target, $certificate)) {
			change_to_super();
			return '写入 ssl.crt 失败';
		}
		if (false === file_put_contents($key_target, $certificate_key)) {
			change_to_super();
			return '写入 ssl.key 失败';
		}
		apicall('vhost', 'setSystemFile', array($vhostName, $docRoot, array('ssl.crt', 'ssl.key')));
		change_to_super();
		$arr = array('certificate' => 'ssl.crt', 'certificate_key' => 'ssl.key');
		$port = isset($user['port']) ? trim($user['port']) : '';
		if ($port === '') {
			$arr['port'] = '80,443s';
		} elseif (strpos($port, '443s') === false) {
			$arr['port'] = $port . ',443s';
		}
		daocall('vhost', 'updateVhost', array($vhostName, $arr));
		apicall('vhost', 'noticeChange', array('localhost', $vhostName));
		notice_cdn_changed();
		return true;
	}

	/**
	 * 应用证书到指定 vhost（使用系统现有 SSL 机制：ssl.crt / ssl.key）
	 */
	public function applyToVhost()
	{
		$domain = trim($_REQUEST['domain']);
		$vhostName = trim($_REQUEST['vhost']);
		if (empty($domain) || empty($vhostName)) {
			$this->failOut('参数错误');
		}
		$dr = $this->deployCertToVhost($domain, $vhostName);
		if ($dr !== true) {
			$this->failOut('部署失败：' . $dr);
		}
		audit('acmessl', 'applyToVhost', $domain, 'success', array('vhost' => $vhostName));
		if ($this->wantJson()) {
			$this->outJson(array('code' => 0, 'msg' => '证书已应用到网站 ' . $vhostName));
		}
		$this->alertGo('证书已应用到网站 ' . $vhostName, '?c=acmessl&a=index');
	}

	/**
	 * 删除证书（同时清理节流 guard 行）
	 */
	public function remove()
	{
		$domain = trim($_REQUEST['domain']);
		if (empty($domain)) {
			$this->failOut('参数错误');
		}
		// 同时尝试删除 ECC 与普通目录
		$result = $this->execAcme('--remove -d ' . escapeshellarg($domain) . ' --home ' . escapeshellarg($this->acmeHome));
		if ($result['code'] !== 0) {
			$this->execAcme('--remove -d ' . escapeshellarg($domain) . '_ecc --home ' . escapeshellarg($this->acmeHome));
		}
		$guard_domain = preg_replace('/^\*\./', '', strtolower($domain));
		apicall('acmethrottle', 'clear', array($guard_domain));
		audit('acmessl', 'remove', $domain, 'success', array());
		if ($this->wantJson()) {
			$this->outJson(array('code' => 0, 'msg' => '删除成功'));
		}
		$this->alertGo('删除成功', '?c=acmessl&a=index');
	}

	/**
	 * 获取 vhost 列表（JSON，用于前端联动）
	 */
	public function getVhosts()
	{
		$vhosts = $this->getVhostList();
		exit(json_encode(array('vhosts' => $vhosts ? $vhosts : array())));
	}
}
