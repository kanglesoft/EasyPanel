<?php
/**
 * 系统计划任务管理（admincron）
 *
 * 单元：admin-system-cron。页面：
 *   ?c=admincron                列表（搜索/分页/启停/立即执行/删除/重建 cron.d/看日志）
 *   ?c=admincron&a=addForm      新增任务表单（from.html）
 *   ?c=admincron&a=editForm&id= 编辑任务表单（复用 from.html）
 *
 * 约定：
 *   - 写操作（save/del/toggle/run/sync）一律 POST，token 由 common/csrf.js 全局注入；
 *   - 每个写操作都落审计 audit('cron', ...)（runtime.php 的 audit() 助手，失败静默）；
 *   - 页面级错误用 msg.html / 表单回填呈现，ajax 类（run/toggle/del/sync）回 JSON，
 *     与 docker.ctl.php 口径一致。
 */
needRole('admin');

class AdmincronControl extends Control
{
	/**
	 * 列表页
	 */
	public function index()
	{
		apicall('admincron', 'ensureTable', array());
		$keyword = trim(strval(isset($_REQUEST['keyword']) ? $_REQUEST['keyword'] : ''));
		$all = daocall('admincron', 'getList', array());
		$all = is_array($all) ? $all : array();

		if ($keyword !== '') {
			$matched = array();

			foreach ($all as $task) {
				if (stripos(strval($task['name']), $keyword) !== false
					|| stripos(strval($task['cmd']), $keyword) !== false
					|| stripos(strval($task['cron']), $keyword) !== false) {
					$matched[] = $task;
				}
			}

			$all = $matched;
		}

		$count = count($all);

		foreach ($all as $key => $task) {
			$all[$key]['cron_desc'] = apicall('admincron', 'describe', array($task['cron']));
			$all[$key]['last_run_text'] = intval($task['last_run']) > 0 ? date('Y-m-d H:i:s', intval($task['last_run'])) : '—';
			$st = intval($task['last_status']);
			$all[$key]['status_text'] = ($st === 1) ? '成功' : (($st === 2) ? '失败' : '未执行');
		}

		$page_count = 15;
		$total_page = max(1, (int) ceil($count / $page_count));
		$page = intval(isset($_REQUEST['page']) ? $_REQUEST['page'] : 1);

		if ($page < 1) {
			$page = 1;
		}

		if ($page > $total_page) {
			$page = $total_page;
		}

		// cron.d 现状（供列表页提示「系统文件与库不一致」场景自行刷新）
		$this->_tpl->assign('tasks', array_slice($all, ($page - 1) * $page_count, $page_count));
		$this->_tpl->assign('count', $count);
		$this->_tpl->assign('total_page', $total_page);
		$this->_tpl->assign('page', $page);
		$this->_tpl->assign('page_count', $page_count);
		$this->_tpl->assign('keyword', $keyword);
		$this->_tpl->assign('msg', trim(strval(isset($_REQUEST['msg']) ? $_REQUEST['msg'] : '')));
		$this->_tpl->assign('breadcrumb', '计划任务');
		return $this->_tpl->fetch('admincron/list.html');
	}

	/**
	 * 新增表单
	 */
	public function addForm()
	{
		$this->_tpl->assign('task', array('id' => 0, 'name' => '', 'type' => 'url', 'cmd' => '', 'cron' => '*/5 * * * *', 'flock' => 1));
		$this->_tpl->assign('action', 'save');
		$this->_tpl->assign('form_title', '添加计划任务');
		$this->_tpl->assign('msg', '');
		$this->_tpl->assign('breadcrumb', '计划任务');
		return $this->_tpl->fetch('admincron/from.html');
	}

	/**
	 * 编辑表单
	 */
	public function editForm()
	{
		apicall('admincron', 'ensureTable', array());
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			$this->_tpl->assign('msg', '任务不存在');
			$this->_tpl->assign('jump_url', '?c=admincron');
			$this->_tpl->assign('button_text', '返回列表');
			return $this->_tpl->fetch('msg.html');
		}

