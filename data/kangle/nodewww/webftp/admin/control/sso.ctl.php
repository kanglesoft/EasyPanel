<?php
class SsoControl extends Control
{
	public function hello()
	{
		session_unset();
		$base_passwd = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_0123456789';
		$base_len = strlen($base_passwd);
		$len = 16;
		$sess_key = '';
		$i = 0;

		while ($i < $len) {
			$sess_key .= $base_passwd[rand() % $base_len];
			++$i;
		}

		/* T05-M3：开放重定向防护。$_REQUEST['url'] 直接进 Location 头，必须白名单校验。 */
		$safe = $this->_safeSsoRedirect($_REQUEST['url']);
		$sep = (strpos($safe, '?') !== false) ? '&' : '?';
		$url = $safe . $sep . 'r=' . $sess_key;
		$_SESSION['sess_key'] = $sess_key;
		header('Location: ' . $url);
		exit();
	}

	public function login()
	{
		if ($_SESSION['sess_key'] == '') {
			exit('error,sess_key is empty');
		}

		$name = $_REQUEST['name'];
		$skey = daocall('setting', 'get', array('skey'));

		if (!$skey) {
			exit('skey error');
		}

		$str = $_REQUEST['r'] . $name . $_SESSION['sess_key'] . $skey;
		$md5str = md5($str);
		if (strtolower($md5str) === $_REQUEST['s'] && $_REQUEST['s'] != '') {
			registerRole('admin', 'admin');
			header('Location: ?c=vhost&a=showVhost&name=' . $name);
			exit();
			return NULL;
		}

		exit('login failed');
	}

	private function _safeSsoRedirect($url)
	{
		/* 仅允许同源相对路径或 setting sso_allowed_hosts 配置的受信主机；
		 * 其余一律回退到面板首页，杜绝任意外部跳转（钓鱼）。 */
		if (!is_string($url) || $url === '') {
			return 'index.php';
		}

		/* 同源相对路径（以 / ? # 开头）直接放行 */
		if (preg_match('#^[?/#]#', $url)) {
			return $url;
		}

		$parts = parse_url($url);

		/* parse_url 解析失败（畸形 URL）一律拒绝，避免 false 被 empty() 误判为“无 host”而放行。 */
		if ($parts === false || !is_array($parts)) {
			return 'index.php';
		}

		/* 含非 http/https 协议（javascript:/data:/mailto: 等）一律拒绝。 */
		if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
			return 'index.php';
		}

		/* 无 host 的相对路径（如 dashboard）视为同源放行。 */
		if (empty($parts['host'])) {
			return $url;
		}

		$allowed = array();

		if ($cfg = daocall('setting', 'get', array('sso_allowed_hosts'))) {
			foreach (explode("\n", $cfg) as $h) {
				$h = trim($h);

				if ($h !== '') {
					$allowed[] = $h;
				}
			}
		}

		$allowed[] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

		if (in_array($parts['host'], $allowed, true)) {
			return $url;
		}

		return 'index.php';
	}
}

?>