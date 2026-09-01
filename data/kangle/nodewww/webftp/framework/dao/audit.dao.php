<?php
/**
 * audit.dao.php —— 审计日志数据访问层（FM-23 / HARD-07 的载体）
 *
 * 设计要点（AD-5）：
 *   1. 审计表 `ep_audit_log` 落在面板业务库 SQLite（/vhs/kangle/etc/vhs.db），
 *      该库文件已随容器 bind mount 持久化，不引入任何新的存储依赖。
 *   2. 表结构用运行时 `CREATE TABLE IF NOT EXISTS` 建立，**不改动 kangle.sql、
 *      不改动 EASYPANEL_VERSION、不新增 upgrade_sql 分支** —— 目的是不触碰
 *      安装/升级链路，把回归面压到最小。建表失败只影响审计，不影响业务。
 *   3. 所有方法都必须「失败可容忍」：审计是旁路设施，绝不能反向阻断业务。
 */

class AuditDAO extends DAO
{
	public function __construct()
	{
		parent::__construct();

		// MAP_ARR 用「逻辑名 => 列名」的恒等映射：
		// 父类 AllQueryFields() 的实现依赖恒等映射（它用列值反查键），
		// 保持恒等可避免踩到父类那个历史写法。
		$this->MAP_ARR = array(
			'id'     => 'id',
			'ts'     => 'ts',
			'actor'  => 'actor',
			'role'   => 'role',
			'module' => 'module',
			'action' => 'action',
			'target' => 'target',
			'result' => 'result',
			'detail' => 'detail',
			'ip'     => 'ip',
			'ua'     => 'ua',
		);

		// id 自增（插入时跳过）；ts 为整型时间戳
		$this->MAP_TYPE = array(
			'id' => FIELD_TYPE_AUTO | FIELD_TYPE_INT,
			'ts' => FIELD_TYPE_INT,
		);

		// DBPRE 在项目里由 framework/lib/db.lib.php 定义（= ''），
		// 但该文件是 DAO 构造时才载入的，此处用 defined() 兜底更稳妥。
		$this->_TABLE = (defined('DBPRE') ? DBPRE : '') . 'ep_audit_log';

		// _DBFILE 沿用父类默认值 'vhs'（/vhs/kangle/etc/vhs.db），不额外指定
	}

	/**
	 * 确保审计表与索引存在（幂等）
	 *
	 * 用静态标记保证每个请求最多执行一次 DDL：审计写操作可能在一次请求内
	 * 发生很多次（例如 CSRF OBSERVE + 业务审计），没必要反复执行建表语句。
	 *
	 * @return bool 表可用返回 true；建表失败返回 false（调用方应静默降级）
	 */
	public function ensureTable()
	{
		static $done = null;

		if ($done !== null) {
			return $done;
		}

		$done = false;
		$t = $this->_TABLE;

		$sqls = array(
			'CREATE TABLE IF NOT EXISTS ' . $t . ' (' .
				'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
				'ts INTEGER NOT NULL,' .
				'actor TEXT NOT NULL,' .
				'role TEXT NOT NULL,' .
				'module TEXT NOT NULL,' .
				'action TEXT NOT NULL,' .
				'target TEXT,' .
				'result TEXT NOT NULL,' .
				'detail TEXT,' .
				'ip TEXT,' .
				'ua TEXT' .
				')',
			'CREATE INDEX IF NOT EXISTS idx_audit_ts ON ' . $t . '(ts)',
			'CREATE INDEX IF NOT EXISTS idx_audit_actor ON ' . $t . '(actor)',
			'CREATE INDEX IF NOT EXISTS idx_audit_mod ON ' . $t . '(module, action)',
		);

		foreach ($sqls as $sql) {
			try {
				// PDO 在本项目中为默认静默错误模式：DDL 成功时 exec() 返回 0，
				// 失败时 db_query() 返回 false。故只能用 === false 判定失败。
				if ($this->executex($sql) === false) {
					return $done;
				}
			}
			catch (Exception $e) {
				return $done;
			}
		}

		$done = true;
		return $done;
	}

	/**
	 * 写入一条审计
	 *
	 * @param array $row 字段数组（键名见 MAP_ARR），ts 缺省时取当前时间
	 * @return bool
	 */
	public function add($row)
	{
		if (!is_array($row)) {
			return false;
		}

		if (!$this->ensureTable()) {
			return false;
		}

		if (!isset($row['ts']) || $row['ts'] === '') {
			$row['ts'] = time();
		}

		$row = $this->sanitizeRow($row);

		try {
			$ret = $this->insert($row, 'INSERT');
		}
		catch (Exception $e) {
			return false;
		}

		// INSERT 成功时 exec() 返回受影响行数（1）；失败时 db_query 返回 false
		return $ret === false ? false : true;
	}

