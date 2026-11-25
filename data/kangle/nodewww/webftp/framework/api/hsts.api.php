<?php
/**
 * hsts.api.php —— HSTS（HTTP Strict Transport Security）开关核心
 *
 * 机制（本会话在远端容器 WHM 实测锁定，证据见 .temp/integration/hsts-toggle.md）：
 *   - 写链目标 = 全局 response access 链 BEGIN 表：new Access(null,'response')
 *     （与 admin/control/response.ctl.php:31 同款作用域；实测 add_chain 200
 *     后链节点即时落 /vhs/kangle/etc/config.xml，del_chain 即时消失——
 *     单元验收 grep ep_hsts config.xml 计数 1→0 依赖的正是这一行为）。
 *     注意：vh 作用域链（Access($vh,'response')）持久化在 vhs.db 的
 *     vhost.access 列，config.xml 不出现——验收口径按全局链，勿改成带 vh。
 *   - 模型名用内核实证名 mark_add_header（data/kangle/etc/config.xml:104/
 *     :121 现用同款；评审命名修正：不用上游表单名 mark_add_response_header）。
 *   - add_chain 必须携带 table_name=BEGIN：实测缺省时返回 status=200 但
 *     id=-1 且内存/磁盘都无残迹（静默失败）。本仓 Access::replaceChain
 *     固定注入 table_name（access.lib.php:93），所以必须走 Access 库，
 *     不可手拼 WHM 参数。
 *   - findChain 语义（Access::findChain→list_chain name=X&detail=1）：
 *     存在→200 + <table> 包裹的链集合；不存在→500→库返回 false。
 *     response.ctl.php:43 的 !findChain 判缺依赖同一语义。
 *   - 链节点命名：全站 !ep_hsts；单域名 !ep_hsts_<md5(domain)前6位>
 *     （命名家族同 !ssl_rewrite / !ssl_rewrite_xxxxxx，ssl.ctl.php:152/239；
 *     名字含 ep_hsts，验收按子串 grep）。
 *   - 单域名限定用 acl_wide_host v='domain|'（节点顺序对齐 ssl.ctl.php:348
 *     先 acl 后 mark；内核类 KWideHostAcl 实证存在，response 链里 acl_*
 *     合法——config.xml:102-121 的 response BEGIN 即含 acl_header/
 *     acl_listen_ports 等）。
 *   - 刻意不加"仅 HTTPS"的 acl 限定：内核 nm 探测无 KFlagAcl 类（只有
 *     KObjFlagAcl，参数名不可考），写内核不认的模型会让整个 add_chain 500；
 *     且 RFC 6797 §7.2 明确浏览器必须忽略非安全信道上的 STS 头，
 *     明文 HTTP 带上它无危害。
 *
 * 状态唯一事实源：config.xml response BEGIN 链里有无该名字节点（findChain）。
 * 不落 sqlite、不建表、不依赖 dao——面板侧没有第二份状态，kangle reload /
 * 外部改 config.xml / 证书替换后 UI 自动反映真实状态，不存在状态漂移。
 */

class HstsAPI extends API
{
	/** access 链：response（config.xml <response> 段，gzip/X-Frame-Options 同链） */
	const ACCESS_CHAIN = 'response';

	/** BEGIN 链节点命名空间前缀 */
	const BEGIN_PREFIX = '!ep_hsts';

	/** HSTS 响应头名（RFC 6797） */
	const HEADER_NAME = 'Strict-Transport-Security';

	/** 默认 max-age：1 年。验收标准要求开启后头值含 max-age>=31536000 */
	const DEFAULT_MAX_AGE = 31536000;

	/** max-age 上限 2 年，防手滑输入天文数字 */
	const MAX_AGE_LIMIT = 63072000;

	/**
	 * 链节点名（$domain 空串 = 全站开关）
	 * @param string $domain
	 * @return string
	 */
	public function chainName($domain = '')
	{
		$domain = $this->normalizeDomain($domain);

		if ($domain === '') {
			return self::BEGIN_PREFIX;
		}

		return self::BEGIN_PREFIX . '_' . substr(md5($domain), 0, 6);
	}

