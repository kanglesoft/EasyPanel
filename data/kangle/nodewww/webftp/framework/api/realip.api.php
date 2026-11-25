<?php
/**
 * realip.api.php —— 真实 IP 还原（CDN/反代 X-Forwarded-For → REMOTE_ADDR）核心
 *
 * 机制（内核实证）：
 *   - 写链走 request access 的 BEGIN 表，模型名 mark_replace_ip 为 kangle 内核
 *     实证名（data/kangle/bin/kangle 含 KReplaceIPMark 全套符号；本机
 *     data/kangle/etc/config.xml:81 全局段已使用同款节点
 *     <mark_replace_ip header='X-Real-Ip'>）。开关状态唯一事实源 = BEGIN 表中
 *     是否存在名为 !ep_realip 的链（与 hsts.api.php 同策略：不落面板 sqlite，
 *     避免两份状态漂移；因此本单元无 upgrade.sql DDL）。
 *   - val 属性照抄上游 .temp/repos/easypanel/vhost/control/realip.ctl.php:96-97：
 *     不带 val 时 replace_ip 会把整个 Header 原样复制进 REMOTE_ADDR——XFF 经
 *     多级代理时是 "client, proxy1, proxy2" 逗号列表，必须用正则只捕获首个 IP。
 *     若 3.5.21 内核不识别 val 属性则退化为整头复制，功能仍可用（单代理场景）。
 *   - 链命名空间 !ep_realip 与 !ep_hsts（hsts.api.php:42）/!deny_* /!allow_*
 *     家族对齐。
 *
 * 链顺序约束（单元规格）：
 *   - 本构建 framework/lib/access.lib.php:86 的 addChain() 是纯 append 语义
 *     （无上游 beginPriority/renumber），链序即插入序。若 BEGIN 表已含 banip
 *     链（banip.ctl.php:100/183 的 addChain(BEGIN, array('action'=>'table:!deny_…'))
 *     模式），后开启的本特性链会落在 deny 链之后而失去意义（deny 命中即断，
 *     轮不到后面的 replace_ip）。save() 成功后 beginOrderFixup() 把排在我们
 *     之前的 deny 家族"纯链接链"整体搬到表尾，保证 ep_realip 居 deny 链之前。
 *   - 只搬移无子模型、有名字、action 以 table:!deny 开头或命中已知 banip/geo
 *     黑名单链名的链——带模型的链（如防盗链 deny_referer）无法经 WHM 参数无损
 *     重建（setModel 只传属性不传文本内容，见 access.lib.php:128），绝不搬移。
 *   - allow → replace_ip → deny 的整体次序由集成步按开启顺序保证；白名单在
 *     本特性之后开启的次序问题属白名单单元责任，不在本单元修复范围。
 */

class RealipAPI extends API
{
	/** BEGIN 链节点名（kangle 链名约定：! 开头 + 合法标识符） */
	const CHAIN_NAME = '!ep_realip';

	/** request access 表名 */
	const BEGIN = 'BEGIN';

	/** 内核模型名（KReplaceIPMark，config.xml:81 实证） */
	const MODEL = 'mark_replace_ip';

	/** 默认还原 Header（远程验收步骤 1 即保存回显此项） */
	const DEFAULT_HEADER = 'X-Forwarded-For';

	/** 首个 IP 捕获正则（照抄上游 realip.ctl.php:97；写入时去掉两端定界符） */
	const IP_CAPTURE_REGEX = '/^\s*((?:[0-9]{1,3}\.){3}[0-9]{1,3}|[0-9A-Fa-f]*:[0-9A-Fa-f:.]+)\s*(?:,|$)/';

	/** Header 名合法字符（RFC 7230 token，\x27 = 单引号）与长度上限 */
	const HEADER_PATTERN = '/\A[!#$%&\x27*+.^_`|~0-9A-Za-z-]{1,64}\z/D';

	/**
	 * 常见 CDN 真实 IP Header 候选（下拉列表数据源，与上游
	 * .temp/repos/easypanel/vhost/control/realip.ctl.php:16 同表）
	 * @return array
	 */
	public function knownHeaders()
	{
		return array('X-Forwarded-For', 'X-Real-Ip', 'CF-Connecting-IP',
			'True-Client-IP', 'Cdn-Src-Ip', 'Ali-CDN-Real-IP', 'EO-Connecting-IP');
	}

