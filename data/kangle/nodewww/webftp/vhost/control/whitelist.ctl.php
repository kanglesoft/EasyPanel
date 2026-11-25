<?php
/**
 * whitelist.ctl.php —— 站点 IP/URL 白名单（access 链 ruleAction=allow）
 *
 * 模式抄 vhost/control/banip.ctl.php:98-112（独立 ctl 复用
 * framework/lib/access.lib.php 的 Access 原语），不改 banip.ctl.php。
 * 与黑名单的三点区别：
 *   1. 表名 !ep_whitelist_ip / !ep_whitelist_url（!ep_ 前缀便于残链审计，
 *      验收点：`grep -c ep_whitelist /vhs/kangle/etc/config.xml` 应为 0，
 *      因此本 ctl 采用「按需建表 + 空表自清理」而不是 banip 的进页即建表）；
 *   2. 叶子链 action='allow'（黑名单是 'deny'）；
 *   3. BEGIN 挂载链通过 add_chain 的 id 参数定位插入（内核 KTable::insertChain
 *      符号在列；upstream access.lib.php:171-197 同款原语），插到所有
 *      deny/realip 链之前，实现 allow→(replace_ip)→deny 链序，借内核
 *      BEGIN「首条命中即停」语义让白名单先于黑名单生效。
 *      若内核忽略 id 参数则自动回退 append，链序由集成步在 ep-test.local
 *      上核对 config.xml 兜底（见 .temp/integration/whitelist-page.md）。
 */
needRole('vhost');
define(ALLOW_BANIP_TABLE, '!ep_whitelist_ip');
define(ALLOW_BANURL_TABLE, '!ep_whitelist_url');
define(ALLOW_ACTION, 'allow');
define(BEGIN, 'BEGIN');
class WhitelistControl extends Control
{
	private $access;

	public function __construct()
	{
		parent::__construct();
		load_lib('pub:access');
		$this->access = new Access(getRole('vhost'));
	}

