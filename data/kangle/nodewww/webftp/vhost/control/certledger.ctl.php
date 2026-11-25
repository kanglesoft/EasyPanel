<?php
/**
 * certledger.ctl.php —— 证书台账（vhost 侧）
 *
 * 功能：
 *   1. 统一视图：把「站点 SSL 证书 + 单域名证书 + 手动上传证书」汇成一张台账，
 *      展示品牌（issuer O/CN）、SAN、有效期；已过期整行标红（ep-expired）。
 *   2. 手动证书入台账：粘贴 PEM 上传，经 certinfo.api 纯 PHP 解析后落
 *      cert_ledger 表（sqlite vhs.db），供后续续签/分发/通知单元复用。
 *
 * 设计说明：
 *   - 不改动 ssl.ctl.php（无主文件）。本控制器只读 vhost/vhost_info，不写证书
 *     文件、不碰 access 链，唯一的写入口是 cert_ledger 表自身。
 *   - 站点/域名证书每次打开列表时从磁盘重新解析并 upsert 进台账（source=
 *     vhost/domain），保证台账反映真实生效的证书；手动行 source=manual。
 *     删除同步行后下次打开会重新出现——台账以「现状」为准，这是刻意行为。
 *   - DDL 权威来源是 admin/control/upgrade.sql 增量（见集成片段）；这里再做一次
 *     运行时 CREATE TABLE IF NOT EXISTS 兜底（先例：framework/dao/audit.dao.php
 *     的 ensureTable），保证升级链未跑到的环境中页面不白屏。
 *   - PDO 错误模式为静默（项目惯例），故所有 exec/prepare 用 === false 判失败。
 */
needRole('vhost');

class CertledgerControl extends Control
{
	const TABLE = 'cert_ledger';
	const MAX_PEM_BYTES = 1048576;

	public function list()
	{
		$vhost = getRole('vhost');
		$rows = array();

		if (!$this->ensureTable()) {
			$this->_tpl->assign('msg', '证书台账表不可用（cert_ledger 建表失败），请先执行面板升级');
			$this->_tpl->assign('jump_url', '?c=certledger&a=form');
			$this->_tpl->assign('button_text', '去上传证书');
			return $this->_tpl->fetch('msg.html');
		}

		$this->syncLiveCerts($vhost);

		$pdo = $this->pdo();
		$sth = $pdo->prepare('SELECT id, domain, brand, sans, not_before, not_after, source, cert_file, note, created_at FROM ' . self::TABLE . ' WHERE vhost = :vhost ORDER BY not_after ASC, id ASC');

		if ($sth && $sth->execute(array(':vhost' => $vhost))) {
			$rows = $sth->fetchAll(PDO::FETCH_ASSOC);
		}

		if (!is_array($rows)) {
			$rows = array();
		}

		$now = time();
		$has_expired = false;
		$source_labels = array(
			'vhost' => '站点证书',
			'domain' => '域名证书',
			'manual' => '手动上传',
		);

		foreach ($rows as $i => $row) {
			$expired = ($row['not_after'] > 0 && $row['not_after'] <= $now);
			$not_yet = ($row['not_before'] > $now);

			if ($expired) {
				$status = 'expired';
			}
			elseif ($not_yet) {
				$status = 'not_yet';
			}
			elseif ($row['not_after'] <= 0) {
				$status = 'unknown';
			}
			elseif ($row['not_after'] - $now <= 30 * 86400) {
				$status = 'expiring';
			}
			else {
				$status = 'ok';
			}

			if ($expired) {
				$has_expired = true;
			}

			$rows[$i]['expired'] = $expired;
			$rows[$i]['status'] = $status;
			$rows[$i]['not_before_text'] = $row['not_before'] > 0 ? date('Y-m-d H:i:s', $row['not_before']) : '未知';
			$rows[$i]['not_after_text'] = $row['not_after'] > 0 ? date('Y-m-d H:i:s', $row['not_after']) : '未知';
			$rows[$i]['days_left'] = $row['not_after'] > 0 ? intval(floor(($row['not_after'] - $now) / 86400)) : -1;
			$rows[$i]['source_label'] = isset($source_labels[$row['source']]) ? $source_labels[$row['source']] : $row['source'];

			// 证书内容完全来自外部（用户上传/第三方签发），入库前不转义、
			// 展示前统一在这里按 HTML 语境转义，防止台账页被证书字段打 XSS。
			foreach (array('domain', 'brand', 'sans', 'cert_file', 'note', 'source') as $field) {
				$rows[$i][$field] = htmlspecialchars((string) $row[$field], ENT_QUOTES, 'UTF-8');
			}
		}

		$this->_tpl->assign('rows', $rows);
		$this->_tpl->assign('has_expired', $has_expired);
		$this->_tpl->assign('breadcrumb', '证书台账');
		$this->_tpl->assign('now_text', date('Y-m-d H:i:s', $now));
		return $this->_tpl->fetch('certledger/list.html');
	}

