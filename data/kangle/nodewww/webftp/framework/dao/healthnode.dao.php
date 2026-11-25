<?php
/**
 * healthnode.dao.php —— 源站/辅节点健康探测数据层（单元 origin-node-health-probe）
 *
 * 两张表，都落在面板业务库 SQLite（/vhs/kangle/etc/vhs.db，与 vhost/manynode 同库）：
 *   health_node   探测目标清单（tcp/http/ping 三种），status 1=up 0=down
 *   health_event  状态翻转事件流水（down/up 各一条，含原因与摘除标记）
 *
 * 与 audit.dao.php 同一策略（AD-5）：运行时 CREATE TABLE IF NOT EXISTS 兜底，
 * 保证 upgrade.sql 片段尚未重放时 cron 也不至于炸库；建表失败只影响探测本身。
 * DDL 的正式出处仍是 admin/control/upgrade.sql（集成步追加），两者语句一致。
 */

class HealthnodeDAO extends DAO
{
	private $_EVENT_TABLE = null;

	public function __construct()
	{
		parent::__construct();

		// 逻辑名 => 列名，恒等映射（AllQueryFields 的历史写法依赖恒等，同 audit.dao）
		$this->MAP_ARR = array(
			'name'       => 'name',
			'type'       => 'type',
			'host'       => 'host',
			'port'       => 'port',
			'url'        => 'url',
			'expect'     => 'expect',
			'node'       => 'node',
			'cycle'      => 'cycle',
			'timeout'    => 'timeout',
			'enabled'    => 'enabled',
			'status'     => 'status',
			'errcount'   => 'errcount',
			'latency'    => 'latency',
			'lasterr'    => 'lasterr',
			'lastcheck'  => 'lastcheck',
			'lastswitch' => 'lastswitch',
		);

		$this->MAP_TYPE = array(
			'port'       => FIELD_TYPE_INT,
			'cycle'      => FIELD_TYPE_INT,
			'timeout'    => FIELD_TYPE_INT,
			'enabled'    => FIELD_TYPE_INT,
			'status'     => FIELD_TYPE_INT,
			'errcount'   => FIELD_TYPE_INT,
			'latency'    => FIELD_TYPE_INT,
			'lastcheck'  => FIELD_TYPE_INT,
			'lastswitch' => FIELD_TYPE_INT,
		);

		$this->_TABLE = (defined('DBPRE') ? DBPRE : '') . 'health_node';
		$this->_EVENT_TABLE = (defined('DBPRE') ? DBPRE : '') . 'health_event';
	}

	/**
	 * 确保两张表与索引存在（幂等，每请求最多一次）
	 *
	 * @return bool
	 */
	public function ensureTable()
	{
		static $done = null;

		if ($done !== null) {
			return $done;
		}

		$done = false;
		$t = $this->_TABLE;
		$e = $this->_EVENT_TABLE;

		$sqls = array(
			'CREATE TABLE IF NOT EXISTS ' . $t . ' (' .
				'name TEXT NOT NULL PRIMARY KEY,' .
				'type TEXT NOT NULL DEFAULT \'tcp\',' .
				'host TEXT,' .
				'port INTEGER NOT NULL DEFAULT 80,' .
				'url TEXT,' .
				'expect TEXT,' .
				'node TEXT,' .
				'cycle INTEGER NOT NULL DEFAULT 1,' .
				'timeout INTEGER NOT NULL DEFAULT 3,' .
				'enabled INTEGER NOT NULL DEFAULT 1,' .
				'status INTEGER NOT NULL DEFAULT 1,' .
				'errcount INTEGER NOT NULL DEFAULT 0,' .
				'latency INTEGER NOT NULL DEFAULT 0,' .
				'lasterr TEXT,' .
				'lastcheck INTEGER NOT NULL DEFAULT 0,' .
				'lastswitch INTEGER NOT NULL DEFAULT 0' .
				')',
			'CREATE TABLE IF NOT EXISTS ' . $e . ' (' .
				'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
				'node TEXT NOT NULL,' .
				'action TEXT NOT NULL,' .
				'reason TEXT,' .
				'detail TEXT,' .
				'ejected INTEGER NOT NULL DEFAULT 0,' .
				'ts INTEGER NOT NULL' .
				')',
			'CREATE INDEX IF NOT EXISTS idx_health_event_ts ON ' . $e . '(ts)',
			'CREATE INDEX IF NOT EXISTS idx_health_event_node ON ' . $e . '(node)',
		);

		foreach ($sqls as $sql) {
			try {
				// PDO 默认静默错误模式：成功 exec() 返回 0，失败 db_query 返回 false，
				// 因此只能用 === false 判失败（同 audit.dao.php:92 的注释）。
				if ($this->executex($sql) === false) {
					return $done;
				}
			}
			catch (Exception $ex) {
				return $done;
			}
		}

		$done = true;
		return $done;
	}

