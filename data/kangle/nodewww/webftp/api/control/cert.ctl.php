<?php
/**
 * cert.ctl.php —— 原生证书 API（skey 签名 JSON 进出）
 *
 * 定位（60 号文档 #13 / 50 号文档 §2 反向教训）：外部 IDC / 自动化系统过去只能
 * 模拟 vhost/?c=ssl 表单并抓 alert("成功") 文本判断结果，极脆弱。本控制器在
 * api/（skey 签名区，仿 cdn.ctl.php 按文件名自动发现）提供原生 JSON 端点：
 *
 *   ?c=cert&a=apply      签发（HTTP-01 / DNS-01 泛域名 / 手动导入）并部署到虚拟主机
 *   ?c=cert&a=deploy     把已有证书（acme.sh 目录或内联 crt/key）部署到虚拟主机
 *   ?c=cert&a=get        查询某虚拟主机当前已部署证书的结构化信息
 *   ?c=cert&a=list_cert  列出 acme.sh 全部证书（SAN/签发方/到期；'list' 是 PHP
 *                        保留字不能作方法名；内核 ACL 白名单 a=list 前缀匹配仍命中）
 *   ?c=cert&a=remove     删除 acme.sh 证书记录（--remove，并清节流 guard 行）
 *   ?c=cert&a=test       通道自检（版本/时间/acme.sh 可用性）
 *
 * 鉴权：api/index.php:2-21 verificationSkey()（s = md5(a + skey + r)）统一完成，
 * 本文件不重复验签；csrf_guard 对 api 入口放行（runtime.php:1239-1245）。
 * 前提：setting 表存在 skey 行（admin → 多节点/通信安全码页保存），否则一切 403。
 *
 * 输出：构造函数强制 $_REQUEST['json']=1，所有响应都是含 "result" 状态码的
 * JSON（whm_return 体系，api/index.php:37-54）。状态码约定：
 *   200 成功 | 400 参数/预检失败 | 404 资源不存在 | 429 ACME 节流拦截 |
 *   500 CA 签发/DNS API/部署执行失败 | 403 验签失败（index.php，本类不会走到）
 *
 * 输入：GET/表单或 application/json 请求体（POST 时 body 并入 $_REQUEST，
 * 见 mergeJsonBody()）。**强烈建议外部系统用 JSON body**：本会话对远端
 * 47.76.172.3:3312 实测内核 mark_param 词表（config.xml:88，dir/link/system/
 * mkdir/copy/touch/unlink 等）会扫描 GET 参数与 urlencoded/multipart/text 请求体
 * 并返回内核 HTML 403，而 application/json 请求体不被扫描——PEM 证书内容
 * 含 base64 随机段，撞词概率高，只有 JSON body 能可靠送达 crt/key。
 *
 * 依赖单元（本工作树已合并）：framework/api/dnsapi.api.php（11 家 DNS 凭据
 * 存储 + bridge hook）、framework/api/acmethrottle.api.php（同域连续失败节流）；
 * 行为对齐 admin 端 acmessl.ctl.php（webroot=doc_root+subdir、三重 PEM 校验、
 * change_to_user 写 ssl.crt/ssl.key、setSystemFile 0600、补 443s 端口、
 * noticeChange + notice_cdn_changed 同步链、acme.sh 3.1.4 参数）。
 *
 * 审计：终态一律走全局 audit()（runtime.php:1464，module=cert，失败静默）。
 * 红线：不回显 vhost 行原始数据（passwd/gid），只回 name/port/domain/证书摘要。
 */
class CertControl extends Control
{
	private $acmeHome = '/root/.acme.sh';
	private $acmeBin;

	/** CA 白名单 → acme.sh --server 取值（对齐 acmessl.caServerArg，另含 buypass；
	 *  https:// 开头的完整 ACME directory URL 也允许，供自定义 CA/EAB 服务商） */
	private $ca_map = array(
		'letsencrypt'      => 'letsencrypt',
		'zerossl'          => 'zerossl',
		'google'           => 'google',
		'buypass'          => 'buypass',
		'staging'          => 'https://acme-staging-v02.api.letsencrypt.org/directory',
		'letsencrypt_test' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
	);

	/** 请求体并入 $_REQUEST 时拒绝覆盖的保留键 */
	private $reserved_keys = array('c', 'a', 'r', 's', 'json', 'fmt', 'PHPSESSID');

	public function __construct()
	{
		parent::__construct();
		$this->acmeBin = $this->acmeHome . '/acme.sh';

		// 机器通道一律 JSON 输出（whm_return 判定读 $_REQUEST['json']）。
		$_REQUEST['json'] = 1;

		// application/json 请求体并入 $_REQUEST（PEM/凭据安全通道，见类注释实测）。
		$this->mergeJsonBody();
	}

	// ───────────────────────── 对外动作 ─────────────────────────