	public function form()
	{
		$this->_tpl->assign('breadcrumb', '证书台账');
		return $this->_tpl->fetch('certledger/from.html');
	}

	/**
	 * 手动证书上传：粘贴 PEM → certinfo 解析 → 入台账（同 vhost+域名 的 manual 行覆盖更新）
	 */
	public function add()
	{
		$vhost = getRole('vhost');
		$pem = isset($_REQUEST['certificate']) ? strval($_REQUEST['certificate']) : '';
		$domain = filterParam(isset($_REQUEST['domain']) ? $_REQUEST['domain'] : '', 'param');
		$note = filterParam(isset($_REQUEST['note']) ? $_REQUEST['note'] : '', 'param');

		if (strlen($pem) > self::MAX_PEM_BYTES) {
			return $this->show_msg('证书内容过大（超过 1MB），请检查粘贴内容');
		}

		if (!$this->ensureTable()) {
			return $this->show_msg('证书台账表不可用（cert_ledger 建表失败），请先执行面板升级');
		}

		$info = apicall('certinfo', 'parse', array($pem, $domain === '' ? null : $domain));

		if ($info === false) {
			audit('ssl', 'certledger_add', $vhost, 'error', array('reason' => 'pem_parse_failed'));
			return $this->show_msg('无法解析该证书：请粘贴以 -----BEGIN CERTIFICATE----- 开头的 PEM 内容');
		}

		// 与表单 maxlength=200 口径一致：按字符数而非字节数限制，避免中文备注被误拒
		$note_len = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);

		if ($note_len > 200) {
			return $this->show_msg('备注最多 200 字符');
		}

		if ($domain === '') {
			$domain = $info['san_text'] !== '未知' && !empty($info['sans']) ? $info['sans'][0] : ($info['subject_cn'] !== '' ? $info['subject_cn'] : '-');
		}

		if ($info['domain_mismatch']) {
			audit('ssl', 'certledger_add', $domain, 'error', array('vhost' => $vhost, 'reason' => 'domain_mismatch'));
			return $this->show_msg('证书与填写的域名「' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '」不匹配（SAN：' . htmlspecialchars($info['san_text'], ENT_QUOTES, 'UTF-8') . '），请核对后再保存');
		}

		// 同域已有手动行则覆盖（换证场景），否则插入
		$pdo = $this->pdo();
		$exist = null;
		$sth = $pdo->prepare('SELECT id FROM ' . self::TABLE . " WHERE vhost = :vhost AND domain = :domain AND source = 'manual' LIMIT 1");

		if ($sth && $sth->execute(array(':vhost' => $vhost, ':domain' => $domain))) {
			$exist = $sth->fetch(PDO::FETCH_ASSOC);
		}

		if ($exist) {
			$sth = $pdo->prepare('UPDATE ' . self::TABLE . ' SET brand = :brand, sans = :sans, not_before = :not_before, not_after = :not_after, note = :note, created_at = :created_at WHERE id = :id');
			$ok = $sth && $sth->execute(array(
				':brand' => $info['brand'],
				':sans' => $info['san_text'],
				':not_before' => $info['not_before'],
				':not_after' => $info['not_after'],
				':note' => $note,
				':created_at' => time(),
				':id' => $exist['id'],
			));
		}
		else {
			$sth = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (vhost, domain, brand, sans, not_before, not_after, source, cert_file, note, created_at) VALUES (:vhost, :domain, :brand, :sans, :not_before, :not_after, \'manual\', NULL, :note, :created_at)');
			$ok = $sth && $sth->execute(array(
				':vhost' => $vhost,
				':domain' => $domain,
				':brand' => $info['brand'],
				':sans' => $info['san_text'],
				':not_before' => $info['not_before'],
				':not_after' => $info['not_after'],
				':note' => $note,
				':created_at' => time(),
			));
		}

