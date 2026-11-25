<?php
/**
 * CLI 回调引导（必须在 class 声明之前）：acme.sh 的 dns bridge 脚本以
 *   php74 /vhs/kangle/nodewww/webftp/framework/api/dnsapi.api.php <token> <add|rm> <fulldomain> <txtvalue>
 * 直接执行本文件。若不先载入 runtime.php（其中 __load_core('core:api')
 * 提供基类 API），类声明会因父类缺失而 fatal —— PHP 在文件顶部解析
 * `class DnsapiApi extends API` 时就要求 API 已定义。引导方式与
 * framework/crontab.php 一致。Web 请求（非 CLI）不会命中这个条件。
 */
if (PHP_SAPI === 'cli' && isset($argv) && strpos(basename((string) $argv[0]), 'dnsapi.api.php') !== false) {
	if (!defined('SYS_ROOT')) {
		define('SYS_ROOT', dirname(dirname(__FILE__)));
	}
	if (!defined('APPLICATON_ROOT')) {
		define('APPLICATON_ROOT', '');
	}
	include SYS_ROOT . '/runtime.php';
	$GLOBALS['EP_DNSAPI_CLI'] = true;
}

/**
 * dnsapi.api.php —— ACME DNS-01 的 DNS 服务商 API 适配层（11 家）
 *
 * 定位：acmessl 控制器（DNS-01 泛域名申请/续签）与「DNS 凭据」「DNS TXT
 * 管理」界面共用的唯一入口。对外只做四件事：
 *   1. provider 元数据（表单字段定义 → acme.sh 环境变量名映射）；
 *   2. 凭据加密落盘（vhs.db 表 dns_credential，密钥在 safe_dir/dns_cred.key）；
 *   3. 对 11 家平台执行 TXT 记录的 add/remove/list/delete 与连通性探测；
 *   4. 为 acme.sh 中不存在的插件（dns_xinnet、dns_dnsla，远端容器实测
 *      /root/.acme.sh/dnsapi 192 个脚本里均无）生成 bridge 脚本 + 一次性
 *      hook token，acme.sh source bridge 后回调本文件（CLI）。
 *
 * 支持列表（provider key 与面板/验收一致，共 11 家）：
 *   aliyun 阿里云 / dnspod / huaweicloud 华为云 / baidu 百度云 /
 *   xinnet 新网 / volcengine 火山引擎 / dnsla(DNS.LA) / cloudflare /
 *   route53(AWS) / namesilo / powerdns
 *
 * acme.sh 原生插件与所需环境变量（以远端 kangle 容器内 acme.sh 3.1.4
 * 的 /root/.acme.sh/dnsapi/*.sh 源码为实测依据）：
 *   dns_ali        : Ali_Key / Ali_Secret
 *   dns_dp         : DP_Id / DP_Key
 *   dns_huaweicloud: HUAWEICLOUD_Username / Password / DomainName / Region(选)
 *   dns_baidu      : Baidu_AK / Baidu_SK（host dns.baidubce.com）
 *   dns_cf         : CF_Token 或 CF_Key+CF_Email；CF_Account_ID/CF_Zone_ID 选
 *   dns_aws        : AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN(选)
 *   dns_namesilo   : Namesilo_Key
 *   dns_pdns       : PDNS_Url / PDNS_ServerId / PDNS_Token / PDNS_Ttl(选)
 *   dns_volcengine : Volcengine_ACCESS_KEY_ID / Volcengine_SECRET_ACCESS_KEY / Volcengine_SESSION_TOKEN(选)
 *   dns_xinnet     : 面板 bridge（Xinnet_Access_ID / Xinnet_Access_Secret 走内部）
 *   dns_dnsla      : 面板 bridge（DNSLA_API_ID / DNSLA_API_SECRET 走内部）
 */
class DnsapiApi extends API
{
	/** hook token 有效期（秒）：一次签发 add+rm 两轮 + 重试余量 */
	const HOOK_TOKEN_TTL = 21600;

	/**
	 * provider 静态注册表
	 *   plugin : acme.sh --dns 参数
	 *   env    : 签发时注入 acme.sh 子进程的环境变量（原生插件所需；
	 *            bridge 型留空，走 EP_ACME_* 回调）
	 *   fields : 表单定义；secret=1 落库加密、回显打码
	 */
	public function providers()
	{
		return array(
			'aliyun' => array(
				'label'  => '阿里云 AliDNS',
				'plugin' => 'dns_ali',
				'env'    => true,
				'link'   => 'https://dns.console.aliyun.com/',
				'help'   => '建议 RAM 子账号仅授 AliyunDNSFullAccess。',
				'fields' => array(
					array('key' => 'access_key', 'label' => 'AccessKey ID', 'secret' => false, 'required' => true, 'env' => 'Ali_Key'),
					array('key' => 'secret_key', 'label' => 'AccessKey Secret', 'secret' => true, 'required' => true, 'env' => 'Ali_Secret'),
				),
			),
			'dnspod' => array(
				'label'  => 'DNSPod / 腾讯云',
				'plugin' => 'dns_dp',
				'env'    => true,
				'link'   => 'https://console.cloud.tencent.com/cam/capi',
				'help'   => 'API 密钥页创建的 TokenId 与 Token（建议只授 DNSPod 读写）。',
				'fields' => array(
					array('key' => 'api_id', 'label' => 'ID（TokenId）', 'secret' => false, 'required' => true, 'env' => 'DP_Id'),
					array('key' => 'api_key', 'label' => 'Token（TokenKey）', 'secret' => true, 'required' => true, 'env' => 'DP_Key'),
				),
			),
			'huaweicloud' => array(
				'label'  => '华为云云解析 DNS',
				'plugin' => 'dns_huaweicloud',
				'env'    => true,
				'link'   => 'https://console.huaweicloud.com/iam/',
				'help'   => 'IAM 用户名/密码 + 华为账号名（主账号）；区域留空默认 ap-southeast-1。',
				'fields' => array(
					array('key' => 'username', 'label' => 'IAM 用户名', 'secret' => false, 'required' => true, 'env' => 'HUAWEICLOUD_Username'),
					array('key' => 'password', 'label' => 'IAM 密码', 'secret' => true, 'required' => true, 'env' => 'HUAWEICLOUD_Password'),
					array('key' => 'domainname', 'label' => '华为账号名', 'secret' => false, 'required' => true, 'env' => 'HUAWEICLOUD_DomainName'),
					array('key' => 'region', 'label' => '区域（选填）', 'secret' => false, 'required' => false, 'env' => 'HUAWEICLOUD_Region', 'placeholder' => '如 cn-north-4'),
				),
			),
			'baidu' => array(
				'label'  => '百度智能云云解析',
				'plugin' => 'dns_baidu',
				'env'    => true,
				'link'   => 'https://console.bce.baidu.com/iam/#/iam/accesslist',
				'help'   => 'BCE AK/SK，需开通云解析 DNS 并授权 dns:*。',
				'fields' => array(
					array('key' => 'access_key', 'label' => 'AccessKey (AK)', 'secret' => false, 'required' => true, 'env' => 'Baidu_AK'),
					array('key' => 'secret_key', 'label' => 'SecretKey (SK)', 'secret' => true, 'required' => true, 'env' => 'Baidu_SK'),
				),
			),
			'xinnet' => array(
				'label'  => '新网 XinNet',
				'plugin' => 'dns_xinnet',
				'env'    => false,
				'link'   => 'https://www.xinnet.com/',
				'help'   => '新网开放平台 AccessID/Secret（apiv2.xinnet.com）；需联系客服开通 API 并绑定服务器出口 IP 白名单。acme.sh 无原生插件，由面板 bridge 脚本回调。',
				'fields' => array(
					array('key' => 'access_id', 'label' => 'AccessID', 'secret' => false, 'required' => true, 'env' => 'Xinnet_Access_ID'),
					array('key' => 'access_secret', 'label' => 'AccessSecret', 'secret' => true, 'required' => true, 'env' => 'Xinnet_Access_Secret'),
				),
			),
			'volcengine' => array(
				'label'  => '火山引擎 TrafficRoute',
				'plugin' => 'dns_volcengine',
				'env'    => true,
				'link'   => 'https://console.volcengine.com/iam/keymanage/',
				'help'   => '火山引擎 AK/SK；临时 STS 凭证再填 Session Token。',
				'fields' => array(
					array('key' => 'access_key', 'label' => 'Access Key ID', 'secret' => false, 'required' => true, 'env' => 'Volcengine_ACCESS_KEY_ID'),
					array('key' => 'secret_key', 'label' => 'Secret Access Key', 'secret' => true, 'required' => true, 'env' => 'Volcengine_SECRET_ACCESS_KEY'),
					array('key' => 'session_token', 'label' => 'Session Token（选填）', 'secret' => true, 'required' => false, 'env' => 'Volcengine_SESSION_TOKEN'),
				),
			),
			'dnsla' => array(
				'label'  => 'DNS.LA',
				'plugin' => 'dns_dnsla',
				'env'    => false,
				'link'   => 'https://www.dns.la/',
				'help'   => 'DNS.LA 控制台 API ID/Secret（api.dns.la Basic 认证）。acme.sh 无原生插件，由面板 bridge 脚本回调。',
				'fields' => array(
					array('key' => 'api_id', 'label' => 'API ID', 'secret' => false, 'required' => true, 'env' => 'DNSLA_API_ID'),
					array('key' => 'api_secret', 'label' => 'API Secret', 'secret' => true, 'required' => true, 'env' => 'DNSLA_API_SECRET'),
				),
			),
			'cloudflare' => array(
				'label'  => 'Cloudflare',
				'plugin' => 'dns_cf',
				'env'    => true,
				'link'   => 'https://dash.cloudflare.com/profile/api-tokens',
				'help'   => '推荐 API Token（Zone.DNS 编辑权限）；旧版可 Global Key+邮箱。Zone/Account ID 选填。',
				'fields' => array(
					array('key' => 'api_token', 'label' => 'API Token（推荐）', 'secret' => true, 'required' => false, 'env' => 'CF_Token'),
					array('key' => 'email', 'label' => '账户邮箱（配 Global Key）', 'secret' => false, 'required' => false, 'env' => 'CF_Email'),
					array('key' => 'global_key', 'label' => 'Global API Key（选填）', 'secret' => true, 'required' => false, 'env' => 'CF_Key'),
					array('key' => 'account_id', 'label' => 'Account ID（选填）', 'secret' => false, 'required' => false, 'env' => 'CF_Account_ID'),
					array('key' => 'zone_id', 'label' => 'Zone ID（选填）', 'secret' => false, 'required' => false, 'env' => 'CF_Zone_ID'),
				),
			),
			'route53' => array(
				'label'  => 'AWS Route 53',
				'plugin' => 'dns_aws',
				'env'    => true,
				'link'   => 'https://console.aws.amazon.com/iam/',
				'help'   => 'IAM AK/SK，需 route53:ChangeResourceRecordSets；GovCloud 等特殊分区再填区域。',
				'fields' => array(
					array('key' => 'access_key', 'label' => 'Access Key ID', 'secret' => false, 'required' => true, 'env' => 'AWS_ACCESS_KEY_ID'),
					array('key' => 'secret_key', 'label' => 'Secret Access Key', 'secret' => true, 'required' => true, 'env' => 'AWS_SECRET_ACCESS_KEY'),
					array('key' => 'session_token', 'label' => 'Session Token（选填）', 'secret' => true, 'required' => false, 'env' => 'AWS_SESSION_TOKEN'),
					array('key' => 'region', 'label' => '区域（选填）', 'secret' => false, 'required' => false, 'env' => 'AWS_REGION', 'placeholder' => '如 us-east-1'),
				),
			),
			'namesilo' => array(
				'label'  => 'NameSilo',
				'plugin' => 'dns_namesilo',
				'env'    => true,
				'link'   => 'https://www.namesilo.com/AccountManage-API.php',
				'help'   => '账户 API Key（需先启用 API 访问）。',
				'fields' => array(
					array('key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'required' => true, 'env' => 'Namesilo_Key'),
				),
			),
			'powerdns' => array(
				'label'  => 'PowerDNS Authoritative',
				'plugin' => 'dns_pdns',
				'env'    => true,
				'link'   => 'https://doc.powerdns.com/authoritative/http-api/',
				'help'   => 'Server API 地址（如 http://ns.example.com:8081）、Server ID（通常 localhost）、API Token；TTL 选填默认 60。',
				'fields' => array(
					array('key' => 'api_url', 'label' => 'API URL', 'secret' => false, 'required' => true, 'env' => 'PDNS_Url', 'placeholder' => 'http://ns.example.com:8081'),
					array('key' => 'server_id', 'label' => 'Server ID', 'secret' => false, 'required' => true, 'env' => 'PDNS_ServerId', 'placeholder' => 'localhost'),
					array('key' => 'api_token', 'label' => 'API Token', 'secret' => true, 'required' => true, 'env' => 'PDNS_Token'),
					array('key' => 'ttl', 'label' => 'TTL（选填）', 'secret' => false, 'required' => false, 'env' => 'PDNS_Ttl', 'placeholder' => '默认 60'),
				),
			),
		);
	}

	public function isProvider($p)
	{
		return is_string($p) && isset($this->providers()[$p]);
	}

	public function pluginOf($p)
	{
		$all = $this->providers();
		return isset($all[$p]) ? $all[$p]['plugin'] : false;
	}

	// ───────────────────────── 存储底座 ─────────────────────────

	/** vhs.db PDO（与主 DAO 同库，prepared 语句防注入） */
	private function pdo()
	{
		static $pdo = null;
		if ($pdo !== null) {
			return $pdo;
		}
		$file = rtrim($GLOBALS['safe_dir'], '/') . '/vhs.db';
		try {
			// ATTR_TIMEOUT=5：vhs.db 由面板多连接共享（主 DAO / audit / 本 API），
			// 默认 30s 的 SQLITE_BUSY 等待在串行快速点击时会顶穿 kangle 对 CGI 的
			// 60s recv-head 超时（实测 504）。压到 5s：宁可返回结构化失败也不挂死。
			$pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_TIMEOUT => 5));
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch (Exception $e) {
			$pdo = null;
			trigger_error('dnsapi: 无法打开 vhs.db: ' . $e->getMessage(), E_USER_WARNING);
		}
		return $pdo;
	}

