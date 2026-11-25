<?php
/**
 * acmethrottle.api.php —— ACME 签发失败节流（防 CA 速率限制封禁）
 *
 * 背景：Let's Encrypt 对「同域名的失败订单」有 小时级速率限制
 * （invalid-authorization 类：每域名每小时最多若干次；账户级封禁更严）。
 * 面板用户在证书申请页反复点「申请」会把配额烧光，连带整台服务器
 * 对 LE 停签。dnsmgr 的做法（.temp/60-actionable-features.md 第 8 条）
 * 是「每轮限 3 失败订单」：同一域名连续失败达到阈值后，在冷却窗口内
 * 拒绝再次发起，直到窗口过期或管理员手动解除。
 *
 * 语义（与验收口径一致）：
 *   - 同一域名在 WINDOW（1h）内累计失败 FAIL_LIMIT（3）次 → 第 4 次
 *     apply 直接被 guard() 拒绝，返回结构化 throttle 信息（JSON），
 *     并且 **不再向 CA 发起任何请求、不新增 DNS 记录**；
 *   - 失败计数以 last_fail 为锚：距上次失败超过 WINDOW 视为新窗口，
 *     fail_count 归 1 重新计；
 *   - 第 3 次失败时 blocked_until = last_fail + COOLDOWN（1h 滚动），
 *     冷却自然到期后自动放行（fail_count 同时清零）；
 *   - 任何一次成功签发 → recordSuccess() 删除 guard 行（完全重置）。
 *
 * 存储：vhs.db 表 acme_order_guard（每域名一行），DDL 见
 * .temp/integration/acme-dns01-wildcard.md，同时本类运行时幂等建表
 * （风格对齐 audit.dao.php：升级脚本未跑也不阻断功能）。
 * 所有方法失败可容忍：节流是保护设施，数据库异常时宁可放行也不可
 * 把签发功能整个打死（放行 + trigger_error 留痕）。
 */
class AcmethrottleApi extends API
{
	/** 连续失败达到该值即封禁 */
	const FAIL_LIMIT = 3;

	/** 失败计数窗口（秒）：超过窗口无新失败则重新计数 */
	const WINDOW = 3600;

	/** 封禁冷却时长（秒）：自最后一次失败起算 */
	const COOLDOWN = 3600;

