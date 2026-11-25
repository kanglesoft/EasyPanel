<?php
/**
 * certtask.api.php —— 证书续签 → CDN 辅节点分发任务引擎
 *
 * 背景（.temp/60-actionable-features.md 第 9 项）：当前续期只 apply 本机 vhost，
 * 辅节点证书靠 notice_cdn_changed 被动触发、失败无感知。本模块补齐
 * 「任务表 + 事件串联 + 指数退避重试 + 执行日志」闭环：
 *
 *   1. 触发源（两条，互为兜底）：
 *      a) notice_cdn_changed() 钩子（runtime.php 集成片段）调用
 *         onCertOrCdnChanged()：面板内改证书/改站点时即时建任务；
 *      b) 每分钟 cron runOnce() 里的 scanCertChanges()：按站点 ssl 文件
 *         mtime 检测续签（renew_ssl.sh / apply_all_acme_certs.php 等
 *         不走框架钩子的外部续签路径也能被捕获）。
 *   2. 推送复用辅节点既有接收端 api/?c=cdn&a=sync（CdnSlaveAPI::sync），
 *      载荷结构照抄 cdnPrimary::load_vhost_info/sync_node_vhost 的线上格式，
 *      但只装本 vhost 一条，做到「单站点→单节点」精准重试，
 *      不触碰 cdnPrimary.api.php（本单元无所有权）。
 *   3. 指数退避：失败后 next_run = now + backoff[retry]，
 *      60s→120s→300s→900s→1800s→3600s，共 6 次；超过置 abandoned
 *      （对照 dnsmgr CertDeployService.php:13 $retry_interval 与 retry>=6 门）。
 *   4. 每轮节流：单轮最多处理 8 个到期任务、连续失败 3 个即停，
 *      防止节点批量故障时打爆面板（借鉴 dnsmgr Certtask.php「每轮 3 败即停」）。
 *   5. cron 入口：install_cron() 生成 /etc/cron.d/ep_certtask 与
 *      $safe_dir/certtask_run.php 引导脚本（重建由 cronresync.php 调用，见
 *      .temp/integration/cert-renewal-cdn-distribution.md）。
 *
 * 风格对齐：daocall/apicall 短名调用、失败静默不抛（钩子挂热路径）、
 * 写库全部走 CerttaskDAO（表结构兜底 ensureTable，正式 DDL 在 upgrade.sql）。
 */
class CerttaskAPI extends API
{
	/** 指数退避秒数表，retry(1..6) 取 backoff[retry-1] */
	private static $backoff = array(60, 120, 300, 900, 1800, 3600);

	/** 最大重试次数，超过置 abandoned，仅手动重试可复活 */
	const MAX_RETRY = 6;

	/** 单轮 runOnce 最多处理的到期任务数 */
	const BATCH_LIMIT = 8;

	/** 单轮连续失败达到该数即停（防打爆故障节点） */
	const FAIL_STOP = 3;

	/** running 卡死回收阈值（秒）：进程中途崩溃的残行自动回 failed */
	const STUCK_SECONDS = 600;

	/** 推送目标载荷中要携带的 vhost 字段白名单。
	 *  与 cdnPrimary::$sync_vhost_fields 同口径 —— 刻意不含 passwd，
	 *  绝不把主节点凭据发给辅节点。 */
	private static $sync_vhost_fields = array('name', 'doc_root', 'uid', 'status', 'subdir', 'web_quota', 'log_file',
		'access', 'speed_limit', 'max_connect', 'cdn', 'sync_seq', 'ip', 'port', 'certificate', 'certificate_key', 'http2');

	private $setting = null;

	private function setting()
	{
		if ($this->setting === null) {
			$this->setting = daocall('setting', 'getAll', array());
		}

		return $this->setting;
	}

	/* =====================================================================
	 *  一、事件入口（notice_cdn_changed 钩子）
	 * ===================================================================== */