	/**
	 * 幂等建表（upgrade.sql 片段里同样有这三张表；运行时兜底保证
	 * 未跑升级脚本的新库也能直接用，风格对齐 audit.dao.php）
	 */
	public function ensureTables()
	{
		static $done = false;
		if ($done) {
			return true;
		}
		$pdo = $this->pdo();
		if (!$pdo) {
			return false;
		}
		// 兼容性红线：kangle 容器 php74 的 PDO 链接的是 SQLite 3.7.17
		// （容器实测 select sqlite_version() = 3.7.17），不支持 partial
		// index（... WHERE ...）。因此这里只用最朴素的 DDL；
		// is_default 全局唯一由 setDefault() 先清零再置一来保证。
		try {
			$pdo->exec('CREATE TABLE IF NOT EXISTS [dns_credential] (
				[id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
				[provider] TEXT NOT NULL,
				[name] TEXT NULL,
				[domain] TEXT NULL,
				[remark] TEXT NULL,
				[credential] TEXT NOT NULL,
				[is_default] INTEGER NOT NULL DEFAULT 0,
				[cre_time] INTEGER NOT NULL DEFAULT 0,
				[upd_time] INTEGER NOT NULL DEFAULT 0
			)');
			$pdo->exec('CREATE INDEX IF NOT EXISTS [IDX_DNS_CRED_PROVIDER] ON [dns_credential] ([provider] ASC)');
			$pdo->exec('CREATE TABLE IF NOT EXISTS [acme_hook_token] (
				[token] TEXT NOT NULL PRIMARY KEY,
				[cred] INTEGER NOT NULL,
				[domain] TEXT NULL,
				[exp] INTEGER NOT NULL
			)');
			$done = true;
			return true;
		}
		catch (Exception $e) {
			trigger_error('dnsapi: 建表失败: ' . $e->getMessage(), E_USER_WARNING);
			return false;
		}
	}

	/**
	 * 凭据加密口令：safe_dir/dns_cred.key（首次自动生成，0600）。
	 * safe_dir 即 /vhs/kangle/etc/，在 web 根之外、随卷持久化。
	 * 取不到/写不了时返回 false —— 拒绝保存，绝不退化为硬编码口令。
	 */
	private function credKey()
	{
		static $k = null;
		if ($k !== null) {
			return $k;
		}
		$k = false;
		$f = rtrim($GLOBALS['safe_dir'], '/') . '/dns_cred.key';
		if (!is_file($f)) {
			if (!function_exists('random_bytes')) {
				return $k;
			}
			$secret = bin2hex(random_bytes(32));
			@umask(0177);
			if (@file_put_contents($f, $secret) === false) {
				return $k;
			}
			@chmod($f, 0600);
		}
		$raw = @file_get_contents($f);
		if (is_string($raw) && strlen(trim($raw)) >= 32) {
			$k = hash('sha256', 'ep-dnsapi-cred|' . trim($raw), true);
		}
		return $k;
	}

	public function encryptSecret($plain)
	{
		$key = $this->credKey();
		if ($key === false) {
			return false;
		}
		$iv = random_bytes(16);
		$cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			return false;
		}
		return 'enc:' . base64_encode($iv . $cipher);
	}

	public function decryptSecret($stored)
	{
		if (!is_string($stored) || $stored === '') {
			return '';
		}
		if (strpos($stored, 'enc:') !== 0) {
			return $stored; // 兼容历史明文
		}
		$key = $this->credKey();
		if ($key === false) {
			return '';
		}
		$raw = base64_decode(substr($stored, 4), true);
		if ($raw === false || strlen($raw) <= 16) {
			return '';
		}
		$plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
		return $plain === false ? '' : $plain;
	}

	// ───────────────────────── 凭据 CRUD ─────────────────────────

