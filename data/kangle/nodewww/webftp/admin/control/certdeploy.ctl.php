<?php
needRole('admin');

/**
 * certdeploy.ctl.php —— 证书分发（续签 → CDN 辅节点）管理界面
 *
 * 页面：
 *   pageList  任务列表（状态过滤 + 分页 + 状态计数）
 *   log       单任务执行日志
 * 动作（POST，csrf.js 自动带 token；强制清单由集成步加 4 行）：
 *   create        手动新建分发任务
 *   retry         立即重试（跳过退避，就地推送并返回结果）
 *   del           删除任务及日志
 *   installCron   重装 ep_certtask 计划任务（镜像重建后 cron.d 丢失时用）
 *   runNow        手动跑一轮 runOnce
 * JSON：
 *   pageListJson  验收/前端轮询用（curl ?c=certdeploy&a=pageList 为 HTML，
 *                 pageListJson 为同一数据的 JSON 形态）
 */
class CertdeployControl extends control
{
	/**
	 * 任务列表页
	 */
	public function pageList()
	{
		$status = isset($_REQUEST['status']) ? filterParam($_REQUEST['status'], 'word') : '';
		$valid = array('', 'pending', 'running', 'failed', 'success', 'abandoned');

		if (!in_array($status, $valid, true)) {
			$status = '';
		}

		$page = intval($_REQUEST['page']);

		if ($page <= 0) {
			$page = 1;
		}

		$page_count = 20;
		$ret = apicall('certtask', 'listTasks', array($status, $page, $page_count));
		$count = intval($ret['total']);
		$total_page = ceil($count / $page_count);

		if ($total_page < 1) {
			$total_page = 1;
		}

		if ($page > $total_page) {
			$page = $total_page;
			$ret = apicall('certtask', 'listTasks', array($status, $page, $page_count));
		}

		// 新建表单的候选项：持证站点 × 辅节点
		$vhosts = array();
		$all = daocall('vhost', 'listVhostNotcdn', array());

		if (is_array($all)) {
			foreach ($all as $vh) {
				if (!empty($vh['certificate']) && !empty($vh['certificate_key'])) {
					$vhosts[] = $vh['name'];
				}
			}
		}

		$nodes = daocall('manynode', 'get', array());
		$nodeNames = array();

		if (is_array($nodes)) {
			foreach ($nodes as $n) {
				$nodeNames[] = $n['name'];
			}
		}

		$this->_tpl->assign('status', $status);
		$this->_tpl->assign('list', $ret['list']);
		$this->_tpl->assign('counts', $ret['counts']);
		$this->_tpl->assign('count', $count);
		$this->_tpl->assign('total_page', $total_page);
		$this->_tpl->assign('page', $page);
		$this->_tpl->assign('page_count', $page_count);
		$this->_tpl->assign('vhosts', $vhosts);
		$this->_tpl->assign('nodes', $nodeNames);
		$this->_tpl->assign('cron_installed', daocall('setting', 'get', array('certtask_cron_installed')));
		return $this->_tpl->fetch('certdeploy/list.html');
	}

	/**
	 * 任务执行日志页
	 */
	public function log()
	{
		$id = intval($_REQUEST['id']);
		$ret = apicall('certtask', 'taskDetail', array($id, 500));

		if (!$ret['success']) {
			$this->_tpl->assign('msg', '任务不存在（id=' . $id . '），可能已被删除。');
			$this->_tpl->assign('jump_url', '?c=certdeploy&a=pageList');
			return $this->_tpl->fetch('msg.html');
		}

		$this->_tpl->assign('task', $ret['task']);
		$this->_tpl->assign('logs', $ret['logs']);
		return $this->_tpl->fetch('certdeploy/log.html');
	}