	/**
	 * 签发或导入证书并部署到虚拟主机
	 *
	 * 参数：
	 *   domain   逗号分隔域名列表（支持 "*.example.com"）；手动导入模式可空
	 *   vhost    可选；缺省按域名在各站点绑定里自动匹配
	 *   crt,key  可选成对（PEM 原文或其 base64）；提供即手动导入模式（不碰 CA）
	 *   mode     http|dns；泛域名强制 dns；给了 cred/provider/dns_api 视为 dns
	 *   cred     可选；dns_credential 记录 id（DNS-01 凭据选择）
	 *   dns_api + dns_env  可选成对；内联 acme.sh 插件名与凭据 JSON 对象
	 *                      （{"DP_Id":"x","DP_Key":"y"}），绕开凭据存储时用
	 *   server|ca  可选；白名单 CA（letsencrypt/zerossl/google/buypass/staging）
	 *              或 https:// 完整 ACME directory URL；默认 letsencrypt
	 *   email      可选；--accountemail
	 *   keytype    ecc|rsa；默认 ecc（acme.sh 3.1.4 --keylength ec-256/2048）
	 *   no_root    可选 1；泛域名签发时不附带裸域 SAN
	 *   force      可选 1；acme.sh --force 重签
	 *   eab_kid, eab_hmac  可选；外部账户绑定（--eab-kid/--eab-hmac-key，
	 *              acme.sh 3.1.4 选项名已在容器内实测：acme.sh:8035-8036）
	 */
	public function apply()
	{
		$domainRaw = strtolower(trim(strval($_REQUEST['domain'])));
		$vhostName = trim(strval($_REQUEST['vhost']));
		$crtRaw = trim(strval($_REQUEST['crt']));
		$keyRaw = trim(strval($_REQUEST['key']));
		$domains = $this->parseDomains($domainRaw);
		if ($domains === false) {
			$this->logAudit('apply', $domainRaw, 'error', array('reason' => 'invalid_domain'));
			whm_return(400, array('msg' => 'domain 格式不正确（支持逗号分隔与 *.example.com）', 'error' => 'invalid_domain'));
		}

		// ---- 手动导入模式（crt/key 成对即入口，不消耗 CA 配额） ----
		if ($crtRaw !== '' || $keyRaw !== '') {
			$crt = $this->normalizePem($crtRaw);
			$key = $this->normalizePem($keyRaw);
			if ($crt === '' || $key === '') {
				$this->logAudit('apply', $vhostName . '|' . $domainRaw, 'error', array('reason' => 'bad_pem'));
				whm_return(400, array('msg' => 'crt 与 key 必须成对提供，且为 PEM 原文或其 base64', 'error' => 'bad_pem'));
			}
			$vh = $this->resolveVhost($vhostName, $domains);
			if ($vh === false || $vh === null) {
				$this->logAudit('apply', $vhostName . '|' . $domainRaw, 'error', array('reason' => 'vhost_not_found'));
				whm_return(400, array('msg' => '虚拟主机不存在或未找到承载站点，请传 vhost 或先绑定域名', 'error' => 'vhost_not_found'));
			}
			$dr = $this->deployPair($vh, $crt, $key);
			if ($dr !== true) {
				$this->logAudit('apply', $vh['name'], 'error', array('reason' => 'deploy_failed', 'msg' => $dr));
				whm_return(500, array('msg' => '证书部署失败: ' . $dr, 'error' => 'deploy_failed'));
			}
			$this->logAudit('apply', $vh['name'], 'success', array('mode' => 'manual'));
			whm_return(200, array(
				'msg' => 'success',
				'mode' => 'manual',
				'vhost' => $vh['name'],
				'cert' => $this->certInfo($crt),
			));
		}

		// ---- ACME 模式 ----
		if (empty($domains)) {
			$this->logAudit('apply', $vhostName, 'error', array('reason' => 'domain_empty'));
			whm_return(400, array('msg' => 'domain 不能为空（或提供 crt+key 走手动导入）', 'error' => 'domain_empty'));
		}
		$primary = $this->primaryDomain($domains);
		$guard = preg_replace('/^\*\./', '', $primary);
		$hasWildcard = ($primary !== $guard);

		// 节流闸门（对齐 acmessl.apply：连续失败 3 次/1h 窗口内拒绝再发起）
		$gate = $this->throttleGuard($guard);
		if ($gate !== true) {
			$this->logAudit('apply', $guard, 'deny', array('reason' => 'throttled', 'fail_count' => $gate['fail_count'], 'wait' => $gate['wait']));
			whm_return(429, array_merge(array('msg' => $gate['msg'], 'error' => 'throttled'), array(
				'fail_count' => $gate['fail_count'], 'limit' => $gate['limit'], 'wait' => $gate['wait'],
			)));
		}

		// 模式判定
		$credId = intval($_REQUEST['cred']);
		$providerKey = trim(strval($_REQUEST['provider']));
		$dnsApi = trim(strval($_REQUEST['dns_api']));
		$dnsEnvRaw = strval($_REQUEST['dns_env']);
		$mode = trim(strtolower(strval($_REQUEST['mode'])));
		if ($mode !== 'http' && $mode !== 'dns') {
			$mode = ($hasWildcard || $dnsApi !== '' || $dnsEnvRaw !== '' || $credId > 0 || $providerKey !== '') ? 'dns' : 'http';
		}
		if ($hasWildcard && $mode !== 'dns') {
			$this->throttleFail($guard, 'HTTP-01 不支持泛域名', '', 'mode');
			$this->logAudit('apply', $guard, 'error', array('reason' => 'wildcard_needs_dns01'));
			whm_return(400, array('msg' => '泛域名证书必须走 DNS-01（mode=dns，配 cred 或 dns_api+dns_env）', 'domain' => $guard, 'error' => 'wildcard_needs_dns01'));
		}

		// CA / keytype / email 校验
		$server = $this->resolveServer(trim(strval($_REQUEST['server'])) !== '' ? trim(strval($_REQUEST['server'])) : trim(strval($_REQUEST['ca'])));
		$email = trim(strval($_REQUEST['email']));
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$this->logAudit('apply', $guard, 'error', array('reason' => 'bad_email'));
			whm_return(400, array('msg' => 'email 格式不正确', 'error' => 'bad_email'));
		}
		$keytype = (trim(strval($_REQUEST['keytype'])) === 'rsa') ? 'rsa' : 'ecc';
		$eabKid = trim(strval($_REQUEST['eab_kid']));
		$eabHmac = trim(strval($_REQUEST['eab_hmac']));
		if (($eabKid !== '' && !preg_match('/^[A-Za-z0-9_\-\.=+]{1,128}$/', $eabKid)) || ($eabHmac !== '' && !preg_match('/^[A-Za-z0-9_\-\.=+]{1,256}$/', $eabHmac))) {
			$this->logAudit('apply', $guard, 'error', array('reason' => 'bad_eab'));
			whm_return(400, array('msg' => 'eab_kid/eab_hmac 含非法字符', 'error' => 'bad_eab'));
		}

