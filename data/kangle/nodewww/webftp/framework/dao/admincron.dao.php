<?php
/**
 * 系统（管理员级）计划任务 DAO —— admin_cron 表
 *
 * 单元：admin-system-cron（计划任务管理 + cron.d 重建通道）。
 *
 * 表结构归属说明：
 *   正式 DDL 以片段形式登记在 .temp/integration/admin-system-cron.md，由集成步追加到
 *   admin/control/upgrade.sql（首装/升级两条路径都会重放，见 session.ctl.php 的
 *   install_sql + upgrade_sql）。本文件的 ensureTable() 只是**幂等兜底**：
 *   容器启动链里的 framework/cronresync.php 不经过 web 升级流程，它先于任何
 *   「登录后触发 upgrade.sql」的时刻运行，必须有办法让表立即可用。
 *   CREATE TABLE IF NOT EXISTS / PRAGMA 检查都不会破坏 upgrade.sql 的重放
 *   （重放时语句报错由 install.api.php executeSql 的 @$pdo->exec 静默吸收）。
 *
 * 风格对齐：cron.dao.php / filter.dao.php（DAO 基类 MAP_ARR/MAP_TYPE 约定，
 * sqlite 驱动，vhs.db）。
 */
class AdmincronDAO extends DAO
{
	public function __construct()
	{
		parent::__construct();
		$this->MAP_ARR = array('id' => 'id', 'name' => 'name', 'type' => 'type', 'cmd' => 'cmd', 'cron' => 'cron', 'flock' => 'flock', 'enabled' => 'enabled', 'last_run' => 'last_run', 'last_status' => 'last_status');
		$this->MAP_TYPE = array('id' => FIELD_TYPE_AUTO, 'flock' => FIELD_TYPE_INT, 'enabled' => FIELD_TYPE_INT, 'last_run' => FIELD_TYPE_INT, 'last_status' => FIELD_TYPE_INT);
		$this->_TABLE = 'admin_cron';
	}

	/**
	 * 幂等确保表结构可用（cronresync 启动链专用兜底）
	 *
	 * ① CREATE TABLE IF NOT EXISTS：与 upgrade.sql 片段的 DDL 逐字段一致；
	 * ② PRAGMA table_info 检查 flock 列：兼容早期手工建过表但缺 flock 列的库。
	 */
	public function ensureTable()
	{
		$sql = 'CREATE TABLE IF NOT EXISTS admin_cron ('
			. 'id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
			. 'name TEXT NULL,'
			. 'type TEXT NULL,'
			. 'cmd TEXT NULL,'
			. 'cron TEXT NULL,'
			. 'flock INTEGER NOT NULL DEFAULT 1,'
			. 'enabled INTEGER NOT NULL DEFAULT 1,'
			. 'last_run INTEGER NOT NULL DEFAULT 0,'
			. 'last_status INTEGER NOT NULL DEFAULT 0)';

		if ($this->executex($sql) === false) {
			return false;
		}

		$cols = $this->executex('PRAGMA table_info(admin_cron)', 'rows');

		if (is_array($cols)) {
			foreach ($cols as $col) {
				if (isset($col['name']) && $col['name'] === 'flock') {
					return true;
				}
			}
		}

		return $this->executex('ALTER TABLE admin_cron ADD COLUMN flock INTEGER NOT NULL DEFAULT 1');
	}

	/**
	 * 全部任务（按 id 升序，界面列表与 cron.d 重建共用）
	 */
	public function getList()
	{
		return $this->select(null, '', 'rows', 'id');
	}

	public function get($id)
	{
		return $this->select(null, $this->getFieldValue2('id', intval($id)), 'row');
	}

	public function add($data)
	{
		return $this->insert($data);
	}

	public function updateById($id, $data)
	{
		return $this->update($data, $this->getFieldValue2('id', intval($id)));
	}

	public function delById($id)
	{
		return $this->delData($this->getFieldValue2('id', intval($id)));
	}
}

?>
