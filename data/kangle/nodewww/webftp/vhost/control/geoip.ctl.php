<?php
/**
 * geoip.ctl.php —— 按地区封禁/放行（vhost 侧）
 *
 * 链路（与 framework/api/geo.api.php 头部注释一致）：
 *   全局 request BEGIN 链 <mark_geo file='...'/>（config.xml 级，仅管理员经
 *   集成步配置；KGeoMark::supportRuntime()=0，内核不支持运行时编辑该标记）
 *   → 内核给请求打地区标签（伪 header `_geo`）
 *   → vhost BEGIN 链引用本空间表 !deny_geo / !allow_geo，
 *     表内链 = acl_header('_geo') + deny / allow。
 *
 * 降级（主验收路径）：仓库零 IP 库、config.xml 无 mark_geo，
 * apicall('geo','isSupported') 恒 false → 本页显示「地区数据库未就绪」，
 * 导航链接由 head.html 的 {{if $geo_supported}} 隐藏；所有写操作 403 拒绝。
 *
 * 风格对齐 banip.ctl.php：needRole('vhost') + Access(getRole('vhost')) +
 * checkAccess + updateVhostSyncseq；写操作额外走 audit() 与 JSON 响应
 * （对齐上游 easypanel banip.ctl.php 的 geoJson 约定，便于前端统一处理）。
 */
needRole('vhost');

class GeoipControl extends Control
{
	/** 地区封禁表（! 前缀 = per-vhost access 表，同 !deny_vhost_ip 约定） */
	const DENY_TABLE = '!deny_geo';

	/** 地区放行表 */
	const ALLOW_TABLE = '!allow_geo';

	const BEGIN = 'BEGIN';

	private $access;

	/** 最近一次探测结果缓存（本请求内） */
	private $geo_status = null;

	public function __construct()
	{
		parent::__construct();
		load_lib('pub:access');
		$this->access = new Access(getRole('vhost'));
	}

