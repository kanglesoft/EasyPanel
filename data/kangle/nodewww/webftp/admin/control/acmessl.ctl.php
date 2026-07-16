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
	 * 从 conf 文件中提取域名（Le_Domain 可能带 _ecc 目录但实际域名不带）
	 */
	private function getDomainFromConf($confFile)
	{
		$conf = @parse_ini_file($confFile);
		if (!empty($conf['Le_Domain'])) {
			return $conf['Le_Domain'];
		}
		return basename(dirname($confFile));
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
			$confFile = $domainDir . '/' . $entry . '.conf';
			$crtFile  = $domainDir . '/fullchain.cer';
			$keyFile  = $domainDir . '/' . $entry . '.key';
			if (!file_exists($confFile) || !file_exists($crtFile) || !file_exists($keyFile)) {
				continue;
			}
			$conf = @parse_ini_file($confFile);
			$domain = $this->getDomainFromConf($confFile);
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
		} else {
			$user = $this->findVhostByDomain($domain);
		}

		if (empty($user) || empty($user['doc_root'])) {
			exit("<script>alert('未找到该域名绑定的虚拟主机，请先在域名绑定中添加该域名。');history.go(-1);</script>");
		}

		$webroot = rtrim($user['doc_root'], '/');
		if (!$this->ensureDocRoot($webroot, $user['uid'], $user['gid'])) {
			exit("<script>alert('无法创建网站目录：{$webroot}');history.go(-1);</script>");
		}

		// 使用 acme.sh HTTP-01 验证（webroot 模式），默认 CA 为 Let's Encrypt
		$args = '--issue -d ' . escapeshellarg($domain)
			. ' --webroot ' . escapeshellarg($webroot)
			. ' --home ' . escapeshellarg($this->acmeHome)
			. ' --server letsencrypt';
		$result = $this->execAcme($args);

		if ($result['code'] !== 0) {
			$msg = '证书申请失败：' . str_replace(array("'", "\r", "\n"), array("\\'", '', '\\n'), substr($result['output'], -800));
			exit("<script>alert('{$msg}');history.go(-1);</script>");
		}

		exit("<script>alert('证书申请成功');location.href='?c=acmessl&a=index';</script>");
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

		$confFile = $certDir . '/' . basename($certDir) . '.conf';
		$realDomain = file_exists($confFile) ? $this->getDomainFromConf($confFile) : $domain;
		$crtFile = $certDir . '/fullchain.cer';
		$keyFile = $certDir . '/' . basename($certDir) . '.key';

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
	 * 应用证书到指定 vhost（使用系统现有 SSL 机制：ssl.crt / ssl.key）
	 */
	public function applyToVhost()
	{
		$domain = trim($_REQUEST['domain']);
		$vhostName = trim($_REQUEST['vhost']);

		if (empty($domain) || empty($vhostName)) {
			exit("<script>alert('参数错误');history.go(-1);</script>");
		}

		$user = daocall('vhost', 'getVhost', array($vhostName));
		if (empty($user) || empty($user['doc_root'])) {
			exit("<script>alert('虚拟主机不存在');history.go(-1);</script>");
		}

		$certDir = $this->getCertDir($domain);
		if (!$certDir) {
			exit("<script>alert('证书文件不存在');history.go(-1);</script>");
		}

		$crtFile = $certDir . '/fullchain.cer';
		$keyFile = $certDir . '/' . basename($certDir) . '.key';

		// 读取证书并校验匹配
		$certificate = @file_get_contents($crtFile);
		$certificate_key = @file_get_contents($keyFile);
		if (!$certificate || !$certificate_key) {
			exit("<script>alert('读取证书失败');history.go(-1);</script>");
		}
		if (!openssl_x509_read($certificate)) {
			exit("<script>alert('证书文件无效');history.go(-1);</script>");
		}
		if (!openssl_get_privatekey($certificate_key)) {
			exit("<script>alert('证书密钥无效');history.go(-1);</script>");
		}
		if (!openssl_x509_check_private_key($certificate, $certificate_key)) {
			exit("<script>alert('证书与密钥不匹配');history.go(-1);</script>");
		}

		$docRoot = rtrim($user['doc_root'], '/');
		if (!$this->ensureDocRoot($docRoot, $user['uid'], $user['gid'])) {
			exit("<script>alert('无法创建网站目录：{$docRoot}');history.go(-1);</script>");
		}

		// 写入 vhost 网站目录，并更新数据库
		change_to_user($user['uid'], $user['gid']);
		$crt_target = $docRoot . '/ssl.crt';
		$key_target = $docRoot . '/ssl.key';

		// 如果目标文件已存在（即使属主是 root），先删除再写入，避免当前用户无写权限
		if (is_file($crt_target) || is_link($crt_target)) @unlink($crt_target);
		if (is_file($key_target) || is_link($key_target)) @unlink($key_target);

		if (false === file_put_contents($crt_target, $certificate)) {
			change_to_super();
			exit("<script>alert('写入 ssl.crt 失败');history.go(-1);</script>");
		}
		if (false === file_put_contents($key_target, $certificate_key)) {
			change_to_super();
			exit("<script>alert('写入 ssl.key 失败');history.go(-1);</script>");
		}

		apicall('vhost', 'setSystemFile', array($vhostName, $docRoot, array('ssl.crt', 'ssl.key')));
		change_to_super();

		$arr = array('certificate' => 'ssl.crt', 'certificate_key' => 'ssl.key');
		// 如果虚拟主机端口未包含 443s，自动追加，使其能响应 HTTPS
		$port = isset($user['port']) ? trim($user['port']) : '';
		if ($port === '') {
			$arr['port'] = '80,443s';
		}
		elseif (strpos($port, '443s') === false) {
			$arr['port'] = $port . ',443s';
		}
		daocall('vhost', 'updateVhost', array($vhostName, $arr));
		apicall('vhost', 'noticeChange', array('localhost', $vhostName));
		notice_cdn_changed();

		exit("<script>alert('证书已应用到网站 {$vhostName}');location.href='?c=acmessl&a=index';</script>");
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