		$env = array();
		$bridgeToken = '';
		if ($mode === 'dns') {
			$dns = $this->buildDnsEnv($guard, $credId, $providerKey, $dnsApi, $dnsEnvRaw);
			if (isset($dns['error'])) {
				$this->throttleFail($guard, $dns['msg'], $dns['provider'], $server);
				$this->logAudit('apply', $guard, 'error', array('reason' => $dns['error'], 'provider' => $dns['provider']));
				whm_return($dns['status'], array('msg' => $dns['msg'], 'domain' => $guard, 'error' => $dns['error']));
			}
			$env = $dns['env'];
			$plugin = $dns['plugin'];
			$bridgeToken = $dns['hook_token'];
		}
		else {
			// HTTP-01 预检：无解析记录直接结构化失败（验收点），不打 CA 不烧配额
			foreach ($domains as $d) {
				$base = preg_replace('/^\*\./', '', $d);
				if (!$this->dnsExists($base)) {
					$this->throttleFail($guard, 'no DNS record for ' . $base, 'http', $server);
					$this->logAudit('apply', $base, 'error', array('reason' => 'no_dns_record'));
					whm_return(400, array(
						'msg' => '域名 ' . $base . ' 无 DNS 记录(A/AAAA/CNAME)，HTTP-01 无法验证',
						'domain' => $base,
						'error' => 'no_dns_record',
					));
				}
			}
		}

		// vhost 定位：显式 vhost 不存在 → 提前失败（两种模式都不能把签发烧在坏参数上）；
		// dns 模式未匹配到 vhost 只签发不部署（尽力而为）；http 模式必须有 webroot（硬失败）
		$vh = $this->resolveVhost($vhostName, $domains);
		if ($vh === false || ($mode === 'http' && $vh === null)) {
			$this->throttleFail($guard, 'vhost not found', 'http', $server);
			$this->logAudit('apply', $vhostName . '|' . $guard, 'error', array('reason' => 'vhost_not_found'));
			whm_return(400, array('msg' => '未找到域名 ' . $guard . ' 绑定的虚拟主机，请传 vhost 或先在站点中绑定域名', 'error' => 'vhost_not_found'));
		}

		// 组装 --issue
		$args = '';
		foreach ($domains as $d) {
			$args .= ' -d ' . escapeshellarg($d);
		}
		// 泛域名默认附带裸域 SAN（对齐 acmessl.include_root 行为，no_root=1 关闭）
		if ($hasWildcard && $mode === 'dns' && strval($_REQUEST['no_root']) !== '1' && !in_array($guard, $domains, true)) {
			$args .= ' -d ' . escapeshellarg($guard);
		}
		if ($mode === 'dns') {
			$args .= ' --dns ' . escapeshellarg($plugin);
		}
		else {
			// webroot 必须是站点真实托管目录 doc_root+subdir（默认 /wwwroot），
			// 否则 challenge 落点与 :80 托管目录错位导致 LE 404（acmessl.ctl.php:484-488 同源教训）
			$subdir = (!empty($vh['subdir'])) ? $vh['subdir'] : '/wwwroot';
			$webroot = rtrim($vh['doc_root'], '/') . '/' . ltrim($subdir, '/');
			if (!$this->ensureDir($webroot, $vh['uid'], $vh['gid'])) {
				$this->throttleFail($guard, 'cannot create webroot ' . $webroot, 'http', $server);
				$this->logAudit('apply', $vh['name'], 'error', array('reason' => 'webroot_failed', 'webroot' => $webroot));
				whm_return(500, array('msg' => '无法创建网站目录: ' . $webroot, 'error' => 'webroot_failed'));
			}
			$args .= ' --webroot ' . escapeshellarg($webroot);
		}
		$args .= ' --server ' . escapeshellarg($server);
		$args .= ' --keylength ' . ($keytype === 'rsa' ? '2048' : 'ec-256');
		if ($email !== '') {
			$args .= ' --accountemail ' . escapeshellarg($email);
		}
		if ($eabKid !== '' && $eabHmac !== '') {
			$args .= ' --eab-kid ' . escapeshellarg($eabKid) . ' --eab-hmac-key ' . escapeshellarg($eabHmac);
		}
		if (intval($_REQUEST['force']) == 1) {
			$args .= ' --force';
		}
		$args .= ' --home ' . escapeshellarg($this->acmeHome);

		$result = $this->runAcme('--issue' . $args, $env);
		$certDir = $this->findCertDir($domains);

		// bridge 场景无论成败都丢弃一次性 hook token（对齐 acmessl.applyDns 收尾）
		if ($bridgeToken !== '') {
			$this->dnsapiCall('dropHookToken', array($bridgeToken));
		}

		if ($certDir === false) {
			$err = 'acme.sh exit=' . intval($result['code']) . ': ' . $this->tail($result['output'], 300);
			$this->throttleFail($guard, $err, ($mode === 'dns') ? $providerKey : 'http', $server);
			$this->logAudit('apply', $guard, 'error', array('reason' => 'issue_failed', 'mode' => $mode, 'code' => intval($result['code'])));
			whm_return(500, array('msg' => '证书签发失败: ' . $err, 'domain' => $guard, 'error' => 'issue_failed'));
		}
		$this->throttleSuccess($guard);
		$issued = $this->realDomainFromDir($certDir);

		// 部署（dns 模式无匹配 vhost 时只签发、部署留待 deploy）
		$deployed = false;
		$deployMsg = '';
		if ($vh !== false && $vh !== null) {
			$crt = @file_get_contents($certDir . '/fullchain.cer');
			$key = @file_get_contents($certDir . '/' . $issued . '.key');
			if (!$crt || !$key) {
				$this->logAudit('apply', $vh['name'], 'error', array('reason' => 'cert_read_failed', 'dir' => $certDir));
				whm_return(500, array('msg' => '签发成功但读取证书文件失败: ' . $certDir, 'domain' => $issued, 'error' => 'cert_read_failed'));
			}
			$dr = $this->deployPair($vh, $crt, $key);
			if ($dr !== true) {
				// 证书已到手：结构化报部署失败 + 目录位置，不吞掉签发结果
				$this->logAudit('apply', $vh['name'], 'error', array('reason' => 'deploy_failed', 'msg' => $dr, 'dir' => $certDir));
				whm_return(500, array('msg' => '证书已签发但部署失败: ' . $dr, 'domain' => $issued, 'cert_dir' => $certDir, 'error' => 'deploy_failed'));
			}
			$deployed = true;
		}
		else {
			$deployMsg = 'no vhost matched, cert stored at ' . $certDir;
		}

