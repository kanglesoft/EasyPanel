<?php
needRole('admin');

/**
 * acme.sh 免费 SSL 证书管理
 *
 * 通过 acme.sh（已随 install.sh 集成在 kangle 容器 /root/.acme.sh）
 * 以 HTTP-01 验证方式自动申请 Let's Encrypt 证书，并可直接应用到系统内
 * 已存在的网站（vhost）。
 */
class AcmesslControl extends control
{
	private $acmeHome = '/root/.acme.sh';
	private $acmeBin;

	public function __construct()
	{
		parent::__construct();
		$this->acmeBin = $this->acmeHome . '/acme.sh';
	}

	/**
	 * 执行 acme.sh 命令并返回输出
	 */
	private function execAcme($args)
	{
		if (!file_exists($this->acmeBin)) {
			return array('code' => -1, 'output' => 'acme.sh 未安装，请检查 install.sh 是否执行成功。');
		}
		$cmd = 'HOME=' . escapeshellarg($this->acmeHome) . ' ' . escapeshellcmd($this->acmeBin) . ' ' . $args . ' 2>&1';
		exec($cmd, $output, $code);
		return array('code' => $code, 'output' => implode("\n", $output));
	}

	/**
	 * 查找 acme.sh 为某个域名生成的证书目录（兼容 ECC 后缀 _ecc）
	 */
	private function getCertDir($domain)
	{
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
				'crt_file'  => $crtFile,
				'key_file'  => $keyFile,
				'Le_CertCreateTimeStr' => isset($conf['Le_CertCreateTimeStr']) ? $conf['Le_CertCreateTimeStr'] : '',
				'Le_NextRenewTimeStr'  => isset($conf['Le_NextRenewTimeStr']) ? $conf['Le_NextRenewTimeStr'] : '',
			);
		}
		closedir($dh);
		return $certs;
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

	/**
	 * 证书列表页
	 */
	public function index()
	{
		$this->_tpl->assign('certs', $this->getCertList());
		return $this->_tpl->fetch('acmessl/list.html');
	}

	/**
	 * 申请证书表单页
	 */
	public function applyForm()
	{
		$this->_tpl->assign('vhosts', $this->getVhostList());
		return $this->_tpl->fetch('acmessl/apply.html');
	}

	/**
	 * 执行证书申请
	 */
	public function apply()
	{
		$domain = strtolower(trim($_REQUEST['domain']));
		$vhostName = trim($_REQUEST['vhost']);

		if (empty($domain)) {
			exit("<script>alert('域名不能为空');history.go(-1);</script>");
		}
		if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]+$/i', $domain)) {
			exit("<script>alert('域名格式不正确');history.go(-1);</script>");
		}

		// 优先使用用户选择的 vhost，否则自动根据域名匹配
		if (!empty($vhostName)) {
			$user = daocall('vhost', 'getVhost', array($vhostName));
		}
		if (empty($user) || empty($user['doc_root'])) {
			$user = $this->findVhostByDomain($domain);
		}

		if (empty($user) || empty($user['doc_root'])) {
			exit("<script>alert('未找到该域名绑定的虚拟主机，请先在域名绑定中添加该域名。');history.go(-1);</script>");
		}

		// HTTP-01 webroot 必须是站点实际托管的 web 根目录：doc_root + subdir（默认 /wwwroot）。
		// 否则 acme.sh 会把 challenge 写到 doc_root/.well-known/acme-challenge，
		// 而 :80 虚拟主机实际托管的是 doc_root/wwwroot，Let's Encrypt 去取时返回 404。
		$subdir = (!empty($user['subdir'])) ? $user['subdir'] : '/wwwroot';
		$webroot = rtrim($user['doc_root'], '/') . '/' . ltrim($subdir, '/');
		if (!$this->ensureDocRoot($webroot, $user['uid'], $user['gid'])) {
			exit("<script>alert('无法创建网站目录：{$webroot}');history.go(-1);</script>");
		}

		// 使用 acme.sh HTTP-01 验证（webroot 模式），默认 CA 为 Let's Encrypt
		$args = '--issue -d ' . escapeshellarg($domain)
			. ' --webroot ' . escapeshellarg($webroot)
			. ' --home ' . escapeshellarg($this->acmeHome)
			. ' --server letsencrypt';
		$result = $this->execAcme($args);
		$certDir = $this->getCertDir($domain);
		if (empty($certDir)) {
			$msg = '证书申请失败：' . str_replace(array("'", "\r", "\n"), array("\\'", '', '\\n'), substr($result['output'], -800));
			exit("<script>alert('{$msg}');history.go(-1);</script>");
		}
		$deployMsg = '';
		if (!empty($vhostName)) {
			$dr = $this->deployCertToVhost($domain, $vhostName);
			$deployMsg = ($dr === true) ? '，并已自动应用到网站 ' . $vhostName : '，但自动部署失败：' . $dr;
		}
		exit("<script>alert('证书申请成功{$deployMsg}');location.href='?c=acmessl&a=index';</script>");
	}

	/**
	 * 查看证书详情（可复制公钥/私钥）
	 */
	public function view()
	{
		$domain = trim($_GET['domain']);
		$certDir = $this->getCertDir($domain);
		if (!$certDir) {
			exit("<script>alert('证书不存在');location.href='?c=acmessl&a=index';</script>");
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
			return '证书文件不存在';
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
			exit("<script>alert('参数错误');history.go(-1);</script>");
		}
		$dr = $this->deployCertToVhost($domain, $vhostName);
		if ($dr !== true) {
			exit("<script>alert('部署失败：" . $dr . "');history.go(-1);</script>");
		}
		exit("<script>alert('证书已应用到网站 " . $vhostName . "');location.href='?c=acmessl&a=index';</script>");
	}

	/**
	 * 删除证书
	 */
	public function remove()
	{
		$domain = trim($_REQUEST['domain']);
		if (empty($domain)) {
			exit("<script>alert('参数错误');history.go(-1);</script>");
		}
		// 同时尝试删除 ECC 与普通目录
		$result = $this->execAcme('--remove -d ' . escapeshellarg($domain) . ' --home ' . escapeshellarg($this->acmeHome));
		if ($result['code'] !== 0) {
			$this->execAcme('--remove -d ' . escapeshellarg($domain) . '_ecc --home ' . escapeshellarg($this->acmeHome));
		}
		$msg = '删除成功';
		exit("<script>alert('{$msg}');location.href='?c=acmessl&a=index';</script>");
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