		if (!$ok) {
			audit('ssl', 'certledger_add', $domain, 'error', array('vhost' => $vhost, 'reason' => 'db_write_failed'));
			return $this->show_msg('台账写入失败，请稍后重试');
		}

		audit('ssl', 'certledger_add', $domain, 'success', array('vhost' => $vhost, 'not_after' => $info['not_after_text'], 'brand' => $info['brand']));
		exit("<script language='javascript'>alert('证书已入台账');window.location.href='?c=certledger&a=list';</script>");
	}

	/**
	 * 删除台账行（仅本 vhost；同步行删除后下次打开列表会按磁盘现状重新出现）
	 */
	public function del()
	{
		$vhost = getRole('vhost');
		$id = intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);

		if ($id <= 0) {
			exit('参数错误');
		}

		if (!$this->ensureTable()) {
			exit('表不可用');
		}

		$pdo = $this->pdo();
		$sth = $pdo->prepare('SELECT domain, source FROM ' . self::TABLE . ' WHERE id = :id AND vhost = :vhost');

		if (!$sth || !$sth->execute(array(':id' => $id, ':vhost' => $vhost))) {
			exit('删除失败');
		}

		$row = $sth->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			exit('记录不存在');
		}

		$sth = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id AND vhost = :vhost');

		if (!$sth || !$sth->execute(array(':id' => $id, ':vhost' => $vhost))) {
			exit('删除失败');
		}

		audit('ssl', 'certledger_del', $row['domain'], 'success', array('vhost' => $vhost, 'source' => $row['source']));
		exit('成功');
	}

	/**
	 * 把站点证书 + 单域名证书从磁盘同步进台账
	 *
	 * 全程只读：读 vhost/vhost_info 表 + 读 doc_root 下的 .crt 文件，
	 * 文件按用户属主读取（先例：ssl.ctl.php:21 change_to_user）。
	 */
	private function syncLiveCerts($vhost)
	{
		$user = daocall('vhost', 'getVhost', array($vhost));

		if (!$user || !is_array($user)) {
			return;
		}

		$doc_root = isset($user['doc_root']) ? $user['doc_root'] : '';
		$found = array();

		if ($doc_root !== '' && !empty($user['certificate'])) {
			change_to_user($user['uid'], $user['gid']);
			$pem = apicall('certinfo', 'readFile', array($doc_root, $user['certificate']));
			change_to_super();
			$info = $pem !== '' ? apicall('certinfo', 'parse', array($pem)) : false;

			if ($info !== false) {
				$found[] = array(
					'domain' => !empty($info['sans']) ? $info['sans'][0] : ($info['subject_cn'] !== '' ? $info['subject_cn'] : $vhost),
					'info' => $info,
					'source' => 'vhost',
					'cert_file' => $user['certificate'],
				);
			}
		}

		$infos = daocall('vhostinfo', 'getInfo', array($vhost, 0));

		if ($infos && is_array($infos)) {
			foreach ($infos as $row) {
				// getInfo($vhost, 0) 里 DAO 的 `if ($type)` 对 0 恒假，等于没按 type 过滤，
				// 这里显式只取 type=0（域名）行（ssl.ctl.php 同款坑，见其单域名证书逻辑）。
				if (intval($row['type']) != 0 || !isset($row['value']) || !isset($row['name'])) {
					continue;
				}

				// 单域名证书 value 形如 server://xxx/nodes=...;domain.crt|domain.key
				if (strncasecmp($row['value'], 'server://', 9) != 0 || strpos($row['value'], ';') === false) {
					continue;
				}

				$parts = explode(';', $row['value'], 2);
				$files = explode('|', $parts[1], 2);

				if (count($files) != 2 || $files[0] === '') {
					continue;
				}

				// 与 ssl.ctl.php domainSslForm 同款判定：只有 crt|key 绑定才算证书行
				if (strpos($files[0], '.crt') === false || strpos($files[1], '.key') === false) {
					continue;
				}

				change_to_user($user['uid'], $user['gid']);
				$pem = apicall('certinfo', 'readFile', array($doc_root, $files[0]));
				change_to_super();
				$info = $pem !== '' ? apicall('certinfo', 'parse', array($pem, $row['name'])) : false;

				if ($info !== false) {
					$found[] = array(
						'domain' => $row['name'],
						'info' => $info,
						'source' => 'domain',
						'cert_file' => $files[0],
					);
				}
			}
		}

		$pdo = $this->pdo();

		foreach ($found as $item) {
			// 按 (vhost, cert_file) 定位同步行；命中则刷新，缺则插入。
			// 手动行（cert_file IS NULL 且 source='manual'）永不参与该匹配。
			$sth = $pdo->prepare('SELECT id FROM ' . self::TABLE . " WHERE vhost = :vhost AND cert_file = :cert_file AND source = :source LIMIT 1");
			$exist = null;

			if ($sth && $sth->execute(array(':vhost' => $vhost, ':cert_file' => $item['cert_file'], ':source' => $item['source']))) {
				$exist = $sth->fetch(PDO::FETCH_ASSOC);
			}

			$info = $item['info'];

			if ($exist) {
				$sth = $pdo->prepare('UPDATE ' . self::TABLE . ' SET domain = :domain, brand = :brand, sans = :sans, not_before = :not_before, not_after = :not_after WHERE id = :id');

				if ($sth) {
					$sth->execute(array(
						':domain' => $item['domain'],
						':brand' => $info['brand'],
						':sans' => $info['san_text'],
						':not_before' => $info['not_before'],
						':not_after' => $info['not_after'],
						':id' => $exist['id'],
					));
				}
			}
			else {
				$sth = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (vhost, domain, brand, sans, not_before, not_after, source, cert_file, note, created_at) VALUES (:vhost, :domain, :brand, :sans, :not_before, :not_after, :source, :cert_file, :note, :created_at)');

				if ($sth) {
					$sth->execute(array(
						':vhost' => $vhost,
						':domain' => $item['domain'],
						':brand' => $info['brand'],
						':sans' => $info['san_text'],
						':not_before' => $info['not_before'],
						':not_after' => $info['not_after'],
						':source' => $item['source'],
						':cert_file' => $item['cert_file'],
						':note' => '',
						':created_at' => time(),
					));
				}
			}
		}
	}

	/**
	 * 幂等建表兜底（权威 DDL 在 admin/control/upgrade.sql，见集成片段）
	 */
	private function ensureTable()
	{
		static $ready = null;

		if ($ready !== null) {
			return $ready;
		}

		$ready = false;
		$pdo = $this->pdo();

		if (!$pdo) {
			return $ready;
		}

		$sqls = array(
			'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, vhost TEXT NOT NULL, domain TEXT NOT NULL, brand TEXT, sans TEXT, not_before INTEGER NOT NULL DEFAULT 0, not_after INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT \'manual\', cert_file TEXT, note TEXT, created_at INTEGER NOT NULL DEFAULT 0)',
			'CREATE INDEX IF NOT EXISTS idx_cert_ledger_vhost ON ' . self::TABLE . ' (vhost)',
		);

		foreach ($sqls as $sql) {
			// PDO 静默模式：DDL 成功时 exec() 返回 0，失败返回 false，只能 === false 判错
			if ($pdo->exec($sql) === false) {
				return $ready;
			}
		}

		$ready = true;
		return $ready;
	}

	private function pdo()
	{
		static $pdo = null;

		if ($pdo !== null) {
			return $pdo;
		}

		load_lib('pub:db');
		$conn = db_connect(null);
		$pdo = $conn ? $conn : false;
		return $pdo;
	}

	private function show_msg($msg)
	{
		$this->_tpl->assign('msg', $msg);
		$this->_tpl->assign('jump_url', '?c=certledger&a=form');
		$this->_tpl->assign('button_text', '返回重新填写');
		return $this->_tpl->fetch('msg.html');
	}
}

?>
