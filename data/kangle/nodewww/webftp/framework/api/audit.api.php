<?php
/**
 * audit.api.php —— 审计日志模块（FM-23 / HARD-07 的载体）
 *
 * 定位：全项目唯一的审计写入口。供 CSRF 守卫、文件管理、登录、宿主级操作
 * （host / docker）等共用，后续任务的审计调用一律走 apicall('audit','write',...)
 * 或更简洁的全局函数 audit()。
 *
 * 铁律（5.5）：**审计写入必须失败静默**。审计是旁路设施，绝不允许因为审计
 * 写失败而阻断业务。因此本类每个方法都做了异常兜底，并在 runtime.php 的
 * audit() 包装里再兜一层。
 */

class AuditApi extends API
{
	/**
	 * 写入一条审计（唯一写入口）
	 *
	 * 自动补全 ts / actor / role / ip / ua；detail 数组会被 json_encode。
	 *
	 * @param string $module 模块：host|docker|filemanager|session|ssl|cdn|csrf|vhost|acmessl|manynode
	 * @param string $action 操作名，与 csrf_policy() 的 a 保持一致
	 * @param string $target 操作对象：路径 / 容器 ID / 域名 / 账号名；无对象时传 '-'
	 * @param string $result success|deny|error|observe
	 * @param array  $detail 附加信息，建议含 layer / reason / count / before / after
	 * @return bool 写入成功返回 true；任何失败返回 false（绝不抛异常、绝不 exit）
	 */
	public function write($module, $action, $target, $result, $detail = array())
	{
		// 模块/动作/结果是审计的检索维度，缺失就没有记录意义，直接放弃
		if (!is_scalar($module) || strval($module) === '') {
			return false;
		}

		try {
			$role = 'guest';
			$actor = $this->currentActor($role);

			$row = array(
				'ts'     => time(),
				'actor'  => $actor,
				'role'   => $role,
				'module' => strval($module),
				'action' => is_scalar($action) ? strval($action) : '',
				'target' => is_scalar($target) ? strval($target) : '-',
				'result' => is_scalar($result) ? strval($result) : 'error',
				'detail' => $this->encodeDetail($detail),
				'ip'     => isset($_SERVER['REMOTE_ADDR']) ? strval($_SERVER['REMOTE_ADDR']) : '',
				'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? strval($_SERVER['HTTP_USER_AGENT']) : '',
			);

			$ret = daocall('audit', 'add', array($row));

			// 1% 概率抽样做一次裁剪：避免每次写入都执行 DELETE。
			// 抽样触发在统计意义上同样能把表规模压在 5 万条量级。
			if ($ret && mt_rand(1, 100) === 1) {
				$this->prune(50000);
			}

			return $ret ? true : false;
		}
		catch (Exception $e) {
			return false;
		}
		catch (Throwable $e) {
			// PHP 7 的 Error（如 PDO 抛出的 Error）不属于 Exception，
			// 单独兜一层，确保「审计绝不阻断业务」这条铁律成立。
			return false;
		}
	}

	/**
	 * 查询审计（本轮只留接口，不做 UI；下一轮审计页面直接复用）
	 *
	 * @param array $filter 过滤条件，支持 module / action / result / actor / role /
	 *                      ts_from / ts_to / keyword（keyword 对 target 与 detail 做 LIKE）
	 * @param int   $page   页码，从 1 开始
	 * @param int   $size   每页条数，上限 500
	 * @return array array('success'=>bool, 'list'=>array, 'total'=>int)
	 */
	public function query($filter = array(), $page = 1, $size = 50)
	{
		$ret = array('success' => false, 'list' => array(), 'total' => 0);

		try {
			if (!is_array($filter)) {
				$filter = array();
			}

			$where = $this->buildWhere($filter);
			$total = daocall('audit', 'countAll', array($where));

			$page = intval($page);

			if ($page < 1) {
				$page = 1;
			}

			$size = intval($size);

			if ($size < 1) {
				$size = 50;
			}

			if ($size > 500) {
				$size = 500;
			}

			$list = daocall('audit', 'pageList', array($where, ($page - 1) * $size, $size));

			$ret['success'] = true;
			$ret['total'] = intval($total);
			$ret['list'] = is_array($list) ? $list : array();
			return $ret;
		}
		catch (Exception $e) {
			return $ret;
		}
		catch (Throwable $e) {
			return $ret;
		}
	}

	/**
	 * 裁剪审计表，保留最近 $keep 条
	 *
	 * @param int $keep 保留条数
	 * @return int 实际删除条数
	 */
	public function prune($keep = 50000)
	{
		try {
			$ret = daocall('audit', 'prune', array($keep));
			return $ret === false ? 0 : intval($ret);
		}
		catch (Exception $e) {
			return 0;
		}
		catch (Throwable $e) {
			return 0;
		}
	}