	public function addTableFrom()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			return $this->show_msg($check_result);
		}

		// 只读渲染：表不存在时 listChain 返回 false，按空列表处理。
		// 不在进页时建表，保证 teardown 后 grep ep_whitelist 残链为 0。
		$result = $this->access->listChain(ALLOW_BANIP_TABLE, 1);
		$result2 = $this->access->listChain(ALLOW_BANURL_TABLE, 1);
		$mounted_ip = $this->access->findChain(BEGIN, ALLOW_BANIP_TABLE) != false;
		$mounted_url = $this->access->findChain(BEGIN, ALLOW_BANURL_TABLE) != false;

		$this->assign('banip', $mounted_ip ? 1 : 0);
		$this->assign('banurl', $mounted_url ? 1 : 0);

		$ips = array();

		if ($result) {
			$id = 0;
			foreach ($result->children() as $chain) {
				$ips[] = array('expire' => $chain['expire'], 'ip' => (string) $chain->children(), 'id' => $id++);
			}
		}

		$this->assign('ips', $ips);

		$urls = array();

		if ($result2) {
			$id = 0;
			foreach ($result2->children() as $chain) {
				$meth = '全部';
				$url = null;
				foreach ($chain->children() as $name => $ch) {
					if ($name == 'acl_url') {
						$url = $ch;
					} elseif ($name == 'acl_meth') {
						$meth = $ch[0];
						if ((string) $ch['revers'] == '1') $meth = '!' . $meth;
					}
				}
				if ($url) {
					$urls[] = array('url' => $url, 'meth' => $meth, 'id' => $id++);
				}
			}
		}

		$this->assign('urls', $urls);
		return $this->_tpl->fetch('whitelist/addFrom.html');
	}

	private function addTable($tablename)
	{
		$tables = $this->access->listTable();
		$table_finded = false;

		if ($tables === false) {
			return false;
		}

		foreach ($tables as $table) {
			if ($table == $tablename) {
				$table_finded = true;
				break;
			}
		}

		if ($table_finded === false) {
			if (!$this->access->addTable($tablename)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 启用某张白名单表：建表 + 挂 BEGIN（定位到 deny/realip 链之前）
	 */
	private function ensureEnabled($table)
	{
		if (!$this->addTable($table) || !$this->addTable(BEGIN)) {
			return false;
		}

		if ($this->access->findChain(BEGIN, $table) != false) {
			return true;
		}

		return $this->mountBegin($table);
	}

	public function switchIp()
	{
		$status = intval($_REQUEST['status']);

		switch ($status) {
		case 1:
			if (!$this->ensureEnabled(ALLOW_BANIP_TABLE)) {
				audit('whitelist', 'switchIp', ALLOW_BANIP_TABLE, 'error', array('reason' => $this->lastError()));
				return $this->show_msg('不能增加链');
			}

			audit('whitelist', 'switchIp', getRole('vhost'), 'success', array('status' => 'on'));
			break;

		case 2:
			$this->access->delChainByName(BEGIN, ALLOW_BANIP_TABLE);
			audit('whitelist', 'switchIp', getRole('vhost'), 'success', array('status' => 'off'));
			break;

		default:
			exit('参数错误');
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function addBanip()
	{
		$ip = trim($_REQUEST['ip']);

		if (!$ip) {
			exit('IP地址不能为空');
		}

		if (!apicall('whitelist', 'validateIp', array($ip))) {
			exit('请输入正确的IP或IP段（如 192.168.0.1 或 192.168.0.1/24）');
		}

		// 加规则即自动启用（幂等），保证只调 add 接口也能产生放行效果
		if (!$this->ensureEnabled(ALLOW_BANIP_TABLE)) {
			audit('whitelist', 'addBanip', $ip, 'error', array('reason' => $this->lastError()));
			exit('启用IP白名单失败：不能增加链');
		}

		$lifetime = isset($_REQUEST['life_time']) ? intval($_REQUEST['life_time']) : 0;
		$expire = 0;

		if (0 < $lifetime) {
			$expire = time() + $lifetime * 60;
		}

		$models = array();
		$models['acl_src'] = array('ip' => $ip);
		$arr = array();
		$arr['action'] = ALLOW_ACTION;
		$arr['expire'] = $expire ? $expire : $lifetime;

		if (!$this->access->addChain(ALLOW_BANIP_TABLE, $arr, $models)) {
			audit('whitelist', 'addBanip', $ip, 'error', array('reason' => $this->lastError()));
			exit('增加失败');
		}

		audit('whitelist', 'addBanip', $ip, 'success', array('lifetime' => $lifetime));
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function delBanip()
	{
		$id = intval($_REQUEST['id']);

		if (!$this->access->delChain(ALLOW_BANIP_TABLE, $id)) {
			exit('删除失败');
		}

		$this->gcTableIfEmpty(ALLOW_BANIP_TABLE);

		audit('whitelist', 'delBanip', getRole('vhost'), 'success', array('id' => $id));
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function delBanipAll()
	{
		if (!$this->access->emptyTable(ALLOW_BANIP_TABLE)) {
			exit('删除失败');
		}

		$this->gcTableIfEmpty(ALLOW_BANIP_TABLE, true);

		audit('whitelist', 'delBanipAll', getRole('vhost'), 'success');
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function switchUrl()
	{
		$status = intval($_REQUEST['status']);

		switch ($status) {
		case 1:
			if (!$this->ensureEnabled(ALLOW_BANURL_TABLE)) {
				audit('whitelist', 'switchUrl', ALLOW_BANURL_TABLE, 'error', array('reason' => $this->lastError()));
				return $this->show_msg('不能增加链');
			}

			audit('whitelist', 'switchUrl', getRole('vhost'), 'success', array('status' => 'on'));
			break;

		case 2:
			$this->access->delChainByName(BEGIN, ALLOW_BANURL_TABLE);
			audit('whitelist', 'switchUrl', getRole('vhost'), 'success', array('status' => 'off'));
			break;

		default:
			exit('参数错误');
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function addBanurl()
	{
		$url = trim($_REQUEST['url']);
		$meth = trim($_REQUEST['meth']);

		if (!$url) {
			exit('URL不能为空');
		}

		if (!apicall('whitelist', 'validateUrl', array($url))) {
			exit('URL过长或无效');
		}

		if ($meth !== '' && !apicall('whitelist', 'validateMeth', array($meth))) {
			exit('请求方法错误');
		}

		if (!$this->ensureEnabled(ALLOW_BANURL_TABLE)) {
			audit('whitelist', 'addBanurl', $url, 'error', array('reason' => $this->lastError()));
			exit('启用URL白名单失败：不能增加链');
		}

		$models = array();
		if (!empty($meth)) {
			if (substr($meth, 0, 1) == '!') {
				$meth = substr($meth, 1);
				$models['acl_meth'] = array('revers' => 1, 'meth' => $meth);
			} else {
				$models['acl_meth'] = array('meth' => $meth);
			}
		}
		$models['acl_url'] = array('url' => $url, 'nc' => 1);
		$arr = array();
		$arr['action'] = ALLOW_ACTION;

		if (!$this->access->addChain(ALLOW_BANURL_TABLE, $arr, $models)) {
			$audit_reason = $this->lastError();
			$this->gcTableIfEmpty(ALLOW_BANURL_TABLE);
			audit('whitelist', 'addBanurl', $url, 'error', array('reason' => $audit_reason));
			exit('增加失败');
		}

		audit('whitelist', 'addBanurl', $url, 'success', array('meth' => $meth));
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function delBanurl()
	{
		$id = intval($_REQUEST['id']);

		if (!$this->access->delChain(ALLOW_BANURL_TABLE, $id)) {
			exit('删除失败');
		}

		$this->gcTableIfEmpty(ALLOW_BANURL_TABLE);

		audit('whitelist', 'delBanurl', getRole('vhost'), 'success', array('id' => $id));
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function delBanurlAll()
	{
		if (!$this->access->emptyTable(ALLOW_BANURL_TABLE)) {
			exit('删除失败');
		}

		$this->gcTableIfEmpty(ALLOW_BANURL_TABLE, true);

		audit('whitelist', 'delBanurlAll', getRole('vhost'), 'success');
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	/**
	 * 空表自清理：规则删光后同时卸下 BEGIN 挂载链并删除表本体，
	 * 保证验收 `grep -c ep_whitelist /vhs/kangle/etc/config.xml` 残链为 0。
	 *
	 * @param string $table 白名单表名
	 * @param bool $forced 强制回收（delAll 场景，表已被 emptyTable 清空）
	 * @return void
	 */
	private function gcTableIfEmpty($table, $forced = false)
	{
		if (!$forced) {
			$chains = $this->access->listChain($table, 1);

			if ($chains === false || count($chains->children()) > 0) {
				return;
			}
		}

		$this->access->delChainByName(BEGIN, $table);
		$this->access->delTable($table);
	}

	/**
	 * 把白名单表挂到 BEGIN，并定位到所有 deny/realip 链之前
	 *
	 * 内核 BEGIN 首条命中即停，allow 想赢过 deny 只需物理插在前面。
	 * add_chain 带 id 参数即在 BEGIN 第 id 位插入（upstream 同原语）。
	 * 定位失败（内核版本不认 id）时回退默认 append，功能仍可用，
	 * 顺序由集成步核对兜底。
	 *
	 * @param string $table 白名单表名
	 * @return bool
	 */
	private function mountBegin($table)
	{
		$arr = array();
		$arr['action'] = 'table:' . $table;
		$arr['name'] = $table;

		$begin = $this->access->listChain(BEGIN, 1);

		if ($begin !== false) {
			$index = 0;

			foreach ($begin->children() as $chain) {
				if ($this->mustStayAfterAllow($chain)) {
					$arr['id'] = $index;
					break;
				}

				$index++;
			}
		}

		if (isset($arr['id']) && $this->access->addChain(BEGIN, $arr)) {
			return true;
		}

		unset($arr['id']);

		return $this->access->addChain(BEGIN, $arr);
	}

	/**
	 * 判定 BEGIN 链是否属于「必须排在白名单之后」的 deny/realip 集合。
	 * 分组与 upstream beginPriority 一致（realip=0 / whitelist=28 / deny=30，
	 * .temp/repos/easypanel/framework/lib/access.lib.php:258-275）。
	 *
	 * @param SimpleXMLElement $chain
	 * @return bool
	 */
	private function mustStayAfterAllow($chain)
	{
		$name = (string) $chain['name'];
		$action = (string) $chain['action'];

		// 真实IP还原链（realip 单元产物：!real_ip 或含 mark_replace_ip）
		if ($name === '!real_ip' || $name === '!replace_ip' || isset($chain->mark_replace_ip)) {
			return true;
		}

		// 各类黑名单/安全表挂载与直接 deny 链（含 cdn 的回源 IP 拦截）
		$deny_tables = array('!deny_vhost_ip', '!deny_url', '!deny_geo', '!deny_ip_table',
			'!deny_referer', '!anti_sql', '!anti_up_inject', '!anticc', '!deny_filter',
			'!safeline_waf', '!cdn');

		if (in_array($name, $deny_tables, true)
			|| strpos($action, 'table:!deny') === 0 || strpos($action, 'table:!anti') === 0
			|| $action === 'table:!cdn') {
			return true;
		}

		// BEGIN 里的直接 deny 叶子链（如 antiupload 的注入拦截）
		if ($action === 'deny' && (isset($chain->acl_src) || isset($chain->acl_srcs) || isset($chain->acl_url))) {
			return true;
		}

		return false;
	}

	private function lastError()
	{
		return isset($GLOBALS['last_error']) ? $GLOBALS['last_error'] : '';
	}

	private function show_msg($msg)
	{
		$this->_tpl->assign('msg', $msg);
		return $this->_tpl->fetch('msg.html');
	}
}

?>
