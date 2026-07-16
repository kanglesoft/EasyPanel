<?php
function php_precreate($params)
{
	$default_version = daocall('setting', 'get', array('default_version'));
	if(!$default_version) $default_version = 'php74';

	if ($params['resync']==1) {

		$vhostinfo = apicall('vhostinfo', 'get2', array($params['name'], 'moduleversion', 101));
		$value = $vhostinfo['value'];
		if(!$value) $value = $default_version;
		$prefix = php_get_handler_prefix($value);
		apicall('vhost', 'addInfo', array($params['name'], '1,php', 3, '1,'.$prefix.$value.',*', false));
		apicall('vhost', 'addInfo', array($params['name'], 'moduleversion', 101, $value, false));

	}else{

		if (!is_win()) {
			@unlink('/vhs/kangle/phpini/php-'.$params['name'].'.ini');
		}

		$prefix = php_get_handler_prefix($default_version);
		apicall('vhost', 'addInfo', array($params['name'], '1,php', 3, '1,'.$prefix.$default_version.',*', false));
		apicall('vhost', 'addInfo', array($params['name'], 'moduleversion', 101, $default_version, false));

		if ($params['default_index']) {
			$default_indexs = explode(',', $params['default_index']);
			$indexs = array();
			$i = 100;

			foreach ($default_indexs as $index) {
				$indexs[] = array($index, 2, $i++, false);
			}
		}
		else {
			$indexs = array(
				array('index.htm', 2, '100', false),
				array('index.html', 2, '101', false),
				array('index.php', 2, '102', false)
				);
		}

		apicall('vhost', 'addInfos', array($params['name'], $indexs));
	}
}

function php_postcreate($params)
{
	// 让独立 fpm 容器（phpXX）能进入站点家目录读取 wwwroot 下文件。
	// kangle 创建 vhost 目录时通常为 700；对额外 PHP 版本需要组（GID 1100）可读，
	// 因此将站点根目录设置为 750，fpm worker 以同组运行即可访问。
	if (!is_win()) {
		$doc_root = apicall('vhost', 'getDocRoot', array($params['name']));
		if ($doc_root && is_dir($doc_root)) {
			@chmod($doc_root, 0750);
		}
	}
}

function php_get_version()
{
	static $cached = null;
	if ($cached !== null) {
		return $cached;
	}

	// cache detection result for 5 minutes to avoid repeated fsockopen/exec
	$cache_file = '/tmp/php_versions.cache';
	$cache_ttl = 300;
	if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
		$data = @json_decode(@file_get_contents($cache_file), true);
		if (is_array($data)) {
			$cached = $data;
			return $data;
		}
	}

	$extdir = $GLOBALS['safe_dir'] . '../ext/';
	$opdir = @opendir($extdir);

	if (!$opdir) {
		$cached = false;
		return false;
	}

	$versions = array();
	while (($file = readdir($opdir)) !== false) {
		if ($file == '.' || $file == '..') {
			continue;
		}

		if (!is_dir($extdir . $file) || strpos($file, 'php') === false || substr($file, -4) === '.bak') {
			continue;
		}

		if (substr($file, 0, 4) == 'tpl_') {
			$name = substr($file, 4);
			if (is_win() && $name == 'php5217') {
				$name = 'php52';
			}
		}
		else {
			$name = $file;
		}

		if (!php_version_available($extdir . $file, $name)) {
			continue;
		}

		$versions[$name] = 'PHP-' . substr($name, -2, 1) . '.' . substr($name, -1, 1);
	}

	@closedir($opdir);
	ksort($versions);

	@file_put_contents($cache_file, json_encode($versions));
	$cached = $versions;
	return $versions;
}

