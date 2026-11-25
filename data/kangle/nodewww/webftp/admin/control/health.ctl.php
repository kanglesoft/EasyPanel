<?php
/**
 * health.ctl.php —— 源站/辅节点健康探测管理页（单元 origin-node-health-probe）
 *
 * 列表 + 新增/编辑探测目标 + 最近事件流水 + 总开关/选项。
 * 业务全部下沉 framework/api/healthprobe.api.php 与 framework/dao/healthnode.dao.php；
 * 本文件只做参数整理、审计（audit()）与模板渲染。
 *
 * 风格对齐 admin/control/manynode.ctl.php 与 host.ctl.php：needRole('admin') 于文件头、
 * $this->_tpl->assign + fetch、写操作走 POST（CSRF 由 Control::__construct 中央守卫 +
 * common/csrf.js 全局注入，视图无需逐表单带 token）。
 */
needRole('admin');
class HealthControl extends Control
{
	/**
	 * 探测目标列表 + 最近事件 + 全局状态
	 */
	public function index()
	{
		if (isset($_REQUEST['success']) && $_REQUEST['success'] == 1) {
			$this->_tpl->assign('msg', '操作成功');
		}

		$list = daocall('healthnode', 'listAll', array());
		$events = daocall('healthnode', 'listEvents', array(30));
		$nodes = daocall('manynode', 'get', array());

		if (!is_array($list)) {
			$list = array();
		}

		if (!is_array($events)) {
			$events = array();
		}

		if (!is_array($nodes)) {
			$nodes = array();
		}

		$enabled = apicall('healthprobe', 'enabled', array());
		$cron_installed = apicall('healthprobe', 'cronInstalled', array());
		$auto_del = intval(daocall('setting', 'get', array('healthprobe_auto_del')));
		$notify = daocall('setting', 'get', array('healthprobe_notify'));

		$this->_tpl->assign('list', $list);
		$this->_tpl->assign('events', $events);
		$this->_tpl->assign('nodes', $nodes);
		$this->_tpl->assign('hp_enabled', $enabled ? 1 : 0);
		$this->_tpl->assign('cron_installed', $cron_installed ? 1 : 0);
		$this->_tpl->assign('auto_del', $auto_del);
		$this->_tpl->assign('notify_on', ($notify === null || $notify === '') ? 1 : intval($notify));
		$this->_tpl->assign('breadcrumb', '健康探测');
		return $this->_tpl->fetch('health/list.html');
	}

	/**
	 * 新增/编辑探测目标（保存后回到列表）
	 */
	public function add()
	{
		$row = array(
			'name' => isset($_REQUEST['name']) ? $_REQUEST['name'] : '',
			'type' => isset($_REQUEST['type']) ? $_REQUEST['type'] : 'tcp',
			'host' => isset($_REQUEST['host']) ? $_REQUEST['host'] : '',
			'port' => isset($_REQUEST['port']) ? $_REQUEST['port'] : 80,
			'url' => isset($_REQUEST['url']) ? $_REQUEST['url'] : '',
			'expect' => isset($_REQUEST['expect']) ? $_REQUEST['expect'] : '',
			'node' => isset($_REQUEST['node']) ? $_REQUEST['node'] : '',
			'cycle' => isset($_REQUEST['cycle']) ? $_REQUEST['cycle'] : 1,
			'timeout' => isset($_REQUEST['timeout']) ? $_REQUEST['timeout'] : 3,
			'enabled' => isset($_REQUEST['enabled']) && $_REQUEST['enabled'] !== '' ? intval($_REQUEST['enabled']) : 1,
		);

		$return = apicall('healthprobe', 'saveTarget', array($row));
		$name = trim(strval($row['name']));

		if (!$return) {
			$err = isset($GLOBALS['last_error']) && $GLOBALS['last_error'] ? $GLOBALS['last_error'] : '保存失败';
			audit('health', 'add', $name, 'error', array('reason' => strval($err)));
			$this->_tpl->assign('msg', $err);
			return $this->index();
		}

		audit('health', 'add', $name, 'success', array(
			'type' => $row['type'],
			'host' => $row['host'],
			'port' => intval($row['port']),
			'node' => $row['node'],
		));
		header('Location:?c=health&a=index&success=1');
		exit();
	}

