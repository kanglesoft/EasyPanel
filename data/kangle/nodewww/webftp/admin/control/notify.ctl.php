<?php
needRole('admin');

/**
 * 通知渠道管理（功能项 11）
 *
 * 提供 SMTP / Telegram / Webhook 三类出站通知渠道的增删改查与「测试发送」。
 * 面板此前所有异常静默（证书到期、备份失败、节点失联都无人知晓），本控制器
 * 是这些告警的统一配置入口；发送引擎在 framework/api/notify.api.php，
 * 数据层在 framework/dao/notifychannel.dao.php。
 *
 * 安全口径：
 *   - 写操作（add/del/toggle）为 POST；CSRF token 由 head.html + common/csrf.js
 *     全局注入，本文件不重复实现。
 *   - testSend 同时接受 GET 与 POST：GET 是为了让
 *     `curl '?c=notify&a=testSend&id=1'`（带登录 cookie）这种非浏览器验收路径
 *     直接可用。它的副作用只是「向管理员自己配置的地址发一条测试消息 + 更新
 *     该渠道的 last_* 字段」，不构成权限/拓扑变更，因此刻意不进入
 *     csrf_enforce_level 的强制清单（理由与集成注意事项见
 *     .temp/integration/notification-channels.md）。
 *   - 所有回显值经过 htmlspecialchars，防止渠道名/备注/错误信息造成存储型 XSS。
 *   - 机密字段（SMTP 密码 / TG token / HMAC 密钥）永不回显：表单只拿到
 *     「已设置」标记，提交留空表示沿用原值。
 */
class NotifyControl extends Control
{
	private $daoname = 'notifychannel';

	/** 允许的渠道类型（与 notify.api.php 的 TYPES 对齐） */
	private $types = array('smtp', 'telegram', 'webhook');

	public function index()
	{
		return $this->pageList();
	}

	/**
	 * 渠道列表
	 */
	public function pageList()
	{
		$this->ready();

		$page = intval($_REQUEST['page']);

		if ($page <= 0) {
			$page = 1;
		}

		$page_count = 20;
		$count = 0;
		$list = daocall($this->daoname, 'pageList', array($page, $page_count, &$count));
		$total_page = $count > 0 ? ceil($count / $page_count) : 1;

		if ($total_page < $page) {
			$page = intval($total_page);
			$list = daocall($this->daoname, 'pageList', array($page, $page_count, &$count));
		}

		$this->_tpl->assign('breadcrumb', '通知渠道');
		$this->_tpl->assign('count', intval($count));
		$this->_tpl->assign('total_page', intval($total_page));
		$this->_tpl->assign('page', intval($page));
		$this->_tpl->assign('page_count', intval($page_count));
		$this->_tpl->assign('list', $this->decorate(is_array($list) ? $list : array()));

		return $this->_tpl->fetch('notify/list.html');
	}

	/**
	 * 新增 / 修改表单
	 */
	public function addFrom()
	{
		$this->ready();

		$id = intval($_REQUEST['id']);
		$channel = array();

		if ($id > 0) {
			$one = daocall($this->daoname, 'get', array($id));

			if (is_array($one) && $one) {
				$channel = $this->prepareForm($one);
			}
		}

		if (empty($channel)) {
			$defType = (isset($_REQUEST['type']) && in_array($_REQUEST['type'], $this->types, true))
				? strval($_REQUEST['type']) : 'webhook';
			$channel = $this->blankChannel($defType);
		}

		$this->_tpl->assign('breadcrumb', $channel['id'] > 0 ? '编辑通知渠道' : '新增通知渠道');
		$this->_tpl->assign('channel', $channel);

		return $this->_tpl->fetch('notify/from.html');
	}

