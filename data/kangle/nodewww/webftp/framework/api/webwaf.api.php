<?php
/**
 * webwaf.api.php —— 雷池 WAF（SafeLine）接入探测（代码保留 / hide 版）
 *
 * 设计约束（计划「雷池 WAF 代码保留」单元，不得违背）：
 *   1. 本单元只探测、只保留代码，不做任何部署：不改 docker-compose.yml
 *      （不新增 /data/safeline sock 卷挂载）、不改 config.xml、不新增
 *      ext/safeline.dso.xml 文件。远端基线内核探测结论：kangle 二进制与
 *      ext/ 目录零命中 safeline/acl_safeline_waf，compose 无 sock 挂载，
 *      因此本环境探测结果恒为 installed=false。
 *   2. 上游 webwaf 的链写入代码（!safeline_waf 表 + acl_safeline_waf +
 *      mark_status_code(451) + BEGIN 链）原样保留在 webwaf.ctl.php，由
 *      probe() 作为总闸：探测不通过绝不触碰 WHM/链，避免当前内核因未知
 *      model 名报错、污染 config.xml。内核升级/外部部署雷池后，本单元
 *      代码零改动即可生效。
 *   3. 探测结果缓存于 setting 表键 `safeline_probe_cache`，值格式
 *      "时间戳|base64(json)"，TTL 1 小时，避免每次页面渲染都 grep 46MB
 *      内核二进制；base64 隔离 reason 文本里的任意字符，防止 SQL 拼装
 *      与分隔符歧义。不给 vhost 表加列。
 *   4. reason 字符串是机器可读码（含 sock / dso_xml / kernel 关键字），
 *      界面展示文案由控制器映射，验收断言（reason 含 "sock"）依赖本约定。
 */
class WebwafAPI extends API
{
	/** 雷池 detector 的 unix socket（宿主安装后需以卷挂载进容器才可见） */
	const SOCK = '/data/safeline/resources/detector/snserver.sock';

	/** kangle 雷池 DSO 配置（当前仓库 ext/ 内无此文件，属外部部署产物） */
	const DSO_XML = '/vhs/kangle/ext/safeline.dso.xml';

	/** 内核二进制路径（面板 php 与 kangle 同容器，与 http3.api.php 同款） */
	const BIN = '/vhs/kangle/bin/kangle';

	/** 探测缓存 setting 键 */
	const CACHE_KEY = 'safeli…ache';

	/** 探测缓存有效期（秒） */
	const CACHE_TTL = 3600;

	/** 雷池请求控制表名（上游原样保留） */
	const CHAIN_TABLE = '!safeline_waf';

	/** BEGIN 链中引用上表用的 action 值（上游原样保留） */
	const CHAIN_BEGIN = 'table:!safeline_waf';

	/**
	 * 雷池 WAF 是否已在本节点可用（探测，不部署）
	 *
	 * 判定链（顺序即 reason 归因优先级，sock 必须最先，见头注释 4）：
	 *   1) detector sock 文件存在 —— 雷池服务在跑且卷已挂进容器；
	 *   2) ext/safeline.dso.xml 存在 —— 内核已加载雷池 DSO 配置；
	 *   3) 内核二进制含 safeline 特征串 —— DSO/model 真正编进内核。
	 * 任一不满足即 installed=false。探测全程只读文件系统 + grep，
	 * 不发起任何网络/WHM 写操作。
	 *
	 * @param bool $refresh 传 true 强制重新探测并刷新缓存
	 * @return array('installed'=>bool, 'reason'=>string, 'endpoint'=>string)
	 */
	public function probe($refresh = false)
	{
		if (!$refresh) {
			$cached = $this->readCache();

			if ($cached !== false) {
				return $cached;
			}
		}

		$result = $this->doProbe();
		$this->writeCache($result);
		return $result;
	}

	/**
	 * 组装 status/recheck 的 JSON 载荷（四键固定：code/installed/reason/endpoint）
	 *
	 * @param array $probe probe() 返回值
	 * @param int   $code  0=探测本身成功（不代表已安装雷池）
	 * @return array
	 */
	public function buildStatus($probe, $code = 0)
	{
		return array(
			'code' => intval($code),
			'installed' => !empty($probe['installed']),
			'reason' => isset($probe['reason']) ? strval($probe['reason']) : 'unknown',
			'endpoint' => isset($probe['endpoint']) ? strval($probe['endpoint']) : '',
		);
	}

	/**
	 * 实际探测（无缓存路径）
	 *
	 * @return array
	 */
	private function doProbe()
	{
		if (!file_exists(self::SOCK)) {
			return array(
				'installed' => false,
				'reason' => 'safeline_detector_sock_missing(' . self::SOCK . ')',
				'endpoint' => '',
			);
		}

		if (!is_readable(self::SOCK)) {
			return array(
				'installed' => false,
				'reason' => 'safeline_detector_sock_not_readable(' . self::SOCK . ')',
				'endpoint' => '',
			);
		}

		if (!is_file(self::DSO_XML)) {
			return array(
				'installed' => false,
				'reason' => 'safeline_dso_xml_missing(' . self::DSO_XML . ')',
				'endpoint' => '',
			);
		}

		if (!$this->checkKernelSupport()) {
			return array(
				'installed' => false,
				'reason' => 'safeline_kernel_module_missing(' . self::BIN . ' 未编译 acl_safeline_waf)',
				'endpoint' => '',
			);
		}

		return array(
			'installed' => true,
			'reason' => 'ok',
			'endpoint' => 'unix://' . self::SOCK,
		);
	}

	/**
	 * 内核 safeline 特征探测
	 *
	 * grep -a 强制按文本处理 46MB ELF；-m 1 命中即停。exec 不可用/命令
	 * 缺失一律按“不支持”保守处理（功能整体隐藏，不会给出可点却无效的开关），
	 * 与 http3.api.php checkBinSupport 同一手法。
	 *
	 * @return bool
	 */
	private function checkKernelSupport()
	{
		if (!is_file(self::BIN)) {
			return false;
		}

		$out = array();
		$status = -1;
		$cmd = 'LC_ALL=C grep -a -m 1 -E ' . escapeshellarg('safeline|acl_safeline') . ' ' . escapeshellarg(self::BIN) . ' 2>/dev/null';
		@exec($cmd, $out, $status);
		return $status === 0;
	}

	/**
	 * 读探测缓存；未命中/过期/格式坏返回 false
	 *
	 * @return array|false
	 */
	private function readCache()
	{
		$raw = daocall('setting', 'get', array(self::CACHE_KEY));

		if (!is_scalar($raw) || $raw === '') {
			return false;
		}

		$parts = explode('|', strval($raw), 2);

		if (count($parts) != 2 || (time() - intval($parts[0])) >= self::CACHE_TTL) {
			return false;
		}

		$json = base64_decode($parts[1], true);

		if ($json === false) {
			return false;
		}

		$arr = json_decode($json, true);

		if (!is_array($arr) || !array_key_exists('installed', $arr) || !array_key_exists('reason', $arr) || !array_key_exists('endpoint', $arr)) {
			return false;
		}

		return $arr;
	}

	/**
	 * 写探测缓存（失败不影响探测结果返回）
	 *
	 * @param array $result
	 */
	private function writeCache($result)
	{
		daocall('setting', 'add', array(self::CACHE_KEY, time() . '|' . base64_encode(json_encode($result))));
	}
}

?>