	/**
	 * 开关是否启用（唯一事实源：全局 response BEGIN 中该名字节点是否存在）
	 * @param string $domain ''=全站
	 * @return bool
	 */
	public function isEnabled($domain = '')
	{
		$access = $this->makeAccess();
		return $this->chainFound($access->findChain('BEGIN', $this->chainName($domain)));
	}

	/**
	 * 读当前生效的头值（未开启返回 ''；UI 回显链内真实值——链即配置）
	 * @param string $domain
	 * @return string
	 */
	public function getHeaderValue($domain = '')
	{
		$access = $this->makeAccess();
		$found = $access->findChain('BEGIN', $this->chainName($domain));
		$chains = $this->chainNodes($found);

		if (count($chains) == 0) {
			return '';
		}

		return $this->chainMarkValue($chains[0]);
	}

	/**
	 * 开 / 关 / 改 HSTS（幂等；控制器唯一的写入口）
	 *
	 * 开启：addChain('BEGIN', {action:'continue', name:链名}, models)
	 *   models = acl_wide_host v='domain|'（仅单域名，先 acl 后 mark，
	 *            对齐 ssl.ctl.php:348 节点顺序）
	 *            mark_add_header attr='Strict-Transport-Security'
	 *            val='max-age=N[; includeSubDomains][; preload]'
	 * 已存在但参数不同：delChainByName 后按新参数重挂（edit_chain 带
	 * models 在本仓无实证用例；链内容只有本特性一种节点，删除重挂无副作用）。
	 * 关闭：delChainByName；节点不存在视为已关闭，返回成功（幂等，与
	 * 验收"关闭后 grep ep_hsts 应为 0"一致）。
	 * 成功后统一 notice_cdn_changed()（runtime.php:499，updateVhostSyncseq
	 * 包装；与 response.ctl.php:45 的同步语义一致）。
	 *
	 * @param bool  $enable
	 * @param array $opts array('max_age'=>int,'include_subdomains'=>bool,'preload'=>bool)
	 *                    传空用默认（max-age=31536000，不含子选项）
	 * @param string $domain ''=全站
	 * @return array array('success'=>bool,'msg'=>string)
	 */
	public function toggle($enable, $opts = array(), $domain = '')
	{
		$domain = $this->normalizeDomain($domain);
		$access = $this->makeAccess();
		$name = $this->chainName($domain);
		$found = $this->chainFound($access->findChain('BEGIN', $name));

		if (!$enable) {
			if (!$found) {
				return array('success' => true, 'msg' => '已经是关闭状态');
			}

			if (!$access->delChainByName('BEGIN', $name)) {
				return array('success' => false, 'msg' => '删除失败：内核拒绝（检查 WHM 通道）');
			}

			notice_cdn_changed();
			return array('success' => true, 'msg' => '成功');
		}

		$val = $this->buildHeaderValue($opts);

		if ($val === false) {
			return array('success' => false, 'msg' => '参数非法：max-age 须为 1~' . self::MAX_AGE_LIMIT . ' 的整数');
		}

		$models = array();

		if ($domain !== '') {
			$models['acl_wide_host'] = array('v' => $domain . '|');
		}

		$models['mark_add_header'] = array('attr' => self::HEADER_NAME, 'val' => $val);

		if ($found) {
			if (!$access->delChainByName('BEGIN', $name)) {
				return array('success' => false, 'msg' => '修改失败：内核拒绝删除旧链');
			}
		}

		$arr = array('action' => 'continue', 'name' => $name);

		if (!$access->addChain('BEGIN', $arr, $models)) {
			// 防御：重挂失败可能留下半链，按名清一次（不存在时 500→false，无副作用）
			$access->delChainByName('BEGIN', $name);
			return array('success' => false, 'msg' => '开启失败：内核拒绝写入链节点');
		}

		notice_cdn_changed();
		return array('success' => true, 'msg' => '成功');
	}