function php_version_available($dir, $name)
{
	// PHP 5.2 runtime libraries are missing on modern systems, force exclude
	if ($name == 'php52') {
		return false;
	}

	$config = $dir . '/config.xml';
	if (!file_exists($config)) {
		return false;
	}

	$content = @file_get_contents($config);
	if ($content === false) {
		return false;
	}

	// resolve %{config_dir} placeholder to the actual extension directory
	$content = str_replace('%{config_dir}', $dir, $content);

	// remote fastcgi via <server> (e.g. php82 container), short timeout to avoid 504
	if (preg_match("/<server[^>]*host=['\"]([^'\"]+)['\"][^>]*port=['\"]([^'\"]+)['\"]/i", $content, $matches) ||
		preg_match("/<server[^>]*port=['\"]([^'\"]+)['\"][^>]*host=['\"]([^'\"]+)['\"]/i", $content, $matches)) {
		$host = $matches[1];
		$port = intval($matches[2]);
		if ($port <= 0) {
			return false;
		}
		$fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
		if ($fp) {
			@fclose($fp);
			return true;
		}
		return false;
	}

	// remote fastcgi via <cmd listen='host:port'> (兼容旧配置)
	if (preg_match("/<cmd[^>]*listen=['\"]([^'\"]+)['\"]/i", $content, $matches)) {
		$listen = $matches[1];
		if ($listen !== 'local') {
			if (strpos($listen, ':') === false) {
				return false;
			}
			list($host, $port) = explode(':', $listen);
			$port = intval($port);
			if ($port <= 0) {
				return false;
			}
			$fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
			if ($fp) {
				@fclose($fp);
				return true;
			}
			return false;
		}
	}

	// local binary (e.g. php74 built into kangle container)
	if (preg_match("/<cmd[^>]*file=['\"]([^'\"]+)['\"]/i", $content, $matches)) {
		$bin = $matches[1];
		return file_exists($bin) && is_executable($bin);
	}

	return false;
}

/**
 * 根据 ext 配置判断该 PHP 版本是本地 CGI（cmd:）还是远程 FPM（server:）
 */
function php_get_handler_prefix($version)
{
	$cfg = $GLOBALS['safe_dir'] . '../ext/tpl_' . $version . '/config.xml';
	if (!file_exists($cfg)) {
		return 'cmd:';
	}
	$content = @file_get_contents($cfg);
	if ($content === false) {
		return 'cmd:';
	}
	if (stripos($content, '<server') !== false) {
		return 'server:';
	}
	return 'cmd:';
}

function php_destroy($params)
{
}

function php_link($params)
{
	$versions = php_get_version();

	if (1 < count($versions)) {
		$vhostinfo = apicall('vhostinfo', 'get2', array(getRole('vhost'), 'moduleversion', 101));
		$value = $vhostinfo['value'];
		$str = '<form action=\'?c=index&a=module&op=php_version\' method=\'POST\'>切换php版本:<select name=v>';

		foreach ($versions as $k => $v) {
			$str .= '<option value=\'' . $k . '\' ';

			if ($value == $k) {
				$str .= 'selected';
			}

			$str .= '>' . $v . '</option>';
		}

		$str .= '</select><input value=\'确定\' type=\'submit\'></form>';
	}

	return $str;
}

function php_update($params)
{
}

function php_cron($params)
{
}

function php_call($params)
{
	if ($_REQUEST['op'] == 'php_version') {
		$v = trim($_REQUEST['v']);
		if(empty($v))return false;
		$vhost = getRole('vhost');
		$ver = php_get_version();
		if(!array_key_exists($v, $ver)) return;

		if (!is_win()) {
			@unlink('/vhs/kangle/phpini/php-'.$vhost.'.ini');
		}

		$prefix = php_get_handler_prefix($v);
		$arr['value'] = '1,' . $prefix . $v . ',*';

		if (!apicall('vhost', 'updateInfo', array($vhost, '1,php', $arr, 3))) {
		}

		if (!apicall('vhostinfo', 'set2', array($vhost, 'moduleversion', 101, $v))) {
		}
	}
}

function php_get_cli_version()
{
	// 主容器仅内置 php7.4 CLI；额外 PHP 版本在独立容器，无容器内 CLI
	if (file_exists('/usr/bin/php74')) {
		return 'php74';
	}
	return null;
}

function php_set_cli_version($version)
{
	// 仅允许设置 php74；空值时删除 /usr/bin/php 软链
	if (empty($version) || $version == 'php74') {
		if (empty($version)) {
			shell_exec('rm -f /usr/bin/php');
		} else {
			shell_exec('ln -sf /usr/bin/php74 /usr/bin/php');
		}
	}
}