	/**
	 * 读取当前状态（唯一事实源：kangle request BEGIN 链）
	 *
	 * @return array array('success'=>bool,'enabled'=>bool,'header'=>string,
	 *                 'custom'=>bool,'has_begin'=>bool,'msg'=>string)
	 */
	public function getStatus()
	{
		$ret = array('success' => false, 'enabled' => false,
			'header' => self::DEFAULT_HEADER, 'custom' => false,
			'has_begin' => false, 'msg' => '');

		$vh = getRole('vhost');

		// 双保险：绝不允许在无 vhost 上下文时操作全局（vh 为空将命中全局表）
		if (!$vh) {
			$ret['msg'] = '无有效空间上下文';
			return $ret;
		}

		load_lib('pub:access');
		$access = new Access($vh, 'request');

		$tables = $access->listTable();

		if ($tables === false) {
			$ret['msg'] = '读取请求控制规则表失败';
			return $ret;
		}

		$ret['success'] = true;
		$ret['has_begin'] = $this->tableExists($tables, self::BEGIN);

		if (!$ret['has_begin']) {
			return $ret;
		}

		$chains = $this->listBeginChains($access);

		if ($chains === false) {
			$ret['success'] = false;
			$ret['msg'] = '读取 BEGIN 请求控制链失败';
			return $ret;
		}

		foreach ($chains as $chain) {
			if (trim(strval($chain['name'])) !== self::CHAIN_NAME) {
				continue;
			}

			$ret['enabled'] = true;
			// SimpleXML 多子节点上 isset($children['mark_replace_ip']) 恒为
			// false（远端 php7.4 实证），必须按 key=>节点 迭代取模型（上游
			// realip.ctl.php:74 同款模式）
			foreach ($chain->children() as $model_name => $model) {
				if ($model_name === self::MODEL || $model->getName() === self::MODEL) {
					$header = trim(strval($model['header']));

					if ($header !== '') {
						$ret['header'] = $header;
					}

					break;
				}
			}

			break;
		}

		$ret['custom'] = $ret['enabled'] && !in_array($ret['header'], $this->knownHeaders(), true);
		return $ret;
	}

	/**
	 * 开/关真实 IP 还原（核心写入口，供 realip 控制器与后续按 IP 匹配的功能页共用）
	 *
	 * @param bool   $enabled true=开启（header 必填合法）；false=关闭并清链
	 * @param string $header  还原来源 Header 名
	 * @return array array('success'=>bool,'msg'=>string,'changed'=>bool,
	 *                 'order_fixed'=>bool,'header'=>string)
	 */
	public function save($enabled, $header = '')
	{
		$vh = getRole('vhost');

		if (!$vh) {
			return array('success' => false, 'msg' => '无有效空间上下文', 'changed' => false);
		}

		$state = $this->getStatus();

		if ($state['success'] !== true) {
			return array('success' => false, 'msg' => strval($state['msg']), 'changed' => false);
		}

		load_lib('pub:access');
		$access = new Access($vh, 'request');

		if (!$enabled) {
			if (!$state['enabled']) {
				return array('success' => true, 'msg' => '成功', 'changed' => false);
			}

			if (!$access->delChainByName(self::BEGIN, self::CHAIN_NAME)) {
				return array('success' => false, 'msg' => '关闭失败：内核拒绝删除链节点', 'changed' => false);
			}

			notice_cdn_changed();
			return array('success' => true, 'msg' => '成功', 'changed' => true);
		}

		$header = trim(strval($header));

		// 表单哨兵值应由控制器先行解析为真实头名（realip.ctl.php save），
		// 直接经 API 传入时拒绝，防 '__custom__' 被当成 Header 名写进内核
		if ($header === '' || $header === '__custom__' || !preg_match(self::HEADER_PATTERN, $header)) {
			return array('success' => false, 'msg' => '请输入有效的Header名称，不要包含冒号、空格或换行', 'changed' => false);
		}

		if (!$state['has_begin'] && !$access->addTable(self::BEGIN)) {
			return array('success' => false, 'msg' => '开启失败：无法创建 BEGIN 规则表', 'changed' => false);
		}

		// 重建本特性链：先删旧再添加。本构建 addChain 纯 append、edit_chain 对
		// 属性/模型是合并语义（未提交的旧属性会残留），delete+add 才能保证
		// header 更新原子生效且无残值。
		if ($state['enabled'] && !$access->delChainByName(self::BEGIN, self::CHAIN_NAME)) {
			return array('success' => false, 'msg' => '开启失败：内核拒绝更新链节点', 'changed' => false);
		}

		$pattern = self::IP_CAPTURE_REGEX;
		$val = substr($pattern, 1, -1);
		$arr = array('action' => 'continue', 'name' => self::CHAIN_NAME);
		$models = array(self::MODEL => array('header' => $header, 'val' => $val));

		if (!$access->addChain(self::BEGIN, $arr, $models)) {
			// add_chain 部分失败可能留下半条链：防御性清一次再报失败（同 hsts.api.php:249）
			$access->delChainByName(self::BEGIN, self::CHAIN_NAME);
			return array('success' => false, 'msg' => '开启失败：内核拒绝写入链节点（请检查 WHM 口令/权限）', 'changed' => false);
		}

		$order_fixed = $this->beginOrderFixup($access);
		notice_cdn_changed();
		return array('success' => true, 'msg' => '成功', 'changed' => true,
			'order_fixed' => $order_fixed, 'header' => $header);
	}