	/**
	 * 保存（id>0 走 UPDATE，否则 INSERT）
	 */
	public function add()
	{
		$this->ready();

		$id = intval($_REQUEST['id']);
		$name = trim(strval($this->param('name')));
		$type = trim(strval($this->param('type')));
		$enabled = $this->param('enabled') === '0' || $this->param('enabled') === '' ? 0 : 1;
		$remark = trim(strval($this->param('remark')));

		// 渠道名要能当标识用：中文/字母/数字/下划线/短横线，2~30 字符
		if (!preg_match('/^[\p{Han}0-9A-Za-z_\-]{2,30}$/u', $name)) {
			return $this->formError('渠道名称请填 2~30 个字符（中文、字母、数字、下划线或短横线）。', $id, $type, $name, $remark, $enabled);
		}

		if (!in_array($type, $this->types, true)) {
			return $this->formError('渠道类型不合法，请重新选择。', $id, 'webhook', $name, $remark, $enabled);
		}

		if (function_exists('mb_strlen') && mb_strlen($remark, 'UTF-8') > 100) {
			$remark = mb_substr($remark, 0, 100, 'UTF-8');
		}

		$cfg = $this->collectConfig($type);

		// 编辑时机密留空 = 沿用库里原值（表单永远不回显明文）
		if ($id > 0) {
			$old = daocall($this->daoname, 'get', array($id));
			$cfg = $this->mergeSecrets($type, $cfg, $this->decodeConfig($old));
		}

		$checked = apicall('notify', 'validate', array($type, $cfg));

		if (!$checked['ok']) {
			return $this->formError($checked['msg'], $id, $type, $name, $remark, $enabled, $cfg);
		}

		$dup = daocall($this->daoname, 'getByName', array($name));

		if (is_array($dup) && intval($dup['id']) !== $id) {
			return $this->formError('渠道名称「' . $name . '」已存在，请换一个名字。', $id, $type, $name, $remark, $enabled, $cfg);
		}

		$row = array(
			'name'    => $name,
			'type'    => $type,
			'enabled' => $enabled,
			'config'  => $this->encodeConfig($checked['cfg']),
			'remark'  => $remark,
		);

		if ($id > 0) {
			if (!daocall($this->daoname, 'updateChannel', array($id, $row))) {
				audit('notify', 'update', $name, 'error', array('id' => $id, 'type' => $type, 'reason' => 'update failed'));
				return $this->msgPage('修改渠道失败（渠道可能已被删除），请返回列表重试。');
			}

			audit('notify', 'update', $name, 'success', array('id' => $id, 'type' => $type, 'enabled' => $enabled));
			return $this->pageList();
		}

		$newId = daocall($this->daoname, 'add', array($row));

		if (!$newId) {
			audit('notify', 'add', $name, 'error', array('type' => $type, 'reason' => 'insert failed'));
			return $this->msgPage('增加渠道失败，请检查名称是否重复后重试。');
		}

		audit('notify', 'add', $name, 'success', array('id' => $newId, 'type' => $type, 'enabled' => $enabled));
		return $this->pageList();
	}

	/**
	 * 删除渠道
	 */
	public function del()
	{
		$this->ready();

		$id = intval($_REQUEST['id']);
		$channel = daocall($this->daoname, 'get', array($id));

		if (!is_array($channel) || !$channel) {
			return $this->msgPage('渠道不存在（id=' . $id . '），可能已被删除。');
		}

		if (!daocall($this->daoname, 'del', array($id))) {
			audit('notify', 'del', $channel['name'], 'error', array('id' => $id, 'reason' => 'delete failed'));
			return $this->msgPage('删除渠道失败，请重试。');
		}

		audit('notify', 'del', $channel['name'], 'success', array('id' => $id, 'type' => $channel['type']));
		return $this->pageList();
	}

	/**
	 * 启用 / 停用
	 */
	public function toggle()
	{
		$this->ready();

		$id = intval($_REQUEST['id']);
		$channel = daocall($this->daoname, 'get', array($id));

		if (!is_array($channel) || !$channel) {
			return $this->msgPage('渠道不存在（id=' . $id . '）。');
		}

		$next = intval($channel['enabled']) === 1 ? 0 : 1;

		if (!daocall($this->daoname, 'setEnabled', array($id, $next))) {
			audit('notify', 'toggle', $channel['name'], 'error', array('id' => $id, 'reason' => 'update failed'));
			return $this->msgPage('切换启用状态失败，请重试。');
		}

		audit('notify', 'toggle', $channel['name'], 'success', array('id' => $id, 'enabled' => $next));
		return $this->pageList();
	}

	/**
	 * 测试发送（ajax / curl 均可）
	 *
	 * 结构化 JSON 返回，绝不裸文本 / 不抛 500：
	 *   成功 {"success":true,"status":"sent",...}
	 *   失败 {"success":false,"status":"failed","error":"Telegram 401: Unauthorized"}
	 */
	public function testSend()
	{
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('X-Content-Type-Options: nosniff');
		}

		$this->ready();

		$id = intval($_REQUEST['id']);

		if ($id <= 0) {
			echo $this->json(array(
				'success' => false,
				'status'  => 'failed',
				'id'      => 0,
				'code'    => 400,
				'error'   => '缺少 id 参数',
				'msg'     => '缺少 id 参数',
			));
			return;
		}