	/**
	 * 推断操作者身份（2.3.4 actor / role 推断规则）
	 *
	 * 直接读 $_SESSION 而不走 getRole()：getRole() 在键不存在时会触发
	 * undefined index 通知，这里用 isset 先判，保证在「未登录」这种
	 * 高频场景下也不会刷出大量通知。
	 *
	 * @param string &$role 出参：admin|vhost|system|guest
	 * @return string actor 字符串
	 */
	private function currentActor(&$role)
	{
		global $_SESSION;

		$role = 'guest';
		$actor = 'anonymous';

		$roles = (isset($_SESSION['janbao_role']) && is_array($_SESSION['janbao_role']))
			? $_SESSION['janbao_role']
			: array();

		if (isset($roles['admin']) && $roles['admin'] !== '') {
			$role = 'admin';
			$actor = 'admin:' . $roles['admin'];
			return $actor;
		}

		// 空间用户侧：仅当入口定义了 VHOST_PATH 时才认定（A7）
		if (defined('VHOST_PATH') && isset($roles['vhost']) && $roles['vhost'] !== '') {
			$role = 'vhost';
			$actor = 'vhost:' . $roles['vhost'];
			return $actor;
		}

		if (PHP_SAPI === 'cli') {
			$role = 'system';
			$script = isset($_SERVER['argv'][0]) ? basename(strval($_SERVER['argv'][0])) : 'cli';
			$actor = 'system:' . $script;
			return $actor;
		}

		return $actor;
	}

	/**
	 * 把 detail 数组序列化为 JSON，并剔除凭据类字段
	 *
	 * 为什么要剔除：审计日志本身会成为新的敏感数据集中地。若某个调用方
	 * 顺手把 $_REQUEST 或一个含 passwd 的数组塞进 detail，就等于把明文口令
	 * 落到了另一个表里。这里做一道统一的兜底过滤，键名命中口令/令牌语义的
	 * 一律替换为 '***'，无论调用方是否自觉。
	 *
	 * @param mixed $detail
	 * @return string
	 */
	private function encodeDetail($detail)
	{
		if ($detail === null || $detail === '') {
			return '';
		}

		if (is_scalar($detail)) {
			return strval($detail);
		}

		if (!is_array($detail)) {
			return '';
		}

		$detail = $this->scrubCredentials($detail);
		$json = json_encode($detail);

		// json_encode 可能因非法 UTF-8 失败，退化为空串而不是让整条审计写入失败
		return $json === false ? '' : $json;
	}

	/**
	 * 递归剔除数组中的凭据类字段
	 *
	 * @param array $arr
	 * @return array
	 */
	private function scrubCredentials($arr)
	{
		$out = array();

		foreach ($arr as $key => $value) {
			if (is_array($value)) {
				$out[$key] = $this->scrubCredentials($value);
				continue;
			}

			if (is_scalar($value) && preg_match('/(pass|passwd|password|secret|token|private_key|apikey|api_key)/i', strval($key))) {
				$out[$key] = '***';
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}

	/**
	 * 组装 WHERE 子句
	 *
	 * 只接受白名单字段，且字符串值统一用父类风格的转义（单引号翻倍），
	 * 避免调用方传进来的过滤条件变成注入点。
	 *
	 * @param array $filter
	 * @return string WHERE 子句（不含 WHERE 关键字），无条件时返回 '1=1'
	 */
	private function buildWhere($filter)
	{
		$parts = array();

		$exact = array('module', 'action', 'result', 'actor', 'role');

		foreach ($exact as $field) {
			if (isset($filter[$field]) && is_scalar($filter[$field]) && strval($filter[$field]) !== '') {
				$parts[] = '`' . $field . '` = ' . $this->quote(strval($filter[$field]));
			}
		}

		if (isset($filter['ts_from']) && is_scalar($filter['ts_from'])) {
			$parts[] = '`ts` >= ' . intval($filter['ts_from']);
		}

		if (isset($filter['ts_to']) && is_scalar($filter['ts_to'])) {
			$parts[] = '`ts` <= ' . intval($filter['ts_to']);
		}

		if (isset($filter['keyword']) && is_scalar($filter['keyword']) && strval($filter['keyword']) !== '') {
			$kw = $this->quote('%' . strval($filter['keyword']) . '%');
			$parts[] = '(`target` LIKE ' . $kw . ' OR `detail` LIKE ' . $kw . ')';
		}

		return empty($parts) ? '1=1' : implode(' AND ', $parts);
	}

	/**
	 * SQLite 字符串字面量转义
	 *
	 * 与父类 DAO::daddslashes() 口径一致（单引号翻倍），这里独立实现一份是因为
	 * 组装发生在 API 层，拿不到 DAO 的 protected 方法。
	 *
	 * @param string $value
	 * @return string 带引号的字面量
	 */
	private function quote($value)
	{
		return '\'' . str_replace('\'', '\'\'', str_replace("\0", '', $value)) . '\'';
	}
}

?>