		$this->_tpl->assign('task', $task);
		$this->_tpl->assign('action', 'save');
		$this->_tpl->assign('form_title', '编辑计划任务');
		$this->_tpl->assign('msg', '');
		$this->_tpl->assign('breadcrumb', '计划任务');
		return $this->_tpl->fetch('admincron/from.html');
	}

	/**
	 * 保存（新增/编辑共用，POST）
	 */
	public function save()
	{
		$this->requirePost();
		$params = array(
			'name' => isset($_POST['name']) ? $_POST['name'] : '',
			'type' => isset($_POST['type']) ? $_POST['type'] : '',
			'cmd' => isset($_POST['cmd']) ? $_POST['cmd'] : '',
			'cron' => isset($_POST['cron']) ? $_POST['cron'] : '',
			'flock' => (isset($_POST['flock']) && $_POST['flock'] === '1') ? 1 : 0
		);
		$id = intval(isset($_POST['id']) ? $_POST['id'] : 0);

		if ($id > 0) {
			$ok = apicall('admincron', 'update', array($id, $params));
			$action = 'update';
		}
		else {
			$ok = apicall('admincron', 'add', array($params));
			$action = 'add';
		}

		if ($ok === false) {
			$err = $this->lastError();
			audit('cron', $action, isset($params['name']) ? strval($params['name']) : '-', 'error', array('reason' => $err));
			// 回填表单，避免输入丢失
			$task = array_merge($params, array('id' => $id, 'flock' => $params['flock']));
			$this->_tpl->assign('task', $task);
			$this->_tpl->assign('action', 'save');
			$this->_tpl->assign('form_title', $id > 0 ? '编辑计划任务' : '添加计划任务');
			$this->_tpl->assign('msg', '保存失败：' . $err);
			$this->_tpl->assign('breadcrumb', '计划任务');
			return $this->_tpl->fetch('admincron/from.html');
		}

		$warn = $this->lastError();
		audit('cron', $action, strval($params['name']), 'success', array('cron' => strval($params['cron']), 'type' => strval($params['type'])));
		$msg = $id > 0 ? '保存成功' : '添加成功';

		if ($warn !== '') {
			$msg .= '（注意：' . $warn . '）';
		}

		$this->back($msg);
	}

	/**
	 * 删除（POST ajax）
	 */
	public function del()
	{
		$this->requirePost();
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$ok = apicall('admincron', 'del', array($id));
		audit('cron', 'del', strval($id), $ok === false ? 'error' : 'success', array('error' => $ok === false ? $this->lastError() : ''));

		if ($ok === false) {
			exit(json_encode(array('success' => false, 'msg' => '删除失败：' . $this->lastError())));
		}

		$msg = '任务已删除';
		$warn = $this->lastError();

		if ($warn !== '') {
			$msg .= '（注意：' . $warn . '）';
		}

		exit(json_encode(array('success' => true, 'msg' => $msg)));
	}

	/**
	 * 启用/停用（POST ajax）——停用后 cron.d 对应行必须消失（sync 重渲染）
	 */
	public function toggle()
	{
		$this->requirePost();
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$enabled = intval(isset($_REQUEST['enabled']) ? $_REQUEST['enabled'] : 0) === 1 ? 1 : 0;
		$ok = apicall('admincron', 'toggle', array($id, $enabled));
		audit('cron', 'toggle', strval($id), $ok === false ? 'error' : 'success', array('enabled' => $enabled));
		$msg = $ok === false ? '操作失败：' . $this->lastError() : ($enabled ? '任务已启用' : '任务已停用');

		if ($ok !== false) {
			$warn = $this->lastError();

			if ($warn !== '') {
				$msg .= '（注意：' . $warn . '）';
			}
		}

		exit(json_encode(array('success' => $ok !== false, 'msg' => $msg)));
	}

	/**
	 * 立即后台执行一次（POST ajax）
	 */
	public function run()
	{
		$this->requirePost();
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$ok = apicall('admincron', 'spawn', array($id));
		audit('cron', 'run', strval($id), $ok === false ? 'error' : 'success', array('error' => $ok === false ? $this->lastError() : ''));
		exit(json_encode(array(
			'success' => $ok !== false,
			'msg' => $ok === false ? '执行失败：' . $this->lastError() : '已触发执行，请稍后查看日志'
		)));
	}

	/**
	 * 手动重建 /etc/cron.d/ep_admincron（POST ajax）
	 */
	public function sync()
	{
		$this->requirePost();
		$ok = apicall('admincron', 'sync', array());
		audit('cron', 'sync', '-', $ok === false ? 'error' : 'success', array('error' => $ok === false ? $this->lastError() : ''));
		exit(json_encode(array(
			'success' => $ok !== false,
			'msg' => $ok === false ? '重建失败：' . $this->lastError() : '系统计划任务已重建'
		)));
	}

	/**
	 * 任务日志尾部（GET，只读；页面内 ajax 拉取）
	 */
	public function logdata()
	{
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$log = apicall('admincron', 'getLog', array($id));
		header('Content-Type: text/plain; charset=UTF-8');
		header('Cache-Control: no-cache');
		echo $log === '' ? '暂无执行日志' : $log;
		exit();
	}

	/**
	 * 清空任务日志（POST ajax）
	 */
	public function clearLog()
	{
		$this->requirePost();
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
		$task = daocall('admincron', 'get', array($id));

		if (!$task) {
			exit(json_encode(array('success' => false, 'msg' => '任务不存在')));
		}

		$ok = apicall('admincron', 'clearLog', array($id));
		audit('cron', 'clearLog', strval($id), $ok === false ? 'error' : 'success');
		exit(json_encode(array('success' => $ok !== false, 'msg' => $ok === false ? '清空失败，请检查日志目录权限' : '日志已清空')));
	}

	/**
	 * 任务详情（GET JSON，编辑弹窗预填用，当前编辑走独立页面，保留兼容 ajax 调用）
	 */
	public function get()
	{
		$task = daocall('admincron', 'get', array(intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0)));
		header('Content-Type: application/json; charset=UTF-8');

		if (!$task) {
			echo '{}';
			exit();
		}

		echo json_encode(array(
			'id' => intval($task['id']),
			'name' => strval($task['name']),
			'type' => strval($task['type']),
			'cmd' => strval($task['cmd']),
			'cron' => strval($task['cron']),
			'flock' => intval($task['flock'])
		));
		exit();
	}

	/**
	 * 取 API 层错误/警告文本；无则返回空串（调用方自行兜底措辞）。
	 */
	private function lastError()
	{
		return !empty($GLOBALS['last_error']) ? strval($GLOBALS['last_error']) : '';
	}

	/**
	 * 写操作只接受 POST（GET 直接访问一律拒绝，配合 csrf.js 的 token 注入）。
	 */
	private function requirePost()
	{
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

		if ($method !== 'POST') {
			exit(json_encode(array('success' => false, 'msg' => '该操作必须以 POST 提交')));
		}
	}

	private function back($msg)
	{
		$url = '?c=admincron&msg=' . urlencode($msg);
		$keyword = trim(strval(isset($_REQUEST['keyword']) ? $_REQUEST['keyword'] : ''));

		if ($keyword !== '') {
			$url .= '&keyword=' . urlencode($keyword);
		}

		$page = intval(isset($_REQUEST['page']) ? $_REQUEST['page'] : 1);

		if ($page > 1) {
			$url .= '&page=' . $page;
		}

		header('Location: ' . $url);
		exit();
	}
}

?>