	/**
	 * 删除探测目标（事件历史保留，名称可对不上号也不影响检索）
	 */
	public function del()
	{
		$name = isset($_REQUEST['name']) ? trim(strval($_REQUEST['name'])) : '';

		if ($name === '') {
			$this->_tpl->assign('msg', '缺少名称');
			return $this->index();
		}

		$return = daocall('healthnode', 'del', array($name));
		audit('health', 'del', $name, $return ? 'success' : 'error');
		$this->_tpl->assign('msg', $return ? ('已删除探测目标 ' . $name) : '删除失败');
		return $this->index();
	}

	/**
	 * 单目标启用/停用
	 */
	public function toggleTarget()
	{
		$name = isset($_REQUEST['name']) ? trim(strval($_REQUEST['name'])) : '';
		$enabled = isset($_REQUEST['enabled']) && intval($_REQUEST['enabled']) === 1 ? 1 : 0;

		if ($name === '') {
			exit(json_encode(array('success' => false, 'msg' => '缺少名称')));
		}

		$return = daocall('healthnode', 'save', array($name, array('enabled' => $enabled)));
		audit('health', 'toggleTarget', $name, $return ? 'success' : 'error', array('enabled' => $enabled));
		exit(json_encode(array('success' => $return ? true : false)));
	}

	/**
	 * 立即探测单个目标（只看结果，不改状态、不写事件）
	 */
	public function testOne()
	{
		$name = isset($_REQUEST['name']) ? trim(strval($_REQUEST['name'])) : '';
		$target = $name === '' ? false : daocall('healthnode', 'get', array($name));

		if (!is_array($target) || !isset($target['name'])) {
			exit(json_encode(array('success' => false, 'msg' => '目标不存在')));
		}

		$result = apicall('healthprobe', 'checkTarget', array($target));
		audit('health', 'testOne', $name, !empty($result['ok']) ? 'success' : 'error', array(
			'reason' => isset($result['reason']) ? $result['reason'] : '',
			'latency' => isset($result['latency']) ? $result['latency'] : 0,
		));
		exit(json_encode(array(
			'success' => true,
			'ok' => !empty($result['ok']),
			'latency' => isset($result['latency']) ? $result['latency'] : 0,
			'reason' => isset($result['reason']) ? $result['reason'] : '',
		)));
	}

	/**
	 * 立即全量探测一轮（等价于手动触发一次 cron，会正常推进状态机/写事件）
	 */
	public function checkNow()
	{
		/* run() 在 down/up 翻转时会走摘除/回补联动（cdn.del_node / cdnPrimary.sync_node），
		 * 这些旧路径在失败分支直接 echo 警告文本（cdnPrimary.api.php 的 get_version/sync_node）。
		 * 不缓冲会把警告混进本响应的 JSON 前面，前端 $.post 解析失败。 */
		ob_start();
		$ret = apicall('healthprobe', 'run', array());
		$noise = ob_get_clean();
		audit('health', 'checkNow', '-', 'success', array(
			'result' => is_array($ret) ? $ret : strval($ret),
			'noise' => strlen($noise) > 512 ? substr($noise, 0, 512) : $noise,
		));
		exit(json_encode(array('success' => true, 'result' => $ret)));
	}

	/**
	 * 总开关：开=安装 /etc/cron.d/ep_healthprobe，关=删除该文件（teardown 入口之一）
	 */
	public function setEnabled()
	{
		$on = isset($_REQUEST['enabled']) && intval($_REQUEST['enabled']) === 1 ? 1 : 0;
		$return = apicall('healthprobe', 'setEnabled', array($on));
		audit('health', 'setEnabled', 'global', $return ? 'success' : 'error', array('enabled' => $on));
		$this->_tpl->assign('msg', $return ? ($on ? '健康探测已开启（cron 已安装）' : '健康探测已禁用（cron 文件已删除）') : ('设置失败：' . (isset($GLOBALS['last_error']) && $GLOBALS['last_error'] ? $GLOBALS['last_error'] : 'cron 文件写入异常')));
		return $this->index();
	}

	/**
	 * 选项：故障自动移除辅节点(healthprobe_auto_del)、翻转通知(healthprobe_notify)
	 */
	public function saveSettings()
	{
		$auto = isset($_REQUEST['auto_del']) && intval($_REQUEST['auto_del']) === 1 ? 1 : 0;
		$notify = isset($_REQUEST['notify']) && intval($_REQUEST['notify']) === 0 ? 0 : 1;
		daocall('setting', 'add', array('healthprobe_auto_del', $auto));
		daocall('setting', 'add', array('healthprobe_notify', $notify));
		audit('health', 'saveSettings', '-', 'success', array('auto_del' => $auto, 'notify' => $notify));
		header('Location:?c=health&a=index&success=1');
		exit();
	}
}

?>