	/* 内部工具 */

	/**
	 * 把排在 !ep_realip 之前的可安全重建 deny 链整体搬到 BEGIN 表尾
	 *
	 * 尽力而为：任何一步失败只返回 false，不抛出、不阻断——链已写入即功能
	 * 生效，顺序只是本特性对 banip/geo 家族的语义保障。链序收集时天然保持，
	 * 逐链搬移即维持相对次序。
	 *
	 * @return bool true = 无需修复或修复成功；false = 修复未完成
	 */
	private function beginOrderFixup($access)
	{
		$chains = $this->listBeginChains($access);

		if ($chains === false) {
			return false;
		}

		$ours_seen = false;
		$to_move = array();

		foreach ($chains as $chain) {
			$name = trim(strval($chain['name']));

			if ($name === self::CHAIN_NAME) {
				$ours_seen = true;
				continue;
			}

			// 只收集出现在我们之前、且可无损重建的 deny 家族链接链
			if (!$ours_seen && $this->isMovableDenyLink($chain)) {
				$to_move[] = $chain;
			}
		}

		if (!$ours_seen || empty($to_move)) {
			return true;
		}

		// 逐链「删除+立即重挂」单趟搬移（绝不一趟全删再一趟全加：中途
		// add 失败会把已删黑名单链整个丢掉）。重挂失败重试一次；仍失败即
		// 中止——该链可能丢失（内核拒绝同参重建属内核级异常），其余未动
		// 的链保持原位，主功能（!ep_realip 链）不受影响。
		foreach ($to_move as $chain) {
			// 只回传 name/action 两个白名单属性（banip.ctl.php:101-102 开启时
			// 提交的正是这一对）；id/expired 等内核定位与状态属性绝不回传，
			// 由内核重新排位（append 到表尾）。
			$arr = array(
				'action' => trim(strval($chain['action'])),
				'name' => trim(strval($chain['name'])),
			);

			if (!$access->delChainByName(self::BEGIN, $arr['name'])) {
				return false;
			}

			if (!$access->addChain(self::BEGIN, $arr) && !$access->addChain(self::BEGIN, $arr)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 判定一条 BEGIN 链是否为"可安全搬移"的 deny 家族链接链
	 *
	 * 条件：有名、action 为 table:!deny 前缀或命中已知 banip/geo 黑名单链名、
	 * 且不含任何子模型（无子模型 = 只有 action+name 属性，可经 WHM 参数无损
	 * 重建；带模型链的文本内容如 acl_param 正则无法经 setModel 回传，见
	 * access.lib.php:128-137）。
	 * @return bool
	 */
	private function isMovableDenyLink($chain)
	{
		$name = trim(strval($chain['name']));
		$action = trim(strval($chain['action']));

		if ($name === '') {
			return false;
		}

		$deny_names = array('!deny_vhost_ip', '!deny_url', '!deny_geo', '!deny_ip_table');
		$is_deny = in_array($name, $deny_names, true) || strpos($action, 'table:!deny') === 0;

		if (!$is_deny) {
			return false;
		}

		return count($chain->children()) == 0;
	}

	/**
	 * listChain('BEGIN',1) 统一取链数组；BEGIN 表缺失链元素时安全返回空数组
	 * @return array|false false = 内核读取失败
	 */
	private function listBeginChains($access)
	{
		$chains = $access->listChain(self::BEGIN, 1);

		if ($chains === false) {
			return false;
		}

		if (!is_object($chains) || !method_exists($chains, 'children')) {
			return array();
		}

		$out = array();

		foreach ($chains->children() as $chain) {
			$out[] = $chain;
		}

		return $out;
	}

	/**
	 * listTable() 结果里查表名（banip.ctl.php addTable 同款宽松比较：
	 * kangle 3.5 返回 <table>BEGIN</table> 文本形（远端实测），新版本可能是
	 * 带 name 属性的结构形（上游 access.lib.php listTable 兼容注释），两形都认。
	 * @return bool
	 */
	private function tableExists($tables, $name)
	{
		if (!is_array($tables)) {
			return false;
		}

		foreach ($tables as $table) {
			$value = strval($table);

			if ($value === '' && $table instanceof SimpleXMLElement && isset($table['name'])) {
				$value = strval($table['name']);
			}

			if ($value == $name) {
				return true;
			}
		}

		return false;
	}
}

?>
