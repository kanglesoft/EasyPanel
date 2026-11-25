<?php
/**
 * notifychannel.dao.php —— 通知渠道数据访问层（功能项 11）
 *
 * 设计要点：
 *   1. 表 `notify_channel` 落在面板业务库 SQLite（/vhs/kangle/etc/vhs.db），与
 *      audit / vhost_webapp 同库，不引入新的存储依赖。
 *   2. 每个渠道的具体参数统一存进 `config` 字段（JSON），避免每加一种渠道就
 *      ALTER 一次表。类型字段 type 取值：smtp | telegram | webhook。
 *   3. DDL 双保险：upgrade.sql 增量脚本建表（集成步追加），本类同时提供
 *      ensureTable() 运行时兜底（照抄 audit.dao.php 的成熟做法），使「升级脚本
 *      尚未重放」的中间态也能打开列表页而不报 no such table。
 *   4. 本 DAO 只做数据存取，绝不发起网络请求 —— 发送逻辑全部在 notify.api.php。
 */
class NotifychannelDAO extends DAO
{
	public function __construct()
	{
		parent::__construct();
		$this->MAP_ARR = array(
			'id'          => 'id',
			'name'        => 'name',
			'type'        => 'type',
			'enabled'     => 'enabled',
			'config'      => 'config',
			'last_status' => 'last_status',
			'last_time'   => 'last_time',
			'last_error'  => 'last_error',
			'remark'      => 'remark',
			'createtime'  => 'createtime',
		);
		// 每一列都要在这里出现：framework/dao.php 的 insert()/update()/getFieldValue()
		// 直接取 $this->MAP_TYPE[$key]（dao.php:236/206/389），缺键时 PHP 7 会抛
		// Undefined index（生产 error_reporting 屏蔽了它，但 E_ALL 下是真实噪声，
		// 而且一旦哪天 error_reporting 调整就会变成脏日志）。文本列显式写
		// FIELD_TYPE_STRING(0)，落到 getFieldValue() 的默认分支按引号字符串处理。
		$this->MAP_TYPE = array(
			'id'          => FIELD_TYPE_AUTO | FIELD_TYPE_INT,
			'name'        => FIELD_TYPE_STRING,
			'type'        => FIELD_TYPE_STRING,
			'enabled'     => FIELD_TYPE_INT,
			'config'      => FIELD_TYPE_STRING,
			'last_status' => FIELD_TYPE_INT,
			'last_time'   => FIELD_TYPE_INT,
			'last_error'  => FIELD_TYPE_STRING,
			'remark'      => FIELD_TYPE_STRING,
			'createtime'  => FIELD_TYPE_INT,
		);
		$this->_TABLE = DBPRE . 'notify_channel';
	}

	/**
	 * 运行时确保表与索引存在（幂等，每请求最多一次 DDL）
	 *
	 * @return bool 可用返回 true；建表失败返回 false（调用方降级为错误提示）
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
				'name TEXT NOT NULL,' .
				'type TEXT NOT NULL,' .
				'enabled INTEGER NOT NULL DEFAULT 1,' .
				'config TEXT NULL,' .
				'last_status INTEGER NOT NULL DEFAULT 0,' .
				'last_time INTEGER NOT NULL DEFAULT 0,' .
				'last_error TEXT NULL,' .
				'remark TEXT NULL,' .
				'createtime INTEGER NOT NULL DEFAULT 0' .
				')',
			'CREATE UNIQUE INDEX IF NOT EXISTS idx_notify_channel_name ON ' . $t . '(name)',
			'CREATE INDEX IF NOT EXISTS idx_notify_channel_enabled ON ' . $t . '(enabled,type)',
		);

		foreach ($sqls as $sql) {
			try {
				// PDO 在本项目为静默错误模式：DDL 成功时 exec() 返回 0，
				// 失败时 db_query() 返回 false，故只能用 === false 判失败。
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
	 * 新增渠道（id 由 AUTOINCREMENT 生成，不入 INSERT 列）
	 *
	 * @param array $arr 逻辑字段数组（键名见 MAP_ARR）
	 * @return int|false 成功返回新行 id，失败返回 false
	 */
	public function add($arr)
	{
		if (!isset($arr['createtime']) || intval($arr['createtime']) <= 0) {
			$arr['createtime'] = time();
		}

		if ($this->insert($arr, 'INSERT') === false) {
			return false;
		}

		try {
			return intval($this->getPdo()->lastInsertId());
		}
		catch (Exception $e) {
			return true;
		}
	}