	/**
	 * 全站开关前置校验：vhost 端口须含 's'（443s；ssl.ctl.php:18 同口径）
	 * @return array array('ok'=>bool,'msg'=>string)
	 */
	public function checkSslPort($vhost = null)
	{
		if ($vhost === null) {
			$vhost = getRole('vhost');
		}

		$user = daocall('vhost', 'getVhost', array($vhost));

		if (!$user) {
			return array('ok' => false, 'msg' => '主机信息读取失败');
		}

		if (strpos(strval($user['port']), 's') === false) {
			return array('ok' => false, 'msg' => '您的账号未开启SSL端口，不能开启HSTS');
		}

		return array('ok' => true, 'msg' => '');
	}

	/**
	 * 单域名归属校验：$domain 必须是该 vhost 名下 type=0 的域名记录
	 * （vhostinfo.getInfo 口径与 ssl.ctl.php domainSsl* 一致）
	 * @return array array('ok'=>bool,'msg'=>string,'domain'=>string)
	 */
	public function checkDomainOwned($domain, $vhost = null)
	{
		$domain = $this->normalizeDomain($domain);

		if ($domain === '' || !checkDomain($domain)) {
			return array('ok' => false, 'msg' => '域名格式错误', 'domain' => '');
		}

		if ($vhost === null) {
			$vhost = getRole('vhost');
		}

		$info = daocall('vhostinfo', 'getInfo', array($vhost, 0, $domain));

		if (!$info) {
			return array('ok' => false, 'msg' => '域名不存在', 'domain' => $domain);
		}

		return array('ok' => true, 'msg' => '', 'domain' => $domain);
	}

	/**
	 * 全站 + 各域名当前状态一览（供 hstsForm 渲染；一次 listChain 出全部）
	 *
	 * @param array $domains vhostinfo getDomain 结果（行含 'name'）
	 * @return array array('global'=>array('enabled','value'),
	 *                     'domains'=>array(array('domain','enabled','value')),
	 *                     'default_max_age'=>int)
	 */
	public function overview($domains = array())
	{
		$access = $this->makeAccess();
		$chains = $this->chainNodes($access->listChain('BEGIN', 1));

		$byName = array();

		foreach ($chains as $chain) {
			$name = $this->chainAttr($chain, 'name');

			if ($name === '' || strpos($name, self::BEGIN_PREFIX) !== 0) {
				continue;
			}

			$byName[$name] = $this->chainMarkValue($chain);
		}

		$global_val = isset($byName[self::BEGIN_PREFIX]) ? $byName[self::BEGIN_PREFIX] : '';

		$ret = array(
			'default_max_age' => self::DEFAULT_MAX_AGE,
			'global' => array(
				'enabled' => $global_val !== '',
				'value' => $global_val,
			),
			'domains' => array(),
		);

		if (!is_array($domains)) {
			$domains = array();
		}

		foreach ($domains as $row) {
			$domain = isset($row['name']) ? strtolower(trim(strval($row['name']))) : '';

			if ($domain === '') {
				continue;
			}

			$name = $this->chainName($domain);
			$val = isset($byName[$name]) ? $byName[$name] : '';
			$ret['domains'][] = array(
				'domain' => $domain,
				'enabled' => $val !== '',
				'value' => $val,
			);
		}

		return $ret;
	}

	/* ---------- 内部工具 ---------- */

	/**
	 * 组装头值；非法参数返回 false。白名单正则校验，原始输入绝不透给内核。
	 * @return string|false
	 */
	private function buildHeaderValue($opts)
	{
		$max_age = '';

		if (is_array($opts) && isset($opts['max_age'])) {
			$max_age = trim(strval($opts['max_age']));
		}

		if ($max_age === '') {
			$max_age = strval(self::DEFAULT_MAX_AGE);
		}

		if (!preg_match('/^[1-9][0-9]{0,7}$/', $max_age)) {
			return false;
		}

		$max_age = intval($max_age);

		if ($max_age > self::MAX_AGE_LIMIT) {
			return false;
		}

		$val = 'max-age=' . $max_age;

		if (is_array($opts) && !empty($opts['include_subdomains'])) {
			$val .= '; includeSubDomains';
		}

		if (is_array($opts) && !empty($opts['preload'])) {
			$val .= '; preload';
		}

		return $val;
	}

