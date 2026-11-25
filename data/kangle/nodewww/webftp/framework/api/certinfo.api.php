<?php
/**
 * certinfo.api.php —— 证书信息解析 API（品牌 / SAN / 有效期 / 过期判定）
 *
 * 定位：全项目唯一的「PEM → 结构化证书信息」解析入口，纯 openssl_x509_parse，
 * 不碰 kangle 内核、不读写 config.xml，任何调用方（证书台账 / 后续分发单元）
 * 都走 apicall('certinfo', 'parse', ...)。
 *
 * 解析逻辑照抄上游 easypanel framework/lib/ssl.lib.php 的 EpSsl::certificateInfo
 * （issuer O/CN 兜底为品牌、SAN 提取 DNS/IP、通配符按「恰好一级标签」匹配、
 * IDN 归一化），并按本面板调用习惯改为返回数值时间戳 + 剩余天数，方便 UI 打标。
 */
class CertinfoAPI extends API
{
	/**
	 * 解析一张 PEM 证书（含证书链时取首张，即叶子证书）
	 *
	 * @param string   $certificate PEM 内容
	 * @param string   $domain      可选：校验 SAN 是否覆盖该域名（不传则不做匹配校验，
	 *                              domain_mismatch 恒为 false）
	 * @param int|null $now         可选：判定基准时间戳（默认当前时间；便于单测注入）
	 * @return array|false 解析失败返回 false；成功返回下面结构：
	 *   brand / subject_cn / sans[] / san_text
	 *   not_before / not_after（时间戳）与 *_text（可读时间）
	 *   expired / not_yet_valid / days_left / domain_mismatch
	 *   serial / sig_alg / key_bits
	 */
	public function parse($certificate, $domain = null, $now = null)
	{
		if (!is_string($certificate) || $certificate === '' || strpos($certificate, "\0") !== false) {
			return false;
		}

		$info = @openssl_x509_parse($certificate);

		if (!$info) {
			return false;
		}

		$issuer = isset($info['issuer']) ? (array) $info['issuer'] : array();
		$brand = '';

		if (isset($issuer['O'])) {
			$brand = $this->flatten($issuer['O']);
		}

		if ($brand === '' && isset($issuer['CN'])) {
			$brand = $this->flatten($issuer['CN']);
		}

		if ($brand === '') {
			$brand = '未知颁发机构';
		}

		$subject = isset($info['subject']) ? (array) $info['subject'] : array();
		$subject_cn = isset($subject['CN']) ? $this->flatten($subject['CN']) : '';

		// SAN：只取 DNS 与 IP Address 条目，EMAIL/URI 等不进台账展示
		$sans = array();

		if (isset($info['extensions']['subjectAltName'])) {
			foreach (explode(',', $info['extensions']['subjectAltName']) as $entry) {
				if (preg_match('/^\s*(?:DNS|IP Address):\s*(.+)$/i', $entry, $match)) {
					$sans[] = trim($match[1]);
				}
			}
		}

		$sans = array_values(array_unique($sans));

		// 老证书没有 SAN 扩展时退回 subject CN（上游同款行为）
		if (!$sans && $subject_cn !== '') {
			$sans = array($subject_cn);
		}

		$not_before = isset($info['validFrom_time_t']) ? intval($info['validFrom_time_t']) : 0;
		$not_after = isset($info['validTo_time_t']) ? intval($info['validTo_time_t']) : 0;
		$now = $now === null ? time() : intval($now);

		// 域名匹配校验：只有显式传了 $domain 才计算
		$domain_mismatch = false;

		if ($domain !== null && $domain !== '') {
			$domain_mismatch = true;

			foreach ($sans as $certificate_domain) {
				if ($this->domainMatches($certificate_domain, $domain)) {
					$domain_mismatch = false;
					break;
				}
			}
		}

		return array(
			'brand' => $brand,
			'subject_cn' => $subject_cn,
			'sans' => $sans,
			'san_text' => $sans ? implode('、', $sans) : '未知',
			'not_before' => $not_before,
			'not_after' => $not_after,
			'not_before_text' => $not_before > 0 ? date('Y-m-d H:i:s', $not_before) : '未知',
			'not_after_text' => $not_after > 0 ? date('Y-m-d H:i:s', $not_after) : '未知',
			'expired' => ($not_after > 0 && $not_after <= $now),
			'not_yet_valid' => ($not_before > $now),
			'days_left' => ($not_after > 0 ? intval(floor(($not_after - $now) / 86400)) : -1),
			'domain_mismatch' => $domain_mismatch,
			'serial' => isset($info['serialNumber']) ? strval($info['serialNumber']) : '',
			'sig_alg' => isset($info['signatureTypeAlg']) ? strval($info['signatureTypeAlg']) : '',
			'key_bits' => $this->keyBits($certificate),
		);
	}