	/**
	 * 按 id 更新（config 整包覆盖）
	 */
	public function updateChannel($id, $arr)
	{
		return $this->update($arr, $this->getFieldValue2('id', intval($id)));
	}

	/**
	 * 删除渠道
	 */
	public function del($id)
	{
		return $this->delData($this->getFieldValue2('id', intval($id)));
	}

	/**
	 * 取渠道
	 *
	 * @param int|null $id 为空取全部
	 * @return array|false
	 */
	public function get($id = null)
	{
		if ($id === null || $id === '') {
			return $this->select(null, '', 'rows', 'id ASC');
		}

		return $this->select(null, $this->getFieldValue2('id', intval($id)), 'row');
	}

	public function getByName($name)
	{
		return $this->select(null, $this->getFieldValue2('name', $name), 'row');
	}

	/**
	 * 取启用中的渠道，可按类型过滤
	 *
	 * @param string|null $type smtp|telegram|webhook
	 */
	public function getEnabled($type = null)
	{
		$where = $this->getFieldValue2('enabled', 1);

		if ($type) {
			$where .= ' AND ' . $this->getFieldValue2('type', $type);
		}

		return $this->select(null, $where, 'rows', 'id ASC');
	}

	/**
	 * 记录一次发送结果（成功/失败 + 时间 + 错误摘要）
	 *
	 * @param int    $id
	 * @param int    $status 1=成功 2=失败
	 * @param string $error  失败原因（成功时传空串清空旧错误）
	 */
	public function markResult($id, $status, $error = '')
	{
		$arr = array(
			'last_status' => intval($status),
			'last_time'   => time(),
			'last_error'  => $this->cutUtf8(strval($error), 480),
		);

		return $this->update($arr, $this->getFieldValue2('id', intval($id)));
	}

	/**
	 * 按 UTF-8 边界截断
	 *
	 * 错误文案大量含中文，直接 substr() 有概率把多字节字符切成半个 —— 半截
	 * UTF-8 存进库后，testSend 的 json_encode() 会整体返回 false（非法 UTF-8），
	 * 表现是「渠道明明发送成功，接口却回空响应」，排查成本极高，所以在入库
	 * 这最后一道关口就把边界守住。
	 *
	 * 实现只用字节算术，不依赖 mbstring/iconv（面板侧 PHP 是 vendored Remi 7.4，
	 * 实测有 mbstring，但 DAO 层不应把正确性押在扩展可用性上）。
	 *
	 * @param string $s
	 * @param int    $max 最大字节数
	 * @return string
	 */
	private function cutUtf8($s, $max)
	{
		if ($max <= 0 || strlen($s) <= $max) {
			return $s;
		}

		$cut = substr($s, 0, $max);

		// 统计结尾的续字节（10xxxxxx，即 0x80..0xBF）个数 k
		$k = 0;

		for ($i = $max - 1; $i >= 0 && $k < 4; $i--) {
			$o = ord($cut[$i]);

			if ($o >= 0x80 && $o <= 0xBF) {
				++$k;
				continue;
			}

			break;
		}

		// 结尾是纯 ASCII：没有残缺，直接用
		if ($k === 0) {
			return $cut;
		}

		// 取最后一个序列的首字节，按首字节推它本该有几个字节
		$leadPos = $max - $k - 1;

		if ($leadPos < 0) {
			return '';
		}

		$o = ord($cut[$leadPos]);

		if ($o >= 0xF0) {
			$want = 4;
		}
		elseif ($o >= 0xE0) {
			$want = 3;
		}
		elseif ($o >= 0xC0) {
			$want = 2;
		}
		else {
			// 非法首字节（裸续字节 / ASCII 后接乱码）：保守砍掉这段尾巴
			return substr($cut, 0, $leadPos);
		}

		// 实际只有 $k + 1 个字节 < 应有长度 => 被切了一半，整枚字符丢掉
		if ($k + 1 < $want) {
			return substr($cut, 0, $leadPos);
		}

		return $cut;
	}

	/**
	 * 只翻转启用标记
	 */
	public function setEnabled($id, $enabled)
	{
		return $this->update(array('enabled' => intval($enabled) ? 1 : 0), $this->getFieldValue2('id', intval($id)));
	}

	public function getCount()
	{
		$sql = 'SELECT count(*) AS c FROM ' . $this->_TABLE;
		$result = $this->executex($sql, 'row');

		if (!$result) {
			return 0;
		}

		return intval($result['c']);
	}

	public function pageList($page, $page_count, &$count)
	{
		return $this->selectPage(null, null, 'id', false, $page, $page_count, $count);
	}
}

?>