		$channel = daocall($this->daoname, 'get', array($id));
		$name = is_array($channel) && $channel ? strval($channel['name']) : '';
		$type = is_array($channel) && $channel ? strval($channel['type']) : '';

		try {
			$r = apicall('notify', 'testSend', array($id));
		}
		catch (Exception $e) {
			$r = array('success' => false, 'status' => 'failed', 'error' => '发送异常：' . $e->getMessage());
		}
		catch (Throwable $e) {
			// PHP 7 的 Error 不属于 Exception，单独兜一层保证始终返回合法 JSON
			$r = array('success' => false, 'status' => 'failed', 'error' => '发送异常：' . $e->getMessage());
		}

		if (!is_array($r)) {
			$r = array('success' => false, 'status' => 'failed', 'error' => '发送返回异常');
		}

		$success = !empty($r['success']);
		$out = array(
			'success'   => $success,
			'status'    => isset($r['status']) ? strval($r['status']) : ($success ? 'sent' : 'failed'),
			'id'        => $id,
			'name'      => $name,
			'type'      => $type,
			'error'     => isset($r['error']) ? strval($r['error']) : '',
			'http_code' => isset($r['http_code']) ? intval($r['http_code']) : 0,
			'msg'       => isset($r['msg']) ? strval($r['msg']) : ($success ? '发送成功' : '发送失败'),
		);

		audit('notify', 'testSend', $name !== '' ? $name : ('#' . $id), $success ? 'success' : 'error',
			array('id' => $id, 'type' => $type, 'status' => $out['status'],
				'http_code' => $out['http_code'], 'error' => $out['error']));