	/**
	 * 页面入口（验收锚点：不可用时必须含「地区数据库未就绪」）
	 */
	public function show()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			return $this->show_msg($check_result);
		}

		$status = $this->status(false);
		$supported = !empty($status['available']);
		$this->assign('geo_supported', $supported);
		$this->assign('breadcrumb', '地区封禁');

		if (!$supported) {
			$this->assign('geo_hint', $this->hint($status));
			$this->assign('geo_status', $status);
			return $this->_tpl->fetch('geoip/form.html');
		}

		$this->assign('geo_hint', '');
		$this->assign('geo_status', $status);
		$this->assign('deny_on', $this->access->findChain(self::BEGIN, self::DENY_TABLE) != false ? 1 : 0);
		$this->assign('allow_on', $this->access->findChain(self::BEGIN, self::ALLOW_TABLE) != false ? 1 : 0);
		$this->assign('deny_regions', $this->regionRules(self::DENY_TABLE));
		$this->assign('allow_regions', $this->regionRules(self::ALLOW_TABLE));

		// 地区树内联进模板：仅含 CJK/字母，json_encode 的 HEX_* 旗标兜底 HTML 安全
		$tree = apicall('geo', 'regions', array());
		$this->assign('regions_json', json_encode(
			is_array($tree) ? $tree : array(),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		));

		return $this->_tpl->fetch('geoip/form.html');
	}

	/**
	 * ajax：数据源/能力探测（验收锚点：a=checkSource 返回 JSON，
	 * 降级态含 "available":false 与 "hint":"geo_mark_download"）
	 *
	 * GET 即可，只读不写。refresh=1 强制重探（重探入口按钮使用）。
	 */
	public function checkSource()
	{
		$refresh = isset($_REQUEST['refresh']) && $_REQUEST['refresh'] == '1';
		$status = $this->status($refresh);

		$ret = array(
			'code' => 200,
			'available' => !empty($status['available']) ? true : false,
			'kernel' => !empty($status['kernel']) ? true : false,
			'chain' => !empty($status['chain']) ? true : false,
			'data' => !empty($status['data']) ? true : false,
			'hint' => isset($status['hint']) ? strval($status['hint']) : '',
			'checked_at' => isset($status['checked_at']) ? intval($status['checked_at']) : 0,
		);

		$this->json_exit($ret);
	}

	/**
	 * ajax：开/关 地区封禁(deny) 或 地区放行(allow) 的 BEGIN 链
	 */
	public function switchGeo()
	{
		$this->requireGeo();
		$mode = $this->modeParam();
		$table = $this->tableOf($mode);
		$status = isset($_REQUEST['status']) ? intval($_REQUEST['status']) : -1;

		if ($status !== 1 && $status !== 2) {
			$this->json_exit(array('code' => 400, 'msg' => '参数错误'));
		}

		$exists = $this->access->findChain(self::BEGIN, $table);
		$vhost = getRole('vhost');

		if ($status === 1) {
			$ok = $this->ensureTable($table) && $this->ensureTable(self::BEGIN);

			if ($ok && $exists == false) {
				$arr = array('action' => 'table:' . $table, 'name' => $table);
				$ok = $this->access->addChain(self::BEGIN, $arr);

				if ($ok && $mode === 'allow') {
					// allow 必须排在 deny 前才有一票放行语义（见类头链路注释）：
					// 重新入链把 !allow_geo 提到 !deny_geo 之前
					$ok = $this->moveBeforeDeny();
				}
			}
		}
		else {
			$ok = ($exists == false) || $this->access->delChainByName(self::BEGIN, $table);
		}

		if (!$ok) {
			audit('geoip', 'switchGeo', $vhost, 'error', array('mode' => $mode, 'status' => $status));
			$this->json_exit(array('code' => 500, 'msg' => '保存地区' . $this->labelOf($mode) . '开关失败'));
		}

		apicall('vhost', 'updateVhostSyncseq', array($vhost));
		audit('geoip', 'switchGeo', $vhost, 'success', array('mode' => $mode, 'status' => $status));
		$this->json_exit(array('code' => 200, 'msg' => '成功'));
	}

	/**
	 * ajax：增加一条地区规则
	 */
	public function addRegion()
	{
		$this->requireGeo();
		$mode = $this->modeParam();
		$table = $this->tableOf($mode);
		$vhost = getRole('vhost');

		$path = apicall('geo', 'selection', array(
			isset($_REQUEST['continent']) ? filterParam($_REQUEST['continent']) : '',
			isset($_REQUEST['country']) ? filterParam($_REQUEST['country']) : '',
			isset($_REQUEST['province']) ? filterParam($_REQUEST['province']) : '',
		));

		if ($path === false) {
			audit('geoip', 'addRegion', $vhost, 'deny', array('mode' => $mode, 'reason' => 'invalid_selection'));
			$this->json_exit(array('code' => 400, 'msg' => '请选择有效的大洲、国家和省份'));
		}

		$name = implode(' / ', $path);

		foreach ($this->regionRules($table) as $rule) {
			if ($rule['region'] === $name) {
				$this->json_exit(array('code' => 400, 'msg' => '该地区已在' . $this->labelOf($mode) . '中'));
			}
		}

		// acl_header 匹配 mark_geo 打上的伪头 _geo，正则锚定完整地区标签
		//（参数形态对齐 response.ctl.php addGzip 的 header/val/regex）
		$models = array('acl_header' => array(
			'header' => '_geo',
			'val' => '^' . preg_quote($name, '/') . '$',
			'regex' => 1,
		));
		$arr = array('action' => $mode === 'allow' ? 'allow' : 'deny', 'name' => $name);

		if (!$this->ensureTable($table) || !$this->access->addChain($table, $arr, $models)) {
			audit('geoip', 'addRegion', $name, 'error', array('mode' => $mode));
			$this->json_exit(array('code' => 500, 'msg' => '增加地区' . $this->labelOf($mode) . '失败'));
		}

		apicall('vhost', 'updateVhostSyncseq', array($vhost));
		audit('geoip', 'addRegion', $name, 'success', array('mode' => $mode));
		$this->json_exit(array('code' => 200, 'msg' => '成功'));
	}

	/**
	 * ajax：删除一条地区规则（按表内序号，与列表渲染同源）
	 */
	public function delRegion()
	{
		$this->requireGeo();
		$mode = $this->modeParam();
		$table = $this->tableOf($mode);
		$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : -1;

		if ($id < 0) {
			$this->json_exit(array('code' => 400, 'msg' => '地区规则不存在'));
		}

		if (!$this->access->delChain($table, $id)) {
			audit('geoip', 'delRegion', $table, 'error', array('mode' => $mode, 'id' => $id));
			$this->json_exit(array('code' => 500, 'msg' => '删除失败'));
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		audit('geoip', 'delRegion', $table, 'success', array('mode' => $mode, 'id' => $id));
		$this->json_exit(array('code' => 200, 'msg' => '成功'));
	}

	/**
	 * ajax：清空某张地区表的全部规则（不动 BEGIN 开关链）
	 */
	public function delRegionAll()
	{
		$this->requireGeo();
		$mode = $this->modeParam();
		$table = $this->tableOf($mode);

		if (!$this->access->emptyTable($table)) {
			audit('geoip', 'delRegionAll', $table, 'error', array('mode' => $mode));
			$this->json_exit(array('code' => 500, 'msg' => '清空失败'));
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		audit('geoip', 'delRegionAll', $table, 'success', array('mode' => $mode));
		$this->json_exit(array('code' => 200, 'msg' => '成功'));
	}

	/* ---------------- 私有辅助 ---------------- */

	/**
	 * 探测状态（本请求内 memo；refresh=true 穿透 API 层缓存）
	 */
	private function status($refresh = false)
	{
		if ($this->geo_status === null || $refresh === true) {
			$status = apicall('geo', 'checkSource', array($refresh));
			$this->geo_status = is_array($status) ? $status : array('available' => false, 'hint' => 'kernel_missing');
		}

		return $this->geo_status;
	}

	private function hint($status)
	{
		$msg = apicall('geo', 'hintText', array($status));
		return is_scalar($msg) && $msg !== '' ? $msg : '地区数据库未就绪。';
	}

	/**
	 * 写操作统一门槛：vhost 权限 + 地区能力可用。
	 * 不可用时 JSON 403（降级路径下前端也不会出这些按钮，双保险）。
	 */
	private function requireGeo()
	{
		$permission = apicall('access', 'checkAccess', array());

		if ($permission !== true) {
			audit('geoip', 'denied', getRole('vhost'), 'deny', array('reason' => strval($permission)));
			$this->json_exit(array('code' => 403, 'msg' => strval($permission)));
		}

		if (empty($this->status(false)['available'])) {
			$this->json_exit(array('code' => 403, 'msg' => '地区数据库未就绪：' . $this->hint($this->status(false))));
		}
	}

	/**
	 * mode 参数：deny=封禁（缺省）| allow=放行
	 */
	private function modeParam()
	{
		$mode = isset($_REQUEST['mode']) ? strtolower(filterParam($_REQUEST['mode'])) : 'deny';

		if ($mode !== 'allow') {
			$mode = 'deny';
		}

		return $mode;
	}

	private function tableOf($mode)
	{
		return $mode === 'allow' ? self::ALLOW_TABLE : self::DENY_TABLE;
	}

	private function labelOf($mode)
	{
		return $mode === 'allow' ? '放行' : '封禁';
	}

	/**
	 * 表存在则跳过，否则创建（同 banip addTable 语义，返回 bool 不渲染错误页）
	 */
	private function ensureTable($name)
	{
		$tables = $this->access->listTable();

		if ($tables === false) {
			return false;
		}

		if (in_array($name, $tables, true)) {
			return true;
		}

		return $this->access->addTable($name);
	}

	/**
	 * 读某地区表规则列表：[{id, region}]（region 取链 name，同上游 geoRules）
	 */
	private function regionRules($table)
	{
		$rows = array();
		$chains = $this->access->listChain($table, 1);
		$id = 0;

		foreach ($chains ? $chains->children() : array() as $chain) {
			$rows[] = array('id' => $id++, 'region' => (string) $chain['name']);
		}

		return $rows;
	}

	/**
	 * 把 BEGIN 里的 !allow_geo 链提到 !deny_geo 之前：
	 * kangle 按链序求值，allow 短路后 deny 表不再命中。实现为「删后重加入」——
	 * addChain 恒追加到表尾，所以先删 deny 链再补回，让 allow 落在前面。
	 * 若当前没有 deny 链则无事可做。
	 *
	 * @return bool
	 */
	private function moveBeforeDeny()
	{
		$deny_exists = $this->access->findChain(self::BEGIN, self::DENY_TABLE);

		if ($deny_exists == false) {
			return true;
		}

		$ok = $this->access->delChainByName(self::BEGIN, self::DENY_TABLE);

		if ($ok) {
			$ok = $this->access->addChain(self::BEGIN, array(
				'action' => 'table:' . self::DENY_TABLE,
				'name' => self::DENY_TABLE,
			));
		}

		return $ok;
	}

	private function json_exit($arr)
	{
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		exit(json_encode($arr));
	}

	private function show_msg($msg)
	{
		$this->_tpl->assign('msg', $msg);
		return $this->_tpl->fetch('msg.html');
	}
}

?>