	private function normalizeDomain($domain)
	{
		return strtolower(trim(strval($domain)));
	}

	/**
	 * 全局 response 链入口。vh=null 是关键：链落 config.xml（验收口径），
	 * 带 vh 则落 vhs.db 的 vhost.access（实测两路径持久化位置不同）。
	 */
	private function makeAccess()
	{
		load_lib('pub:access');
		return new Access(null, self::ACCESS_CHAIN);
	}

	/**
	 * findChain/listChain 结果判存：不存在时 list_chain 500→库返回 false；
	 * 存在时返回 <table_info> 的 children（SimpleXMLElement 集合）。
	 */
	private function chainFound($found)
	{
		if (!$found) {
			return false;
		}

		if (is_array($found) || $found instanceof Countable) {
			return count($found) > 0;
		}

		return true;
	}

	/**
	 * 把 listChain/findChain 的返回统一展开成 chain 元素数组。
	 * 实测结构：返回 <table_info> 的 children()，即 [<table>...]；
	 * cache.ctl.php:24 同款二次 children() 下钻到 <chain>。
	 * 兼容直挂 <chain> 的返回形态；非 SimpleXMLElement 形态返回空数组
	 * （调用方按"未开启"降级，绝不致命）。
	 * @return array
	 */
	private function chainNodes($found)
	{
		$out = array();

		if (!$this->chainFound($found)) {
			return $out;
		}

		if (!is_object($found) || !method_exists($found, 'children')) {
			// 数组形态（理论不发生）：原样过滤对象
			if (is_array($found)) {
				foreach ($found as $node) {
					if (is_object($node)) {
						$out[] = $node;
					}
				}
			}

			return $out;
		}

		foreach ($found as $node) {
			$tag = method_exists($node, 'getName') ? strtolower($node->getName()) : '';

			if ($tag === 'table' || $tag === 'tables') {
				foreach ($node->children() as $chain) {
					$out[] = $chain;
				}
			}
			else {
				$out[] = $node;
			}
		}

		return $out;
	}

	/**
	 * 取一条 chain 元素里 mark_add_header 的 val。
	 * 读法实证：SimpleXMLElement children() 按标签名键控（rewrite.ctl.php:26
	 * 同款），属性走 ArrayAccess $ch['attr']/$ch['val']（cache.ctl.php:33
	 * 同款）。只认 attr='Strict-Transport-Security'，防误读其它 add_header。
	 * 内核回显会补 force='0' 属性（实测 list 输出如此），不影响本读取。
	 * @return string ''=无该头
	 */
	private function chainMarkValue($chain)
	{
		if (!is_object($chain) || !method_exists($chain, 'children')) {
			return '';
		}

		foreach ($chain->children() as $tag => $node) {
			if ($tag !== 'mark_add_header') {
				continue;
			}

			$attr = strval($node['attr']);

			if ($attr !== self::HEADER_NAME) {
				continue;
			}

			return strval($node['val']);
		}

		return '';
	}

	/**
	 * 取 chain 元素属性（name）。SimpleXMLElement 属性走 attributes()
	 * （无 name 属性的内核内置链 config.xml:98 <chain action='continue'>
	 * 直接下标访问会报 notice，attributes() 判存更安全）；兜底数组形态。
	 */
	private function chainAttr($chain, $key)
	{
		if (is_object($chain)) {
			if (method_exists($chain, 'attributes')) {
				$attrs = $chain->attributes();

				if (isset($attrs[$key])) {
					return strval($attrs[$key]);
				}

				return '';
			}

			if (method_exists($chain, 'offsetGet')) {
				return strval($chain[$key]);
			}
		}

		return '';
	}
}

?>