	/**
	 * JSON：任务列表（验收断言 & 前端轮询）
	 */
	public function pageListJson()
	{
		$status = isset($_REQUEST['status']) ? filterParam($_REQUEST['status'], 'word') : '';
		$valid = array('', 'pending', 'running', 'failed', 'success', 'abandoned');

		if (!in_array($status, $valid, true)) {
			$status = '';
		}

		$page = max(1, intval($_REQUEST['page']));
		$size = max(1, intval($_REQUEST['size']) ? intval($_REQUEST['size']) : 20);
		$ret = apicall('certtask', 'listTasks', array($status, $page, $size));

		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}

		echo json_encode($ret);
		return;
	}

	/**
	 * 手动新建任务
	 */
	public function create()
	{
		$vhost = trim($_REQUEST['vhost']);
		$node = trim($_REQUEST['node']);
		$ret = apicall('certtask', 'createTask', array($vhost, $node));
		audit('certdeploy', 'create', $vhost . '@' . $node, $ret['success'] ? 'success' : 'error', array('msg' => $ret['msg']));
		$this->_tpl->assign('msg', $ret['msg']);
		$this->_tpl->assign('jump_url', '?c=certdeploy&a=pageList');
		return $this->_tpl->fetch('msg.html');
	}

	/**
	 * 立即重试（就地推送并展示结果）
	 */
	public function retry()
	{
		$id = intval($_REQUEST['id']);
		$ret = apicall('certtask', 'manualRetry', array($id));
		audit('certdeploy', 'retry', 'task#' . $id, $ret['success'] ? 'success' : 'error', array('msg' => $ret['msg']));

		if ($_REQUEST['ajax']) {
			if (!headers_sent()) {
				header('Content-Type: application/json; charset=utf-8');
			}

			echo json_encode($ret);
			return;
		}

		$this->_tpl->assign('msg', $ret['msg']);
		$this->_tpl->assign('jump_url', '?c=certdeploy&a=log&id=' . $id);
		return $this->_tpl->fetch('msg.html');
	}

	/**
	 * 删除任务
	 */
	public function del()
	{
		$id = intval($_REQUEST['id']);
		$task = daocall('certtask', 'get', array($id));
		$ok = apicall('certtask', 'deleteTask', array($id));
		audit('certdeploy', 'del', $task ? ($task['vhost'] . '@' . $task['node']) : 'task#' . $id, $ok ? 'success' : 'error', array('task_id' => $id));
		$this->_tpl->assign('msg', $ok ? '任务已删除。' : '删除失败。');
		$this->_tpl->assign('jump_url', '?c=certdeploy&a=pageList');
		return $this->_tpl->fetch('msg.html');
	}

	/**
	 * 重装计划任务（容器 recreate 后 /etc/cron.d 丢失时的一键恢复）
	 */
	public function installCron()
	{
		$ret = apicall('certtask', 'install_cron', array());
		audit('certdeploy', 'installCron', 'ep_certtask', $ret['success'] ? 'success' : 'error', array('msg' => $ret['msg']));
		$this->_tpl->assign('msg', $ret['success'] ? ('计划任务已安装：' . $ret['msg']) : ('安装失败：' . $ret['msg']));
		$this->_tpl->assign('jump_url', '?c=certdeploy&a=pageList');
		return $this->_tpl->fetch('msg.html');
	}

	/**
	 * 手动跑一轮（等价 cron 一次触发，验收「立即同步」入口）
	 */
	public function runNow()
	{
		$line = apicall('certtask', 'runOnce', array());
		audit('certdeploy', 'runNow', '-', 'success', array('summary' => strval($line)));

		if ($_REQUEST['ajax']) {
			if (!headers_sent()) {
				header('Content-Type: application/json; charset=utf-8');
			}

			echo json_encode(array('success' => true, 'summary' => strval($line)));
			return;
		}

		$this->_tpl->assign('msg', '已执行一轮：' . nl2br(htmlspecialchars(strval($line), ENT_QUOTES, 'UTF-8')));
		$this->_tpl->assign('jump_url', '?c=certdeploy&a=pageList');
		return $this->_tpl->fetch('msg.html');
	}
}

?>
