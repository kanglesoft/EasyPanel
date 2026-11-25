<?php
/**
 * certtask.dao.php —— 证书分发任务数据访问层
 *
 * 表：cert_task（任务表，vhost+node 一行，UNIQUE 保幂等）与
 *     cert_task_log（执行日志表，按 task_id 关联）。
 * 库：面板业务库 SQLite（$GLOBALS['safe_dir'].'vhs.db'，_DBFILE 沿用父类 'vhs'）。
 *
 * 表结构正式来源是 admin/control/upgrade.sql（集成步追加，见
 * .temp/integration/cert-renewal-cdn-distribution.md）；这里保留 ensureTable()
 * 运行时 CREATE TABLE IF NOT EXISTS 兜底（与 audit.dao.php 同一模式），
 * 保证「升级 SQL 尚未重放」的窗口内引擎与界面也不至于致命。
 */
class CerttaskDAO extends DAO
{
	private $log_table;

	public function __construct()
	{
		parent::__construct();
		$pre = defined('DBPRE') ? DBPRE : '';
		$this->MAP_ARR = array(
			'id'       => 'id',
			'vhost'    => 'vhost',
			'node'     => 'node',
			'type'     => 'type',
			'status'   => 'status',
			'retry'    => 'retry',
			'next_run' => 'next_run',
			'error'    => 'error',
			'ctime'    => 'ctime',
			'mtime'    => 'mtime',
		);
		$this->MAP_TYPE = array(
			'id'       => FIELD_TYPE_AUTO | FIELD_TYPE_INT,
			'retry'    => FIELD_TYPE_INT,
			'next_run' => FIELD_TYPE_INT,
			'ctime'    => FIELD_TYPE_INT,
			'mtime'    => FIELD_TYPE_INT,
		);
		$this->_TABLE = $pre . 'cert_task';
		$this->log_table = $pre . 'cert_task_log';
	}

	/**
	 * 兜底建表（幂等，每请求最多执行一次）
	 *
	 * @return bool 两张表都可用返回 true
	 */
	public function ensureTable()
	{
		static $done = null;

		if ($done !== null) {
			return $done;
		}

		$done = false;
		$t = $this->_TABLE;
		$lt = $this->log_table;

		$sqls = array(
			'CREATE TABLE IF NOT EXISTS ' . $t . ' (' .
				'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
				'vhost TEXT NOT NULL,' .
				'node TEXT NOT NULL,' .
				'type TEXT NOT NULL DEFAULT \'cert\',' .
				'status TEXT NOT NULL DEFAULT \'pending\',' .
				'retry INTEGER NOT NULL DEFAULT 0,' .
				'next_run INTEGER NOT NULL DEFAULT 0,' .
				'error TEXT,' .
				'ctime INTEGER NOT NULL DEFAULT 0,' .
				'mtime INTEGER NOT NULL DEFAULT 0' .
				')',
			'CREATE UNIQUE INDEX IF NOT EXISTS IDX_CERTTASK_VHOST_NODE ON ' . $t . '(vhost,node)',
			'CREATE INDEX IF NOT EXISTS IDX_CERTTASK_STATUS_RUN ON ' . $t . '(status,next_run)',
			'CREATE TABLE IF NOT EXISTS ' . $lt . ' (' .
				'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
				'task_id INTEGER NOT NULL,' .
				'ts INTEGER NOT NULL,' .
				'level TEXT NOT NULL DEFAULT \'info\',' .
				'msg TEXT' .
				')',
			'CREATE INDEX IF NOT EXISTS IDX_CERTTASKLOG_TASK ON ' . $lt . '(task_id,ts)',
		);

		foreach ($sqls as $sql) {
			try {
				// PDO 默认静默错误模式：DDL 成功返回 0，失败时 db_query 返回 false，
				// 所以必须用 === false 判定（与 audit.dao.php 同口径）。
				if ($this->executex($sql) === false) {
					return $done;
				}
			}
			catch (Exception $e) {
				return $done;
			}
			catch (Throwable $e) {
				return $done;
			}
		}

		$done = true;
		return $done;
	}

	/**
	 * 按 id 取任务
	 */
	public function get($id)
	{
		return $this->select(null, $this->getFieldValue2('id', intval($id)), 'row');
	}