	/**
	 * 新增/覆盖一个探测目标（name 为主键，REPLACE 语义同 manynode.dao）
	 *
	 * @param array $row 键名见 MAP_ARR
	 * @return bool
	 */
	public function add($row)
	{
		if (!is_array($row) || !isset($row['name']) || $row['name'] === '') {
			return false;
		}

		if (!$this->ensureTable()) {
			return false;
		}

		return $this->insert($row, 'REPLACE') !== false;
	}

	/**
	 * 按局部字段更新（只允许已知列）
	 */
	public function save($name, $arr)
	{
		if (!$this->ensureTable()) {
			return false;
		}

		if ($name === '' || $name === null || !is_array($arr) || empty($arr)) {
			return false;
		}

		return $this->update($arr, $this->getFieldValue2('name', $name)) !== false;
	}

	public function get($name = null)
	{
		if (!$this->ensureTable()) {
			return false;
		}

		if ($name === null || $name === '') {
			return $this->select(null, '', 'rows', 'name ASC');
		}

		return $this->select(null, $this->getFieldValue2('name', $name), 'row');
	}

	/**
	 * 启用的探测目标（cron 轮询入口）
	 */
	public function listEnabled()
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$rows = $this->select(null, 'enabled=1', 'rows', 'name ASC');
		return is_array($rows) ? $rows : array();
	}

	/**
	 * 全部探测目标（管理页）
	 */
	public function listAll()
	{
		$rows = $this->get();
		return is_array($rows) ? $rows : array();
	}

	public function del($name)
	{
		if (!$this->ensureTable() || $name === '' || $name === null) {
			return false;
		}

		return $this->delData($this->getFieldValue2('name', $name)) !== false;
	}

	/**
	 * teardown 用：清空全部探测目标
	 */
	public function delAll()
	{
		if (!$this->ensureTable()) {
			return false;
		}

		return $this->delData('') !== false;
	}

	/* ---------- health_event ---------- */

	/**
	 * 写一条状态翻转事件。返回插入是否成功（事件是验收硬判据，失败要能被发现）
	 *
	 * @param array $row node/action/reason/detail/ejected/ts
	 * @return bool
	 */
	public function addEvent($row)
	{
		if (!$this->ensureTable() || !is_array($row)) {
			return false;
		}

		$node = isset($row['node']) ? strval($row['node']) : '';
		$action = isset($row['action']) ? strval($row['action']) : '';

		if ($node === '' || ($action !== 'down' && $action !== 'up')) {
			return false;
		}

		$reason = isset($row['reason']) ? strval($row['reason']) : '';
		$detail = isset($row['detail']) ? strval($row['detail']) : '';
		$ejected = isset($row['ejected']) ? intval($row['ejected']) : 0;
		$ts = isset($row['ts']) ? intval($row['ts']) : time();

		$sql = 'INSERT INTO ' . $this->_EVENT_TABLE .
			' (`node`,`action`,`reason`,`detail`,`ejected`,`ts`) VALUES (' .
			'\'' . $this->daddslashes($node) . '\',' .
			'\'' . $this->daddslashes($action) . '\',' .
			'\'' . $this->daddslashes($reason) . '\',' .
			'\'' . $this->daddslashes($detail) . '\',' .
			$ejected . ',' . $ts . ')';

		try {
			return $this->executex($sql) !== false;
		}
		catch (Exception $ex) {
			return false;
		}
	}

	/**
	 * 最近事件（倒序）
	 */
	public function listEvents($limit = 30)
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$limit = intval($limit);

		if ($limit <= 0 || $limit > 500) {
			$limit = 30;
		}

		try {
			$rows = $this->executex('SELECT * FROM ' . $this->_EVENT_TABLE . ' ORDER BY id DESC LIMIT 0,' . $limit, 'rows');
		}
		catch (Exception $ex) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	public function countEvents()
	{
		if (!$this->ensureTable()) {
			return 0;
		}

		try {
			$row = $this->executex('SELECT COUNT(*) AS c FROM ' . $this->_EVENT_TABLE, 'row');
		}
		catch (Exception $ex) {
			return 0;
		}

		return is_array($row) && isset($row['c']) ? intval($row['c']) : 0;
	}

	/**
	 * 事件裁剪（防止一年前的抖动把表撑大）：保留最近 $keep 条
	 */
	public function pruneEvents($keep = 5000)
	{
		if (!$this->ensureTable()) {
			return 0;
		}

		$keep = intval($keep);

		if ($keep <= 0) {
			return 0;
		}

		try {
			$row = $this->executex('SELECT MAX(id) AS max_id FROM ' . $this->_EVENT_TABLE, 'row');

			if (!is_array($row) || !isset($row['max_id'])) {
				return 0;
			}

			$max_id = intval($row['max_id']);

			if ($max_id <= $keep) {
				return 0;
			}

			$ret = $this->executex('DELETE FROM ' . $this->_EVENT_TABLE . ' WHERE id <= ' . ($max_id - $keep));
		}
		catch (Exception $ex) {
			return 0;
		}

		return $ret === false ? 0 : intval($ret);
	}
}

?>