		$this->logAudit('apply', $deployed ? $vh['name'] : $issued, 'success', array(
			'mode' => $mode, 'domain' => $issued, 'server' => $server, 'keytype' => $keytype,
		));
		$ret = array(
			'msg' => 'success',
			'mode' => $mode,
			'domain' => $issued,
			'domains' => $domains,
			'server' => $server,
			'keytype' => $keytype,
			'deployed' => $deployed ? 1 : 0,
			'cert' => $this->certInfo(@file_get_contents($certDir . '/fullchain.cer')),
		);
		if ($deployed) {
			$ret['vhost'] = $vh['name'];
		}
		else {
			$ret['warning'] = $deployMsg;
			$ret['cert_dir'] = $certDir;
		}
		whm_return(200, $ret);
	}

	/**
	 * 部署已有证书到虚拟主机（acme.sh 已签发证书按域名取，或内联 crt/key）
	 *
	 * 参数：vhost（或 domain 自动匹配）；domain 与 crt/key 二选一（内联优先）
	 */
	public function deploy()
	{
		$vhostName = trim(strval($_REQUEST['vhost']));
		$domainRaw = strtolower(trim(strval($_REQUEST['domain'])));
		$crtRaw = trim(strval($_REQUEST['crt']));
		$keyRaw = trim(strval($_REQUEST['key']));

		$domains = $this->parseDomains($domainRaw);
		if ($domains === false) {
			$this->logAudit('deploy', $domainRaw, 'error', array('reason' => 'invalid_domain'));
			whm_return(400, array('msg' => 'domain 格式不正确', 'error' => 'invalid_domain'));
		}
		if ($domainRaw === '' && $crtRaw === '' && $keyRaw === '') {
			$this->logAudit('deploy', $vhostName, 'error', array('reason' => 'param_empty'));
			whm_return(400, array('msg' => '需要 domain（已签发的 acme 证书）或 crt+key（内联证书）', 'error' => 'param_empty'));
		}

		$vh = $this->resolveVhost($vhostName, $domains);
		if ($vh === false || $vh === null) {
			$this->logAudit('deploy', $vhostName . '|' . $domainRaw, 'error', array('reason' => 'vhost_not_found'));
			whm_return(400, array('msg' => '虚拟主机不存在或未找到承载站点', 'error' => 'vhost_not_found'));
		}

		if ($crtRaw !== '' || $keyRaw !== '') {
			$crt = $this->normalizePem($crtRaw);
			$key = $this->normalizePem($keyRaw);
			if ($crt === '' || $key === '') {
				$this->logAudit('deploy', $vh['name'], 'error', array('reason' => 'bad_pem'));
				whm_return(400, array('msg' => 'crt 与 key 必须成对提供，且为 PEM 原文或其 base64', 'error' => 'bad_pem'));
			}
		}
		else {
			$certDir = $this->findCertDir($domains);
			if ($certDir === false) {
				$this->logAudit('deploy', $domainRaw, 'error', array('reason' => 'cert_not_found'));
				whm_return(404, array('msg' => 'acme.sh 中没有该域名的证书，请先 a=apply', 'domain' => $domainRaw, 'error' => 'cert_not_found'));
			}
			$real = $this->realDomainFromDir($certDir);
			$crt = @file_get_contents($certDir . '/fullchain.cer');
			$key = @file_get_contents($certDir . '/' . $real . '.key');
			if (!$crt || !$key) {
				$this->logAudit('deploy', $domainRaw, 'error', array('reason' => 'cert_read_failed'));
				whm_return(500, array('msg' => '读取证书文件失败: ' . $certDir, 'error' => 'cert_read_failed'));
			}
		}

		$dr = $this->deployPair($vh, $crt, $key);
		if ($dr !== true) {
			$this->logAudit('deploy', $vh['name'], 'error', array('reason' => 'deploy_failed', 'msg' => $dr));
			whm_return(500, array('msg' => '证书部署失败: ' . $dr, 'error' => 'deploy_failed'));
		}
		$this->logAudit('deploy', $vh['name'], 'success', array('domain' => $domainRaw));
		whm_return(200, array(
			'msg' => 'success',
			'vhost' => $vh['name'],
			'domain' => $domainRaw,
			'cert' => $this->certInfo($crt),
		));
	}

	/**
	 * 查询某虚拟主机当前已部署证书的结构化信息（替代外部抓 HTML 判断）
	 * 参数：vhost 或 domain 至少一个
	 */
	public function get()
	{
		$vhostName = trim(strval($_REQUEST['vhost']));
		$domainRaw = strtolower(trim(strval($_REQUEST['domain'])));

		$domains = $this->parseDomains($domainRaw);
		if ($domains === false) {
			whm_return(400, array('msg' => 'domain 格式不正确', 'error' => 'invalid_domain'));
		}
		if ($vhostName === '' && empty($domains)) {
			whm_return(400, array('msg' => '需要 vhost 或 domain 参数', 'error' => 'param_empty'));
		}

		$vh = $this->resolveVhost($vhostName, $domains);
		if ($vh === false || $vh === null) {
			whm_return(404, array('msg' => '虚拟主机不存在或未找到承载站点', 'error' => 'vhost_not_found'));
		}

		$crtPem = @file_get_contents(rtrim($vh['doc_root'], '/') . '/ssl.crt');
		if (!$crtPem) {
			whm_return(404, array('msg' => '该虚拟主机尚未部署 SSL 证书', 'vhost' => $vh['name'], 'error' => 'cert_not_deployed'));
		}
		$domains2 = array();
		$ds = daocall('vhostinfo', 'getDomain', array($vh['name']));
		if (is_array($ds)) {
			foreach ($ds as $d) {
				$domains2[] = $d['name'];
			}
		}
		whm_return(200, array(
			'vhost' => $vh['name'],
			'port' => isset($vh['port']) ? strval($vh['port']) : '',
			'domains' => $domains2,
			'cert' => $this->certInfo($crtPem),
		));
	}

	/**
	 * 列出 acme.sh 全部证书（含到期/续期时间与 x509 解析）
	 */
	public function list_cert()
	{
		$certs = array();
		if (is_dir($this->acmeHome)) {
			$dh = @opendir($this->acmeHome);
			if ($dh) {
				$skip = array('account.conf', 'ca', 'dnsapi', 'deploy', 'notify', 'log', 'src');
				while (($entry = readdir($dh)) !== false) {
					if ($entry == '.' || $entry == '..' || in_array($entry, $skip, true)) {
						continue;
					}
					$certDir = $this->acmeHome . '/' . $entry;
					if (!is_dir($certDir)) {
						continue;
					}
					$real = $this->realDomainFromDir($certDir);
					if (!file_exists($certDir . '/fullchain.cer') || !file_exists($certDir . '/' . $real . '.key')) {
						continue;
					}
					$conf = @parse_ini_file($certDir . '/' . $real . '.conf');
					$pem = @file_get_contents($certDir . '/fullchain.cer');
					$certs[] = array(
						'domain' => $real,
						'base_domain' => preg_replace('/^\*\./', '', $real),
						'is_wildcard' => (strpos($real, '*') === 0) ? 1 : 0,
						'issued_at' => (is_array($conf) && isset($conf['Le_CertCreateTimeStr'])) ? $conf['Le_CertCreateTimeStr'] : '',
						'next_renew' => (is_array($conf) && isset($conf['Le_NextRenewTimeStr'])) ? $conf['Le_NextRenewTimeStr'] : '',
						'cert' => $this->certInfo($pem),
					);
				}
				closedir($dh);
			}
		}
		whm_return(200, array('total' => count($certs), 'certs' => $certs));
	}

	/**
	 * 删除 acme.sh 证书记录与文件（--remove，兼容 ECC 目录），并清该域节流行。
	 * 注意：不回滚已部署到站点的 ssl.crt/ssl.key（与 admin 端 acmessl.remove 一致）。
	 */
	public function remove()
	{
		$domain = strtolower(trim(strval($_REQUEST['domain'])));
		$domains = $this->parseDomains($domain);
		if ($domains === false || $domain === '') {
			$this->logAudit('remove', $domain, 'error', array('reason' => 'bad_domain'));
			whm_return(400, array('msg' => 'domain 为空或格式不正确', 'error' => 'bad_domain'));
		}

		if (!file_exists($this->acmeBin)) {
			whm_return(500, array('msg' => 'acme.sh 未安装', 'error' => 'acme_missing'));
		}
		$result = $this->runAcme('--remove -d ' . escapeshellarg($domain) . ' --home ' . escapeshellarg($this->acmeHome), array());
		if ($result['code'] !== 0) {
			// 兼容仅存在 ECC 记录的场景（对齐 acmessl.ctl.php remove）
			$this->runAcme('--remove -d ' . escapeshellarg($domain) . '_ecc --home ' . escapeshellarg($this->acmeHome), array());
		}
		if ($this->getCertDir($domain) !== false) {
			$this->logAudit('remove', $domain, 'error', array('reason' => 'remove_failed', 'code' => intval($result['code'])));
			whm_return(500, array('msg' => '证书删除失败，目录仍存在', 'domain' => $domain, 'error' => 'remove_failed'));
		}
		$guard = preg_replace('/^\*\./', '', $domain);
		$this->throttleClear($guard);
		$this->logAudit('remove', $domain, 'success', array());
		whm_return(200, array('msg' => 'success', 'domain' => $domain));
	}

	/**
	 * 通道自检：验签通了即返回版本/时间/acme.sh 可用性
	 */
	public function test()
	{
		whm_return(200, array(
			'version' => get_number_version(),
			'time' => time(),
			'acme_installed' => file_exists($this->acmeBin) ? 1 : 0,
			'dnsapi_ready' => (class_exists('DnsapiApi') || $this->loadApiQuiet('dnsapi')) ? 1 : 0,
		));
	}

	// ───────────────────────── 内部实现 ─────────────────────────

	/**
	 * application/json 请求体并入 $_REQUEST。
	 * 双重动机：(1) 大体积 PEM 不挤 query string；(2) 本会话实测内核
	 * mark_param 词表只扫描 URL 参数与 urlencoded/multipart/plain 请求体，
	 * 不扫描 JSON 体 —— JSON 是 PEM 能安全抵达 PHP 的唯一通道。
	 * 验签已在 api/index.php:86 完成，此处合并无法影响 s/r/a。
	 */
	private function mergeJsonBody()
	{
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
		if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
			return;
		}
		$ctype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
		if (stripos($ctype, 'application/json') === false) {
			return;
		}
		$raw = @file_get_contents('php://input');
		if ($raw === false || $raw === '' || strlen($raw) > 2097152) {
			return;
		}
		$body = json_decode($raw, true);
		if (!is_array($body)) {
			return;
		}
		foreach ($body as $k => $v) {
			if (!is_string($k) || $k === '' || substr($k, 0, 1) === '_') {
				continue;
			}
			if (in_array(strtolower($k), $this->reserved_keys, true)) {
				continue;
			}
			if (is_scalar($v)) {
				$_REQUEST[$k] = strval($v);
			}
			elseif (is_array($v)) {
				$_REQUEST[$k] = json_encode($v);
			}
		}
	}

	/**
	 * 逗号分隔域名列表；逐个用全局 checkDomain()（runtime.php:649，允许
	 * *. 前缀）。返回 array；非法返回 false；全空返回 array()。
	 */
	private function parseDomains($raw)
	{
		$raw = strtolower(trim(strval($raw), " \t\n\r\0\x0B."));
		if ($raw === '') {
			return array();
		}
		$ok = array();
		foreach (explode(',', $raw) as $d) {
			$d = trim($d, " \t\n\r\0\x0B.");
			if ($d === '') {
				continue;
			}
			if (!function_exists('checkDomain') || !checkDomain($d)) {
				return false;
			}
			$ok[] = $d;
		}
		return $ok;
	}

	private function primaryDomain($domains)
	{
		foreach ($domains as $d) {
			if (strpos($d, '*') === false) {
				return $d;
			}
		}
		if (!empty($domains)) {
			return preg_replace('/^\*\./', '', $domains[0]);
		}
		return '';
	}

	private function resolveServer($ca)
	{
		if ($ca === '') {
			return 'letsencrypt';
		}
		$ca = strtolower($ca);
		if (isset($this->ca_map[$ca])) {
			return $this->ca_map[$ca];
		}
		// 自定义 ACME directory：仅允许无凭据嵌入的 https URL
		if (preg_match('#^https://[a-z0-9.\-]+(:[0-9]{2,5})?/[a-z0-9_/.\-]*$#i', $ca)) {
			return $ca;
		}
		return 'letsencrypt';
	}

	private function dnsExists($domain)
	{
		if (!function_exists('checkdnsrr')) {
			return true; // 函数被禁用时不误杀，交给 acme.sh 实际验证
		}
		if (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA') || @checkdnsrr($domain, 'CNAME')) {
			return true;
		}
		return false;
	}

	/**
	 * DNS-01 参数装配（对齐 acmessl.applyDns 的 5 步：选凭据→预检→插件/环境变量/
	 * bridge token→交由调用方拼 --issue→调用方收尾 dropHookToken）。
	 *
	 * 优先级：内联 dns_api+dns_env（IDC 自带凭据、绕开存储，仅适用 acme.sh
	 * 原生插件）> cred id / provider / 按域名自动匹配存储凭据（含 xinnet/dnsla
	 * bridge 自动装配 + testCredential 预检，坏凭据不烧 CA 配额）。
	 *
	 * @return array 'env'=>array 'plugin'=>string 'hook_token'=>string
	 *               或 'error'/'status'/'msg'/'provider'
	 */
	private function buildDnsEnv($guard, $credId, $providerKey, $dnsApi, $dnsEnvRaw)
	{
		if ($dnsApi !== '' || $dnsEnvRaw !== '') {
			if ($dnsApi === '' || $dnsEnvRaw === '') {
				return array('status' => 400, 'error' => 'dns_params_partial', 'msg' => '内联模式需同时提供 dns_api 与 dns_env', 'provider' => '');
			}
			if (!preg_match('/^dns_[A-Za-z0-9_]{1,40}$/', $dnsApi)) {
				return array('status' => 400, 'error' => 'bad_dns_api', 'msg' => 'dns_api 应形如 dns_dp / dns_ali / dns_cf', 'provider' => '');
			}
			$arr = json_decode($dnsEnvRaw, true);
			if (!is_array($arr) || empty($arr)) {
				return array('status' => 400, 'error' => 'bad_dns_env', 'msg' => 'dns_env 需为 JSON 对象，如 {"DP_Id":"xx","DP_Key":"yy"}', 'provider' => '');
			}
			$env = array();
			foreach ($arr as $k => $v) {
				if (!is_scalar($v)) {
					continue;
				}
				if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', strval($k))) {
					return array('status' => 400, 'error' => 'bad_dns_env', 'msg' => 'dns_env 键名不是合法环境变量名: ' . $k, 'provider' => '');
				}
				$env[$k] = strval($v);
			}
			if (empty($env)) {
				return array('status' => 400, 'error' => 'bad_dns_env', 'msg' => 'dns_env 无标量值', 'provider' => '');
			}
			return array('env' => $env, 'plugin' => $dnsApi, 'hook_token' => '');
		}

		if (!$this->loadApiQuiet('dnsapi')) {
			return array('status' => 500, 'error' => 'dnsapi_missing', 'msg' => 'DNS 适配层不可用（framework/api/dnsapi.api.php 缺失）', 'provider' => '');
		}

		$cred = $this->dnsapiCall('pickCredentialForDomain', array($guard, $credId));
		if (!$cred) {
			return array(
				'status' => 400,
				'error' => 'no_dns_credential',
				'msg' => '域名 ' . $guard . ' 无匹配的 DNS 凭据且无默认凭据；请在「证书/DNS 凭据」页添加，或传 cred=<id>，或内联 dns_api+dns_env',
				'provider' => '',
			);
		}
		$provider = $cred['provider'];

		// 连通性预检（对齐 acmessl：坏凭据直接失败，不消耗 CA 订单配额）
		$probe = $this->dnsapiCall('testCredential', array(intval($cred['id'])));
		if (!is_array($probe) || intval($probe['code']) != 0) {
			return array(
				'status' => 500,
				'error' => 'dns_probe_failed',
				'msg' => 'DNS 预检失败: ' . (is_array($probe) ? $probe['msg'] : '凭据不可用'),
				'provider' => $provider,
			);
		}

		$providers = $this->dnsapiCall('providers', array());
		if (!is_array($providers) || !isset($providers[$provider])) {
			return array('status' => 500, 'error' => 'provider_unsupported', 'msg' => '凭据平台不受支持: ' . $provider, 'provider' => $provider);
		}
		$plugin = $providers[$provider]['plugin'];
		$hookToken = '';

		if (empty($providers[$provider]['env'])) {
			// bridge 平台（xinnet/dnsla：acme.sh 3.1.4 无原生插件，容器实测）
			if (!$this->dnsapiCall('installBridgePlugins', array($this->acmeHome))) {
				return array('status' => 500, 'error' => 'bridge_install_failed', 'msg' => '无法写入 acme.sh bridge 插件目录 ' . $this->acmeHome . '/dnsapi', 'provider' => $provider);
			}
			$token = $this->dnsapiCall('issueHookToken', array(intval($cred['id']), $guard));
			if (!$token) {
				return array('status' => 500, 'error' => 'hook_token_failed', 'msg' => '无法生成 DNS hook token', 'provider' => $provider);
			}
			$hookToken = $token;
			$env = array(
				'EP_ACME_API'        => defined('SYS_ROOT') ? SYS_ROOT . '/api/dnsapi.api.php' : '/vhs/kangle/nodewww/webftp/framework/api/dnsapi.api.php',
				'EP_ACME_PHP'        => '/usr/bin/php74',
				'EP_ACME_HOOK_TOKEN' => $token,
				'EP_ACME_HOOK_LOG'   => $this->acmeHome . '/acme_hook.log',
			);
		}
		else {
			$env = $this->dnsapiCall('credentialEnv', array(intval($cred['id'])));
			if (!is_array($env) || empty($env)) {
				return array('status' => 500, 'error' => 'credential_decrypt_failed', 'msg' => '凭据解密失败或必填项缺失', 'provider' => $provider);
			}
		}
		return array('env' => $env, 'plugin' => $plugin, 'hook_token' => $hookToken, 'provider' => $provider);
	}

	/** 静默加载 framework/api/*.api.php 并判断类可用（依赖单元缺席时降级而非致命） */
	private function loadApiQuiet($module)
	{
		if (class_exists(ucfirst($module) . 'Api')) {
			return true;
		}
		if (function_exists('load_api')) {
			load_api($module);
		}
		return class_exists(ucfirst($module) . 'Api');
	}

	/** apicall 包装：类不可用时返回 false（依赖单元缺席降级） */
	private function dnsapiCall($method, $args)
	{
		if (!$this->loadApiQuiet('dnsapi')) {
			return false;
		}
		return apicall('dnsapi', $method, $args);
	}

	/** 节流三件套的缺席安全包装 */
	private function throttleGuard($domain)
	{
		if (!$this->loadApiQuiet('acmethrottle')) {
			return true;
		}
		$gate = apicall('acmethrottle', 'guard', array($domain));
		return is_array($gate) ? $gate : true;
	}

	private function throttleFail($domain, $err, $provider, $ca)
	{
		if (!$this->loadApiQuiet('acmethrottle')) {
			return false;
		}
		return apicall('acmethrottle', 'recordFail', array($domain, $err, $provider, $ca));
	}

	private function throttleSuccess($domain)
	{
		if (!$this->loadApiQuiet('acmethrottle')) {
			return false;
		}
		return apicall('acmethrottle', 'recordSuccess', array($domain));
	}

	private function throttleClear($domain)
	{
		if (!$this->loadApiQuiet('acmethrottle')) {
			return false;
		}
		return apicall('acmethrottle', 'clear', array($domain));
	}

	/**
	 * 定位 vhost：显式名 > 按域名在各站点绑定里精确匹配（含泛域名对裸域）。
	 * @return array|null|false 找到返回行；显式 vhost 不存在返回 false；未找到 null
	 */
	private function resolveVhost($vhostName, $domains)
	{
		if ($vhostName !== '') {
			$vh = daocall('vhost', 'getVhost', array($vhostName));
			if (!empty($vh) && !empty($vh['doc_root'])) {
				return $vh;
			}
			return false;
		}
		if (empty($domains)) {
			return null;
		}
		$vhs = daocall('vhost', 'listVhostNotcdn', array());
		if (empty($vhs) || !is_array($vhs)) {
			return null;
		}
		$wanted = array();
		foreach ($domains as $d) {
			$wanted[$d] = 1;
			$bare = preg_replace('/^\*\./', '', $d);
			$wanted[$bare] = 1;
			$wanted['www.' . $bare] = 1;
		}
		foreach ($vhs as $v) {
			if (empty($v['doc_root'])) {
				continue;
			}
			$ds = daocall('vhostinfo', 'getDomain', array($v['name']));
			if (!is_array($ds)) {
				continue;
			}
			foreach ($ds as $d) {
				if (isset($wanted[strtolower(trim(strval($d['name'])))])) {
					return $v;
				}
			}
		}
		return null;
	}

	/**
	 * 执行 acme.sh（转义方式对齐 acmessl.execAcme：变量名白名单 + escapeshellarg）
	 */
	private function runAcme($args, $env = array())
	{
		if (!file_exists($this->acmeBin)) {
			return array('code' => -1, 'output' => 'acme.sh 未安装，请检查 install.sh 是否执行成功。');
		}
		$envstr = '';
		foreach ($env as $k => $v) {
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
				continue;
			}
			$envstr .= $k . '=' . escapeshellarg(strval($v)) . ' ';
		}
		$cmd = 'HOME=' . escapeshellarg($this->acmeHome) . ' ' . $envstr . escapeshellcmd($this->acmeBin) . ' ' . $args . ' 2>&1';
		@set_time_limit(3600);
		exec($cmd, $output, $code);
		return array('code' => $code, 'output' => implode("\n", $output));
	}

	/** acme.sh 证书目录（兼容 ECC 后缀 _ecc；行为对齐 acmessl.getCertDir） */
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

	/** 域名列表逐个找证书目录；泛域名回退裸域（对齐 acmessl.applyDns 593-596） */
	private function findCertDir($domains)
	{
		foreach ($domains as $d) {
			$dir = $this->getCertDir($d);
			if ($dir !== false) {
				return $dir;
			}
			$bare = preg_replace('/^\*\./', '', $d);
			if ($bare !== $d) {
				$dir = $this->getCertDir($bare);
				if ($dir !== false) {
					return $dir;
				}
			}
		}
		return false;
	}

	/** ECC 目录名带 _ecc 但目录内 conf/key 文件名是真实域名（acmessl 同源实现） */
	private function realDomainFromDir($certDir)
	{
		$dirName = basename(rtrim($certDir, '/'));
		$real = preg_replace('/_ecc$/', '', $dirName);
		$confFile = $certDir . '/' . $real . '.conf';
		if (file_exists($confFile)) {
			$conf = @parse_ini_file($confFile);
			if (is_array($conf) && !empty($conf['Le_Domain'])) {
				return $conf['Le_Domain'];
			}
		}
		foreach (glob($certDir . '/*.conf') as $cf) {
			$conf = @parse_ini_file($cf);
			if (is_array($conf) && !empty($conf['Le_Domain'])) {
				return $conf['Le_Domain'];
			}
		}
		return $real;
	}

	private function ensureDir($dir, $uid, $gid)
	{
		$uid = intval($uid);
		$gid = intval($gid);
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0750, true)) {
				return false;
			}
		}
		@chown($dir, $uid);
		@chgrp($dir, $gid);
		return true;
	}

	/** PEM 原文优先，否则尝试 base64（IDC 常 base64 传 PEM）；都不像 PEM 返回 '' */
	private function normalizePem($pem)
	{
		$pem = strval($pem);
		if ($pem === '') {
			return '';
		}
		if (strpos($pem, '-----BEGIN') !== false) {
			return $pem;
		}
		$dec = base64_decode(preg_replace('/\s+/', '', $pem), true);
		if ($dec !== false && strpos($dec, '-----BEGIN') !== false) {
			return $dec;
		}
		return '';
	}

	/**
	 * 部署证书对到 vhost —— 与 admin 端 acmessl.deployCertToVhost 同路径同顺序：
	 * 三重 PEM 校验 → change_to_user 写 ssl.crt/ssl.key → setSystemFile(0600) →
	 * 登记 certificate 字段并补 443s 端口 → noticeChange 重载 →
	 * notice_cdn_changed($vhost)（显式传参：api 上下文无 vhost 角色，
	 * 靠 getrole 会静默 no-op；60 号 #9 同步链入口）。
	 *
	 * @return true|string 成功 true；失败中文原因
	 */
	private function deployPair($vh, $certificate, $certificate_key)
	{
		if (!openssl_x509_read($certificate)) {
			return '证书内容无效（openssl_x509_read 失败）';
		}
		if (!openssl_get_privatekey($certificate_key)) {
			return '私钥内容无效';
		}
		if (!openssl_x509_check_private_key($certificate, $certificate_key)) {
			return '证书与私钥不匹配';
		}
		$docRoot = rtrim($vh['doc_root'], '/');
		if (!$this->ensureDir($docRoot, $vh['uid'], $vh['gid'])) {
			return '无法创建网站目录: ' . $docRoot;
		}
		change_to_user($vh['uid'], $vh['gid']);
		$crt_target = $docRoot . '/ssl.crt';
		$key_target = $docRoot . '/ssl.key';
		if (is_file($crt_target) || is_link($crt_target)) {
			@unlink($crt_target);
		}
		if (is_file($key_target) || is_link($key_target)) {
			@unlink($key_target);
		}
		if (false === file_put_contents($crt_target, $certificate)) {
			change_to_super();
			return '写入 ssl.crt 失败';
		}
		if (false === file_put_contents($key_target, $certificate_key)) {
			change_to_super();
			return '写入 ssl.key 失败';
		}
		apicall('vhost', 'setSystemFile', array($vh['name'], $docRoot, array('ssl.crt', 'ssl.key')));
		change_to_super();
		$arr = array('certificate' => 'ssl.crt', 'certificate_key' => 'ssl.key');
		$port = isset($vh['port']) ? trim(strval($vh['port'])) : '';
		if ($port === '') {
			$arr['port'] = '80,443s';
		}
		elseif (strpos($port, '443s') === false) {
			$arr['port'] = $port . ',443s';
		}
		daocall('vhost', 'updateVhost', array($vh['name'], $arr));
		if (apicall('vhost', 'noticeChange', array('localhost', $vh['name'])) === false) {
			return 'kangle 重载失败（证书已落盘，稍后可重试）';
		}
		notice_cdn_changed($vh['name']);
		return true;
	}

	/** 结构化证书信息（品牌/SAN/有效期/剩余天数），替代文本抓取 */
	private function certInfo($pem)
	{
		$parsed = @openssl_x509_parse(strval($pem));
		if (!$parsed) {
			return null;
		}
		$san = array();
		if (isset($parsed['extensions']['subjectAltName'])) {
			foreach (explode(',', $parsed['extensions']['subjectAltName']) as $line) {
				$san[] = trim(preg_replace('/^(DNS|dNSName):/i', '', trim($line)));
			}
		}
		elseif (isset($parsed['subject']['CN'])) {
			$san[] = $parsed['subject']['CN'];
		}
		$notAfter = isset($parsed['validTo_time_t']) ? intval($parsed['validTo_time_t']) : 0;
		$notBefore = isset($parsed['validFrom_time_t']) ? intval($parsed['validFrom_time_t']) : 0;
		return array(
			'subject' => isset($parsed['subject']['CN']) ? $parsed['subject']['CN'] : '',
			'issuer' => isset($parsed['issuer']['CN']) ? $parsed['issuer']['CN'] : '',
			'san' => $san,
			'not_before' => $notBefore,
			'not_after' => $notAfter,
			'days_left' => ($notAfter > 0) ? intval(($notAfter - time()) / 86400) : -1,
			'expired' => ($notAfter > 0 && $notAfter < time()) ? 1 : 0,
		);
	}

	/** 输出截尾 + 引号/换行清洗（acme.sh 日志回显限长） */
	private function tail($text, $len)
	{
		$text = preg_replace('/\s+/u', ' ', strval($text));
		$text = str_replace("'", '', $text);
		$len = intval($len);
		if (strlen($text) > $len) {
			return substr($text, -$len);
		}
		return $text;
	}

	/** 审计写入口（module=cert）；audit() 自身失败静默 */
	private function logAudit($action, $target, $result, $detail = array())
	{
		$detail['channel'] = 'api_cert';
		$target = substr(strval($target), 0, 480);
		if (function_exists('audit')) {
			audit('cert', $action, $target, $result, $detail);
		}
	}
}

?>
