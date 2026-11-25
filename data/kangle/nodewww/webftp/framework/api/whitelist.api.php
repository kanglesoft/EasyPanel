<?php
/**
 * whitelist.api.php —— IP/URL 白名单的公共校验与常量入口
 *
 * 定位：白名单（ruleAction=allow 链）的参数校验层。控制器
 * vhost/control/whitelist.ctl.php 通过 apicall('whitelist', ...) 复用这里的
 * 校验逻辑，后续若有其他单元（如地区放行）需要白名单语义，也从这里取
 * 表名/校验，避免 '!ep_wl_*' 命名散落。
 *
 * 表命名约定：所有本面板写入的 access 表以 !ep_ 前缀，且总长 ≤16 字符
 * （内核 add_table 限制「表名称长度错误(两位到16位)」，超长名建表直接
 * 500 失败），便于内核侧 grep 审计与集成步残链清理
 * （验收点：grep -c ep_wl config.xml 应为 0）。
 */
class WhitelistAPI extends API
{
	const IP_TABLE = '!ep_wl_ip';
	const URL_TABLE = '!ep_wl_url';

	/**
	 * 白名单使用的 access 表名清单（供集成/验收脚本比对残链）
	 *
	 * @return array
	 */
	public function tables()
	{
		return array(self::IP_TABLE, self::URL_TABLE);
	}

	/**
	 * 校验单个 IP 或 CIDR 段（IPv4/IPv6 均可，允许 prefix 0）
	 *
	 * @param string $ip 形如 1.2.3.4 或 1.2.3.0/24 或 2001:db8::/32
	 * @return bool
	 */
	public function validateIp($ip)
	{
		$ip = trim(strval($ip));

		if ($ip === '' || strlen($ip) > 128) {
			return false;
		}

		$addr = $ip;
		$prefix = null;

		if (strpos($ip, '/') !== false) {
			$parts = explode('/', $ip, 2);
			$addr = $parts[0];
			$prefix = $parts[1];

			if (!ctype_digit($prefix)) {
				return false;
			}

			$prefix = intval($prefix);
		}

		$is_v4 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		$is_v6 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

		if (!$is_v4 && !$is_v6) {
			return false;
		}

		if ($prefix === null) {
			return true;
		}

		return $prefix <= ($is_v4 ? 32 : 128);
	}

	/**
	 * 校验请求方法参数（允许 '!' 前缀表示反向匹配）
	 *
	 * @param string $meth 例如 GET / !POST / 空串（空串=全部方法，由调用方决定是否放行空）
	 * @return bool
	 */
	public function validateMeth($meth)
	{
		$meth = trim(strval($meth));

		if ($meth === '') {
			return true;
		}

		$name = substr($meth, 0, 1) === '!' ? substr($meth, 1) : $meth;
		$allowed = array('GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH');

		return in_array(strtoupper($name), $allowed, true);
	}

	/**
	 * 校验 URL 规则串长度（kangle acl_url 走正则/前缀匹配，仅做长度与空白防御）
	 *
	 * @param string $url
	 * @return bool
	 */
	public function validateUrl($url)
	{
		$url = trim(strval($url));

		return $url !== '' && strlen($url) <= 2048;
	}
}

?>