	/**
	 * 按 vhost+node 取任务（UNIQUE 保证最多一行）
	 */
	public function findByVhostNode($vhost, $node)
	{
		$where = $this->getFieldValue2('vhost', $vhost) . ' AND ' . $this->getFieldValue2('node', $node);
		return $this->select(null, $where, 'row');
	}

	/**
	 * 新建任务行
	 *
	 * @param array $arr 键取 MAP_ARR 子集
	 * @return int|false 成功返回新行 id；失败 false
	 */
	public function insertTask($arr)
	{
		if (!$this->ensureTable()) {
			return false;
		}

		if ($this->insert($arr, 'INSERT') === false) {
			return false;
		}

		// 框架没有 lastInsertId 封装，取本 (vhost,node) 行 id 回读，UNIQUE 索引下安全
		$row = $this->findByVhostNode($arr['vhost'], $arr['node']);
		return $row ? intval($row['id']) : false;
	}

	/**
	 * 更新任务行
	 */
	public function updateTask($id, $arr)
	{
		$id = intval($id);

		if ($id <= 0 || empty($arr)) {
			return false;
		}

		return $this->update($arr, $this->getFieldValue2('id', $id));
	}

	/**
	 * 领取任务：pending/failed → running 的原子抢锁
	 *
	 * 用「带状态条件的 UPDATE + 影响行数」做锁，避免 Web 端「立即执行」与
	 * cron 同时处理同一行。
	 *
	 * @return bool 抢到返回 true
	 */
	public function claimTask($id)
	{
		$sql = 'UPDATE ' . $this->_TABLE . ' SET `status`=\'running\',`mtime`=' . time() .
			' WHERE `id`=' . intval($id) . ' AND `status` IN (\'pending\',\'failed\')';

		try {
			$ret = $this->executex($sql);
		}
		catch (Throwable $e) {
			return false;
		}

		return $ret === 1;
	}

	/**
	 * 到期任务列表
	 *
	 * @param int $now   当前时间戳
	 * @param int $limit 上限
	 */
	public function getDue($now, $limit = 10)
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$sql = 'SELECT * FROM ' . $this->_TABLE .
			' WHERE `status` IN (\'pending\',\'failed\') AND `next_run`<=' . intval($now) .
			' ORDER BY `next_run` ASC LIMIT ' . max(1, intval($limit));

		try {
			$rows = $this->executex($sql, 'rows');
		}
		catch (Throwable $e) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	/**
	 * 回收卡死的 running（处理进程中途崩溃的残行）
	 *
	 * @param int $before 时间戳阈值，mtime 早于此的 running 视为卡死
	 * @return int 回收行数
	 */
	public function reclaimStuckRunning($before)
	{
		$sql = 'UPDATE ' . $this->_TABLE .
			' SET `status`=\'failed\',`mtime`=' . time() .
			' WHERE `status`=\'running\' AND `mtime`<' . intval($before);

		try {
			$ret = $this->executex($sql);
		}
		catch (Throwable $e) {
			return 0;
		}

		return $ret === false ? 0 : intval($ret);
	}