	private function pdo()
	{
		static $pdo = null;
		if ($pdo !== null) {
			return $pdo;
		}
		$file = rtrim($GLOBALS['safe_dir'], '/') . '/vhs.db';
		try {
			// 同 dnsapi：busy 等待压到 5s，避免快速连点时顶穿 kangle 60s CGI 超时
			$pdo = new PDO('sqlite:' . $file, null, null, array(PDO::ATTR_TIMEOUT => 5));
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch (Exception $e) {
			$pdo = null;
			trigger_error('acmethrottle: 无法打开 vhs.db: ' . $e->getMessage(), E_USER_WARNING);
		}
		return $pdo;
	}

	public function ensureTable()
	{
		static $done = false;
		if ($done) {
			return true;
		}
		$pdo = $this->pdo();
		if (!$pdo) {
			return false;
		}
		try {
			$pdo->exec('CREATE TABLE IF NOT EXISTS [acme_order_guard] (
				[domain] TEXT NOT NULL PRIMARY KEY,
				[fail_count] INTEGER NOT NULL DEFAULT 0,
				[last_error] TEXT NULL,
				[provider] TEXT NULL,
				[ca] TEXT NULL,
				[first_fail] INTEGER NOT NULL DEFAULT 0,
				[last_fail] INTEGER NOT NULL DEFAULT 0,
				[blocked_until] INTEGER NOT NULL DEFAULT 0
			)');
			$done = true;
			return true;
		}
		catch (Exception $e) {
			trigger_error('acmethrottle: 建表失败: ' . $e->getMessage(), E_USER_WARNING);
			return false;
		}
	}

	/** 归一化域名（与 dnsapi 一致：小写、去 *./去尾点） */
	private function norm($domain)
	{
		$d = strtolower(trim((string) $domain, " \t\n\r\0\x0B."));
		return preg_replace('/^\*\./', '', $d);
	}

	/**
	 * 读取并解释某域名的 guard 行（含惰性过期：blocked/fail 超窗自动清）
	 * @return array(fail_count, blocked, wait, last_error, last_fail, row_exists)
	 */
	public function status($domain)
	{
		$out = array('fail_count' => 0, 'blocked' => false, 'wait' => 0, 'last_error' => '', 'last_fail' => 0, 'row_exists' => false);
		$domain = $this->norm($domain);
		if ($domain === '' || !$this->ensureTable()) {
			return $out;
		}
		$pdo = $this->pdo();
		$st = $pdo->prepare('SELECT * FROM acme_order_guard WHERE domain=?');
		$st->execute(array($domain));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$st->closeCursor();
		unset($st);
		if (!$row) {
			return $out;
		}
		$now = time();
		$fail_count = intval($row['fail_count']);
		$last_fail = intval($row['last_fail']);
		// 失败窗口过期：整行清理，视为从未失败
		if ($now - $last_fail > self::WINDOW && $now - $last_fail > self::COOLDOWN) {
			$pdo->prepare('DELETE FROM acme_order_guard WHERE domain=?')->execute(array($domain));
			return $out;
		}
		$out['row_exists'] = true;
		$out['fail_count'] = $fail_count;
		$out['last_error'] = (string) $row['last_error'];
		$out['last_fail'] = $last_fail;
		$out['provider'] = (string) $row['provider'];
		$out['ca'] = (string) $row['ca'];
		$blocked_until = intval($row['blocked_until']);
		if ($fail_count >= self::FAIL_LIMIT && $blocked_until > $now) {
			$out['blocked'] = true;
			$out['wait'] = $blocked_until - $now;
		} elseif ($blocked_until > 0 && $blocked_until <= $now) {
			// 冷却已过：清零放行（保留一行空记录没必要，直接删）
			$pdo->prepare('DELETE FROM acme_order_guard WHERE domain=?')->execute(array($domain));
			$out['row_exists'] = false;
			$out['fail_count'] = 0;
		}
		return $out;
	}

	/**
	 * 签发前闸门。放行返回 true；拦截返回结构化数组（控制器据此输出
	 * throttle JSON，验收点：第 4 次 apply 返回 code=throttle）。
	 * @return bool|array
	 */
	public function guard($domain)
	{
		$s = $this->status($domain);
		if (empty($s['blocked'])) {
			return true;
		}
		return array(
			'code'       => 'throttle',
			'domain'     => $this->norm($domain),
			'fail_count' => intval($s['fail_count']),
			'limit'      => self::FAIL_LIMIT,
			'wait'       => intval($s['wait']),
			'last_error' => mb_substr((string) $s['last_error'], 0, 300),
			'msg'        => '该域名连续失败 ' . intval($s['fail_count']) . ' 次，已触发防 CA 速率限制节流，请 ' . intval($s['wait']) . ' 秒后再试（或在「DNS 凭据」页手动解除）。',
		);
	}

	/**
	 * 记录一次签发失败。$err 截断存 last_error；达到阈值时置
	 * blocked_until = now + COOLDOWN。
	 * @return bool
	 */
	public function recordFail($domain, $err = '', $provider = '', $ca = '')
	{
		$domain = $this->norm($domain);
		if ($domain === '' || !$this->ensureTable()) {
			return false;
		}
		$pdo = $this->pdo();
		$now = time();
		$err = mb_substr(preg_replace('/\s+/u', ' ', strval($err)), 0, 500);
		$st = $pdo->prepare('SELECT fail_count, last_fail, first_fail, blocked_until FROM acme_order_guard WHERE domain=?');
		$st->execute(array($domain));
		$row = $st->fetch(PDO::FETCH_ASSOC);
		$st->closeCursor();
		unset($st); // 立刻释放只读游标，避免与本连接后续写事务互踩
		if ($row && ($now - intval($row['last_fail'])) <= self::WINDOW) {
			$fail = intval($row['fail_count']) + 1;
			$first = intval($row['first_fail']) > 0 ? intval($row['first_fail']) : $now;
			$blocked = $fail >= self::FAIL_LIMIT ? $now + self::COOLDOWN : intval($row['blocked_until']);
			$up = $pdo->prepare('UPDATE acme_order_guard SET fail_count=?, last_error=?, provider=?, ca=?, first_fail=?, last_fail=?, blocked_until=? WHERE domain=?');
			$ok = $up->execute(array($fail, $err, $provider, $ca, $first, $now, $blocked, $domain));
		}
		else {
			// 无行或窗口已过：重开一轮
			$blocked = self::FAIL_LIMIT <= 1 ? $now + self::COOLDOWN : 0;
			$ins = $pdo->prepare('INSERT OR REPLACE INTO acme_order_guard
				(domain, fail_count, last_error, provider, ca, first_fail, last_fail, blocked_until)
				VALUES (?,?,?,?,?,?,?,?)');
			$ok = $ins->execute(array($domain, 1, $err, $provider, $ca, $now, $now, $blocked));
		}
		if ($ok) {
			$s = $this->status($domain);
			audit('acmessl', 'applyThrottleFail', $domain, 'error', array(
				'fail_count' => intval($s['fail_count']),
				'blocked'    => !empty($s['blocked']),
				'provider'   => $provider,
				'ca'         => $ca,
			));
		}
		return $ok ? true : false;
	}

	/** 签发成功：清空该域名 guard 行 */
	public function recordSuccess($domain)
	{
		$domain = $this->norm($domain);
		if ($domain === '' || !$this->ensureTable()) {
			return false;
		}
		$st = $this->pdo()->prepare('DELETE FROM acme_order_guard WHERE domain=?');
		return $st->execute(array($domain));
	}

	/** 手动解除节流（界面/验收 teardown 用）。$domain 空 = 清空全部 */
	public function clear($domain = '')
	{
		if (!$this->ensureTable()) {
			return array('code' => 1, 'msg' => '数据库不可用');
		}
		$pdo = $this->pdo();
		$domain = $this->norm($domain);
		if ($domain === '') {
			$pdo->exec('DELETE FROM acme_order_guard');
			audit('acmessl', 'throttleClearAll', '-', 'success', array());
			return array('code' => 0, 'msg' => '已清空全部节流记录');
		}
		$st = $pdo->prepare('DELETE FROM acme_order_guard WHERE domain=?');
		$st->execute(array($domain));
		audit('acmessl', 'throttleClear', $domain, 'success', array());
		return array('code' => 0, 'msg' => '已解除 ' . $domain . ' 的节流');
	}

	/** 当前处于封禁状态的域名列表（界面展示） */
	public function listBlocked()
	{
		$out = array();
		if (!$this->ensureTable()) {
			return $out;
		}
		$now = time();
		$st = $this->pdo()->prepare('SELECT * FROM acme_order_guard WHERE fail_count>=? AND blocked_until>? ORDER BY blocked_until DESC');
		$st->execute(array(self::FAIL_LIMIT, $now));
		foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = array(
				'domain'     => $row['domain'],
				'fail_count' => intval($row['fail_count']),
				'wait'       => intval($row['blocked_until']) - $now,
				'last_error' => mb_substr((string) $row['last_error'], 0, 160),
				'provider'   => (string) $row['provider'],
				'ca'         => (string) $row['ca'],
			);
		}
		return $out;
	}

	public function failLimit()
	{
		return self::FAIL_LIMIT;
	}
}
