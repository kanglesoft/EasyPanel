<?php
/**
 * http3.api.php —— HTTP/3 (QUIC) 支持探测与开关状态
 *
 * 设计约束（评审 2e，不得违背）：
 *   1. 开关状态只存 vhs.db 的 setting 表键 `http3_flag_<vhost>`，
 *      **不给 vhost 表加 http3 列**——避开两个无主文件
 *      （framework/dao/vhost.dao.php 的 MAP_ARR 与
 *        framework/api/cdnPrimary.api.php 的 $sync_vhost_fields）。
 *   2. 不写 config.xml、不碰 docker-compose.yml：本仓库当前 kangle
 *      二进制不含 QUIC（nm 无 quic/nghttp3 符号，strings 无 alt-svc），
 *      compose 仅映射 TCP 443。内核将来升级支持后，配置生成层按
 *      setting 键取值落地即可，面板侧代码无需再改。
 *   3. 假开关具体化钩子：setting 键 `http3_force_show=1` 时强制视为
 *      支持（供远端验收跑通「显示表单→保存→回读」链路，见
 *      .temp/integration/http3-quic-toggle.md）。
 *
 * 探测结果缓存于 setting 键 `http3_support_cache`，格式 "时间戳:0|1"，
 * TTL 1 小时，避免每次页面渲染都 grep 46MB 内核二进制。
 */
class Http3API extends API
{
	/** 内核二进制路径（面板 php 与 kangle 同容器，参照 restore.api.php 同款路径） */
	const BIN = '/vhs/kangle/bin/kangle';

	/** 探测缓存键，值格式 "ts:0|1" */
	const CACHE_KEY = 'http3_sup…ache';

	/** 强制显示钩子键（验收/演示用，置 '1' 即视为支持） */
	const FORCE_KEY = 'http3_force_show';

	/** 探测缓存有效期（秒） */
	const CACHE_TTL = 3600;

	/**
	 * 当前内核是否真正可用 HTTP/3
	 *
	 * 判定链：force 钩子 → 缓存 → (二进制含 h3 特征串) && (UDP 443 未被
	 * 探测判死)。UDP 探测手段缺失（容器内无 ss 命令）时不否决，退化为
	 * 仅看二进制特征串。
	 *
	 * 注意：grep -i quic 会误命中 OpenSSL 的 BN_mod_*_quick / quick 类
	 * 单词，因此特征串只认 h3 的强特征：nghttp3 / HTTP/3 / Alt-Svc。
	 *
	 * @param bool $refresh 传 true 强制重新探测并刷新缓存
	 * @return bool
	 */
	public function supportsHttp3($refresh = false)
	{
		$force = daocall('setting', 'get', array(self::FORCE_KEY));

		if ($force === '1' || $force === 1) {
			return true;
		}

		if (!$refresh) {
			$cached = daocall('setting', 'get', array(self::CACHE_KEY));

			if (is_scalar($cached) && $cached !== '') {
				$parts = explode(':', strval($cached));

				if (count($parts) == 2 && (time() - intval($parts[0])) < self::CACHE_TTL) {
					return intval($parts[1]) === 1;
				}
			}
		}

		$support = false;

		if ($this->checkBinSupport()) {
			$udp = $this->checkUdp443();
			// -1 = 探测手段缺失，不否决；0 = 明确没监听 UDP 443，判不支持
			$support = ($udp !== 0);
		}

		daocall('setting', 'add', array(self::CACHE_KEY, time() . ':' . ($support ? '1' : '0')));
		return $support;
	}

	/**
	 * 读取某虚拟主机的 HTTP/3 开关状态
	 *
	 * @param string $vhost
	 * @return int 0|1
	 */
	public function getStatus($vhost)
	{
		$key = $this->flagKey($vhost);

		if ($key === false) {
			return 0;
		}

		$value = daocall('setting', 'get', array($key));
		return ($value === '1' || $value === 1) ? 1 : 0;
	}

	/**
	 * 写入开关状态（只落 setting 键，不碰 vhost 列 / config.xml）
	 *
	 * 成功后调用 notice_cdn_changed() bump sync_seq：复用既有
	 * updateVhostSyncseq 通路让复制/CDN 侧感知“该 vhost 配置有变”，
	 * 为内核支持 QUIC 后的配置生成留钩子，本方法自身不生成任何
	 * alt-svc/quic 内核配置。
	 *
	 * @param string $vhost
	 * @param int    $on 0|1
	 * @return bool
	 */
	public function setStatus($vhost, $on)
	{
		$key = $this->flagKey($vhost);

		if ($key === false) {
			return false;
		}

		$saved = daocall('setting', 'add', array($key, $on ? '1' : '0'));

		if (!$saved) {
			return false;
		}

		notice_cdn_changed($vhost);
		return true;
	}

	/**
	 * 给界面展示的检测摘要（只读，不触发新探测）
	 *
	 * @return string
	 */
	public function getProbeInfo()
	{
		$force = daocall('setting', 'get', array(self::FORCE_KEY));

		if ($force === '1' || $force === 1) {
			return '强制显示模式（http3_force_show），实际内核支持情况以重新检测为准';
		}

		$cached = daocall('setting', 'get', array(self::CACHE_KEY));

		if (is_scalar($cached) && $cached !== '') {
			$parts = explode(':', strval($cached));

			if (count($parts) == 2) {
				$ago = time() - intval($parts[0]);

				if ($ago < 0) {
					$ago = 0;
				}

				return '内核支持检测完成（' . intval($ago) . ' 秒前）';
			}
		}

		return '尚未缓存检测结果，可点击“重新检测”';
	}

	/**
	 * 二进制 h3 特征探测
	 *
	 * grep -a 强制按文本处理 46MB ELF；-m 1 命中即停，代价可接受。
	 * exec 不可用/命令缺失一律按“不支持”保守处理（功能整体隐藏，
	 * 不会给出可点却无效的开关）。
	 *
	 * @return bool
	 */
	private function checkBinSupport()
	{
		if (!is_file(self::BIN)) {
			return false;
		}

		$out = array();
		$status = -1;
		$cmd = 'LC_ALL=C grep -a -m 1 -E ' . escapeshellarg('nghttp3|ngtcp2|HTTP/3|[Aa]lt-[Ss]vc') . ' ' . escapeshellarg(self::BIN) . ' 2>/dev/null';
		@exec($cmd, $out, $status);
		return $status === 0;
	}

	/**
	 * 容器内 UDP 443 监听探测
	 *
	 * @return int 1=在监听 0=探测可用且没监听 -1=探测手段缺失
	 */
	private function checkUdp443()
	{
		$whereis = array();
		$have = -1;
		@exec('command -v ss 2>/dev/null', $whereis, $have);

		if ($have !== 0 || empty($whereis)) {
			return -1;
		}

		$out = array();
		$status = -1;
		@exec('ss -uln 2>/dev/null | grep -c -E ' . escapeshellarg(':443([^0-9]|$)') , $out, $status);

		if ($status > 1 || empty($out)) {
			// ss 执行失败（无权限等），按手段缺失处理
			return -1;
		}

		return intval($out[0]) > 0 ? 1 : 0;
	}

	/**
	 * setting 键名组装（vhost 名白名单校验，杜绝把任意串拼进键名）
	 *
	 * @param string $vhost
	 * @return string|false
	 */
	private function flagKey($vhost)
	{
		if (!is_scalar($vhost)) {
			return false;
		}

		$vhost = trim(strval($vhost));

		if ($vhost === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $vhost)) {
			return false;
		}

		return 'http3_flag_' . $vhost;
	}
}

?>