	/**
	 * 按条件查询审计
	 *
	 * @param string $where WHERE 子句（不含 WHERE 关键字），由 AuditApi 组装
	 * @param string $order ORDER BY 子句，缺省按 id 倒序
	 * @param int    $limit 返回条数上限
	 * @return array 记录数组；失败返回空数组
	 */
	public function query($where = '', $order = '', $limit = 50)
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$sql = 'SELECT * FROM ' . $this->_TABLE;

		if ($where != '') {
			$sql .= ' WHERE ' . $where;
		}

		$sql .= ' ORDER BY ' . ($order != '' ? $order : 'id DESC');

		$limit = intval($limit);

		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}

		try {
			$rows = $this->executex($sql, 'rows');
		}
		catch (Exception $e) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	/**
	 * 分页查询
	 *
	 * @param string $where  WHERE 子句
	 * @param int    $offset 偏移量
	 * @param int    $size   每页条数
	 * @return array
	 */
	public function pageList($where = '', $offset = 0, $size = 50)
	{
		if (!$this->ensureTable()) {
			return array();
		}

		$sql = 'SELECT * FROM ' . $this->_TABLE;

		if ($where != '') {
			$sql .= ' WHERE ' . $where;
		}

		$sql .= ' ORDER BY id DESC LIMIT ' . intval($offset) . ',' . intval($size);

		try {
			$rows = $this->executex($sql, 'rows');
		}
		catch (Exception $e) {
			return array();
		}

		return is_array($rows) ? $rows : array();
	}

	/**
	 * 统计符合条件的记录数
	 *
	 * @param string $where WHERE 子句
	 * @return int 失败返回 0
	 */
	public function countAll($where = '')
	{
		if (!$this->ensureTable()) {
			return 0;
		}

		$sql = 'SELECT COUNT(*) AS count FROM ' . $this->_TABLE;

		if ($where != '') {
			$sql .= ' WHERE ' . $where;
		}

		try {
			$row = $this->executex($sql, 'row');
		}
		catch (Exception $e) {
			return 0;
		}

		return is_array($row) && isset($row['count']) ? intval($row['count']) : 0;
	}

	/**
	 * 按 id 裁剪，保留最近 $keep 条
	 *
	 * 由 AuditApi::write() 以 1% 概率抽样触发：每次写入都做一次 DELETE 太昂贵，
	 * 而审计表的写入量可能很大，抽样触发在统计意义上同样能控制表规模。
	 *
	 * @param int $keep 保留条数
	 * @return int 实际删除条数
	 */
	public function prune($keep = 50000)
	{
		if (!$this->ensureTable()) {
			return 0;
		}

		$keep = intval($keep);

		if ($keep <= 0) {
			return 0;
		}

		try {
			$row = $this->executex('SELECT MAX(id) AS max_id FROM ' . $this->_TABLE, 'row');
		}
		catch (Exception $e) {
			return 0;
		}

		if (!is_array($row) || !isset($row['max_id'])) {
			return 0;
		}

		$max_id = intval($row['max_id']);

		if ($max_id <= $keep) {
			return 0;
		}

		// id 是自增值，用「删除 <= 阈值」比 LIMIT 更可控，且不需要排序扫描
		$sql = 'DELETE FROM ' . $this->_TABLE . ' WHERE id <= ' . ($max_id - $keep);

		try {
			$ret = $this->executex($sql);
		}
		catch (Exception $e) {
			return 0;
		}

		return $ret === false ? 0 : intval($ret);
	}

	/**
	 * 入库前规范化：截断超长字段、剔除非标量、去掉 NUL 字节
	 *
	 * 为什么需要：detail 是调用方自由传入的 JSON，ip/ua 来自客户端可控输入。
	 * 不设上限会让异常调用把审计表撑爆，进而影响同一个 SQLite 文件里的业务表。
	 *
	 * @param array $row
	 * @return array
	 */
	private function sanitizeRow($row)
	{
		$limits = array(
			'actor'  => 128,
			'role'   => 32,
			'module' => 64,
			'action' => 64,
			'target' => 512,
			'result' => 32,
			'detail' => 4000,
			'ip'     => 64,
			'ua'     => 512,
		);

		$out = array();

		foreach ($this->MAP_ARR as $field => $column) {
			if (!array_key_exists($field, $row)) {
				continue;
			}

			$value = $row[$field];

			if (is_bool($value)) {
				$value = $value ? '1' : '0';
			}

			if (!is_scalar($value)) {
				continue;
			}

			$value = str_replace("\0", '', strval($value));

			if (isset($limits[$field]) && strlen($value) > $limits[$field]) {
				$value = substr($value, 0, $limits[$field]);
			}

			$out[$field] = $value;
		}

		return $out;
	}
}

?>