	/**
	 * 证书/站点变更钩子。由 runtime.php::notice_cdn_changed() 首行调用。
	 *
	 * 铁律：本函数在面板热路径（每次站点变更）执行，必须：
	 *   - 任何异常都不外抛（调用方另有 try/catch(Throwable) 兜底）；
	 *   - 无辅节点、无证书时 O(1) 返回，不产生任何写入。
	 *
	 * @param mixed $vh vhost 名或 vhost 行数组（沿用 notice_cdn_changed 入参）
	 * @return int 新建/重置的任务行数
	 */
	public function onCertOrCdnChanged($vh = null)
	{
		$name = $this->resolveVhostName($vh);

		if ($name === '') {
			return 0;
		}

		$vhost = daocall('vhost', 'getVhost', array($name));

		// 只给「配置了证书的主节点站点」建分发任务；
		// 辅节点镜像行（@ 前缀 / cdn=1）不分发，避免环回。
		if (!$vhost || !is_array($vhost) || $vhost['cdn'] || strncmp($vhost['name'], '@', 1) === 0) {
			return 0;
		}

		if (empty($vhost['certificate']) && empty($vhost['certificate_key'])) {
			return 0;
		}

		$nodes = daocall('manynode', 'get', array());

		if (!is_array($nodes) || count($nodes) <= 0) {
			return 0;
		}

		$count = 0;

		foreach ($nodes as $node) {
			if ($this->upsertTask($vhost['name'], $node['name'])) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * 入参归一：$vh 可能是名字字符串、行数组或 null（取会话角色）
	 */
	private function resolveVhostName($vh)
	{
		if (is_array($vh)) {
			return isset($vh['name']) ? trim(strval($vh['name'])) : '';
		}

		$vh = trim(strval($vh));

		if ($vh !== '') {
			return $vh;
		}

		if (function_exists('getRole')) {
			$role = getRole('vhost');
			return is_scalar($role) ? trim(strval($role)) : '';
		}

		return '';
	}

	/* =====================================================================
	 *  二、任务表操作
	 * ===================================================================== */

	/**
	 * 建/重置一个 (vhost,node) 分发任务。
	 *
	 * 事件语义 = 「有一份新证书要发」：已存在的 pending/failed 行重置回
	 * pending 并把退避清零重新计时（新事件优先级高于旧退避）；
	 * running 行不动（正在推送，完成后 mtime 对比会自愈）；
	 * success/abandoned 行复活重推。
	 *
	 * @param string $vhost vhost 名
	 * @param string $node  辅节点名
	 * @param bool   $force true = 跳过 55s 去抖（手动「立即重试」）
	 * @return int|false 任务 id
	 */
	public function upsertTask($vhost, $node, $force = false)
	{
		$vhost = trim(strval($vhost));
		$node = trim(strval($node));

		if ($vhost === '' || $node === '') {
			return false;
		}

		$now = time();
		$row = daocall('certtask', 'findByVhostNode', array($vhost, $node));

		if ($row) {
			// running 行不动：正在推送，完成后若证书又有更新会被
			// scanCertChanges 的 mtime 对比自动重开，不会丢事件。
			if ($row['status'] === 'running') {
				return intval($row['id']);
			}

			// 去抖：一次操作可能连环触发多次 notice（如批量改域名），
			// 55 秒内不重复重置，避免退避永远走不完。
			if (!$force && $row['status'] === 'pending' && intval($row['mtime']) > $now - 55) {
				return intval($row['id']);
			}

			$ok = daocall('certtask', 'updateTask', array(intval($row['id']), array(
				'type' => 'cert', 'status' => 'pending', 'retry' => 0,
				'next_run' => $now, 'error' => '', 'mtime' => $now,
			)));

			if ($ok !== false) {
				daocall('certtask', 'addLog', array(intval($row['id']), 'info', 'new change event, task reset to pending'));
				return intval($row['id']);
			}

			return false;
		}

		$id = daocall('certtask', 'insertTask', array(array(
			'vhost' => $vhost, 'node' => $node, 'type' => 'cert',
			'status' => 'pending', 'retry' => 0, 'next_run' => $now,
			'error' => '', 'ctime' => $now, 'mtime' => $now,
		)));

		if ($id) {
			daocall('certtask', 'addLog', array($id, 'info', 'task created for vhost=' . $vhost . ' node=' . $node));
		}

		return $id;
	}

	/**
	 * 管理界面手动新建任务（校验对象存在后复用 upsertTask）
	 *
	 * @return array array('success'=>bool,'msg'=>string,'id'=>int)
	 */
	public function createTask($vhost, $node)
	{
		$ret = array('success' => false, 'msg' => '', 'id' => 0);

		$vhost = trim(strval($vhost));
		$node = trim(strval($node));

		if ($vhost === '' || $node === '') {
			$ret['msg'] = '网站与节点均不能为空';
			return $ret;
		}

		$vh = daocall('vhost', 'getVhost', array($vhost));

		if (!$vh || !is_array($vh)) {
			$ret['msg'] = '虚拟主机不存在：' . $vhost;
			return $ret;
		}

		$nodeRow = daocall('manynode', 'get', array($node));

		if (!$nodeRow || !is_array($nodeRow)) {
			$ret['msg'] = '辅节点不存在：' . $node;
			return $ret;
		}

		$id = $this->upsertTask($vhost, $node, true);

		if (!$id) {
			$ret['msg'] = '任务写入失败，请检查数据库';
			return $ret;
		}

		$ret['success'] = true;
		$ret['id'] = intval($id);
		$ret['msg'] = '任务已创建，将在 1 分钟内由计划任务推送（或用「立即重试」马上执行）';
		return $ret;
	}

	/**
	 * 手动立即重试（跳过退避等待，不影响后续自动链）
	 *
	 * @return array array('success'=>bool,'msg'=>string)
	 */
	public function manualRetry($id)
	{
		$ret = array('success' => false, 'msg' => '');
		$id = intval($id);
		$task = daocall('certtask', 'get', array($id));

		if (!$task) {
			$ret['msg'] = '任务不存在';
			return $ret;
		}

		daocall('certtask', 'updateTask', array($id, array(
			'status' => 'pending', 'retry' => 0, 'next_run' => time(),
			'error' => '', 'mtime' => time(),
		)));
		daocall('certtask', 'addLog', array($id, 'info', 'manual retry requested by admin'));

		// 就地执行一次，管理员立刻看到结果
		$result = $this->processTask($id);
		$ret['success'] = $result['success'];
		$ret['msg'] = $result['success'] ? '推送成功' : ('推送失败：' . $result['error']);
		return $ret;
	}

	/**
	 * 删除任务及其日志
	 */
	public function deleteTask($id)
	{
		return daocall('certtask', 'delTask', array(intval($id)));
	}

	/* =====================================================================
	 *  三、cron 主循环
	 * ===================================================================== */

	/**
	 * 每分钟由 /etc/cron.d/ep_certtask 调一次。
	 *
	 * 顺序：先扫描证书 mtime 补建/重开任务（兜住不经过框架钩子的续签路径），
	 * 再领取执行到期任务。返回一行摘要供 CLI/cron 日志。
	 *
	 * @return string 摘要
	 */
	public function runOnce()
	{
		$now = time();
		$scanned = $this->scanCertChanges();
		daocall('certtask', 'reclaimStuckRunning', array($now - self::STUCK_SECONDS));

		$due = daocall('certtask', 'getDue', array($now, self::BATCH_LIMIT));

		if (!is_array($due)) {
			$due = array();
		}

		$ok = 0;
		$fail = 0;
		$consecutive = 0;
		$skipped = 0;

		foreach ($due as $task) {
			if ($consecutive >= self::FAIL_STOP) {
				$skipped = count($due) - $ok - $fail;
				break;
			}

			if (!daocall('certtask', 'claimTask', array(intval($task['id'])))) {
				continue; // 并发方已领取
			}

			$result = $this->finishTask($task);

			if ($result['success']) {
				++$ok;
				$consecutive = 0;
			}
			else {
				++$fail;
				++$consecutive;
			}
		}

		// 1% 抽样裁剪日志表（与 audit.api 同套路），避免每轮 DELETE
		if (mt_rand(1, 100) === 1) {
			daocall('certtask', 'pruneLogs', array(20000));
		}

		$line = date('Y-m-d H:i:s') . " certtask done scanned={$scanned} due=" . count($due) .
			" ok={$ok} fail={$fail}" . ($skipped ? " deferred={$skipped}" : '') . "\n";
		return $line;
	}

	/**
	 * 证书 mtime 扫描（续签事件的第二触发源）
	 *
	 * renew_ssl.sh → apply_all_acme_certs.php 直接改站点目录里的 ssl.crt/key，
	 * 不经过面板框架钩子；这里按「证书文件 mtime > 任务最近一次成功 mtime」
	 * 判定证书有更新，重开 success 行 / 为首见持证站点建任务。
	 *
	 * @return int 本次新建或重开的任务数
	 */
	private function scanCertChanges()
	{
		$nodes = daocall('manynode', 'get', array());

		if (!is_array($nodes) || count($nodes) <= 0) {
			return 0;
		}

		$vhosts = daocall('vhost', 'listVhostNotcdn', array());

		if (!is_array($vhosts)) {
			return 0;
		}

		$touched = 0;

		foreach ($vhosts as $vh) {
			if (empty($vh['certificate']) || empty($vh['certificate_key'])) {
				continue;
			}

			$certMtime = $this->certFileMtime($vh['doc_root'], $vh['certificate']);
			$keyMtime = $this->certFileMtime($vh['doc_root'], $vh['certificate_key']);
			$latest = max($certMtime, $keyMtime);

			if ($latest <= 0) {
				continue; // 证书文件不在，等钩子/手动路径处理
			}

			foreach ($nodes as $node) {
				$row = daocall('certtask', 'findByVhostNode', array($vh['name'], $node['name']));

				if (!$row) {
					// 首次发现持证站点：建任务把当前证书补发过去（幂等，重复推无害）
					if ($this->upsertTask($vh['name'], $node['name'])) {
						++$touched;
					}
					continue;
				}

				if (in_array($row['status'], array('success', 'abandoned'), true)
					&& $latest > intval($row['mtime'])) {
					if ($this->upsertTask($vh['name'], $node['name'])) {
						++$touched;
					}
				}
			}
		}

		return $touched;
	}

	/**
	 * 站点目录内证书文件的 mtime，取不到返回 0
	 */
	private function certFileMtime($docRoot, $file)
	{
		$file = trim(strval($file));

		if ($docRoot === '' || $file === '' || strpos($file, '..') !== false) {
			return 0;
		}

		$path = rtrim(strval($docRoot), '/') . '/' . ltrim($file, '/');
		clearstatcache(true, $path);
		$m = @filemtime($path);
		return $m === false ? 0 : intval($m);
	}

	/**
	 * 执行一个已领取（running）的任务并落状态
	 *
	 * @param array $task 任务行
	 * @return array array('success'=>bool,'error'=>string)
	 */
	private function finishTask($task)
	{
		$id = intval($task['id']);
		$result = $this->pushTask($task);
		$now = time();

		if ($result['success']) {
			daocall('certtask', 'updateTask', array($id, array(
				'status' => 'success', 'retry' => 0, 'error' => '',
				'next_run' => $now, 'mtime' => $now,
			)));
			daocall('certtask', 'addLog', array($id, 'success', '[Success] pushed vhost=' . $task['vhost'] . ' to node=' . $task['node']));
			audit('certdeploy', 'push', $task['vhost'] . '@' . $task['node'], 'success', array('task_id' => $id));
			return $result;
		}

		$errMsg = str_replace(array("\r", "\n"), ' ', substr($result['error'], 0, 300));
		$retry = intval($task['retry']) + 1;

		if ($retry >= self::MAX_RETRY) {
			daocall('certtask', 'updateTask', array($id, array(
				'status' => 'abandoned', 'retry' => $retry, 'error' => $errMsg,
				'next_run' => $now, 'mtime' => $now,
			)));
			daocall('certtask', 'addLog', array($id, 'error', '[Error] ' . $errMsg . ' | abandoned after ' . $retry . ' retries'));
			audit('certdeploy', 'push', $task['vhost'] . '@' . $task['node'], 'error', array('task_id' => $id, 'error' => $errMsg, 'abandoned' => 1));
		}
		else {
			$interval = self::backoffInterval($retry);
			daocall('certtask', 'updateTask', array($id, array(
				'status' => 'failed', 'retry' => $retry, 'error' => $errMsg,
				'next_run' => $now + $interval, 'mtime' => $now,
			)));
			daocall('certtask', 'addLog', array($id, 'error', '[Error] ' . $errMsg . ' | retry=' . $retry . ' next in ' . $interval . 's'));
		}

		return $result;
	}

	/**
	 * 领取后即推（manualRetry 复用 finishTask 的记账逻辑）
	 */
	private function processTask($id)
	{
		$task = daocall('certtask', 'get', array($id));

		if (!$task) {
			return array('success' => false, 'error' => '任务不存在');
		}

		if (!daocall('certtask', 'claimTask', array($id))) {
			return array('success' => false, 'error' => '任务正被其它进程处理，请稍后再看日志');
		}

		return $this->finishTask($task);
	}

	/**
	 * retry(1..) → 退避秒数
	 */
	private static function backoffInterval($retry)
	{
		$idx = intval($retry) - 1;
		$table = self::$backoff;

		if ($idx < 0) {
			$idx = 0;
		}

		if ($idx >= count($table)) {
			$idx = count($table) - 1;
		}

		return intval($table[$idx]);
	}

	/* =====================================================================
	 *  四、推送实现（复用辅节点 api/?c=cdn&a=sync 接收端）
	 * ===================================================================== */

	/**
	 * 把 vhost（含证书文件内容）推送到一个辅节点。
	 *
	 * @param array $task 任务行（vhost/node 字段）
	 * @return array array('success'=>bool,'error'=>string)
	 */
	private function pushTask($task)
	{
		$fail = function ($msg) {
			return array('success' => false, 'error' => $msg);
		};

		$node = daocall('manynode', 'get', array($task['node']));

		if (!$node || !is_array($node)) {
			return $fail('辅节点已从多节点设置中删除');
		}

		$vhost = daocall('vhost', 'getVhost', array($task['vhost']));

		if (!$vhost || !is_array($vhost)) {
			return $fail('虚拟主机不存在（可能已删除）：' . $task['vhost']);
		}

		if (empty($vhost['certificate']) || empty($vhost['certificate_key'])) {
			return $fail('该站点尚未配置证书：' . $task['vhost']);
		}

		$setting = $this->setting();
		$localName = isset($setting['local_cdn_name']) ? trim($setting['local_cdn_name']) : '';

		if ($localName === '') {
			return $fail('主节点名称(local_cdn_name)未设置，请先在多节点设置中配置');
		}

		$skey = trim(strval($node['skey']));

		if ($skey === '' && isset($setting['skey'])) {
			$skey = trim(strval($setting['skey']));
		}

		if ($skey === '') {
			return $fail('辅节点安全码为空，无法签名请求');
		}

		$payload = $this->buildSyncPayload($vhost);
		$port = intval($node['port']) ? intval($node['port']) : 3312;
		$whm = apicall('nodes', 'makeEpanelWhm', array($node['host'], $port, $skey));

		// makeEpanelWhm 内部已 load_lib('pub:whm')，这里显式再调一次（include_once 幂等），
		// 保证 new WhmCall 在任何调用路径下类都已定义
		load_lib('pub:whm');

		if (!$whm) {
			return $fail('makeEpanelWhm failed: host=' . $node['host'] . ':' . $node['port']);
		}

		$call = new WhmCall('api/', 'sync');
		$call->addParam('c', 'cdn');
		$call->addParam('nodename', $localName);
		$call->addParam('sync', json_encode(array($payload)));
		$call->addParam('del', json_encode(array()));
		$call->addParam('url', json_encode(array()));

		$result = $whm->callEpanel($call, 10);

		if (is_object($result) && $result->getCode() == 200) {
			return array('success' => true, 'error' => '');
		}

		// callWhm 失败时通过 setLastError 写明原因（如 can't connect to host）
		$detail = isset($GLOBALS['last_error']) ? strval($GLOBALS['last_error']) : '';
		$GLOBALS['last_error'] = '';
		return $fail('node ' . $node['name'] . '(' . $node['host'] . ':' . $port . ') sync failed ' . $detail);
	}

	/**
	 * 构造单站点的 sync 载荷。
	 *
	 * 结构必须与 CdnSlaveAPI::sync()/sync_vhost() 消费端严格对齐：
	 *   v        = 白名单后的 vhost 行（绝不带 passwd）
	 *   info     = 域名绑定列表（剔除动态型 type 2/3/4/5/7，同 cdnPrimary）
	 *   access / certificate / certificate_key = doc_root 下文件内容的 base64
	 *   certs    = server:// 型 info 引用的额外 .crt/.key 文件（泛域名/SNI 多证书）
	 * 载荷格式即线上协议（对照 cdnPrimary.api.php:313-380 / cdnSlave.api.php:41-135），
	 * 辅节点无需任何改动即可接收。
	 */
	private function buildSyncPayload($vhost)
	{
		$v = array();

		foreach (self::$sync_vhost_fields as $field) {
			$v[$field] = isset($vhost[$field]) ? $vhost[$field] : null;
		}

		$payload = array('v' => $v, 'info' => array(), 'certs' => array());
		$infos = daocall('vhostinfo', 'getAll', array($vhost['name']));
		$docRoot = rtrim(strval($vhost['doc_root']), '/');

		if (is_array($infos)) {
			foreach ($infos as $info) {
				// 与 cdnPrimary.api.php:324 一致：跳过数据库/ftp/动态型绑定
				if ($info['type'] == 2 || $info['type'] == 3 || $info['type'] == 4 || $info['type'] == 5 || $info['type'] == 7) {
					continue;
				}

				// server:// 且引用 crt/key 的绑定，把证书文件一并带上（cdnPrimary.api.php:327 同逻辑）
				if ($info['type'] == 0
					&& strncasecmp($info['value'], 'server://', 9) == 0
					&& strpos($info['value'], ';') !== false
					&& strpos($info['value'], '.crt') !== false
					&& strpos($info['value'], '.key') !== false) {
					$crtFile = $info['name'] . '.crt';
					$keyFile = $info['name'] . '.key';
					$crt = @file_get_contents($docRoot . '/' . $crtFile);

					if ($crt !== false) {
						$payload['certs'][$crtFile] = base64_encode($crt);
					}

					$key = @file_get_contents($docRoot . '/' . $keyFile);

					if ($key !== false) {
						$payload['certs'][$keyFile] = base64_encode($key);
					}
				}

				$payload['info'][] = $info;
			}
		}

		if (!empty($vhost['access'])) {
			$access = @file_get_contents($docRoot . '/' . $vhost['access']);

			if ($access !== false) {
				$payload['access'] = base64_encode($access);
			}
		}

		if (!empty($vhost['certificate'])) {
			$crt = @file_get_contents($docRoot . '/' . $vhost['certificate']);

			if ($crt !== false) {
				// 文件缺失时不阻断也不下发空值：辅节点保留旧证书，
				// 等下一次续签/重扫自愈（载荷严格保持 sync 协议字段）
				$payload['certificate'] = base64_encode($crt);
			}
		}

		if (!empty($vhost['certificate_key'])) {
			$key = @file_get_contents($docRoot . '/' . $vhost['certificate_key']);

			if ($key !== false) {
				$payload['certificate_key'] = base64_encode($key);
			}
		}

		return $payload;
	}

	/* =====================================================================
	 *  五、cron 安装（由 cronresync.php 在容器启动时重建）
	 * ===================================================================== */

	/**
	 * 写入 /etc/cron.d/ep_certtask 与引导脚本 $safe_dir/certtask_run.php。
	 *
	 * 幂等：内容固定，重复执行等价覆盖。cron 每 1 分钟一次 —— 退避基数
	 * 60s 要求分钟粒度（远端验收「两次采样隔 ≥60s」同源）。
	 *
	 * 远端基线：entrypoint.sh 把 cron.d 烘焙逻辑放在容器启动；镜像重建会
	 * 清空 /etc/cron.d，因此本函数必须可被 cronresync 反复重放。
	 *
	 * @return array array('success'=>bool,'msg'=>string)
	 */
	public function install_cron()
	{
		$ret = array('success' => false, 'msg' => '');

		if (is_win()) {
			$ret['msg'] = 'Windows 下不支持 ep_certtask 系统计划任务';
			return $ret;
		}

		$php = '';

		foreach (array('/usr/bin/php74', '/usr/bin/php') as $cand) {
			if (file_exists($cand)) {
				$php = $cand;
				break;
			}
		}

		if ($php === '') {
			$ret['msg'] = '未找到 php 可执行文件（/usr/bin/php74 或 /usr/bin/php）';
			return $ret;
		}

		$runner = $this->runnerFile();

		// 引导脚本放 etc（700，Web 不可达），不落在 webftp 目录内
		$code = "<?php\n"
			. "/* certtask_run.php —— 证书分发任务 cron 入口，由 CerttaskAPI::install_cron() 生成，请勿手改 */\n"
			. "if (PHP_SAPI !== 'cli') { exit('CLI only'); }\n"
			. "@set_time_limit(0);\n"
			. "date_default_timezone_set('Asia/Shanghai');\n"
			. "define('SYS_ROOT', '" . addslashes(SYS_ROOT) . "');\n"
			. "define('APPLICATON_ROOT', '');\n"
			. "include SYS_ROOT . '/runtime.php';\n"
			. "echo apicall('certtask', 'runOnce', array());\n"
			. "?>\n";

		if (@file_put_contents($runner, $code) === false) {
			$ret['msg'] = '无法写入引导脚本 ' . $runner;
			return $ret;
		}

		@chmod($runner, 0700);

		$cronDir = '/etc/cron.d/';

		if (!file_exists($cronDir)) {
			@mkdir($cronDir);
		}

		$cronFile = $cronDir . 'ep_certtask';
		// 输出丢弃：过程与结果都在 cert_task_log 表里，防 cron.log 膨胀
		$line = "* * * * * root " . $php . " " . $runner . " > /dev/null 2>&1\n";
		$msg = "SHELL=/bin/bash\nPATH=/sbin:/bin:/usr/sbin:/usr/bin\nHOME=/\n" . $line;

		if (@file_put_contents($cronFile, $msg) === false) {
			$ret['msg'] = '无法写入 ' . $cronFile;
			return $ret;
		}

		@chmod($cronFile, 0644);
		daocall('setting', 'add', array('certtask_cron_installed', strval(time())));
		$ret['success'] = true;
		$ret['msg'] = 'ep_certtask installed: ' . trim($line);
		return $ret;
	}

	/**
	 * 卸载 cron（保留任务数据；teardown/停用场景）
	 */
	public function uninstall_cron()
	{
		@unlink('/etc/cron.d/ep_certtask');
		@unlink($this->runnerFile());
		return true;
	}

	private function runnerFile()
	{
		return rtrim($GLOBALS['safe_dir'], '/') . '/certtask_run.php';
	}

	/* =====================================================================
	 *  六、查询（供 certdeploy.ctl.php 与验收 curl 使用）
	 * ===================================================================== */

	/**
	 * 分页查询任务，返回结构化数组
	 *
	 * @param string $status '' = 全部
	 * @return array array('success'=>bool,'list'=>array,'total'=>int,'counts'=>array)
	 */
	public function listTasks($status = '', $page = 1, $size = 20)
	{
		$ret = array('success' => false, 'list' => array(), 'total' => 0, 'counts' => array());
		$page = max(1, intval($page));
		$size = max(1, min(100, intval($size)));
		$count = 0;
		$list = daocall('certtask', 'pageList', array($status, $page, $size, &$count));
		$ret['success'] = true;
		$ret['list'] = is_array($list) ? $list : array();
		$ret['total'] = intval($count);
		$ret['counts'] = daocall('certtask', 'countByStatus', array());
		return $ret;
	}

	/**
	 * 任务详情 + 执行日志
	 *
	 * @return array array('success'=>bool,'task'=>array|null,'logs'=>array)
	 */
	public function taskDetail($id, $logLimit = 200)
	{
		$ret = array('success' => false, 'task' => null, 'logs' => array());
		$id = intval($id);
		$task = daocall('certtask', 'get', array($id));

		if (!$task) {
			return $ret;
		}

		$ret['success'] = true;
		$ret['task'] = $task;

		$logs = daocall('certtask', 'getLogs', array($id, intval($logLimit)));
		$ret['logs'] = is_array($logs) ? $logs : array();
		return $ret;
	}
}

?>