	/**
	 * 安全读取 doc_root 下的证书文件（软链接/越界路径一律返回空串）
	 *
	 * 与上游 EpSsl::readFile 同款防御：证书文件名来自 sqlite，理论上面板自己写的，
	 * 但用户可经 SSL 页任意改 certificate 字段，这里仍按不可信字符串处理。
	 */
	public function readFile($root, $name)
	{
		if (!is_string($name) || $name === '' || strpos($name, "\0") !== false) {
			return '';
		}

		if (basename($name) !== $name || strpos($name, '\\') !== false) {
			return '';
		}

		$file = rtrim(strval($root), '/\\') . '/' . $name;

		if (is_link($file) || !is_file($file)) {
			return '';
		}

		$content = @file_get_contents($file, false, null, 0, 1024000);
		return $content === false ? '' : $content;
	}

	/**
	 * 通配符匹配：*.a.com 覆盖 b.a.com，但不覆盖 a.com 本身和 c.b.a.com
	 * （上游 EpSsl::domainMatches 同款语义）
	 */
	public function domainMatches($certificate_domain, $domain)
	{
		$certificate_domain = $this->normalizeDomain($certificate_domain);
		$domain = $this->normalizeDomain($domain);

		if ($certificate_domain === '' || $domain === '') {
			return false;
		}

		if ($certificate_domain === $domain) {
			return true;
		}

		if (substr($certificate_domain, 0, 2) !== '*.' || strpos($domain, '*') !== false) {
			return false;
		}

		$dot = strpos($domain, '.');
		return $dot !== false && substr($domain, $dot + 1) === substr($certificate_domain, 2);
	}

	private function normalizeDomain($domain)
	{
		$domain = strtolower(rtrim(trim(strval($domain)), '.'));
		$wildcard = substr($domain, 0, 2) === '*.';
		$name = $wildcard ? substr($domain, 2) : $domain;

		if ($name !== '' && function_exists('idn_to_ascii')) {
			$ascii = defined('INTL_IDNA_VARIANT_UTS46')
				? @idn_to_ascii($name, 0, INTL_IDNA_VARIANT_UTS46)
				: @idn_to_ascii($name);

			if ($ascii !== false) {
				$name = strtolower($ascii);
			}
		}

		return ($wildcard ? '*.' : '') . $name;
	}

	/**
	 * issuer/subject 字段可能是数组（多值 RDN），压平成展示字符串
	 */
	private function flatten($value)
	{
		if (is_array($value)) {
			return implode(' / ', $value);
		}

		return strval($value);
	}

	/**
	 * 公钥位数（尽力而为，取不到返回 0，不影响主流程）
	 */
	private function keyBits($certificate)
	{
		$key = @openssl_pkey_get_public($certificate);

		if (!$key) {
			return 0;
		}

		$details = @openssl_pkey_get_details($key);
		return (isset($details['bits']) ? intval($details['bits']) : 0);
	}
}

?>