	/**
	 * 管理界面分页列表
	 *
	 * @param string $status 状态过滤，'' 为全部
	 */
	public function pageList($status, $page, $page_count, &$count)
	{
		if (!$this->ensureTable()) {
			$count = 0;
			return array();
		}

		$where = '';

		if ($status !== '' && preg_match('/^[a-z]+$/', $status)) {
			$where = '`status`=\'' . $status . '\'';
		}

		$rows = $this->selectPage(null, $where, 'id', true, intval($page), intval($page_count), $count);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * 各状态计数（列表页汇总徽标）
	 *
	 * @return array array('pending'=>n, 'failed'=>n, ...)
	 */
	public function countByStatus()
	{
		$out = array('pending' => 0, 'running' => 0, 'failed' => 0, 'success' => 0, 'abandoned' => 0);

		if (!$this->ensureTable()) {
			return $out;
		}

		try {
			$rows = $this->executex('SELECT `status`,COUNT(*) AS c FROM ' . $this->_TABLE . ' GROUP BY `status`', 'rows');
		}
		catch (Throwable $e) {
			return $out;
		}

		if (is_array($rows)) {
			foreach ($rows as $r) {
				if (isset($out[$r['status']])) {
					$out[$r['status']] = intval($r['c']);
				}
			}
		}

		return $out;
	}

	/**
	 * 删除任务及其日志
	 */
	public function delTask($id)
	{
		$id = intval($id);

		if ($id <= 0) {
			return false;
		}

		$ret1 = $this->delData($this->getFieldValue2('id', $id));
		$ret2 = $this->delTaskLogs($id);

		return ($ret1 !== false && $ret2 !== false);
	}

	/* ---------- 执行日志 ---------- */

	/**
	 * 写一条任务执行日志
	 *
	 * @param int    $task_id
	 * @param string $level   info|success|error
	 * @param string $msg     日志内容（超长截断、剔除 NUL 与换行，保证单行可读）
	 */
	public function addLog($task_id, $level, $msg)
	{
		if (!$this->ensureTable()) {
			return false;
		}

		$task_id = intval($task_id);

		if ($task_id <= 0) {
			return false;
		}

		if (!in_array($level, array('info', 'success', 'error'), true)) {
			$level = 'info';
		}

		$msg = str_replace(array("\r", "\n", "\0"), array(' ', ' ', ''), strval($msg));

		if (strlen($msg) > 4000) {
			$msg = substr($msg, 0, 4000);
		}

		$sql = 'INSERT INTO ' . $this->log_table . ' (task_id,ts,level,msg) VALUES (' .
			$task_id . ',' . time() . ',\'' . $level . '\',\'' . $this->daddslashes($msg) . '\')';

		try {
			$ret = $this->executex($sql);
		}
		catch (Throwable $e) {
			return false;
		}

		return $ret !== false;
	}

	/**
	 * 某任务的日志（时间倒序，默认最近 200 条）
	 */
	public function getLogs($task_id, $limit = 200)
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$sql = 'SELECT * FROM ' . $this->log_table . ' WHERE task_id=' . intval($task_id) .
			' ORDER BY id DESC LIMIT ' . max(1, intval($limit));

		try {
			$rows = $this->executex($sql, 'rows');
		}
		catch (Throwable $e) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	/**
	 * 某任务日志分页（日志页 UI 用）
	 */
	public function pageLogs($task_id, $page, $page_count, &$count)
	{
		$task_id = intval($task_id);
		$count = 0;

		if (!$this->ensureTable() || $task_id <= 0) {
			return array();
		}

		$row = $this->executex('SELECT COUNT(*) AS c FROM ' . $this->log_table . ' WHERE task_id=' . $task_id, 'row');
		$count = is_array($row) ? intval($row['c']) : 0;

		$page = max(1, intval($page));
		$page_count = max(1, intval($page_count));

		$sql = 'SELECT * FROM ' . $this->log_table . ' WHERE task_id=' . $task_id .
			' ORDER BY id DESC LIMIT ' . (($page - 1) * $page_count) . ',' . $page_count;

		try {
			$rows = $this->executex($sql, 'rows');
		}
		catch (Throwable $e) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	public function delTaskLogs($task_id)
	{
		$sql = 'DELETE FROM ' . $this->log_table . ' WHERE task_id=' . intval($task_id);

		try {
			return $this->executex($sql);
		}
		catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * 裁剪日志，保留最近 $keep 条
	 */
	public function pruneLogs($keep = 20000)
	{
		$keep = intval($keep);

		if ($keep <= 0 || !$this->ensureTable()) {
			return 0;
		}

		try {
			$row = $this->executex('SELECT MAX(id) AS max_id FROM ' . $this->log_table, 'row');
		}
		catch (Throwable $e) {
			return 0;
		}

		if (!is_array($row) || !isset($row['max_id'])) {
			return 0;
		}

		$max_id = intval($row['max_id']);

		if ($max_id <= $keep) {
			return 0;
		}

		try {
			$ret = $this->executex('DELETE FROM ' . $this->log_table . ' WHERE id<=' . ($max_id - $keep));
		}
		catch (Throwable $e) {
			return 0;
		}

		return $ret === false ? 0 : intval($ret);
	}
}

?>