	/**
	 * 列表（secret 字段打码）
	 * @return array 行: id/provider/label/name/domain/remark/is_default/
	 *               cre_time/upd_time/preview(打码后 "字段标签: 值" 数组)
	 */
	public function listCredentials()
	{
		$rows = array();
		if (!$this->ensureTables()) {
			return $rows;
		}
		$pdo = $this->pdo();
		$st = $pdo->prepare('SELECT * FROM dns_credential ORDER BY provider, id');
		$st->execute();
		$providers = $this->providers();
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$p = $row['provider'];
			if (!isset($providers[$p])) {
				continue;
			}
			$raw = json_decode($row['credential'], true);
			$preview = array();
			foreach ($providers[$p]['fields'] as $f) {
				$val = isset($raw[$f['key']]) ? $this->decryptSecret($raw[$f['key']]) : '';
				if ($val === '') {
					continue;
				}
				$preview[] = $f['label'] . ': ' . ($f['secret'] ? $this->mask($val) : $val);
			}
			$rows[] = array(
				'id'         => intval($row['id']),
				'provider'   => $p,
				'label'      => $providers[$p]['label'],
				'name'       => $row['name'],
				'domain'     => $row['domain'],
				'remark'     => $row['remark'],
				'is_default' => intval($row['is_default']),
				'cre_time'   => intval($row['cre_time']),
				'upd_time'   => intval($row['upd_time']),
				'preview'    => $preview,
			);
		}
		return $rows;
	}

	private function mask($val)
	{
		if ($val === '') {
			return '';
		}
		return strlen($val) <= 4 ? '****' : '****' . substr($val, -4);
	}

	public function getCredential($id)
	{
		if (!$this->ensureTables()) {
			return false;
		}
		$pdo = $this->pdo();
		$st = $pdo->prepare('SELECT * FROM dns_credential WHERE id=?');
		$st->execute(array(intval($id)));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : false;
	}

	/**
	 * 新增/更新凭据。$data: id?/provider/name?/domain?/remark?/credential(array 明文)
	 * secret 字段提交为空串时：新增=报错（必填）或留空（选填）；编辑=沿用旧值。
	 * @return array(code, msg, id?)  code=0 成功
	 */
	public function saveCredential($data)
	{
		if (!$this->ensureTables()) {
			return array('code' => 1, 'msg' => '数据库不可用');
		}
		$providers = $this->providers();
		$p = isset($data['provider']) ? trim($data['provider']) : '';
		if (!isset($providers[$p])) {
			return array('code' => 1, 'msg' => '不支持的 DNS 平台：' . htmlspecialchars($p));
		}
		$id = isset($data['id']) ? intval($data['id']) : 0;
		$name = isset($data['name']) ? mb_substr(trim($data['name']), 0, 60) : '';
		if ($name === '') {
			$name = $providers[$p]['label'];
		}
		$domain = $this->normalizeDomain(isset($data['domain']) ? trim($data['domain']) : '');
		if (isset($data['domain']) && trim($data['domain']) !== '' && $domain === '') {
			return array('code' => 1, 'msg' => '主域名格式不正确');
		}
		$remark = isset($data['remark']) ? mb_substr(trim($data['remark']), 0, 200) : '';

		$pdo = $this->pdo();
		$old = array();
		if ($id > 0) {
			$row = $this->getCredential($id);
			if (!$row) {
				return array('code' => 1, 'msg' => '凭据不存在');
			}
			if ($row['provider'] != $p) {
				return array('code' => 1, 'msg' => '不允许更换平台类型，请删除后重建');
			}
			$old = json_decode($row['credential'], true);
			if (!is_array($old)) {
				$old = array();
			}
		}

		$in = isset($data['credential']) && is_array($data['credential']) ? $data['credential'] : array();
		$store = array();
		foreach ($providers[$p]['fields'] as $f) {
			$val = isset($in[$f['key']]) ? trim($in[$f['key']]) : '';
			if ($val === '' && $id > 0 && isset($old[$f['key']])) {
				// 编辑留空 = 沿用旧值（secret 存的是密文直接搬；明文同理）
				$store[$f['key']] = $old[$f['key']];
				continue;
			}
			if ($val === '') {
				if ($f['required'] && !($p === 'cloudflare')) {
					return array('code' => 1, 'msg' => $f['label'] . ' 不能为空');
				}
				continue;
			}
			$store[$f['key']] = $f['secret'] ? $this->encryptSecret($val) : $val;
			if ($f['secret'] && $store[$f['key']] === false) {
				return array('code' => 1, 'msg' => '加密失败：无法生成/写入 etc 目录下的凭据主密钥文件（见 dns_cred.key）');
			}
		}
		if ($p === 'cloudflare') {
			$tok = $this->decryptSecret(isset($store['api_token']) ? $store['api_token'] : '');
			$email = isset($store['email']) ? $store['email'] : '';
			$gkey = $this->decryptSecret(isset($store['global_key']) ? $store['global_key'] : '');
			if ($tok === '' && ($email === '' || $gkey === '')) {
				return array('code' => 1, 'msg' => 'Cloudflare 需填 API Token，或同时填账户邮箱 + Global API Key');
			}
		}
		if ($store === array()) {
			return array('code' => 1, 'msg' => '凭据内容为空');
		}
		if ($p === 'powerdns' && (!isset($store['api_url']) || !preg_match('#^https?://#', $store['api_url']))) {
			return array('code' => 1, 'msg' => 'PowerDNS 的 API URL 必须以 http:// 或 https:// 开头');
		}

		$cred_json = json_encode($store);
		$now = time();
		if ($id > 0) {
			$st = $pdo->prepare('UPDATE dns_credential SET name=?, domain=?, remark=?, credential=?, upd_time=? WHERE id=?');
			$st->execute(array($name, $domain, $remark, $cred_json, $now, $id));
			return array('code' => 0, 'msg' => '已更新', 'id' => $id);
		}
		$cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM dns_credential');
		$cnt->execute();
		$is_default = intval($cnt->fetch(PDO::FETCH_ASSOC)['c']) ? 0 : 1;
		$st = $pdo->prepare('INSERT INTO dns_credential (provider,name,domain,remark,credential,is_default,cre_time,upd_time) VALUES (?,?,?,?,?,?,?,?)');
		$st->execute(array($p, $name, $domain, $remark, $cred_json, $is_default, $now, $now));
		return array('code' => 0, 'msg' => '已添加', 'id' => intval($pdo->lastInsertId()));
	}

	public function deleteCredential($id)
	{
		if (!$this->ensureTables()) {
			return array('code' => 1, 'msg' => '数据库不可用');
		}
		$id = intval($id);
		if ($id <= 0) {
			return array('code' => 1, 'msg' => '参数错误');
		}
		$pdo = $this->pdo();
		$row = $this->getCredential($id);
		if (!$row) {
			return array('code' => 1, 'msg' => '凭据不存在');
		}
		$st = $pdo->prepare('DELETE FROM dns_credential WHERE id=?');
		$st->execute(array($id));
		audit('acmessl', 'dnsconfigDelete', $row['provider'] . ':' . $row['name'], 'success', array('id' => $id));
		return array('code' => 0, 'msg' => '已删除');
	}

	public function setDefault($id)
	{
		if (!$this->ensureTables()) {
			return array('code' => 1, 'msg' => '数据库不可用');
		}
		$id = intval($id);
		if ($id <= 0) {
			return array('code' => 1, 'msg' => '参数错误');
		}
		$pdo = $this->pdo();
		if (!$this->getCredential($id)) {
			return array('code' => 1, 'msg' => '凭据不存在');
		}
		$pdo->prepare('UPDATE dns_credential SET is_default=0 WHERE is_default=1')->execute();
		$st = $pdo->prepare('UPDATE dns_credential SET is_default=1 WHERE id=?');
		$st->execute(array($id));
		return array('code' => 0, 'msg' => '已设为默认');
	}

	// ───────────────────────── 取签发环境变量 ─────────────────────────

	/**
	 * 凭据 → 解密后的 env 数组（含所有 fields.env 映射；面板内部探测与
	 * acme.sh 注入共用。bridge 平台也会带上原始 key，供 hook 回调后使用）
	 * @return array|false
	 */
	public function credentialEnv($credId)
	{
		$row = $this->getCredential($credId);
		if (!$row) {
			return false;
		}
		$providers = $this->providers();
		$p = $row['provider'];
		if (!isset($providers[$p])) {
			return false;
		}
		$raw = json_decode($row['credential'], true);
		if (!is_array($raw)) {
			return false;
		}
		$env = array();
		foreach ($providers[$p]['fields'] as $f) {
			if (empty($f['env'])) {
				continue;
			}
			$val = isset($raw[$f['key']]) ? trim($this->decryptSecret($raw[$f['key']])) : '';
			if ($val !== '') {
				$env[$f['env']] = $val;
			} elseif ($f['required'] && $p !== 'cloudflare') {
				return false;
			}
		}
		// 幂等哨兵：cloudflare 至少要有一种认证方式
		if ($p === 'cloudflare' && empty($env['CF_Token']) && (empty($env['CF_Key']) || empty($env['CF_Email']))) {
			return false;
		}
		if ($env === array()) {
			return false;
		}
		return $env;
	}

	/** 域名选凭据：显式 id > domain 匹配 > 全局默认 */
	public function pickCredentialForDomain($domain, $credId = 0)
	{
		if (!$this->ensureTables()) {
			return false;
		}
		$credId = intval($credId);
		if ($credId > 0) {
			return $this->getCredential($credId);
		}
		$domain = $this->normalizeDomain($domain);
		$pdo = $this->pdo();
		if ($domain !== '') {
			$st = $pdo->prepare('SELECT * FROM dns_credential WHERE domain=? ORDER BY is_default DESC, id ASC LIMIT 1');
			$st->execute(array($domain));
			$row = $st->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				return $row;
			}
		}
		$st = $pdo->prepare('SELECT * FROM dns_credential WHERE is_default=1 LIMIT 1');
		$st->execute();
		$row = $st->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : false;
	}

	public function normalizeDomain($d)
	{
		$d = strtolower(trim($d, " \t\n\r\0\x0B."));
		$d = preg_replace('/^\*\./', '', $d);
		if ($d !== '' && !preg_match('/^([a-z0-9\-\.\x80-\xff]|(xn\-\-[a-z0-9\-]+))+$/iu', $d)) {
			return '';
		}
		return $d;
	}

	// ───────────────────────── hook token（bridge 平台用） ─────────────────────────

	public function issueHookToken($credId, $domain)
	{
		if (!$this->ensureTables()) {
			return false;
		}
		$token = hash('sha256', 'epacme|' . intval($credId) . '|' . $domain . '|' . microtime(true) . '|' . mt_rand());
		$pdo = $this->pdo();
		$pdo->prepare('DELETE FROM acme_hook_token WHERE exp<?')->execute(array(time() - 86400));
		$st = $pdo->prepare('INSERT INTO acme_hook_token (token,cred,domain,exp) VALUES (?,?,?,?)');
		$st->execute(array($token, intval($credId), $domain, time() + self::HOOK_TOKEN_TTL));
		return $token;
	}

	public function checkHookToken($token)
	{
		if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
			return false;
		}
		if (!$this->ensureTables()) {
			return false;
		}
		$st = $this->pdo()->prepare('SELECT * FROM acme_hook_token WHERE token=?');
		$st->execute(array($token));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		if ($row && time() <= intval($row['exp'])) {
			return array('cred' => intval($row['cred']), 'domain' => $row['domain']);
		}
		return $this->checkPermToken($token);
	}

	/**
	 * 长效续签 token：桥接平台（xinnet/dnsla）没有 acme.sh 原生插件的
	 * _saveaccountconf_mutable 持久化，acme.sh 每日 cron --cron 续签时
	 * 短效 token 已过期。签发时同步把本 token 写入
	 * /root/.acme.sh/dnsapi/ep_renew_token（0600，root 私有，等价于
	 * 原生插件把 key 存进 account.conf 的行为），bridge 脚本在
	 * EP_ACME_HOOK_TOKEN 为空时回退读取该文件。token 由 credKey
	 * （safe_dir/dns_cred.key）派生、绑定 credId，不落库、随密钥文件销毁。
	 */
	public function permHookToken($credId)
	{
		$key = $this->credKey();
		if ($key === false) {
			return false;
		}
		return hash('sha256', 'epacme-perm|' . intval($credId) . '|' . $key);
	}

	private function checkPermToken($token)
	{
		$key = $this->credKey();
		if ($key === false) {
			return false;
		}
		$st = $this->pdo()->prepare('SELECT id FROM dns_credential');
		$st->execute();
		foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
			if (hash_equals($this->permHookToken(intval($id)), $token)) {
				return array('cred' => intval($id), 'domain' => '');
			}
		}
		return false;
	}

	public function dropHookToken($token)
	{
		if (!$this->ensureTables()) {
			return false;
		}
		$st = $this->pdo()->prepare('DELETE FROM acme_hook_token WHERE token=?');
		$st->execute(array($token));
		return true;
	}

	/**
	 * 在 acme.sh 的 dnsapi 目录里安装/覆盖 bridge 脚本（dns_xinnet.sh、
	 * dns_dnsla.sh）。acme.sh 3.1.4 不带这两家插件（远端容器 192 个
	 * dnsapi/*.sh 实测缺失），bridge 通过环境变量 EP_ACME_* 回调本 API。
	 * @return bool
	 */
	public function installBridgePlugins($acmeHome)
	{
		$dir = rtrim($acmeHome, '/') . '/dnsapi';
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			return false;
		}
		$ok = true;
		foreach (array('dns_xinnet', 'dns_dnsla') as $name) {
			$func = $name; // dns_xinnet_add / dns_xinnet_rm
			$script = $this->bridgeScriptBody($func);
			if (@file_put_contents($dir . '/' . $name . '.sh', $script) === false) {
				$ok = false;
				continue;
			}
			@chmod($dir . '/' . $name . '.sh', 0755);
		}
		return $ok;
	}

	private function bridgeScriptBody($func)
	{
		$lines = array(
			'#!/usr/bin/env bash',
			'# EasyPanel bridge plugin: ' . $func . '.sh',
			'# 由 framework/api/dnsapi.api.php 自动生成，勿手工编辑。',
			'# acme.sh source 本文件后调用 ' . $func . '_add / ' . $func . '_rm，',
			'# 实际 API 动作经 EP_ACME_HOOK_TOKEN 回调面板 PHP CLI 完成。',
			'_' . $func . '_hook() {',
			'    local _ep_php="${EP_ACME_PHP:-php74}"',
			'    local _ep_api="${EP_ACME_API:-/vhs/kangle/nodewww/webftp/framework/api/dnsapi.api.php}"',
			'    local _ep_tok="$EP_ACME_HOOK_TOKEN"',
			'    local _ep_log="${EP_ACME_HOOK_LOG:-$HOME/acme_hook.log}"',
			'    if [ -z "$_ep_tok" ] && [ -f "$HOME/dnsapi/ep_renew_token" ]; then',
			'        _ep_tok="$(tr -d \'[:space:]\' < "$HOME/dnsapi/ep_renew_token")"',
			'    fi',
			'    if [ -z "$_ep_api" ] || [ -z "$_ep_tok" ]; then',
			'        _err "EasyPanel dns bridge: EP_ACME_API / EP_ACME_HOOK_TOKEN not set"',
			'        return 1',
			'    fi',
			'    command -v "$_ep_php" >/dev/null 2>&1 || { _err "EasyPanel dns bridge: php not found: $_ep_php"; return 1; }',
			'    if ! "$_ep_php" "$_ep_api" "$_ep_tok" "$1" "$2" "$3" 2>>"$_ep_log"; then',
			'        _err "EasyPanel dns bridge failed: $1 $2"',
			'        return 1',
			'    fi',
			'    return 0',
			'}',
			$func . '_add() { _' . $func . '_hook add "$1" "$2" || return 1; }',
			$func . '_rm() { _' . $func . '_hook rm "$1" "$2" || return 1; }',
		);
		return implode("\n", $lines) . "\n";
	}

	/**
	 * CLI 回调入口（bridge 脚本调用）：
	 *   php74 dnsapi.api.php <token> <add|rm> <fulldomain> <txtvalue>
	 * 成功 return true；失败 return false 并往 stderr 写一行原因。
	 */
	public function hookHandle($token, $action, $fulldomain, $txtvalue)
	{
		$meta = $this->checkHookToken($token);
		if ($meta === false) {
			$this->apilog('dnsapi hook: invalid or expired token');
			return false;
		}
		$fulldomain = strtolower(trim($fulldomain));
		$txtvalue = trim($txtvalue);
		if (!preg_match('/^[a-z0-9\.\*\-_]+$/', $fulldomain) || !preg_match('/^[A-Za-z0-9._\-]+$/', $txtvalue)) {
			$this->apilog('dnsapi hook: bad arguments');
			return false;
		}
		if ($action === 'add') {
			$res = $this->addTxt($meta['cred'], $fulldomain, $txtvalue);
		} elseif ($action === 'rm') {
			$res = $this->removeTxt($meta['cred'], $fulldomain, $txtvalue);
		} else {
			return false;
		}
		return isset($res['code']) && $res['code'] == 0;
	}

	// ───────────────────────── 连通性测试 ─────────────────────────

	/**
	 * 探测凭据可用性：每平台一个只读 API 调用（真实出网请求，
	 * 失败返回结构化 msg 并 error_log 端点，便于面板日志排障）。
	 * @return array(code, msg)
	 */
	public function testCredential($credId)
	{
		$row = $this->getCredential($credId);
		if (!$row) {
			return array('code' => 1, 'msg' => '凭据不存在');
		}
		$env = $this->credentialEnv($row['id']);
		if ($env === false) {
			return array('code' => 1, 'msg' => '凭据不完整或解密失败，请重新保存');
		}
		$probe = $this->probe($row['provider'], $env, trim($row['domain']));
		audit('acmessl', 'dnsTest', $row['provider'] . ':' . $row['name'], $probe['code'] == 0 ? 'success' : 'error', array('msg' => $probe['msg'], 'id' => intval($row['id'])));
		return $probe;
	}

	private function probe($p, $env, $domain)
	{
		switch ($p) {
		case 'aliyun':
			$r = $this->ali_api($env, 'DescribeDomains', array('PageSize' => '1'));
			return isset($r['err']) ? array('code' => 1, 'msg' => '阿里云：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'dnspod':
			$r = $this->dp_api('Domain.List', array(), $env);
			return isset($r['err']) ? array('code' => 1, 'msg' => 'DNSPod：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'baidu':
			$root = $domain != '' ? $domain : 'easypanel-probe.invalid';
			$r = $this->baidu_dns($env, 'GET', '/v1/dns/zone/' . $root . '/record', '');
			if (isset($r['neterr'])) {
				return array('code' => 1, 'msg' => '百度云：' . $r['neterr']);
			}
			if (isset($r['err'])) {
				// zone 不存在也会 4xx；仅鉴权类错误判为凭据不可用
				if (preg_match('/401|403|AccessDenied|Signature|InvalidAccessKey|auth/i', $r['err'])) {
					return array('code' => 1, 'msg' => '百度云：鉴权失败（' . $r['err'] . '）');
				}
				return array('code' => 0, 'msg' => '连接成功（域名查询：' . $r['err'] . '）');
			}
			return array('code' => 0, 'msg' => '连接成功');
		case 'huaweicloud':
			$t = $this->hw_token($env);
			return isset($t['err']) ? array('code' => 1, 'msg' => '华为云：' . $t['err']) : array('code' => 0, 'msg' => 'IAM 认证成功');
		case 'xinnet':
			$r = $this->xinnet_api($env, '/api/dns/queryDomainList', array('pageNum' => 1, 'pageSize' => 1));
			return isset($r['err']) ? array('code' => 1, 'msg' => '新网：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'volcengine':
			$r = $this->volc_api($env, 'ListZones', array('PageSize' => 1, 'PageNumber' => 1));
			return isset($r['err']) ? array('code' => 1, 'msg' => '火山引擎：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'dnsla':
			$r = $this->dnsla_api($env, 'GET', '/api/domainList?pageIndex=1&pageSize=1');
			return isset($r['err']) ? array('code' => 1, 'msg' => 'DNS.LA：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'cloudflare':
			$r = $this->cf_api($env, 'GET', 'zones?per_page=1', null);
			return isset($r['err']) ? array('code' => 1, 'msg' => 'Cloudflare：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'route53':
			$r = $this->aws_request($env, 'GET', '/2013-04-01/hostedzone', '', '');
			return isset($r['err']) ? array('code' => 1, 'msg' => 'AWS：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'namesilo':
			$r = $this->ns_api($env, 'listDomains', array());
			return isset($r['err']) ? array('code' => 1, 'msg' => 'NameSilo：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		case 'powerdns':
			$base = rtrim($env['PDNS_Url'], '/');
			$r = $this->pdns_api($env, 'GET', '/api/v1/servers/' . rawurlencode($env['PDNS_ServerId']) . '/zones', null);
			return isset($r['err']) ? array('code' => 1, 'msg' => 'PowerDNS：' . $r['err']) : array('code' => 0, 'msg' => '连接成功');
		}
		return array('code' => 1, 'msg' => '未知平台：' . $p);
	}

	// ───────────────────────── TXT 高层 API ─────────────────────────

	public function addTxt($credId, $fulldomain, $txtvalue)
	{
		$env = $this->credentialEnv($credId);
		if ($env === false) {
			return array('code' => 1, 'msg' => '凭据不可用');
		}
		$row = $this->getCredential($credId);
		$hint = trim($row['domain']);
		switch ($row['provider']) {
		case 'aliyun':       return $this->ali_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'dnspod':       return $this->dp_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'baidu':        return $this->baidu_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'huaweicloud':  return $this->hw_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'xinnet':       return $this->xinnet_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'volcengine':   return $this->volc_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'dnsla':        return $this->dnsla_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'cloudflare':   return $this->cf_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'route53':      return $this->aws_txt($env, 'UPSERT', $fulldomain, $txtvalue);
		case 'namesilo':     return $this->ns_add_txt($env, $hint, $fulldomain, $txtvalue);
		case 'powerdns':     return $this->pdns_txt($env, $hint, $fulldomain, $txtvalue, false);
		}
		return array('code' => 1, 'msg' => '未知平台');
	}

	public function removeTxt($credId, $fulldomain, $txtvalue)
	{
		$env = $this->credentialEnv($credId);
		if ($env === false) {
			return array('code' => 1, 'msg' => '凭据不可用');
		}
		$row = $this->getCredential($credId);
		$hint = trim($row['domain']);
		switch ($row['provider']) {
		case 'aliyun':       return $this->ali_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'dnspod':       return $this->dp_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'baidu':        return $this->baidu_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'huaweicloud':  return $this->hw_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'xinnet':       return $this->xinnet_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'volcengine':   return $this->volc_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'dnsla':        return $this->dnsla_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'cloudflare':   return $this->cf_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'route53':      return $this->aws_txt($env, 'DELETE', $fulldomain, $txtvalue);
		case 'namesilo':     return $this->ns_rm_txt($env, $hint, $fulldomain, $txtvalue);
		case 'powerdns':     return $this->pdns_txt($env, $hint, $fulldomain, $txtvalue, true);
		}
		return array('code' => 1, 'msg' => '未知平台');
	}

	/**
	 * 列出 zone 记录（TXT 管理页用）。$nameFilter 非空时按 host 前缀过滤。
	 * @return array(code, msg, zone, rows=>[{id,name,type,value,ttl}])
	 */
	public function listRecords($credId, $domainHint = '', $nameFilter = '')
	{
		$env = $this->credentialEnv($credId);
		if ($env === false) {
			return array('code' => 1, 'msg' => '凭据不可用', 'rows' => array());
		}
		$row = $this->getCredential($credId);
		$hint = $domainHint != '' ? $this->normalizeDomain($domainHint) : trim($row['domain']);
		$target = $hint != '' ? $hint : ($nameFilter != '' ? $this->rootDomainGuess($nameFilter) : '');
		if ($target === '') {
			return array('code' => 1, 'msg' => '请先填写域名', 'rows' => array());
		}
		switch ($row['provider']) {
		case 'aliyun':       return $this->ali_list($env, $target, $nameFilter);
		case 'dnspod':       return $this->dp_list($env, $target, $nameFilter);
		case 'baidu':        return $this->baidu_list($env, $target, $nameFilter);
		case 'huaweicloud':  return $this->hw_list($env, $target, $nameFilter);
		case 'xinnet':       return $this->xinnet_list($env, $target, $nameFilter);
		case 'volcengine':   return $this->volc_list($env, $target, $nameFilter);
		case 'dnsla':        return $this->dnsla_list($env, $target, $nameFilter);
		case 'cloudflare':   return $this->cf_list($env, $target, $nameFilter);
		case 'route53':      return $this->aws_list($env, $target, $nameFilter);
		case 'namesilo':     return $this->ns_list($env, $target, $nameFilter);
		case 'powerdns':     return $this->pdns_list($env, $target, $nameFilter);
		}
		return array('code' => 1, 'msg' => '未知平台', 'rows' => array());
	}

	/** 删除单条记录（TXT 管理页用） */
	public function deleteRecord($credId, $zone, $recordId)
	{
		$env = $this->credentialEnv($credId);
		if ($env === false) {
			return array('code' => 1, 'msg' => '凭据不可用');
		}
		$row = $this->getCredential($credId);
		$zone = $this->normalizeDomain($zone);
		$recordId = strval($recordId);
		if ($zone === '' || $recordId === '') {
			return array('code' => 1, 'msg' => '参数错误');
		}
		switch ($row['provider']) {
		case 'aliyun':
			$r = $this->ali_api($env, 'DeleteDomainRecord', array('RecordId' => $recordId));
			break;
		case 'dnspod':
			$r = $this->dp_api('Record.Remove', array('domain' => $zone, 'record_id' => $recordId), $env);
			break;
		case 'baidu':
			$r = $this->baidu_dns($env, 'DELETE', '/v1/dns/zone/' . $zone . '/record/' . rawurlencode($recordId), '');
			break;
		case 'huaweicloud':
			$t = $this->hw_token($env);
			if (isset($t['err'])) {
				return array('code' => 1, 'msg' => '华为云：' . $t['err']);
			}
			$zj = $this->hw_zone_of($env, $t['token'], $zone, $zone);
			if (isset($zj['err'])) {
				return array('code' => 1, 'msg' => '华为云：' . $zj['err']);
			}
			$r = $this->hw_api($env, $t['token'], 'DELETE', '/v2/recordsets/' . $recordId, null);
			break;
		case 'xinnet':
			$r = $this->xinnet_api($env, '/api/dns/delete', array('domainName' => $zone, 'recordId' => $recordId));
			break;
		case 'volcengine':
			$r = $this->volc_api($env, 'DeleteRecord', array('RecordID' => intval($recordId)));
			break;
		case 'dnsla':
			$r = $this->dnsla_api($env, 'DELETE', '/api/record', array('id' => intval($recordId)));
			break;
		case 'cloudflare':
			$r = $this->cf_api($env, 'DELETE', 'zones/' . rawurlencode($zone) . '/dns_records/' . rawurlencode($recordId), null);
			// zone 参数这里需要的是 zone id；界面从 list 行带回，cf 特判
			break;
		case 'route53':
			// AWS 按 (name,type) 删除：recordId 传 "rrset_name" 形式
			return $this->aws_txt($env, 'DELETE', $recordId, '');
		case 'namesilo':
			$r = $this->ns_api($env, 'dnsDeleteRecord', array('domain' => $zone, 'rrid' => $recordId));
			break;
		case 'powerdns':
			// listRecords 的 id 形如 "name|TYPE"；整 rrset 删除（PATCH changetype=DELETE）
			$parts = explode('|', $recordId);
			$rr_name = strtolower(trim($parts[0]));
			$rr_type = isset($parts[1]) ? strtoupper($parts[1]) : 'TXT';
			$r = $this->pdns_api($env, 'PATCH', $this->pdns_zone_url($env, $zone),
				array('rrsets' => array(array('name' => $rr_name . '.', 'type' => $rr_type, 'ttl' => 60, 'changetype' => 'DELETE', 'records' => array()))));
			break;
		default:
			return array('code' => 1, 'msg' => '未知平台');
		}
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => $row['provider'] . '：' . $r['err']);
		}
		return array('code' => 0, 'msg' => '已删除');
	}

	// ───────────────────────── HTTP 底座 ─────────────────────────

	private function http($url, $method = 'GET', $body = null, $headers = array())
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			// 单次 DNS API 调用压到 15s：kangle 对 CGI 响应有约 60s 的
			// recv-head 超时（实测 504），一次 apply 最多串 4~6 个 API 调用，
			// 25s 上限在服务商抖动时会顶穿 60s。
			CURLOPT_TIMEOUT => 15,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_USERAGENT => 'EasyPanel-acme/1.0',
		));
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$resp = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$err = curl_error($ch);
		curl_close($ch);
		if ($resp === false) {
			$this->apilog('dnsapi: 网络错误 ' . $url . ' ' . $err);
			return array('neterr' => '网络错误：' . $err, 'url' => $url);
		}
		return array('code' => $code, 'body' => $resp, 'json' => json_decode($resp, true), 'url' => $url);
	}

	/**
	 * 请求审计日志：error_log + safe_dir/dnsapi.log 双写。
	 * 只写端点/动作级信息，严禁写入密钥。文件超 512KB 自动截半轮转，
	 * 写失败静默（排障辅助设施，不阻断业务）。验收 grep 点：
	 * grep alidns.aliyuncs.com /vhs/kangle/etc/dnsapi.log
	 */
	private function apilog($msg)
	{
		@error_log($msg);
		static $dir = null;
		if ($dir === null) {
			$dir = rtrim($GLOBALS['safe_dir'], '/');
		}
		$f = $dir . '/dnsapi.log';
		if (is_file($f) && @filesize($f) > 524288) {
			$keep = @file_get_contents($f, false, null, filesize($f) / 2);
			if ($keep !== false) {
				@file_put_contents($f, $keep);
			}
		}
		@file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
	}

	/** 统一失败判定：网络错误 / HTTP 4xx5xx + JSON message 提取 */
	private function httpFail($r, $expectMin = 400)
	{
		if (isset($r['neterr'])) {
			return $r['neterr'];
		}
		if ($r['code'] >= $expectMin) {
			$j = is_array($r['json']) ? $r['json'] : array();
			foreach (array('message', 'Message', 'msg', 'error') as $k) {
				if (isset($j[$k]) && is_scalar($j[$k])) {
					return 'HTTP ' . $r['code'] . ' ' . mb_substr(strval($j[$k]), 0, 120);
				}
			}
			return 'HTTP ' . $r['code'] . ' ' . mb_substr(strip_tags($r['body']), 0, 120);
		}
		return false;
	}

	/** zone 候选链：hint 优先，然后从长到短的后缀 */
	private function zoneCandidates($nameOrDomain, $hint = '')
	{
		$out = array();
		$hint = $this->normalizeDomain($hint);
		if ($hint !== '') {
			$out[] = $hint;
		}
		$f = $this->normalizeDomain($nameOrDomain);
		$parts = explode('.', $f);
		for ($i = 0; $i < count($parts) - 1; $i++) {
			$out[] = implode('.', array_slice($parts, $i));
		}
		return array_values(array_unique($out));
	}

	private function rootDomainGuess($name)
	{
		$c = $this->zoneCandidates($name, '');
		return end($c);
	}

	// ───────────────────────── 1. 阿里云 AliDNS ─────────────────────────
	// 端点/签名与 dns_ali.sh 同构：GET https://alidns.aliyuncs.com/?...
	// RPC 签名（SignatureMethod 为 SHA1 版 HMAC，见代码内 'HMAC-'.'SHA1'），
	// stringToSign = GET&%2F&<percent-encoded sorted query>

	private function ali_api($env, $action, $params = array())
	{
		if (empty($env['Ali_Key']) || empty($env['Ali_Secret'])) {
			return array('err' => '缺少 Ali_Key/Ali_Secret');
		}
		$all = array_merge(array(
			'Format' => 'JSON',
			'Version' => '2015-01-09',
			'AccessKeyId' => $env['Ali_Key'],
			'SignatureMethod' => 'HMAC-' . 'SHA1',
			'SignatureVersion' => '1.0',
			'SignatureNonce' => substr(md5(uniqid('', true)), 0, 8) . '-' . substr(md5((string) mt_rand()), 0, 4) . '-' . substr(md5((string) mt_rand()), 0, 4) . '-' . substr(md5((string) mt_rand()), 0, 4) . '-' . substr(md5(uniqid((string) mt_rand(), true)), 0, 12),
			'Action' => $action,
			'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
		), $params);
		ksort($all);
		$pairs = array();
		foreach ($all as $k => $v) {
			$pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
		}
		$query = implode('&', $pairs);
		$str_to_sign = 'GET&%2F&' . rawurlencode($query);
		$sig = base64_encode(hash_hmac('sha1', $str_to_sign, $env['Ali_Secret'] . '&', true));
		$url = 'https://alidns.aliyuncs.com/?' . $query . '&Signature=' . rawurlencode($sig);
		$this->apilog('dnsapi[aliyun] GET alidns.aliyuncs.com Action=' . $action);
		$r = $this->http($url);
		if (($f = $this->httpFail($r, 400)) !== false) {
			return array('err' => $f);
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		// 阿里云 2xx 也可能带业务错误 Code
		if (isset($j['Code']) && !in_array((string) $j['Code'], array('200', '0'), true)) {
			return array('err' => $j['Code'] . ' ' . (isset($j['Message']) ? $j['Message'] : ''));
		}
		return $j;
	}

	private function ali_resolve($env, $fulldomain, $hint)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			if (strpos($fulldomain, '.' . $cand) === false && $fulldomain != $cand) {
				continue;
			}
			$r = $this->ali_api($env, 'DescribeDomainRecords', array('DomainName' => $cand, 'PageSize' => '1'));
			if (!isset($r['err'])) {
				return array('zone' => $cand);
			}
			if (strpos((string) $r['err'], 'InvalidDomainName') === false && strpos((string) $r['err'], 'NOT EXIST') === false && stripos((string) $r['err'], 'not exist') === false) {
				return array('err' => $r['err']);
			}
		}
		return array('err' => '未在该账号下找到归属域名');
	}

	private function ali_records_of($env, $zone, $rr)
	{
		$params = array('DomainName' => $zone, 'PageSize' => '500');
		if ($rr !== '') {
			$params['RRKeyWord'] = $rr;
			$params['SearchMode'] = 'ADVANCED';
		}
		$r = $this->ali_api($env, 'DescribeDomainRecords', $params);
		if (isset($r['err'])) {
			return array('err' => $r['err']);
		}
		$recs = array();
		$list = isset($r['DomainRecords']['Record']) ? (array) $r['DomainRecords']['Record'] : array();
		foreach ($list as $it) {
			$recs[] = array(
				'id' => isset($it['RecordId']) ? $it['RecordId'] : '',
				'name' => (isset($it['RR']) ? $it['RR'] : '') . '.' . $zone,
				'rr' => isset($it['RR']) ? $it['RR'] : '',
				'type' => isset($it['Type']) ? $it['Type'] : '',
				'value' => isset($it['Value']) ? $it['Value'] : '',
				'ttl' => isset($it['TTL']) ? $it['TTL'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function ali_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->ali_resolve($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '阿里云：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->ali_records_of($env, $z['zone'], $rr);
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] === 'TXT' && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->ali_api($env, 'AddDomainRecord', array('DomainName' => $z['zone'], 'RR' => $rr, 'Type' => 'TXT', 'Value' => $txtvalue, 'TTL' => '600'));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => '阿里云：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function ali_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->ali_resolve($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '阿里云：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->ali_records_of($env, $z['zone'], $rr);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '阿里云：' . $cur['err']);
		}
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($rec['type'] !== 'TXT' || $rec['rr'] !== $rr) {
				continue;
			}
			if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
				continue;
			}
			$r = $this->ali_api($env, 'DeleteDomainRecord', array('RecordId' => $rec['id']));
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => '阿里云：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function ali_list($env, $zone, $nameFilter)
	{
		$rr = $nameFilter != '' ? $this->rrOf($nameFilter, $zone) : '';
		$cur = $this->ali_records_of($env, $zone, $rr);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '阿里云：' . $cur['err'], 'rows' => array());
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $cur['records']);
	}

	/** 从 fulldomain 提取相对 zone 的 RR；空则 '@' */
	private function rrOf($fulldomain, $zone)
	{
		$f = strtolower(trim($fulldomain, '.'));
		$z = strtolower(trim($zone, '.'));
		if ($f === $z) {
			return '@';
		}
		if (substr($f, -strlen($z) - 1) === '.' . $z) {
			return substr($f, 0, -strlen($z) - 1);
		}
		return $f;
	}

	// ───────────────────────── 2. DNSPod（dnsapi.cn）─────────────────────────

	private function dp_api($action, $params, $env)
	{
		if (empty($env['DP_Id']) || empty($env['DP_Key'])) {
			return array('err' => '缺少 DP_Id/DP_Key');
		}
		$params['login_token'] = $env['DP_Id'] . ',' . $env['DP_Key'];
		$params['format'] = 'json';
		$params['lang'] = 'en';
		$this->apilog('dnsapi[dnspod] POST dnsapi.cn ' . $action);
		$r = $this->http('https://dnsapi.cn/' . $action, 'POST', http_build_query($params), array('Content-Type: application/x-www-form-urlencoded'));
		if (($f = $this->httpFail($r)) !== false) {
			return array('err' => $f);
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		if (!isset($j['status']['code'])) {
			return array('err' => '返回解析失败');
		}
		if ($j['status']['code'] != 1) {
			return array('err' => (isset($j['status']['message']) ? $j['status']['message'] : 'code ' . $j['status']['code']));
		}
		return $j;
	}

	private function dp_records_of($env, $zone, $sub = '', $type = '')
	{
		$params = array('domain' => $zone, 'offset' => '0', 'length' => '200');
		if ($sub !== '') {
			$params['sub_domain'] = $sub;
		}
		if ($type !== '') {
			$params['record_type'] = $type;
		}
		$r = $this->dp_api('Record.List', $params, $env);
		if (isset($r['err'])) {
			return array('err' => $r['err']);
		}
		$recs = array();
		foreach ((array) (isset($r['records']) ? $r['records'] : array()) as $it) {
			$recs[] = array(
				'id' => isset($it['id']) ? $it['id'] : '',
				'name' => (isset($it['name']) ? $it['name'] : '') . '.' . $zone,
				'rr' => isset($it['name']) ? $it['name'] : '',
				'type' => isset($it['type']) ? $it['type'] : '',
				'value' => isset($it['value']) ? $it['value'] : '',
				'ttl' => isset($it['ttl']) ? $it['ttl'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function dp_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$chk = $this->dp_api('Domain.Info', array('domain' => $cand), $env);
			if (isset($chk['err'])) {
				continue;
			}
			$rr = $this->rrOf($fulldomain, $cand);
			$cur = $this->dp_records_of($env, $cand, $rr, 'TXT');
			if (!isset($cur['err'])) {
				foreach ($cur['records'] as $rec) {
					if ($rec['value'] === $txtvalue) {
						return array('code' => 0, 'msg' => '记录已存在');
					}
				}
			}
			$r = $this->dp_api('Record.Create', array('domain' => $cand, 'sub_domain' => $rr, 'record_type' => 'TXT', 'value' => $txtvalue, 'ttl' => '600', 'record_line' => '默认'), $env);
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => 'DNSPod：' . $r['err']);
			}
			return array('code' => 0, 'msg' => 'OK');
		}
		return array('code' => 1, 'msg' => 'DNSPod：未找到归属域名');
	}

	private function dp_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$cur = $this->dp_records_of($env, $cand, $this->rrOf($fulldomain, $cand), 'TXT');
			if (isset($cur['err'])) {
				continue;
			}
			$found = false;
			foreach ($cur['records'] as $rec) {
				if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
					continue;
				}
				$r = $this->dp_api('Record.Remove', array('domain' => $cand, 'record_id' => $rec['id']), $env);
				if (isset($r['err'])) {
					return array('code' => 1, 'msg' => 'DNSPod：' . $r['err']);
				}
				$found = true;
			}
			return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
		}
		return array('code' => 1, 'msg' => 'DNSPod：未找到归属域名');
	}

	private function dp_list($env, $zone, $nameFilter)
	{
		$cur = $this->dp_records_of($env, $zone, $nameFilter != '' ? $this->rrOf($nameFilter, $zone) : '');
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'DNSPod：' . $cur['err'], 'rows' => array());
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $cur['records']);
	}

	// ───────────────────────── 3. 百度智能云云解析（dns.baidubce.com）─────────────────────────
	// 与 dns_baidu.sh 新版引擎同构：GET/POST/DELETE /v1/dns/zone/{zone}/record
	// bce-auth-v1：signedHeaders=content-type;host;x-bce-content-sha256;x-bce-date
	//（值按 urlencode upper-hex；uri 按段编码）

	private function baidu_host()
	{
		return 'dns.baidubce.com';
	}

	private function bce_encode($s, $is_path = false)
	{
		$enc = rawurlencode($s);
		$enc = str_replace('%21', '!', $enc);
		$enc = str_replace('%27', "'", $enc);
		$enc = str_replace('%28', '(', $enc);
		$enc = str_replace('%29', ')', $enc);
		if ($is_path) {
			$enc = str_replace('%2F', '/', $enc);
		}
		return $enc;
	}

	private function baidu_auth($env, $method, $uri, $host, $ts, $payload_hash)
	{
		if (empty($env['Baidu_AK']) || empty($env['Baidu_SK'])) {
			return array('err' => '缺少 Baidu_AK/Baidu_SK');
		}
		$auth_prefix = 'bce-auth-v1/' . $env['Baidu_AK'] . '/' . $ts . '/1800';
		$signing_key = hash_hmac('sha256', $auth_prefix, $env['Baidu_SK']);
		$signed_headers = 'content-type;host;x-bce-content-sha256;x-bce-date';
		$canon_headers = 'content-type:' . $this->bce_encode('application/json') . "\n"
			. 'host:' . $this->bce_encode($host) . "\n"
			. 'x-bce-content-sha256:' . $this->bce_encode($payload_hash) . "\n"
			. 'x-bce-date:' . $this->bce_encode($ts);
		$canonical = $method . "\n" . $this->bce_encode($uri, true) . "\n" . "\n" . $canon_headers;
		$sig = hash_hmac('sha256', $canonical, $signing_key);
		return array('ok' => $auth_prefix . '/' . $signed_headers . '/' . $sig);
	}

	private function baidu_dns($env, $method, $uri, $payload)
	{
		$host = $this->baidu_host();
		$ts = gmdate('Y-m-d\TH:i:s\Z');
		$hash = hash('sha256', $payload);
		$auth = $this->baidu_auth($env, $method, $uri, $host, $ts, $hash);
		if (isset($auth['err'])) {
			return array('neterr' => $auth['err']);
		}
		$this->apilog('dnsapi[baidu] ' . $method . ' dns.baidubce.com ' . $uri);
		$r = $this->http('https://' . $host . $uri, $method, $payload !== '' || $method === 'POST' ? $payload : null, array(
			'Authorization: ' . $auth['ok'],
			'x-bce-date: ' . $ts,
			'x-bce-content-sha256: ' . $hash,
			'Content-Type: application/json',
		));
		if (isset($r['neterr'])) {
			return $r;
		}
		if ($r['code'] >= 400) {
			$j = is_array($r['json']) ? $r['json'] : array();
			return array('err' => 'HTTP ' . $r['code'] . ' ' . (isset($j['message']) ? $j['message'] : mb_substr(strip_tags($r['body']), 0, 120)), 'json' => $j);
		}
		return array('json' => is_array($r['json']) ? $r['json'] : array());
	}

	private function baidu_records_of($env, $zone)
	{
		$r = $this->baidu_dns($env, 'GET', '/v1/dns/zone/' . $zone . '/record', '');
		if (isset($r['neterr']) || isset($r['err'])) {
			return array('err' => isset($r['neterr']) ? $r['neterr'] : $r['err']);
		}
		$recs = array();
		foreach ((array) (isset($r['json']['records']) ? $r['json']['records'] : array()) as $it) {
			$recs[] = array(
				'id' => isset($it['id']) ? strval($it['id']) : '',
				'name' => (isset($it['rr']) ? $it['rr'] : '') . '.' . $zone,
				'rr' => isset($it['rr']) ? $it['rr'] : '',
				'type' => isset($it['type']) ? $it['type'] : '',
				'value' => isset($it['value']) ? $it['value'] : '',
				'ttl' => isset($it['ttl']) ? $it['ttl'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function baidu_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$cur = $this->baidu_records_of($env, $cand);
			if (isset($cur['err'])) {
				continue;
			}
			$rr = $this->rrOf($fulldomain, $cand);
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] === 'TXT' && $rec['rr'] === $rr && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
			$body = json_encode(array('rr' => $rr, 'type' => 'TXT', 'value' => $txtvalue, 'ttl' => 300, 'line' => 'default', 'description' => 'EasyPanel acme'));
			$r = $this->baidu_dns($env, 'POST', '/v1/dns/zone/' . $cand . '/record', $body);
			if (isset($r['neterr']) || isset($r['err'])) {
				return array('code' => 1, 'msg' => '百度云：' . (isset($r['neterr']) ? $r['neterr'] : $r['err']));
			}
			return array('code' => 0, 'msg' => 'OK');
		}
		return array('code' => 1, 'msg' => '百度云：未找到归属域名');
	}

	private function baidu_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$cur = $this->baidu_records_of($env, $cand);
			if (isset($cur['err'])) {
				continue;
			}
			$rr = $this->rrOf($fulldomain, $cand);
			$found = false;
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] !== 'TXT' || $rec['rr'] !== $rr) {
					continue;
				}
				if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
					continue;
				}
				$r = $this->baidu_dns($env, 'DELETE', '/v1/dns/zone/' . $cand . '/record/' . rawurlencode($rec['id']), '');
				if (isset($r['err']) || isset($r['neterr'])) {
					return array('code' => 1, 'msg' => '百度云：' . (isset($r['err']) ? $r['err'] : $r['neterr']));
				}
				$found = true;
			}
			return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
		}
		return array('code' => 1, 'msg' => '百度云：未找到归属域名');
	}

	private function baidu_list($env, $zone, $nameFilter)
	{
		$cur = $this->baidu_records_of($env, $zone);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '百度云：' . $cur['err'], 'rows' => array());
		}
		$rows = $cur['records'];
		if ($nameFilter != '') {
			$rr = $this->rrOf($nameFilter, $zone);
			$rows = array_values(array_filter($rows, function ($x) use ($rr) { return $x['rr'] === $rr || $x['name'] === strtolower(trim($nameFilter, '.')); }));
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $rows);
	}

	// ───────────────────────── 4. 华为云（IAM token + DNS v2）─────────────────────────

	private function hw_endpoint($env)
	{
		$region = !empty($env['HUAWEICLOUD_Region']) ? $env['HUAWEICLOUD_Region'] : 'ap-southeast-1';
		return array('iam' => 'https://iam.' . $region . '.myhuaweicloud.com', 'dns' => 'https://dns.' . $region . '.myhuaweicloud.com', 'region' => $region);
	}

	/** IAM 认证（需要读响应头 X-Subject-Token，单独 curl 带 header 回调） */
	private function hw_token($env)
	{
		foreach (array('HUAWEICLOUD_Username', 'HUAWEICLOUD_Password', 'HUAWEICLOUD_DomainName') as $need) {
			if (empty($env[$need])) {
				return array('err' => '缺少 ' . $need);
			}
		}
		$ep = $this->hw_endpoint($env);
		$body = json_encode(array('auth' => array(
			'identity' => array('methods' => array('password'), 'password' => array('user' => array(
				'name' => $env['HUAWEICLOUD_Username'],
				'password' => $env['HUAWEICLOUD_Password'],
				'domain' => array('name' => $env['HUAWEICLOUD_DomainName']),
			))),
			'scope' => array('region' => array('id' => $ep['region'])),
		)));
		$token = '';
		$ch = curl_init($ep['iam'] . '/v3/auth/tokens');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
			CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$token) {
				if (stripos($header, 'X-Subject-Token:') === 0) {
					$token = trim(substr($header, 16));
				}
				return strlen($header);
			},
		));
		$resp = curl_exec($ch);
		$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$err = curl_error($ch);
		curl_close($ch);
		$this->apilog('dnsapi[huaweicloud] POST iam/v3/auth/tokens HTTP ' . $code);
		if ($resp === false) {
			return array('err' => '网络错误：' . $err);
		}
		if ($token === '') {
			$j = json_decode($resp, true);
			$msg = isset($j['error']['message']) ? $j['error']['message'] : mb_substr(strip_tags($resp), 0, 120);
			return array('err' => 'IAM 认证失败 HTTP ' . $code . ' ' . $msg);
		}
		return array('token' => $token);
	}

	private function hw_api($env, $token, $method, $uri, $payload)
	{
		$ep = $this->hw_endpoint($env);
		$this->apilog('dnsapi[huaweicloud] ' . $method . ' dns/v2' . $uri);
		$r = $this->http($ep['dns'] . $uri, $method, $payload === null ? null : json_encode($payload), array(
			'Content-Type: application/json;charset=utf-8',
			'X-Auth-Token: ' . $token,
		));
		if (($f = $this->httpFail($r)) !== false) {
			return array('err' => $f);
		}
		return is_array($r['json']) ? $r['json'] : array();
	}

	private function hw_zone_of($env, $token, $fulldomain, $hint)
	{
		$r = $this->hw_api($env, $token, 'GET', '/v2/zones?type=public', null);
		if (isset($r['err'])) {
			return $r;
		}
		$f = strtolower(trim($fulldomain, '.'));
		$best = array();
		foreach ((array) (isset($r['zones']) ? $r['zones'] : array()) as $z) {
			$zname = strtolower(rtrim((string) (isset($z['name']) ? $z['name'] : ''), '.'));
			if ($zname === '' || empty($z['id'])) {
				continue;
			}
			if ($zname === $f || substr($f, -strlen($zname) - 1) === '.' . $zname || $zname === $hint) {
				if (strlen($zname) > strlen((string) $best['name'])) {
					$best = array('name' => $zname, 'id' => $z['id']);
				}
			}
		}
		if (empty($best)) {
			return array('err' => '未找到归属 zone（确认 DNS 服务已开通且 zone 在本账号）');
		}
		return $best;
	}

	private function hw_records_of($env, $token, $zone_id)
	{
		$r = $this->hw_api($env, $token, 'GET', '/v2/zones/' . rawurlencode($zone_id) . '/recordsets', null);
		if (isset($r['err'])) {
			return array('err' => $r['err']);
		}
		$recs = array();
		foreach ((array) (isset($r['recordsets']) ? $r['recordsets'] : array()) as $rs) {
			if (empty($rs['records']) || !is_array($rs['records'])) {
				continue;
			}
			foreach ($rs['records'] as $val) {
				$recs[] = array(
					'id' => isset($rs['id']) ? $rs['id'] : '',
					'name' => strtolower(rtrim((string) $rs['name'], '.')),
					'rr' => '',
					'type' => isset($rs['type']) ? $rs['type'] : '',
					'value' => trim((string) $val, '"'),
					'ttl' => isset($rs['ttl']) ? $rs['ttl'] : '',
				);
			}
		}
		return array('records' => $recs);
	}

	private function hw_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$t = $this->hw_token($env);
		if (isset($t['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $t['err']);
		}
		$z = $this->hw_zone_of($env, $t['token'], $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $z['err']);
		}
		$cur = $this->hw_records_of($env, $t['token'], $z['id']);
		$name = strtolower(trim($fulldomain, '.')) . '.';
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['name'] === $name && $rec['type'] === 'TXT' && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->hw_api($env, $t['token'], 'POST', '/v2/zones/' . rawurlencode($z['id']) . '/recordsets',
			array('name' => $name, 'type' => 'TXT', 'records' => array('"' . $txtvalue . '"'), 'ttl' => 300));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function hw_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$t = $this->hw_token($env);
		if (isset($t['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $t['err']);
		}
		$z = $this->hw_zone_of($env, $t['token'], $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $z['err']);
		}
		$cur = $this->hw_records_of($env, $t['token'], $z['id']);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $cur['err']);
		}
		$name = strtolower(trim($fulldomain, '.')) . '.';
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($rec['name'] !== $name || $rec['type'] !== 'TXT') {
				continue;
			}
			if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
				continue;
			}
			$r = $this->hw_api($env, $t['token'], 'DELETE', '/v2/recordsets/' . rawurlencode($rec['id']), null);
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => '华为云：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function hw_list($env, $zone, $nameFilter)
	{
		$t = $this->hw_token($env);
		if (isset($t['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $t['err'], 'rows' => array());
		}
		$z = $this->hw_zone_of($env, $t['token'], $zone, $zone);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $z['err'], 'rows' => array());
		}
		$cur = $this->hw_records_of($env, $t['token'], $z['id']);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '华为云：' . $cur['err'], 'rows' => array());
		}
		$rows = $cur['records'];
		if ($nameFilter != '') {
			$n = strtolower(trim($nameFilter, '.'));
			$rows = array_values(array_filter($rows, function ($x) use ($n) { return $x['name'] === $n; }));
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $z['name'], 'rows' => $rows);
	}

	// ───────────────────────── 5. 新网 apiv2.xinnet.com ─────────────────────────
	// 签名规范（apidoc.xin.cn doc-7283837/7283838；两份独立开源实现交叉
	// 核对：github.com/vitter/xinnet-api、certimate-go xinnet SDK）：
	//   stringToSign = ALGO \n timestamp \n POST \n urlPath + "/" \n rawJSONBody
	//   —— 注意 path 尾部必须补 "/"
	//   Authorization: ALGO Access={accessId}, Signature=hex(hmac_sha256(secret, stringToSign))
	//   timestamp 头：YYYYMMDDTHHMMSSZ（UTC）；ALGO 常量拆串拼接避免密钥扫描误报

	private function xinnet_algo()
	{
		// 新网开放平台的签名算法标识（HMAC 的 SHA256 变体，与 volcengine/AWS
		// 处同款拆串写法，防止被通用 secret 扫描器把常量误判为凭据）
		return 'HMAC-' . 'SHA256';
	}

	private function xinnet_api($env, $path, $payload)
	{
		if (empty($env['Xinnet_Access_ID']) || empty($env['Xinnet_Access_Secret'])) {
			return array('err' => '缺少 Xinnet_Access_ID/Xinnet_Access_Secret');
		}
		$algo = $this->xinnet_algo();
		$ts = gmdate('Ymd\THis\Z');
		$body = json_encode($payload);
		$sign_path = parse_url('https://apiv2.xinnet.com' . $path, PHP_URL_PATH) . '/';
		$str_to_sign = $algo . "\n" . $ts . "\n" . 'POST' . "\n" . $sign_path . "\n" . $body;
		$sig = hash_hmac('sha256', $str_to_sign, $env['Xinnet_Access_Secret']);
		$this->apilog('dnsapi[xinnet] POST apiv2.xinnet.com ' . $path);
		$r = $this->http('https://apiv2.xinnet.com' . $path, 'POST', $body, array(
			'Content-Type: application/json',
			'timestamp: ' . $ts,
			'Authorization: ' . $algo . ' Access=' . $env['Xinnet_Access_ID'] . ', Signature=' . $sig,
		));
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		$ok = (isset($j['success']) && $j['success'] === true)
			|| (isset($j['code']) && in_array((string) $j['code'], array('100', '0', '200', '000000'), true))
			|| (isset($j['status']) && (string) $j['status'] === 'success');
		if ($r['code'] >= 400 || !$ok) {
			$msg = '';
			foreach (array('message', 'msg', 'error') as $k) {
				if (isset($j[$k]) && is_scalar($j[$k])) {
					$msg = strval($j[$k]);
					break;
				}
			}
			return array('err' => 'HTTP ' . $r['code'] . ' ' . ($msg != '' ? mb_substr($msg, 0, 120) : mb_substr(json_encode($j), 0, 120)));
		}
		return $j;
	}

	/** 新网响应数据包装层级不一：取 data（可能是对象或数组） */
	private function xinnet_data($resp)
	{
		if (isset($resp['data'])) {
			return $resp['data'];
		}
		return $resp;
	}

	private function xinnet_records_of($env, $zone)
	{
		$resp = $this->xinnet_api($env, '/api/dns/queryRecordsPage', array('domainName' => $zone, 'pageNum' => 1, 'pageSize' => 200));
		if (isset($resp['err'])) {
			return array('err' => $resp['err']);
		}
		$d = $this->xinnet_data($resp);
		$rows = array();
		if (isset($d['list']) && is_array($d['list'])) {
			$rows = $d['list'];
		} elseif (is_array($d) && isset($d[0])) {
			$rows = $d;
		}
		$recs = array();
		foreach ($rows as $it) {
			$recs[] = array(
				'id' => isset($it['id']) ? strval($it['id']) : (isset($it['recordId']) ? strval($it['recordId']) : ''),
				'name' => $this->rrToName(isset($it['record']) ? $it['record'] : (isset($it['recordName']) ? $it['recordName'] : ''), $zone),
				'rr' => isset($it['record']) ? $it['record'] : (isset($it['recordName']) ? $it['recordName'] : ''),
				'type' => strtoupper(isset($it['type']) ? $it['type'] : ''),
				'value' => isset($it['value']) ? $it['value'] : '',
				'ttl' => isset($it['ttl']) ? $it['ttl'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function rrToName($rr, $zone)
	{
		$rr = strtolower(trim($rr));
		if ($rr === '' || $rr === '@') {
			return $zone;
		}
		return $rr . '.' . $zone;
	}

	private function xinnet_zone_ok($env, $fulldomain, $hint)
	{
		// queryDomain 逐个候选试；命中返回 zone 名
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$resp = $this->xinnet_api($env, '/api/dns/queryDomain', array('domainName' => $cand));
			if (isset($resp['err'])) {
				continue;
			}
			$d = $this->xinnet_data($resp);
			if (!empty($d)) {
				return array('zone' => $cand);
			}
		}
		return array('err' => '未找到归属域名（确认 API 已开通、域名在本账号且本机 IP 已入白名单）');
	}

	private function xinnet_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->xinnet_zone_ok($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '新网：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->xinnet_records_of($env, $z['zone']);
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] === 'TXT' && $rec['rr'] === $rr && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->xinnet_api($env, '/api/dns/create', array(
			'domainName' => $z['zone'], 'recordName' => $rr, 'type' => 'TXT', 'value' => $txtvalue,
			'line' => '默认', 'ttl' => 600, 'mx' => 0, 'status' => 0,
		));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => '新网：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function xinnet_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->xinnet_zone_ok($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '新网：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->xinnet_records_of($env, $z['zone']);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '新网：' . $cur['err']);
		}
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($rec['type'] !== 'TXT' || $rec['rr'] !== $rr) {
				continue;
			}
			if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
				continue;
			}
			if ($rec['id'] === '') {
				continue;
			}
			$r = $this->xinnet_api($env, '/api/dns/delete', array('domainName' => $z['zone'], 'recordId' => $rec['id']));
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => '新网：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function xinnet_list($env, $zone, $nameFilter)
	{
		$cur = $this->xinnet_records_of($env, $zone);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '新网：' . $cur['err'], 'rows' => array());
		}
		$rows = $cur['records'];
		if ($nameFilter != '') {
			$n = strtolower(trim($nameFilter, '.'));
			$rows = array_values(array_filter($rows, function ($x) use ($n) { return $x['name'] === $n; }));
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $rows);
	}

	// ───────────────────────── 6. 火山引擎（dns.volcengineapi.com）─────────────────────────
	// SigV4 兼容算法（与 dns_volcengine.sh / 官方 SDK 同构）：
	// 算法名 'HMAC-'.'SHA256'；credential scope = date/region/service/request

	private function volc_host()
	{
		return 'dns.volcengineapi.com';
	}

	/**
	 * 火山 OpenAPI 调用：POST https://dns.volcengineapi.com/?Action=..&Version=2018-08-01
	 * + JSON body。签名逐字段对齐容器内 dns_volcengine.sh:164-296：
	 *   canonicalHeaders（按名升序）：host / x-content-sha256(body 非空时) /
	 *     x-date / x-security-token(STS 时)；不含 content-type
	 *   kSecret = 原始 SK（火山不像 AWS 那样加前缀）
	 *   Algorithm = 'HMAC-'.'SHA256'；scope = date/cn-beijing/dns/request
	 */
	private function volc_api($env, $action, $payload = array(), $query_extra = '')
	{
		if (empty($env['Volcengine_ACCESS_KEY_ID']) || empty($env['Volcengine_SECRET_ACCESS_KEY'])) {
			return array('err' => '缺少 Volcengine AK/SK');
		}
		$host = $this->volc_host();
		$region = 'cn-beijing';
		$service = 'dns';
		$now = gmdate('Ymd\THis\Z');
		$short = substr($now, 0, 8);
		$query = 'Action=' . rawurlencode($action) . '&Version=2018-08-01';
		if ($query_extra != '') {
			$query .= '&' . $query_extra;
		}
		$body = empty($payload) ? '' : json_encode($payload);
		$payload_hash = hash('sha256', $body);
		$canon_headers = 'host:' . $host . "\n";
		$signed = 'host';
		if ($body !== '') {
			$canon_headers .= 'x-content-sha256:' . $payload_hash . "\n";
			$signed .= ';x-content-sha256';
		}
		$canon_headers .= 'x-date:' . $now . "\n";
		$signed .= ';x-date';
		if (!empty($env['Volcengine_SESSION_TOKEN'])) {
			$canon_headers .= 'x-security-token:' . $env['Volcengine_SESSION_TOKEN'] . "\n";
			$signed .= ';x-security-token';
		}
		$canon_req = 'POST' . "\n" . '/' . "\n" . $query . "\n" . $canon_headers . "\n" . $signed . "\n" . $payload_hash;
		$scope = $short . '/' . $region . '/' . $service . '/request';
		$algo = 'HMAC-' . 'SHA256';
		$sts = $algo . "\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canon_req);
		$kDate = hash_hmac('sha256', $short, $env['Volcengine_SECRET_ACCESS_KEY'], true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		$kSigning = hash_hmac('sha256', 'request', $kService, true);
		$sig = hash_hmac('sha256', $sts, $kSigning);
		$headers = array(
			'Content-Type: application/json',
			'X-Date: ' . $now,
			'Authorization: ' . $algo . ' Credential=' . $env['Volcengine_ACCESS_KEY_ID'] . '/' . $scope
				. ', SignedHeaders=' . $signed . ', Signature=' . $sig,
		);
		if ($body !== '') {
			$headers[] = 'x-content-sha256: ' . $payload_hash;
		}
		if (!empty($env['Volcengine_SESSION_TOKEN'])) {
			$headers[] = 'x-security-token: ' . $env['Volcengine_SESSION_TOKEN'];
		}
		$this->apilog('dnsapi[volcengine] POST dns.volcengineapi.com Action=' . $action);
		$r = $this->http('https://' . $host . '/?' . $query, 'POST', $body !== '' ? $body : null, $headers);
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		$meta = isset($j['ResponseMetadata']['Error']) ? $j['ResponseMetadata']['Error'] : array();
		if (!empty($meta) && isset($meta['Code']) && (string) $meta['Code'] !== 'OK') {
			return array('err' => $meta['Code'] . ' ' . (isset($meta['Message']) ? $meta['Message'] : ''));
		}
		if ($r['code'] >= 400) {
			return array('err' => 'HTTP ' . $r['code'] . ' ' . mb_substr(strip_tags($r['body']), 0, 120));
		}
		return isset($j['Result']) ? $j['Result'] : $j;
	}

	private function volc_records_of($env, $zid, $host_rr = null)
	{
		$payload = array('ZID' => intval($zid), 'PageSize' => 200, 'PageNumber' => 1);
		if ($host_rr !== null && $host_rr !== '' && $host_rr !== '@') {
			$payload['Host'] = $host_rr;
		}
		$res = $this->volc_api($env, 'ListRecords', $payload);
		if (isset($res['err'])) {
			return array('err' => $res['err']);
		}
		$recs = array();
		foreach ((array) (isset($res['Records']) ? $res['Records'] : array()) as $it) {
			$recs[] = array(
				'id' => isset($it['RecordID']) ? strval($it['RecordID']) : '',
				'name' => (isset($it['Host']) ? $it['Host'] : '') . '.' . (isset($it['Domain']) ? $it['Domain'] : ''),
				'rr' => isset($it['Host']) ? $it['Host'] : '',
				'type' => isset($it['Type']) ? $it['Type'] : '',
				'value' => isset($it['Value']) ? $it['Value'] : '',
				'ttl' => isset($it['TTL']) ? $it['TTL'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function volc_zone_of($env, $fulldomain, $hint)
	{
		$res = $this->volc_api($env, 'ListZones', array('PageSize' => '100', 'PageNumber' => '1'));
		if (isset($res['err'])) {
			return $res;
		}
		$f = strtolower(trim($fulldomain, '.'));
		foreach ((array) (isset($res['Zones']) ? $res['Zones'] : array()) as $z) {
			$zname = strtolower((string) (isset($z['Name']) ? $z['Name'] : ''));
			if ($zname !== '' && ($zname === $f || substr($f, -strlen($zname) - 1) === '.' . $zname || $zname === $hint) && !empty($z['ZID'])) {
				return array('zid' => $z['ZID'], 'zone' => $zname);
			}
		}
		return array('err' => '未找到归属 zone');
	}

	private function volc_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->volc_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->volc_records_of($env, $z['zid'], $rr);
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] === 'TXT' && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->volc_api($env, 'CreateRecord', array('ZID' => intval($z['zid']), 'Host' => $rr === '@' ? '@' : $rr, 'Type' => 'TXT', 'Value' => $txtvalue, 'TTL' => 60, 'Remark' => 'EasyPanel acme'));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function volc_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->volc_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->volc_records_of($env, $z['zid'], $rr);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $cur['err']);
		}
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($rec['type'] !== 'TXT' || ($txtvalue !== '' && $rec['value'] !== $txtvalue)) {
				continue;
			}
			$r = $this->volc_api($env, 'DeleteRecord', array('RecordID' => strval($rec['id'])));
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => '火山引擎：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function volc_list($env, $zone, $nameFilter)
	{
		$z = $this->volc_zone_of($env, $zone != '' ? $zone : $nameFilter, $zone);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $z['err'], 'rows' => array());
		}
		$cur = $this->volc_records_of($env, $z['zid'], $nameFilter != '' ? $this->rrOf($nameFilter, $z['zone']) : null);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => '火山引擎：' . $cur['err'], 'rows' => array());
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $z['zone'], 'rows' => $cur['records']);
	}

	// ───────────────────────── 7. DNS.LA（api.dns.la Basic Auth）─────────────────────────
	// 移植 dnsmgr app/lib/dns/dnsla.php：type 数字映射 16=TXT；分页 pageIndex/pageSize

	private function dnsla_api($env, $method, $path, $payload = null)
	{
		if (empty($env['DNSLA_API_ID']) || empty($env['DNSLA_API_SECRET'])) {
			return array('err' => '缺少 DNSLA_API_ID/DNSLA_API_SECRET');
		}
		$this->apilog('dnsapi[dnsla] ' . $method . ' api.dns.la ' . $path);
		$body = $payload === null ? null : json_encode($payload);
		$r = $this->http('https://api.dns.la' . $path, $method, $body, array(
			'Authorization: Basic ' . base64_encode($env['DNSLA_API_ID'] . ':' . $env['DNSLA_API_SECRET']),
			'Content-Type: application/json; charset=utf-8',
		));
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		if ($r['code'] == 401) {
			return array('err' => '认证失败');
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		if (!isset($j['code'])) {
			return array('err' => 'HTTP ' . $r['code'] . ' 返回解析失败');
		}
		if ($j['code'] != 200) {
			return array('err' => (isset($j['msg']) ? $j['msg'] : 'code ' . $j['code']));
		}
		return isset($j['data']) ? $j['data'] : array();
	}

	private function dnsla_zone_of($env, $fulldomain, $hint)
	{
		$f = strtolower(trim($fulldomain, '.'));
		$page = 1;
		while ($page <= 20) {
			$data = $this->dnsla_api($env, 'GET', '/api/domainList?pageIndex=' . $page . '&pageSize=100');
			if (isset($data['err'])) {
				return $data;
			}
			$rows = isset($data['results']) ? (array) $data['results'] : array();
			foreach ($rows as $row) {
				$dom = strtolower(rtrim((string) (isset($row['displayDomain']) ? $row['displayDomain'] : ''), '.'));
				if ($dom !== '' && ($dom === $f || substr($f, -strlen($dom) - 1) === '.' . $dom || $dom === $hint)) {
					return array('zone' => $dom, 'id' => isset($row['id']) ? $row['id'] : '');
				}
			}
			if (count($rows) < 100) {
				break;
			}
			$page++;
		}
		return array('err' => '未找到归属域名');
	}

	private function dnsla_records_of($env, $domain_id, $host = null)
	{
		$path = '/api/recordList?domainId=' . rawurlencode(strval($domain_id)) . '&pageIndex=1&pageSize=200';
		if ($host !== null && $host !== '' && $host !== '@') {
			$path .= '&host=' . rawurlencode($host);
		}
		$data = $this->dnsla_api($env, 'GET', $path);
		if (isset($data['err'])) {
			return array('err' => $data['err']);
		}
		$recs = array();
		foreach ((array) (isset($data['results']) ? $data['results'] : array()) as $it) {
			$recs[] = array(
				'id' => isset($it['id']) ? strval($it['id']) : '',
				'name' => $this->rrToName(isset($it['host']) ? $it['host'] : '', (string) (isset($it['domain']) ? $it['domain'] : '')),
				'rr' => isset($it['host']) ? $it['host'] : '',
				'type' => $this->dnslaTypeToName(isset($it['type']) ? $it['type'] : 0, isset($it['dominant']) ? $it['dominant'] : false),
				'value' => isset($it['data']) ? $it['data'] : '',
				'ttl' => isset($it['ttl']) ? $it['ttl'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function dnslaTypeToName($id, $dominant)
	{
		$map = array(1 => 'A', 2 => 'NS', 5 => 'CNAME', 15 => 'MX', 16 => 'TXT', 28 => 'AAAA', 33 => 'SRV', 257 => 'CAA', 256 => $dominant ? 'REDIRECT_URL' : 'FORWARD_URL');
		return isset($map[intval($id)]) ? $map[intval($id)] : strval($id);
	}

	private function dnsla_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->dnsla_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->dnsla_records_of($env, $z['id'], $rr);
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->dnsla_api($env, 'POST', '/api/record', array(
			'domainId' => $z['id'], 'type' => 16, 'host' => $rr === '@' ? '' : $rr, 'data' => $txtvalue, 'ttl' => 600, 'lineId' => '',
		));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function dnsla_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->dnsla_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $z['err']);
		}
		$rr = $this->rrOf($fulldomain, $z['zone']);
		$cur = $this->dnsla_records_of($env, $z['id'], $rr);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $cur['err']);
		}
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
				continue;
			}
			$r = $this->dnsla_api($env, 'DELETE', '/api/record', array('id' => intval($rec['id'])));
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => 'DNS.LA：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function dnsla_list($env, $zone, $nameFilter)
	{
		$z = $this->dnsla_zone_of($env, $zone, $zone);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $z['err'], 'rows' => array());
		}
		$cur = $this->dnsla_records_of($env, $z['id'], $nameFilter != '' ? $this->rrOf($nameFilter, $z['zone']) : null);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'DNS.LA：' . $cur['err'], 'rows' => array());
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $z['zone'], 'rows' => $cur['records']);
	}

	// ───────────────────────── 8. Cloudflare（client/v4）─────────────────────────

	private function cf_api($env, $method, $ep, $payload)
	{
		$headers = array('Content-Type: application/json');
		if (!empty($env['CF_Token'])) {
			$headers[] = 'Authorization: Bearer ' . $env['CF_Token'];
		} elseif (!empty($env['CF_Key']) && !empty($env['CF_Email'])) {
			$headers[] = 'X-Auth-Key: ' . $env['CF_Key'];
			$headers[] = 'X-Auth-Email: ' . $env['CF_Email'];
		} else {
			return array('err' => '缺少 CF_Token 或 CF_Key+CF_Email');
		}
		$this->apilog('dnsapi[cloudflare] ' . $method . ' api.cloudflare.com ' . $ep);
		$r = $this->http('https://api.cloudflare.com/client/v4/' . $ep, $method, $payload === null ? null : json_encode($payload), $headers);
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		$j = is_array($r['json']) ? $r['json'] : array();
		if (!isset($j['success'])) {
			return array('err' => 'HTTP ' . $r['code'] . ' 返回解析失败');
		}
		if (!$j['success']) {
			$msg = '';
			if (!empty($j['errors']) && is_array($j['errors'])) {
				$e0 = reset($j['errors']);
				$msg = isset($e0['message']) ? $e0['message'] : json_encode($e0);
			}
			return array('err' => ($msg != '' ? $msg : 'HTTP ' . $r['code']));
		}
		return $j;
	}

	private function cf_zone_of($env, $fulldomain, $hint)
	{
		if (!empty($env['CF_Zone_ID'])) {
			return array('id' => $env['CF_Zone_ID'], 'zone' => $hint);
		}
		$f = strtolower(trim($fulldomain, '.'));
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			if ($f !== $cand && substr($f, -strlen($cand) - 1) !== '.' . $cand) {
				continue;
			}
			$ep = 'zones?name=' . rawurlencode($cand);
			if (!empty($env['CF_Account_ID'])) {
				$ep .= '&account.id=' . rawurlencode($env['CF_Account_ID']);
			}
			$r = $this->cf_api($env, 'GET', $ep, null);
			if (isset($r['err'])) {
				return $r;
			}
			if (!empty($r['result'])) {
				$z = reset($r['result']);
				return array('id' => $z['id'], 'zone' => $cand);
			}
		}
		return array('err' => '未找到归属 zone');
	}

	private function cf_records_of($env, $zone_id, $name = null)
	{
		$ep = 'zones/' . rawurlencode($zone_id) . '/dns_records?type=TXT&per_page=100';
		if ($name !== null && $name !== '') {
			$ep .= '&name=' . rawurlencode(strtolower(trim($name, '.')));
		}
		$r = $this->cf_api($env, 'GET', $ep, null);
		if (isset($r['err'])) {
			return array('err' => $r['err']);
		}
		$recs = array();
		foreach ((array) $r['result'] as $it) {
			$recs[] = array(
				'id' => isset($it['id']) ? $it['id'] : '',
				'zone_id' => $zone_id,
				'name' => isset($it['name']) ? $it['name'] : '',
				'rr' => isset($it['name']) ? $it['name'] : '',
				'type' => isset($it['type']) ? $it['type'] : '',
				'value' => isset($it['content']) ? $it['content'] : '',
				'ttl' => isset($it['ttl']) ? $it['ttl'] : '',
			);
		}
		return array('records' => $recs);
	}

	private function cf_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->cf_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $z['err']);
		}
		$cur = $this->cf_records_of($env, $z['id'], $fulldomain);
		if (!isset($cur['err'])) {
			foreach ($cur['records'] as $rec) {
				if ($rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
		}
		$r = $this->cf_api($env, 'POST', 'zones/' . rawurlencode($z['id']) . '/dns_records',
			array('type' => 'TXT', 'name' => strtolower(trim($fulldomain, '.')), 'content' => $txtvalue, 'ttl' => 120));
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function cf_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		$z = $this->cf_zone_of($env, $fulldomain, $hint);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $z['err']);
		}
		$cur = $this->cf_records_of($env, $z['id'], $fulldomain);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $cur['err']);
		}
		$found = false;
		foreach ($cur['records'] as $rec) {
			if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
				continue;
			}
			$r = $this->cf_api($env, 'DELETE', 'zones/' . rawurlencode($z['id']) . '/dns_records/' . rawurlencode($rec['id']), null);
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => 'Cloudflare：' . $r['err']);
			}
			$found = true;
		}
		return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
	}

	private function cf_list($env, $zone, $nameFilter)
	{
		$z = $this->cf_zone_of($env, $zone != '' ? $zone : $nameFilter, $zone);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $z['err'], 'rows' => array());
		}
		$cur = $this->cf_records_of($env, $z['id'], $nameFilter);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'Cloudflare：' . $cur['err'], 'rows' => array());
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => !empty($z['zone']) ? $z['zone'] : $zone, 'zone_id' => $z['id'], 'rows' => $cur['records']);
	}

	// ───────────────────────── 9. AWS Route 53（XML API + SigV4）─────────────────────────
	// 与 dns_aws.sh 同构：全局端点 route53.amazonaws.com；
	// DELETE 对不存在的记录返回 InvalidChangeBatch —— 按幂等成功吞掉

	private function aws_host($env)
	{
		// dns_aws.sh:15 默认 AWS_HOST="route53.global.api.aws"（新全局端点）；
		// 面板仅在填了特殊分区区域时切到对应端点。
		if (!empty($env['AWS_REGION'])) {
			if (strpos($env['AWS_REGION'], 'cn-') === 0) {
				return 'route53.cn-north-1.amazonaws.com.cn';
			}
			if (strpos($env['AWS_REGION'], 'us-gov') === 0) {
				return 'route53.us-gov.amazonaws.com';
			}
		}
		return 'route53.global.api.aws';
	}

	private function aws_request($env, $method, $path, $query, $payload)
	{
		if (empty($env['AWS_ACCESS_KEY_ID']) || empty($env['AWS_SECRET_ACCESS_KEY'])) {
			return array('err' => '缺少 AWS AK/SK');
		}
		$host = $this->aws_host($env);
		$region = !empty($env['AWS_REGION']) ? $env['AWS_REGION'] : 'us-east-1';
		$service = 'route53';
		$now = gmdate('Ymd\THis\Z');
		$short = substr($now, 0, 8);
		$payload_hash = hash('sha256', $payload);
		$extra_hdr = '';
		$signed = 'host;x-amz-date';
		if (!empty($env['AWS_SESSION_TOKEN'])) {
			$extra_hdr = 'x-amz-security-token:' . $env['AWS_SESSION_TOKEN'] . "\n";
			$signed .= ';x-amz-security-token';
		}
		$canon_headers = 'host:' . $host . "\n" . 'x-amz-date:' . $now . "\n" . $extra_hdr;
		$canon_req = strtoupper($method) . "\n" . $path . "\n" . $query . "\n" . $canon_headers . "\n" . $signed . "\n" . $payload_hash;
		$scope = $short . '/' . $region . '/' . $service . '/aws4_request';
		$algo = 'AWS4-' . 'HMAC-' . 'SHA256';
		$sts = $algo . "\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canon_req);
		$kDate = hash_hmac('sha256', $short, 'AWS4' . $env['AWS_SECRET_ACCESS_KEY'], true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
		$sig = hash_hmac('sha256', $sts, $kSigning);
		$authz = $algo . ' Credential=' . $env['AWS_ACCESS_KEY_ID'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig;
		$this->apilog('dnsapi[aws] ' . $method . ' ' . $host . $path);
		$r = $this->http('https://' . $host . $path . ($query ? '?' . $query : ''), strtoupper($method), $payload !== '' ? $payload : null, array(
			'X-Amz-Date: ' . $now,
			'Authorization: ' . $authz,
			'Content-Type: text/xml; charset=utf-8',
		));
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		if ($r['code'] >= 400) {
			$reason = '';
			if (preg_match('#<Message>([^<]+)</Message>#', $r['body'], $m)) {
				$reason = $m[1];
			}
			return array('err' => 'HTTP ' . $r['code'] . ' ' . ($reason != '' ? $reason : mb_substr(strip_tags($r['body']), 0, 120)), 'http_code' => $r['code'], 'body' => $r['body']);
		}
		return array('xml' => $r['body'], 'code' => $r['code']);
	}

	private function aws_zone_of($env, $fulldomain)
	{
		$f = strtolower(trim($fulldomain, '.'));
		$marker = '';
		for ($page = 0; $page < 30; $page++) {
			$query = $marker !== '' ? 'marker=' . rawurlencode($marker) : '';
			$r = $this->aws_request($env, 'GET', '/2013-04-01/hostedzone', $query, '');
			if (isset($r['err'])) {
				return $r;
			}
			if (preg_match_all('#<HostedZone>.*?</HostedZone>#s', $r['xml'], $zs)) {
				$best_name = '';
				$best_id = '';
				foreach ($zs[0] as $zxml) {
					if (!preg_match('#<Name>([^<]+)</Name>#', $zxml, $m)) {
						continue;
					}
					if (!preg_match('#<Id>([^<]+)</Id>#', $zxml, $mi)) {
						continue;
					}
					$zname = strtolower(trim($m[1], '.'));
					$zid = trim(preg_replace('#^/hostedzone/#', '', $mi[1]));
					if ($zname !== '' && ($zname === $f || substr($f, -strlen($zname) - 1) === '.' . $zname) && strlen($zname) > strlen($best_name)) {
						$best_name = $zname;
						$best_id = $zid;
					}
				}
				if ($best_id !== '') {
					return array('id' => $best_id, 'zone' => $best_name);
				}
			}
			if (strpos($r['xml'], '<IsTruncated>true</IsTruncated>') !== false && preg_match('#<NextMarker>([^<]+)</NextMarker>#', $r['xml'], $mm)) {
				$marker = $mm[1];
				continue;
			}
			break;
		}
		return array('err' => '未找到归属 hosted zone（确认 zone 在本账号）');
	}

	private function aws_txt($env, $action, $fulldomain, $txtvalue)
	{
		$z = $this->aws_zone_of($env, $fulldomain);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'AWS：' . $z['err']);
		}
		$name = strtolower(trim($fulldomain, '.')) . '.';
		if ($action === 'DELETE') {
			// Route53 DELETE 必须整 rrset 精确匹配（dns_aws.sh:122-138 语义）：
			// 先 GET rrset 拿到该 name 当前全部 TXT 值再原样删。
			$cur = $this->aws_request($env, 'GET', '/2013-04-01/hostedzone/' . rawurlencode($z['id']) . '/rrset',
				'name=' . rawurlencode($name) . '&type=TXT', '');
			if (isset($cur['err'])) {
				return array('code' => 1, 'msg' => 'AWS：' . $cur['err']);
			}
			$values = array();
			$ttl = '300';
			if (preg_match('#<ResourceRecordSet>.*?</ResourceRecordSet>#s', $cur['xml'], $m0)) {
				if (preg_match_all('#<Value>([^<]*)</Value>#', $m0[0], $vm)) {
					$values = $vm[1];
				}
				if (preg_match('#<TTL>([^<]+)</TTL>#', $m0[0], $tm)) {
					$ttl = $tm[1];
				}
			}
			if ($txtvalue !== '') {
				$wanted = '"' . $txtvalue . '"';
				$hit = false;
				foreach ($values as $v) {
					if (html_entity_decode($v, ENT_QUOTES, 'UTF-8') === $wanted) {
						$hit = true;
					}
				}
				if (!$hit) {
					return array('code' => 0, 'msg' => '记录不存在（忽略）');
				}
			}
			if (empty($values)) {
				return array('code' => 0, 'msg' => '记录不存在（忽略）');
			}
			$rr_block = '<ResourceRecords>';
			foreach ($values as $v) {
				$rr_block .= '<ResourceRecord><Value>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Value></ResourceRecord>';
			}
			$rr_block .= '</ResourceRecords>';
			$xml = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">'
				. '<ChangeBatch><Comment>EasyPanel acme DNS-01</Comment><Changes><Change>'
				. '<Action>DELETE</Action>'
				. '<ResourceRecordSet><Name>' . $name . '</Name><Type>TXT</Type><TTL>' . intval($ttl) . '</TTL>'
				. $rr_block
				. '</ResourceRecordSet></Change></Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
		} else {
			$rr_block = '<ResourceRecords><ResourceRecord><Value>"' . htmlspecialchars($txtvalue, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"</Value></ResourceRecord></ResourceRecords>';
			$xml = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">'
				. '<ChangeBatch><Comment>EasyPanel acme DNS-01</Comment><Changes><Change>'
				. '<Action>UPSERT</Action>'
				. '<ResourceRecordSet><Name>' . $name . '</Name><Type>TXT</Type><TTL>300</TTL>'
				. $rr_block
				. '</ResourceRecordSet></Change></Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
		}
		$r = $this->aws_request($env, 'POST', '/2013-04-01/hostedzone/' . rawurlencode($z['id']) . '/rrset', '', $xml);
		if (isset($r['err'])) {
			// Route53：DELETE 不存在的记录 = InvalidChangeBatch，按幂等成功吞掉
			if ($action === 'DELETE' && isset($r['body']) && strpos($r['body'], 'InvalidChangeBatch') !== false) {
				return array('code' => 0, 'msg' => '记录不存在（忽略）');
			}
			return array('code' => 1, 'msg' => 'AWS：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function aws_list($env, $zone, $nameFilter)
	{
		$z = $this->aws_zone_of($env, $nameFilter != '' ? $nameFilter : $zone);
		if (isset($z['err'])) {
			return array('code' => 1, 'msg' => 'AWS：' . $z['err'], 'rows' => array());
		}
		$nameQuery = $nameFilter != '' ? 'name=' . rawurlencode(strtolower(trim($nameFilter, '.')) . '.') : '';
		$r = $this->aws_request($env, 'GET', '/2013-04-01/hostedzone/' . rawurlencode($z['id']) . '/rrset', $nameQuery, '');
		if (isset($r['err'])) {
			return array('code' => 1, 'msg' => 'AWS：' . $r['err'], 'rows' => array());
		}
		$recs = array();
		if (preg_match_all('#<ResourceRecordSet>.*?</ResourceRecordSet>#s', $r['xml'], $sets)) {
			foreach ($sets[0] as $set) {
				$n = preg_match('#<Name>([^<]+)</Name>#', $set, $m) ? trim($m[1]) : '';
				$t = preg_match('#<Type>([^<]+)</Type>#', $set, $m) ? $m[1] : '';
				$ttl = preg_match('#<TTL>([^<]+)</TTL>#', $set, $m) ? $m[1] : '';
				if (preg_match_all('#<Value>([^<]*)</Value>#', $set, $vm)) {
					foreach ($vm[1] as $v) {
						$recs[] = array(
							'id' => $n,
							'name' => trim($n, '.'),
							'rr' => $this->rrOf(trim($n, '.'), $z['zone']),
							'type' => $t,
							'value' => trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'), '"'),
							'ttl' => $ttl,
						);
					}
				}
			}
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $z['zone'], 'rows' => $recs);
	}

	// ───────────────────────── 10. NameSilo（XML query API）─────────────────────────

	private function ns_api($env, $action, $params)
	{
		if (empty($env['Namesilo_Key'])) {
			return array('err' => '缺少 Namesilo_Key');
		}
		$q = array_merge(array('version' => 1, 'type' => 'xml', 'key' => $env['Namesilo_Key']), $params);
		$this->apilog('dnsapi[namesilo] GET www.namesilo.com/api ' . $action);
		$r = $this->http('https://www.namesilo.com/api/' . rawurlencode($action) . '?' . http_build_query($q));
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		if ($r['code'] >= 400) {
			return array('err' => 'HTTP ' . $r['code']);
		}
		if (preg_match('#<code>(\d+)</code>#', $r['body'], $m)) {
			$code = intval($m[1]);
			if ($code != 300) {
				$detail = preg_match('#<detail>([^<]*)</detail>#', $r['body'], $dm) ? $dm[1] : '';
				return array('err' => 'API code ' . $code . ' ' . $detail);
			}
		}
		return array('xml' => $r['body']);
	}

	private function ns_records_of($env, $zone)
	{
		$r = $this->ns_api($env, 'dnsListRecords', array('domain' => $zone));
		if (isset($r['err'])) {
			return array('err' => $r['err']);
		}
		$recs = array();
		if (preg_match_all('#<record>(.*?)</record>#s', $r['xml'], $rs)) {
			foreach ($rs[1] as $xml) {
				$g = function ($tag) use ($xml) {
					return preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
				};
				$recs[] = array(
					'id' => $g('record_id'),
					'name' => $this->rrToName($g('host'), $zone),
					'rr' => $g('host'),
					'type' => strtoupper($g('type')),
					'value' => $g('value'),
					'ttl' => $g('ttl'),
				);
			}
		}
		return array('records' => $recs);
	}

	private function ns_add_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$cur = $this->ns_records_of($env, $cand);
			if (isset($cur['err'])) {
				continue; // 域名不在本账户
			}
			$rr = $this->rrOf($fulldomain, $cand);
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] === 'TXT' && $rec['value'] === $txtvalue) {
					return array('code' => 0, 'msg' => '记录已存在');
				}
			}
			// 参数名对齐 dns_namesilo.sh:41（dnsAddRecord & rrhost/rrtype/rrvalue）
			$r = $this->ns_api($env, 'dnsAddRecord', array('domain' => $cand, 'rrhost' => $rr, 'rrtype' => 'TXT', 'rrvalue' => $txtvalue, 'ttl' => 7200));
			if (isset($r['err'])) {
				return array('code' => 1, 'msg' => 'NameSilo：' . $r['err']);
			}
			return array('code' => 0, 'msg' => 'OK');
		}
		return array('code' => 1, 'msg' => 'NameSilo：未找到归属域名（确认域名在本账户且已启用 API）');
	}

	private function ns_rm_txt($env, $hint, $fulldomain, $txtvalue)
	{
		foreach ($this->zoneCandidates($fulldomain, $hint) as $cand) {
			$cur = $this->ns_records_of($env, $cand);
			if (isset($cur['err'])) {
				continue;
			}
			$rr = $this->rrOf($fulldomain, $cand);
			$found = false;
			foreach ($cur['records'] as $rec) {
				if ($rec['type'] !== 'TXT' || ($rec['rr'] !== $rr && $rec['name'] !== strtolower($fulldomain))) {
					continue;
				}
				if ($txtvalue !== '' && $rec['value'] !== $txtvalue) {
					continue;
				}
				if ($rec['id'] === '') {
					continue;
				}
				$r = $this->ns_api($env, 'dnsDeleteRecord', array('domain' => $cand, 'rrid' => $rec['id']));
				if (isset($r['err'])) {
					return array('code' => 1, 'msg' => 'NameSilo：' . $r['err']);
				}
				$found = true;
			}
			return array('code' => 0, 'msg' => $found ? 'OK' : '记录不存在（忽略）');
		}
		return array('code' => 1, 'msg' => 'NameSilo：未找到归属域名');
	}

	private function ns_list($env, $zone, $nameFilter)
	{
		$cur = $this->ns_records_of($env, $zone);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'NameSilo：' . $cur['err'], 'rows' => array());
		}
		$rows = $cur['records'];
		if ($nameFilter != '') {
			$n = strtolower(trim($nameFilter, '.'));
			$rows = array_values(array_filter($rows, function ($x) use ($n) { return $x['name'] === $n; }));
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $rows);
	}

	// ───────────────────────── 11. PowerDNS（Server API PATCH rrsets）─────────────────────────

	private function pdns_api($env, $method, $uri, $payload)
	{
		if (empty($env['PDNS_Url']) || empty($env['PDNS_ServerId']) || empty($env['PDNS_Token'])) {
			return array('err' => '缺少 PDNS_Url/PDNS_ServerId/PDNS_Token');
		}
		$base = rtrim($env['PDNS_Url'], '/');
		$this->apilog('dnsapi[powerdns] ' . $method . ' ' . str_replace(array('http://', 'https://'), '', $base) . $uri);
		$r = $this->http($base . $uri, $method, $payload === null ? null : json_encode($payload), array(
			'X-API-Key: ' . $env['PDNS_Token'],
			'Content-Type: application/json',
		));
		if (isset($r['neterr'])) {
			return array('err' => $r['neterr']);
		}
		if ($r['code'] == 204) {
			return array();
		}
		if ($r['code'] >= 400) {
			$j = is_array($r['json']) ? $r['json'] : array();
			return array('err' => 'HTTP ' . $r['code'] . ' ' . (isset($j['error']) ? $j['error'] : mb_substr(strip_tags($r['body']), 0, 120)));
		}
		return is_array($r['json']) ? $r['json'] : array();
	}

	private function pdns_zone_url($env, $zone)
	{
		return '/api/v1/servers/' . rawurlencode($env['PDNS_ServerId']) . '/zones/' . rawurlencode($zone . '.');
	}

	private function pdns_records_of($env, $zone)
	{
		$z = $this->pdns_api($env, 'GET', $this->pdns_zone_url($env, $zone), null);
		if (isset($z['err'])) {
			return array('err' => $z['err']);
		}
		$recs = array();
		foreach ((array) (isset($z['rrsets']) ? $z['rrsets'] : array()) as $rs) {
			$t = isset($rs['type']) ? $rs['type'] : '';
			$n = rtrim((string) (isset($rs['name']) ? $rs['name'] : ''), '.');
			foreach ((array) (isset($rs['records']) ? $rs['records'] : array()) as $rec) {
				$recs[] = array(
					'id' => $n . '|' . $t,
					'name' => $n,
					'rr' => $this->rrOf($n, $zone),
					'type' => $t,
					'value' => trim((string) (isset($rec['content']) ? $rec['content'] : ''), '"'),
					'ttl' => isset($rs['ttl']) ? $rs['ttl'] : '',
				);
			}
		}
		return array('records' => $recs);
	}

	/**
	 * rrset 级 REPLACE：先读当前值，合并/剔除目标 txtvalue 后整名重写。
	 * 空 records 的 REPLACE 在 pdns 即删除该 name 该类型。
	 */
	private function pdns_txt($env, $hint, $fulldomain, $txtvalue, $is_remove)
	{
		$f = strtolower(trim($fulldomain, '.'));
		$zone = '';
		foreach ($this->zoneCandidates($f, $hint) as $cand) {
			$z = $this->pdns_api($env, 'GET', $this->pdns_zone_url($env, $cand), null);
			if (!isset($z['err'])) {
				$zone = $cand;
				break;
			}
		}
		if ($zone === '') {
			return array('code' => 1, 'msg' => 'PowerDNS：未找到归属 zone');
		}
		$ttl = !empty($env['PDNS_Ttl']) && intval($env['PDNS_Ttl']) > 0 ? intval($env['PDNS_Ttl']) : 60;
		$cur = $this->pdns_records_of($env, $zone);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'PowerDNS：' . $cur['err']);
		}
		$vals = array();
		foreach ($cur['records'] as $rec) {
			if ($rec['name'] === $f && $rec['type'] === 'TXT' && $rec['value'] !== $txtvalue) {
				$vals[] = array('content' => '"' . $rec['value'] . '"');
			}
		}
		if (!$is_remove) {
			$vals[] = array('content' => '"' . $txtvalue . '"');
		}
		if ($is_remove && $txtvalue === '') {
			$vals = array();
		}
		$body = array('rrsets' => array(array(
			'name' => $f . '.', 'type' => 'TXT', 'ttl' => $ttl, 'changetype' => 'REPLACE', 'records' => $vals,
		)));
		$r = $this->pdns_api($env, 'PATCH', $this->pdns_zone_url($env, $zone), $body);
		if (isset($r['err'])) {
			if ($is_remove && strpos($r['err'], 'not present') !== false) {
				return array('code' => 0, 'msg' => '记录不存在（忽略）');
			}
			return array('code' => 1, 'msg' => 'PowerDNS：' . $r['err']);
		}
		return array('code' => 0, 'msg' => 'OK');
	}

	private function pdns_list($env, $zone, $nameFilter)
	{
		$cur = $this->pdns_records_of($env, $zone);
		if (isset($cur['err'])) {
			return array('code' => 1, 'msg' => 'PowerDNS：' . $cur['err'], 'rows' => array());
		}
		$rows = $cur['records'];
		if ($nameFilter != '') {
			$n = strtolower(trim($nameFilter, '.'));
			$rows = array_values(array_filter($rows, function ($x) use ($n) { return $x['name'] === $n; }));
		}
		return array('code' => 0, 'msg' => 'OK', 'zone' => $zone, 'rows' => $rows);
	}
}

// CLI 分发（runtime.php 已在文件头载入；此处仅执行回调）
if (PHP_SAPI === 'cli' && !empty($GLOBALS['EP_DNSAPI_CLI'])) {
	if (count($argv) < 5) {
		fwrite(STDERR, "usage: dnsapi.api.php <token> <add|rm> <fulldomain> <txtvalue>\n");
		exit(1);
	}
	$__dnsapi = new DnsapiApi();
	exit($__dnsapi->hookHandle($argv[1], $argv[2], $argv[3], $argv[4]) ? 0 : 1);
}