		echo $this->json($out);
		return;
	}

	/* ------------------------------------------------------------------
	 *  内部实现
	 * ------------------------------------------------------------------ */

	/**
	 * 表就绪兜底：notify_channel 由 upgrade.sql 增量创建，但升级脚本重放发生在
	 * 登录/升级动作上；万一还没重放到，ensureTable() 让本页不至于
	 * 直接撞 sqlite「no such table」。
	 */
	private function ready()
	{
		daocall($this->daoname, 'ensureTable', array());
	}

	private function param($key, $default = '')
	{
		return isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key]) ? strval($_REQUEST[$key]) : $default;
	}

	/**
	 * 表单错误 → 回到 from.html 并保留已填内容
	 */
	private function formError($msg, $id, $type, $name = '', $remark = '', $enabled = 1, $cfg = null)
	{
		if ($cfg === null) {
			$cfg = $this->collectConfig($type);
		}

		$channel = $this->blankChannel($type);
		$channel['id'] = intval($id);
		$channel['name'] = $name;
		$channel['remark'] = $remark;
		$channel['enabled'] = $enabled ? 1 : 0;
		$channel['cfg'] = is_array($cfg) ? $cfg : array();

		$this->_tpl->assign('breadcrumb', $id > 0 ? '编辑通知渠道' : '新增通知渠道');
		// 原文交给模板，由 {{$err|escape}} 统一转义（错误文案可能回显用户输入的渠道名）
		$this->_tpl->assign('err', strval($msg));
		$this->_tpl->assign('channel', $channel);

		return $this->_tpl->fetch('notify/from.html');
	}

	private function msgPage($msg)
	{
		// msg.html 沿用既有写法（{$msg} 原文输出，其它控制器会塞 <a>），因此这里
		// 只允许传「静态文案 + intval 出来的 id」，绝不把用户输入拼进来。
		$this->_tpl->assign('msg', strval($msg));
		$this->_tpl->assign('jump_url', '?c=notify&amp;a=pageList');
		$this->_tpl->assign('breadcrumb', '通知渠道');
		$this->_tpl->assign('title', '通知渠道');

		return $this->_tpl->fetch('msg.html');
	}

	/**
	 * JSON 输出兜底
	 *
	 * json_encode 在输入含非法 UTF-8 时返回 **false**，若直接 echo 就是空响应体：
	 * 前端 ajax 解析失败、验收的 curl 断言也会拿到空串而误判。非法 UTF-8 的
	 * 来源是第三方应答（webhook 响应体、SMTP/TG 的非 UTF-8 错误描述），
	 * 因此第一道防线在 notify.api 的 fail() 里已把文案洗成合法 UTF-8；
	 * 这里是第二道：万一还有漏网字节，宁可输出一条固定的错误 JSON，
	 * 也绝不让 testSend 返回空体 —— 保证本接口任何情况下都可被 json 解析。
	 *
	 * @param array $a
	 * @return string
	 */
	private function json($a)
	{
		$s = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (is_string($s) && $s !== '') {
			return $s;
		}

		return '{"success":false,"status":"failed","error":"response encode error","msg":"响应编码失败"}';
	}

	/**
	 * config 列编码（与 json() 同一兜底思路，写库前保证是合法字符串）
	 */
	private function encodeConfig($cfg)
	{
		$s = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (!is_string($s) || $s === '') {
			$s = json_encode($cfg);
		}

		if (!is_string($s) || $s === '') {
			return '{}';
		}

		return $s;
	}

	/**
	 * 空白渠道结构（新增 / 校验失败回填都用它，字段齐全，模板无需判空）
	 */
	private function blankChannel($type)
	{
		return array(
			'id'      => 0,
			'name'    => '',
			'type'    => in_array($type, $this->types, true) ? $type : 'webhook',
			'enabled' => 1,
			'remark'  => '',
			// 默认值一律用 notify.api validate() 的规范字段名（collectConfig 同名），
			// 这样「新增空表单」与「校验失败回填」两条路径在模板里读同一组键。
			'cfg'     => array(
				'timeout'    => 10,
				'port'       => 587,
				'secure'     => 'auto',
				'method'     => 'POST',
				'body_type'  => 'json',
				'parse_mode' => 'html',
			),
			'secret_pass'  => false,
			'secret_token' => false,
			'secret_sign'  => false,
			// from.html 底部「最近一次投递」区块读这三个键；新增表单与校验失败
			// 回填都走这里，键必须齐，否则模板按未定义索引判断
			'last_status'  => 0,
			'last_time'    => 0,
			'last_error'   => '',
		);
	}

	/**
	 * 按类型收集表单里的渠道参数（机密字段原样取出，供 mergeSecrets 判定）
	 */
	private function collectConfig($type)
	{
		$cfg = array();
		$timeout = intval($this->param('timeout'));

		if ($timeout > 0) {
			$cfg['timeout'] = $timeout;
		}

		switch ($type) {
		case 'smtp':
			$cfg['host'] = trim($this->param('smtp_host'));
			$cfg['port'] = intval($this->param('smtp_port'));
			$cfg['secure'] = $this->param('smtp_secure', 'auto');
			$cfg['user'] = trim($this->param('smtp_user'));
			$cfg['pass'] = $this->param('smtp_pass');
			$cfg['from'] = trim($this->param('smtp_from'));
			$cfg['from_name'] = trim($this->param('smtp_from_name'));
			$cfg['to'] = $this->param('smtp_to');
			break;

		case 'telegram':
			$cfg['token'] = trim($this->param('tg_token'));
			$cfg['chat_id'] = trim($this->param('tg_chat_id'));
			$cfg['api_base'] = trim($this->param('tg_api_base'));
			$cfg['parse_mode'] = $this->param('tg_parse_mode', 'html');
			$cfg['thread_id'] = intval($this->param('tg_thread_id'));
			break;

		case 'webhook':
			$cfg['url'] = trim($this->param('hook_url'));
			$cfg['method'] = strtoupper($this->param('hook_method', 'POST'));
			$cfg['body_type'] = $this->param('hook_body_type', 'json');
			$cfg['body_template'] = $this->param('hook_body_template');
			$cfg['headers'] = $this->param('hook_headers');
			$cfg['sign_key'] = $this->param('hook_sign_key');
			break;
		}

		return $cfg;
	}

	/**
	 * 机密字段留空时沿用原值
	 */
	private function mergeSecrets($type, $newCfg, $oldCfg)
	{
		$keys = apicall('notify', 'secretKeys', array($type));

		if (!is_array($keys) || empty($keys)) {
			return $newCfg;
		}

		foreach ($keys as $key) {
			if (!isset($newCfg[$key])) {
				continue;
			}

			if ($newCfg[$key] === '' && isset($oldCfg[$key]) && $oldCfg[$key] !== '') {
				$newCfg[$key] = $oldCfg[$key];
			}
		}

		return $newCfg;
	}

	private function decodeConfig($channel)
	{
		if (!is_array($channel) || !$channel) {
			return array();
		}

		$cfg = apicall('notify', 'decodeConfig', array($channel));

		return is_array($cfg) ? $cfg : array();
	}

	/**
	 * 库行 → 表单可用的 channel 结构
	 *
	 * 注意两点：
	 *   1. 机密字段清空，只给模板「已设置」布尔标记；
	 *   2. webhook 的 headers 库里是解析后的数组，表单要的是每行一条的文本，
	 *      这里折回去（validate 的解析是幂等的，折回去再解析结果一致）。
	 */
	private function prepareForm($channel)
	{
		$type = strval($channel['type']);
		$cfg = $this->decodeConfig($channel);

		$map = array(
			'smtp'     => array('pass' => 'secret_pass'),
			'telegram' => array('token' => 'secret_token'),
			'webhook'  => array('sign_key' => 'secret_sign'),
		);

		$flags = array(
			'secret_pass'  => false,
			'secret_token' => false,
			'secret_sign'  => false,
		);

		if (isset($map[$type])) {
			foreach ($map[$type] as $key => $flag) {
				if (isset($cfg[$key]) && $cfg[$key] !== '') {
					$flags[$flag] = true;
				}

				$cfg[$key] = '';
			}
		}

		if (isset($cfg['headers']) && is_array($cfg['headers'])) {
			$lines = array();

			foreach ($cfg['headers'] as $k => $v) {
				$lines[] = $k . ': ' . $v;
			}

			$cfg['headers'] = implode("\n", $lines);
		}

		$out = $this->blankChannel($type);
		$out['id'] = intval($channel['id']);
		$out['name'] = strval($channel['name']);
		$out['enabled'] = intval($channel['enabled']);
		$out['remark'] = strval($channel['remark']);
		$out['cfg'] = array_merge($out['cfg'], $cfg);
		$out['last_status'] = intval($channel['last_status']);
		$out['last_time']   = intval($channel['last_time']);
		$out['last_error']  = strval($channel['last_error']);
		$out = array_merge($out, $flags);

		return $out;
	}

	/**
	 * 列表行加工：解析 config 摘要
	 *
	 * 输出的是原始值，模板一律用 |escape 输出 —— 与本仓库最新代码（certdeploy /
	 * admincron）保持同一口径：控制器不做二次转义，避免「转义后再转义」把
	 * 渠道名里的引号显示成 &amp;quot;。
	 */
	private function decorate($list)
	{
		$out = array();

		foreach ($list as $row) {
			$type = strval($row['type']);
			$cfg = $this->decodeConfig($row);

			$out[] = array(
				'id'          => intval($row['id']),
				'name'        => strval($row['name']),
				'type'        => $type,
				'type_label'  => $this->typeLabel($type),
				'enabled'     => intval($row['enabled']),
				'target'      => $this->summarize($type, $cfg),
				'remark'      => strval($row['remark']),
				'last_status' => intval($row['last_status']),
				'last_time'   => intval($row['last_time']),
				'last_error'  => strval($row['last_error']),
				'createtime'  => intval($row['createtime']),
			);
		}

		return $out;
	}

	/**
	 * 列表页展示用的「目标」摘要：一眼看出通知发去哪儿，且不泄露机密
	 */
	private function summarize($type, $cfg)
	{
		switch ($type) {
		case 'smtp':
			$hosts = array_filter(array_map('trim', explode(',', strval(isset($cfg['to']) ? $cfg['to'] : ''))));
			$first = !empty($hosts) ? reset($hosts) : '';
			$more = count($hosts) > 1 ? ' 等' . count($hosts) . '人' : '';

			return strval(isset($cfg['host']) ? $cfg['host'] : '') . ':' . intval(isset($cfg['port']) ? $cfg['port'] : 0)
				. ' → ' . ($first !== '' ? $first . $more : '未填收件人');

		case 'telegram':
			$chat = strval(isset($cfg['chat_id']) ? $cfg['chat_id'] : '');
			$base = rtrim(strval(isset($cfg['api_base']) ? $cfg['api_base'] : ''), '/');

			return ($chat !== '' ? $chat : '未填 chat_id')
				. ($base !== '' && $base !== 'https://api.telegram.org' ? ' via ' . $base : '');

		case 'webhook':
			$url = strval(isset($cfg['url']) ? $cfg['url'] : '');

			return $url !== '' ? $url : '未填地址';
		}

		return '-';
	}

	private function typeLabel($type)
	{
		$map = array(
			'smtp'     => '邮件 SMTP',
			'telegram' => 'Telegram',
			'webhook'  => 'Webhook',
		);

		return isset($map[$type]) ? $map[$type] : $type;
	}
}

?>
