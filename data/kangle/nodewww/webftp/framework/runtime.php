<?php
function get_number_version()
{
	$versions = explode('.', EASYPANEL_VERSION);
	return intval($versions[0] * 10000 + $versions[1] * 100 + $versions[2]);
}

function is_mobile_request()
{
	$_SERVER['ALL_HTTP'] = isset($_SERVER['ALL_HTTP']) ? $_SERVER['ALL_HTTP'] : '';
	$mobile_browser = 0;

	if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|iphone|ipad|ipod|android|xoom)/i', strtolower($_SERVER['HTTP_USER_AGENT']))) {
		++$mobile_browser;
	}

	if (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/vnd.wap.xhtml+xml') !== false) {
		++$mobile_browser;
	}

	if (isset($_SERVER['HTTP_X_WAP_PROFILE'])) {
		++$mobile_browser;
	}

	if (isset($_SERVER['HTTP_PROFILE'])) {
		++$mobile_browser;
	}

	$mobile_ua = strtolower(substr($_SERVER['HTTP_USER_AGENT'], 0, 4));
	$mobile_agents = array('w3c ', 'acs-', 'alav', 'alca', 'amoi', 'audi', 'avan', 'benq', 'bird', 'blac', 'blaz', 'brew', 'cell', 'cldc', 'cmd-', 'dang', 'doco', 'eric', 'hipt', 'inno', 'ipaq', 'java', 'jigs', 'kddi', 'keji', 'leno', 'lg-c', 'lg-d', 'lg-g', 'lge-', 'maui', 'maxo', 'midp', 'mits', 'mmef', 'mobi', 'mot-', 'moto', 'mwbp', 'nec-', 'newt', 'noki', 'oper', 'palm', 'pana', 'pant', 'phil', 'play', 'port', 'prox', 'qwap', 'sage', 'sams', 'sany', 'sch-', 'sec-', 'send', 'seri', 'sgh-', 'shar', 'sie-', 'siem', 'smal', 'smar', 'sony', 'sph-', 'symb', 't-mo', 'teli', 'tim-', 'tosh', 'tsm-', 'upg1', 'upsi', 'vk-v', 'voda', 'wap-', 'wapa', 'wapi', 'wapp', 'wapr', 'webc', 'winw', 'winw', 'xda', 'xda-');

	if (in_array($mobile_ua, $mobile_agents)) {
		++$mobile_browser;
	}

	if (strpos(strtolower($_SERVER['ALL_HTTP']), 'operamini') !== false) {
		++$mobile_browser;
	}

	if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows') !== false) {
		$mobile_browser = 0;
	}

	if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows phone') !== false) {
		++$mobile_browser;
	}

	if (0 < $mobile_browser) {
		return true;
	}

	return false;
}

function loadSetting($tpl)
{
	$tpl->assign('EASYPANEL_VERSION', EASYPANEL_VERSION);
	$partner_file = dirname(dirname(__FILE__)) . '/partner.txt';

	if (file_exists($partner_file)) {
		$line = file($partner_file);
		$partner_id = trim($line[0]);

		if ($partner_id != '') {
			$tpl->assign('partner_id', $partner_id);
		}
	}
}

/**
 * 按类型白名单过滤外部入参（H2 / HARD-01）
 *
 * 为什么必须修：原实现只有 `return trim($param);`，名为「过滤」实为空实现，
 * 所有历史调用点都误以为自己拿到了安全值。此处改为按 $type 走白名单正则，
 * 校验不通过统一返回空字符串，由调用方决定「拒绝」还是「取默认值」。
 *
 * 重要（SH-2）：本函数只做「入参合法性收敛」，**不是 shell 安全边界**。
 * 任何要拼进 shell 命令的变量，必须另外用 escapeshellarg() 包裹。
 *
 * 兼容说明：既有调用点实际用到的 $type 为 param / dir / url / mime / path，
 * 本实现覆盖了这些类型，并补充 int / alnum / word / domain 供新增代码使用。
 * 未知 $type 一律降级为 param（拒绝控制字符），绝不降级为「原样放行」。
 *
 * @param mixed  $param 待过滤的值；数组/null 一律返回空字符串
 * @param string $type  白名单类型：param|int|alnum|word|dir|path|url|mime|domain
 * @return string 校验通过返回 trim 后的原值；否则返回空字符串
 */
function filterParam($param, $type = 'param')
{
	if ($param === null || is_array($param) || is_object($param)) {
		return '';
	}

	$param = trim(strval($param));

	switch ($type) {
	case 'int':
		// 十进制整数，允许负号，禁止前导空白以外的一切字符
		return preg_match('/^-?[0-9]+$/', $param) ? $param : '';

	case 'alnum':
		// 纯字母数字：账号、标识类短字段
		return preg_match('/^[A-Za-z0-9]+$/', $param) ? $param : '';

	case 'word':
		// 字母数字下划线：控制器名、action 名、配置项键名
		return preg_match('/^[A-Za-z0-9_]+$/', $param) ? $param : '';

	case 'dir':
	case 'path':
		// 目录/路径：允许中英文、数字、常见文件名符号与空格；
		// 显式拒绝 '..'（目录穿越）与 shell 元字符（; | & $ ` " ' < > \ * ?）。
		// 用白名单而非黑名单：文件名的字符空间是开放的，但危险字符集是封闭的。
		if ($param === '') {
			return '';
		}

		if (strpos($param, '..') !== false) {
			return '';
		}

		return preg_match('/^[\\/\\p{L}\\p{N} ._+\\-@%(),=\\[\\]{}!]+$/u', $param) ? $param : '';

	case 'url':
		// URL：优先用 PHP 内置校验（自动拒绝空格、控制字符、非法 scheme）；
		// 另允许以 / 开头的站内相对路径。二者皆不满足则返回空。
		if ($param === '') {
			return '';
		}

		if (substr($param, 0, 1) === '/') {
			return preg_match('/^[\\/\\p{L}\\p{N}._~:\\/?#\\[\\]@!$&\'()*+,;=%\\-]+$/u', $param) ? $param : '';
		}

		return filter_var($param, FILTER_VALIDATE_URL) ? $param : '';

	case 'mime':
		// MIME 类型，形如 text/html、application/x-javascript
		return preg_match('/^[A-Za-z0-9!#$&^_.+\\-]+\\/[A-Za-z0-9!#$&^_.+\\-]+$/', $param) ? $param : '';

	case 'domain':
		// 复用既有域名校验，保证与 checkDomain() 判定口径一致
		return checkDomain($param) ? $param : '';

	case 'param':
	default:
		// 通用单行参数：只拒绝控制字符与换行（口令等自由文本仍需原样保留）
		if (preg_match('/[\\x00-\\x1F\\x7F]/', $param)) {
			return '';
		}

		return $param;
	}
}

function is_win()
{
	if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
		return true;
	}

	return false;
}

function isEnt()
{
	return defined('EP_ENT_KEYS') && defined('EP_ENT_EXPIRE') && defined('EP_ENT_HOST');
}

function setLastError($errormsg)
{
	$GLOBALS['last_error'] = $errormsg;
}

function __load_core($file, $dir = '', $return = false)
{
	global $__core_env;
	$tag = '';
	$pos = strpos($file, ':');

	if ($pos !== false) {
		$tag = substr($file, 0, $pos);
		$file = substr($file, $pos + 1);
	}

	if (!preg_match('/^[A-Za-z0-9_.-]+$/', $file, $ret)) {
		exit('incorrect file include');
	}

	if (!in_array(substr($file, 0, 1), array('/', '\\'))) {
		$file = '/' . $file;
	}

	$file = $dir . $file . '.php';

	if (substr($file, 0, 1) == '/') {
		$file = substr($file, 1);
	}

	switch ($tag) {
	case 'core':
		$file = SYS_ROOT . '/' . $file;
		break;

	case 'pub':
		$file = SYS_ROOT . '/' . $file;
		break;

	case 'app':
	default:
		$file = APPLICATON_ROOT . '/' . $file;
		break;
	}

	$__core_env['last_load_file'] = $file;

	if (file_exists($file)) {
		if ($return) {
			return $file;
		}

		include_once $file;
		return true;
	}

	trigger_error('文件不存在: ' . $file, E_USER_WARNING);
	return false;
}

function change_to_super()
{
	if (strtoupper(substr(PHP_OS, 0, 3)) != 'WIN') {
		if (function_exists('posix_seteuid')) {
			@posix_seteuid(0);
			@posix_setegid(0);
			return NULL;
		}
	}
	else {
		if (function_exists('win32_logout')) {
			win32_logout();
		}
	}
}

function change_to_user($user, $group)
{
	if (strtoupper(substr(PHP_OS, 0, 3)) != 'WIN') {
		if (function_exists('posix_seteuid')) {
			$ret = @posix_getgrnam($group);

			if (is_array($ret)) {
				$group = $ret['gid'];
			}

			$ret = @posix_getpwnam($user);

			if (is_array($ret)) {
				$user = $ret['uid'];
			}

			posix_setegid($group);
			posix_seteuid($user);

			if (posix_geteuid() != $user) {
				exit('程序指行出错,请联系管理员');
				return NULL;
			}
		}
	}
	else {
		if (!function_exists('win32_logon')) {
			return NULL;
		}

		if (!win32_logon($user, $group)) {
			exit('logon failed');
		}
	}
}

function __get_last_load()
{
	global $__core_env;
	return $__core_env['last_load_file'];
}

function load_lib($file)
{
	__load_core($file . '.lib', 'lib');
}

function load_conf($file)
{
	__load_core($file . '.cfg', 'configs');
}

function load_ctl($file)
{
	__load_core($file . '.ctl', 'control');
}

function load_api($file)
{
	__load_core('pub:' . $file . '.api', 'api');
}

function load_lng($file)
{
	__load_core('pub:' . $file . '.lng', 'lng');
}

function load_dao($file)
{
	__load_core('pub:' . $file . '.dao', 'dao');
}

function load_mod($name)
{
	$model_dir = defined(MODULE_DIR) == true ? MODULE_DIR : dirname(dirname(__FILE__)) . '/modules/';
	$model_dir .= '/' . $name;

	if (!file_exists($model_dir)) {
		exit($model_dir . ' 不存在');
	}

	include_once $model_dir . '/' . $name . '.php';
}

function ctlcall($module, $method, $args = array())
{
	$module = str_replace(array('-'), array('/'), $module);
	load_ctl($module);
	$pos = strrpos($module, '/');
	$class = $module;

	if (false !== $pos) {
		$class = substr($class, $pos + 1, 100);
	}

	$class[0] = strtoupper($class[0]);
	$className = $class . 'Control';
	return BaseCall('ctl', $className, $method, $args);
}

function getListDir($dir)
{
	$list = false;
	$op = opendir($dir);

	if (!$op) {
		trigger_error('不能打开目录 ' . $dir . ' 请检查');
		return false;
	}

	while ($read = readdir($op)) {
		if ($read == '.' || $read == '..') {
			continue;
		}

		if (substr($dir, 0 - 1) != '/') {
			$dir .= '/';
		}

		if (is_dir($dir . $read)) {
			$list[] = $read;
		}
	}

	closedir($op);
	return $list;
}

function modlist()
{
	$model_dir = defined(MODULE_DIR) == true ? MODULE_DIR : dirname(dirname(__FILE__)) . '/modules/';
	return getlistdir($model_dir);
}

function modcall($module, $function, $args = array())
{
	load_mod($module);

	if (function_exists($function)) {
		return call_user_func_array($function, $args);
	}

	return false;
}

function apicall($module, $method, $args = null)
{
	load_api($module);
	$className = exportClass($module, 'API');
	return BaseCall('api', $className, $method, $args);
}

function newapi($module)
{
	load_api($module);
	$className = exportClass($module, 'API');
	return Container::getinstance()->newObj($module, $className, true);
}

function daocall($module, $method, $args = null, $is_stat = true)
{
	load_dao($module);
	$className = exportClass($module, 'DAO');
	return BaseCall('dao', $className, $method, $args, false, $is_stat);
}

function newdao($module)
{
	load_dao($module);
	$className = exportClass($module, 'DAO');
	return Container::getinstance()->newObj($module, $className, true);
}

function exportClass($module, $lay)
{
	$module_clips = explode('_', $module);
	$className = '';

	foreach ($module_clips as $clip) {
		$clip[0] = strtoupper($clip[0]);
		$className .= $clip;
	}

	$className .= $lay;
	return $className;
}

function BaseCall($module, $className, $method, $args, $mul_mod = false, $is_stat = true)
{
	$start = 0;
	global $__core_env;
	$__core_env['DEBUG'] === true && $__core_env['STRACE'][$module . '/' . $className . '/' . $method]['start'] = microtime_float();
	$object = Container::getinstance()->newObj($module, $className, $mul_mod);

	if (method_exists($object, $method)) {
		if ($args && !is_array($args)) {
			debug_print_backtrace();
		}

		$result = call_user_func_array(array($object, $method), $args == null ? array() : $args);
		return $result;
	}

	return false;
}

function getRoles()
{
	global $_SESSION;
	return $_SESSION['janbao_role'];
}

function getRole($role)
{
	global $_SESSION;
	return $_SESSION['janbao_role'][$role];
}

function unregisterRole($role)
{
	global $_SESSION;
	unset($_SESSION['janbao_role_ip'][$role]);
	unset($_SESSION['janbao_role'][$role]);
}

function registerRole($role, $user)
{
	global $_SESSION;
	$_SESSION['janbao_role_ip'][$role] = $_SERVER['REMOTE_ADDR'];
	$_SESSION['janbao_role'][$role] = $user;

	// M5 / HARD-25：原实现为 `assert(isRole($role))`。
	// PHP 7 起 assert() 的行为由 zend.assertions 控制，生产环境默认值为 -1，
	// 断言会被完全跳过（零成本、不执行），该自检在生产环境形同虚设。
	// 改为显式判断 + 抛异常：会话写入结果一定被校验，且失败时不会被静默吞掉。
	// 清理半写入状态，避免留下「有 role 无 ip」的不一致会话。
	if (!isRole($role)) {
		unset($_SESSION['janbao_role'][$role]);
		unset($_SESSION['janbao_role_ip'][$role]);
		throw new Exception('registerRole 失败：会话写入后角色校验不通过（role=' . $role . '）');
	}
}

function isRole($role)
{
	$user = getrole($role);
	if ($user == null || $user == '') {
		return false;
	}

	return true;
}

function notice_cdn_changed($vh = null)
{
	if (!$vh) {
		$vh = getrole('vhost');
	}

	if (!$vh) {
		return false;
	}

	return apicall('vhost', 'updateVhostSyncseq', array($vh));
}

function setTitle($title)
{
	global $__core_env;
	$__core_env['title'] = $title;
}

function getTitle()
{
	global $__core_env;

	if ($__core_env['title'] == '') {
		if (file_exists('../config.php')) {
			$title = daocall('setting', 'get', array('title'));
		}

		$__core_env['title'] = $title ? $title : 'easypanel 虚拟主机控制面板';
	}

	return $__core_env['title'] . ' - Powered by ' . $__core_env['title'];
}

function getRandPasswd($len = 8)
{
	$base_passwd = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-0123456789';
	srand((double) microtime() * 1000000);
	$base_len = strlen($base_passwd);

	if ($len < 8) {
		$len = 8;
	}

	$passwd = 'K~w';
	$i = 0;

	while ($i < $len) {
		$passwd .= $base_passwd[rand() % $base_len];
		++$i;
	}

	return $passwd;
}

/**
 * 当前请求是否为 AJAX / 期望 JSON 响应。
 *
 * 老框架对未授权请求一律回一段「JS 跳转登录页」的 HTML 且 HTTP 200，
 * 这对页面请求可行，但对 $.post(...,'json') 这类调用有两个后果：
 *   1) 状态码 200 让调用方误判为成功；
 *   2) 返回 HTML 导致 JSON 解析失败，前端只能报「未知错误」，
 *      用户无法得知真实原因是「会话已过期」。
 * 因此按请求类型分流：AJAX 回 401 + JSON，页面请求保持原行为不变。
 *
 * @return bool
 */
function isAjaxRequest()
{
	if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
		&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
		return true;
	}

	// fetch() 默认不带 X-Requested-With，退而依据 Accept 判断
	if (isset($_SERVER['HTTP_ACCEPT'])
		&& stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
		&& stripos($_SERVER['HTTP_ACCEPT'], 'text/html') === false) {
		return true;
	}

	return false;
}

function needRole($role)
{
	if (!isrole($role)) {
		if ($_SERVER['QUERY_STRING'] == 'c=session&a=loginForm') {
			exit('');
		}

		if (isAjaxRequest()) {
			if (!headers_sent()) {
				header('HTTP/1.1 401 Unauthorized');
				header('Content-Type: application/json; charset=utf-8');
			}

			exit(json_encode(array(
				'success'  => false,
				'code'     => 401,
				'msg'      => '会话已过期或无权访问，请重新登录后再试。',
				'relogin'  => true,
				'loginUrl' => '?c=session&a=loginForm',
			)));
		}

		exit('<html><body><script language="javascript">window.top.location.href="?c=session&a=loginForm";</script></body></html>');
	}
}

function microtime_float()
{
	list($usec, $sec) = explode(' ', microtime());
	return (double) $usec + (double) $sec;
}

function startFramework()
{
	if (!defined('CORE_DAEMON')) {
		__dispatch_init();
		echo __dispatch_start();
	}
}

function checkIfActive($string) {
	$array=explode(',',$string);
	if (in_array($_GET['c'],$array)){
		return 'active';
	}elseif ($_GET['c']=='index' && in_array($_GET['a'],$array)){
		return 'active';
	}else
		return null;
}

function checkIfIn($string) {
	$array=explode(',',$string);
	if (in_array($_GET['c'],$array)){
		return 'in';
	}elseif ($_GET['c']=='index' && in_array($_GET['a'],$array)){
		return 'in';
	}else
		return null;
}

function checkDomain($domain){
	if(empty($domain) || !preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || substr($domain, -1) == '.' || substr($domain, 0 ,1) == '.' || substr($domain, 0 ,1) == '*' && substr($domain, 1 ,1) != '.' || substr_count($domain, '*')>1) return false;
	return true;
}

function checkIp($ip)
{
	if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		return false;
	}

	return true;
}

function is_https(){
	if(isset($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) == 'on' || $_SERVER['HTTPS'] === '1')){
		return true;
	}elseif(isset($_SERVER['HTTP_X_CLIENT_SCHEME']) && $_SERVER['HTTP_X_CLIENT_SCHEME'] == 'https'){
		return true;
	}elseif(isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'){
		return true;
	}
	return false;
}

/* ==========================================================================
 * 安全内核（T01）：CSRF 防护 / 审计日志 / 凭据读取
 *
 * 落点为什么是 runtime.php（AD-1）：本文件被所有入口（admin/index.php、
 * vhost/index.php、api/index.php…）无条件 include，且所有函数定义都排在
 * 末尾的引导段之前。因此这里新增的函数：
 *   ① 零加载风险，不需要引入新的 load_lib 约定；
 *   ② 在 runtime.php:551 加载 config.php 之前就已经全部定义完毕，
 *      故 config.php 与 node.cfg.php 中可以安全调用本文件的函数。
 * ========================================================================== */

/* --------------------------------------------------------------------------
 * CSRF 常量（SK-3：常量定义在 runtime.php）
 * ------------------------------------------------------------------------ */
if (!defined('CSRF_SESSION_KEY')) {
	/** session 中存放 token 的键名 */
	define('CSRF_SESSION_KEY', '_csrf_token');
}
if (!defined('CSRF_FIELD_NAME')) {
	/** token 的提交字段名（POST 字段 / 表单隐藏域） */
	define('CSRF_FIELD_NAME', '_csrf');
}
if (!defined('CSRF_HEADER_NAME')) {
	/** token 的请求头名，供 curl / 自动化验收使用（SEC-10/11） */
	define('CSRF_HEADER_NAME', 'X-CSRF-Token');
}
if (!defined('CSRF_REAUTH_PARAM')) {
	/** 二次密码确认的默认提交字段名 */
	define('CSRF_REAUTH_PARAM', 'reauth_pass');
}

if (!defined('CSRF_REAUTH_NONE')) {
	/** 二次密码确认强度：不需要 */
	define('CSRF_REAUTH_NONE', 0);
}
if (!defined('CSRF_REAUTH_GRACE')) {
	/**
	 * 二次密码确认强度：需要，但处于「前端尚未落地口令弹层」的宽限期。
	 * 传了但错误 → 403 拒绝；完全没传 → 放行，但写审计 result='observe'
	 * 并回 X-EP-CSRF: reauth-pending，便于下一轮按命中量翻转为 STRICT。
	 *
	 * 为什么需要这一档：ENFORCE 清单里 acmessl.remove / manynode.del /
	 * manynode.toggle 要求二次确认，但本轮（T01-T05）没有任何任务为它们
	 * 做前端口令弹层。若直接按 STRICT 阻断，这三项功能会永久不可用。
	 * GRACE 档让「语义已声明、强制尚未生效、去向可度量」三者兼得。
	 */
	define('CSRF_REAUTH_GRACE', 1);
}
if (!defined('CSRF_REAUTH_STRICT')) {
	/** 二次密码确认强度：需要且强制，未提供或错误一律 403 */
	define('CSRF_REAUTH_STRICT', 2);
}

/**
 * 生成或读取当前会话的 CSRF token（SEC-06）
 *
 * 幂等：同一会话内多次调用返回同一个值，不会每次刷新。
 *
 * @return string 64 位十六进制 token
 */
function csrf_token()
{
	global $_SESSION;

	// 长度校验一并做掉：历史会话里若存在被截断/非法的值，直接重建，
	// 避免用一个弱 token 去和攻击者可控的值做比较。
	if (isset($_SESSION[CSRF_SESSION_KEY])
		&& is_string($_SESSION[CSRF_SESSION_KEY])
		&& strlen($_SESSION[CSRF_SESSION_KEY]) >= 32) {
		return $_SESSION[CSRF_SESSION_KEY];
	}

	if (function_exists('random_bytes')) {
		try {
			$_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
		}
		catch (Exception $e) {
			// CSPRNG 不可用时退化为多重熵源拼接（仍远强于 rand()）
			$_SESSION[CSRF_SESSION_KEY] = md5(uniqid('', true) . microtime(true) . mt_rand() . serialize($_SERVER));
		}
	}
	else {
		$_SESSION[CSRF_SESSION_KEY] = md5(uniqid('', true) . microtime(true) . mt_rand() . serialize($_SERVER));
	}

	return $_SESSION[CSRF_SESSION_KEY];
}

/**
 * 判断本次请求进入前，会话里是否已经存在 token。
 *
 * 用途：登录场景需要区分「客户端根本没拿到过 token」与「客户端丢弃了 token」。
 * 必须在 csrf_token() 之前调用——csrf_token() 会就地创建，调用后就再也问不出来了。
 *
 * @return bool true 表示此前已下发过 token
 */
function csrf_token_existed()
{
	global $_SESSION;
	return isset($_SESSION[CSRF_SESSION_KEY]) && is_string($_SESSION[CSRF_SESSION_KEY]);
}

/**
 * 生成供原生 <form method="post"> 使用的隐藏域（AD-4）
 *
 * @return string 形如 <input type="hidden" name="_csrf" value="..." />
 */
function csrf_field()
{
	return '<input type="hidden" name="' . CSRF_FIELD_NAME . '" value="'
		. htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '" />';
}

/**
 * 从当前请求中提取客户端提交的 token
 *
 * 只接受 POST 字段与请求头，**不接受 GET 查询串**：
 * token 出现在 URL 里会随 Referer 外泄，等于放弃防护。
 *
 * @return string 未提交或类型异常时返回空字符串
 */
function csrf_request_token()
{
	$token = '';

	if (isset($_POST[CSRF_FIELD_NAME])) {
		$token = $_POST[CSRF_FIELD_NAME];
	}
	else if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
		$token = $_SERVER['HTTP_X_CSRF_TOKEN'];
	}

	// 数组（形如 ?_csrf[]=x）属于攻击性输入，直接判为「未提供」
	if (!is_string($token)) {
		return '';
	}

	return trim($token);
}

/**
 * 归一化「host[:port]」用于同源比对
 *
 * 为什么要剥掉默认端口：浏览器对 http://a.com/ 的 Origin 头不带 :80，
 * 而对 http://a.com:80/ 会带；不归一化会造成误判。
 *
 * @param string $url 绝对 URL
 * @return string 形如 example.com:3312；解析失败返回空字符串
 */
function csrf_origin_host($url)
{
	if (!is_string($url) || $url === '') {
		return '';
	}

	$parts = @parse_url($url);

	if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
		return '';
	}

	$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
	$host = strtolower($parts['host']);
	$port = isset($parts['port']) ? intval($parts['port']) : 0;

	if ($port > 0 && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
		$host .= ':' . $port;
	}

	return $host;
}

/**
 * 同源判定（Origin / Referer 兜底）
 *
 * 只在「CSRF token 缺失或不可用」的放行路径上作为补充判据使用：
 * token 才是主防线，同源检查用来堵住「攻击者页面发起的跨站 POST」。
 * 浏览器对跨站 POST 一定会带 Origin（较老浏览器至少带 Referer），
 * 因此这里足以识别出来自第三方页面的请求。
 *
 * @return bool true 表示同源、或无法判定（交由调用方决定放行）
 */
function csrf_same_origin()
{
	$host = isset($_SERVER['HTTP_HOST']) ? strtolower(strval($_SERVER['HTTP_HOST'])) : '';

	// 拿不到 Host（CLI / 内部调用）时不额外收紧，避免误伤
	if ($host === '') {
		return true;
	}

	// Origin 优先：规范上跨站与同站的 POST 都会带
	if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== '') {
		return csrf_origin_host($_SERVER['HTTP_ORIGIN']) === $host;
	}

	if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
		return csrf_origin_host($_SERVER['HTTP_REFERER']) === $host;
	}

	// 两者都不带：多为 curl 等自动化客户端，放行（它们本来也能直接带 token）
	return true;
}

/**
 * 校验本次请求携带的 CSRF token（SEC-08）
 *
 * 比较一律走 hash_equals()：普通 ==/=== 在字符串比较时会逐字节短路返回，
 * 耗时差异可被用于逐字节爆破 token；hash_equals() 是恒定时间比较。
 *
 * @param bool $silent true = 只返回布尔值，不做任何阻断动作（供 csrf_guard 分档决策）；
 *                     false = 校验失败时直接 403 + 审计 + 终止请求
 * @return bool 校验通过返回 true
 */
function csrf_check($silent = false)
{
	$token = csrf_token();
	$given = csrf_request_token();

	$ok = ($given !== '') && hash_equals($token, $given);

	if (!$ok && !$silent) {
		csrf_deny('CSRF 校验失败', 'token');
	}

	return $ok;
}

/**
 * 拒绝本次请求：403 + 统一 JSON + 审计，然后终止（5.4 统一返回结构）
 *
 * @param string $msg    返回给前端的提示
 * @param string $reason 写入审计 detail 的原因代码
 * @return void 本函数不返回（exit）
 */
function csrf_deny($msg, $reason = '')
{
	$detail = array('reason' => $reason, 'c' => csrf_current_control(), 'a' => csrf_current_action());

	if (!headers_sent()) {
		header('HTTP/1.1 403 Forbidden');
		header('Content-Type: application/json; charset=utf-8');
	}

	csrf_audit_result('deny', $detail);
	exit(json_encode(array('success' => false, 'msg' => $msg)));
}

/**
 * 取当前请求的控制器名（已归一化为「末段 + 小写」）
 *
 * dispatch 允许 `c=foo/bar` 与 `c=foo-bar`（ '-' 会被替换成 '/'），
 * 类名取最后一段；PHP 类名/方法名不区分大小写，故统一小写后比对更稳妥，
 * 也不会因为大小写变形（如 c=HOST）被绕过强制清单。
 *
 * @return string
 */
function csrf_current_control()
{
	global $__core_env;

	$c = isset($__core_env['control']) ? $__core_env['control'] : (isset($_REQUEST['c']) ? $_REQUEST['c'] : '');

	if (!is_string($c)) {
		return '';
	}

	$pos = strrpos($c, '/');

	if ($pos !== false) {
		$c = substr($c, $pos + 1);
	}

	return strtolower($c);
}

/**
 * 取当前请求的 action 名（小写）
 *
 * @return string
 */
function csrf_current_action()
{
	global $__core_env;

	$a = isset($__core_env['action']) ? $__core_env['action'] : (isset($_REQUEST['a']) ? $_REQUEST['a'] : '');

	if (!is_string($a)) {
		return '';
	}

	return strtolower($a);
}

/**
 * 查询某个 c/a 在强制清单中的「二次密码确认强度」
 *
 * 强制清单以静态数组形式定义在函数体内（5.3：不新建配置文件、不引入新加载约定）。
 * key 保持与控制器方法同名的驼峰写法以便检索比对；比对时统一转小写，
 * 因为 PHP 的方法名不区分大小写，`a=setsshport` 同样能命中 setSshPort()。
 *
 * @param string $c 控制器名（大小写不敏感）
 * @param string $a action 名（大小写不敏感）
 * @return int|null 命中返回 CSRF_REAUTH_* 之一；未命中（即不属于强制清单）返回 null
 */
function csrf_enforce_level($c, $a)
{
	// ENFORCE 强制清单（2.3.3）：value = 二次密码确认强度
	static $enforce = array(
		'host' => array(
			'setSshPort'         => CSRF_REAUTH_STRICT, // 宿主级；改错即失联
			'toggleSsh'          => CSRF_REAUTH_STRICT, // 宿主级；关闭即彻底失联
			'changeRootPassword' => CSRF_REAUTH_STRICT, // 宿主 root 凭据
		),
		'docker' => array(
			'start'   => CSRF_REAUTH_NONE,   // 宿主级（经 docker.sock）
			'stop'    => CSRF_REAUTH_STRICT, // 宿主级；可致业务中断
			'restart' => CSRF_REAUTH_STRICT, // 宿主级（本轮新增 action）
		),
		'filemanager' => array(
			'upload'      => CSRF_REAUTH_NONE,  // 写站点根，root 权限
			'writeFile'   => CSRF_REAUTH_NONE,  // 写站点根
			'mkdir'       => CSRF_REAUTH_NONE,  // 写站点根
			'rename'      => CSRF_REAUTH_NONE,  // 写站点根
			'copy'        => CSRF_REAUTH_NONE,  // 写站点根
			'move'        => CSRF_REAUTH_NONE,  // 写站点根
			'chmod'       => CSRF_REAUTH_NONE,  // 写站点根
			'compress'    => CSRF_REAUTH_NONE,  // 触发 7z 子进程
			'delete'      => CSRF_REAUTH_GRACE, // 不可逆删除
			'batchDelete' => CSRF_REAUTH_GRACE, // 不可逆批量删除
			'extract'     => CSRF_REAUTH_GRACE, // 解压可覆盖既有文件
		),
		'session' => array(
			'login'          => CSRF_REAUTH_NONE,   // 防登录 CSRF（攻击者以自己账号劫持管理员会话）
			'logout'         => CSRF_REAUTH_NONE,   // 防强制登出骚扰
			'changePassword' => CSRF_REAUTH_STRICT, // 管理员凭据（用既有 oldpasswd 字段）
		),
		'acmessl' => array(
			'apply'              => CSRF_REAUTH_NONE,  // 触发外部 CA 请求（有配额/频率风险）
			'applyToVhost'       => CSRF_REAUTH_NONE,  // 写宿主证书 + 重载 kangle
			'deployCertToDomain' => CSRF_REAUTH_NONE,  // 写宿主证书（本轮新增）
			'remove'             => CSRF_REAUTH_GRACE, // 删除证书
		),
		'manynode' => array(
			'add'          => CSRF_REAUTH_NONE,   // 变更全网同步拓扑
			'setLocalName' => CSRF_REAUTH_NONE,   // 变更主节点标识
			'del'          => CSRF_REAUTH_GRACE,  // 变更全网同步拓扑
			'toggle'       => CSRF_REAUTH_GRACE,  // 停用/启用多节点（本轮新增）
		),
	);

	/**
	 * action 名别名表：把「代码里真实存在的 action」映射到「设计文档登记的 action」。
	 *
	 * 已核实：manynode 控制器里真正的方法是 addLocalname()（小写 n），
	 * 设计文档登记的 setLocalName 在当前代码库中不存在。这里登记别名，
	 * 使真实 action 同样纳入强制清单，同时不改动文档登记的 28 项清单本身。
	 */
	static $alias = array(
		'manynode' => array('addLocalname' => 'setLocalName'),
	);

	$cl = strtolower($c);
	$al = strtolower($a);

	if ($cl === '' || $al === '' || !isset($enforce[$cl])) {
		return null;
	}

	// 直接命中：把清单 key 统一小写后比对，取回对应强度
	$lower_map = array_change_key_case($enforce[$cl], CASE_LOWER);

	if (isset($lower_map[$al])) {
		return $lower_map[$al];
	}

	if (isset($alias[$cl])) {
		foreach ($alias[$cl] as $real => $canonical) {
			if (strtolower($real) === $al) {
				return $enforce[$cl][$canonical];
			}
		}
	}

	return null;
}

/**
 * 判定 c/a 的 CSRF 策略档位（AD-3）
 *
 * @param string $c 控制器名
 * @param string $a action 名
 * @return string 'enforce' | 'observe' | 'skip'
 */
function csrf_policy($c, $a)
{
	// 空间用户侧（vhost/index.php 定义 VHOST_PATH）本轮不动，避免打挂用户面板
	if (defined('VHOST_PATH')) {
		return 'skip';
	}

	return csrf_enforce_level($c, $a) === null ? 'observe' : 'enforce';
}

/**
 * 该 c/a 是否需要二次密码确认
 *
 * @param string $c 控制器名
 * @param string $a action 名
 * @return bool
 */
function csrf_need_reauth($c, $a)
{
	$level = csrf_enforce_level($c, $a);
	return $level !== null && $level > CSRF_REAUTH_NONE;
}

/**
 * 取该 c/a 的二次密码确认强度（未命中强制清单时返回 CSRF_REAUTH_NONE）
 *
 * @param string $c 控制器名
 * @param string $a action 名
 * @return int CSRF_REAUTH_* 之一
 */
function csrf_reauth_level($c, $a)
{
	$level = csrf_enforce_level($c, $a);
	return $level === null ? CSRF_REAUTH_NONE : $level;
}

/**
 * 该 c/a 的二次密码确认凭据从哪个请求参数读取
 *
 * 为什么需要它：session.changePassword 的表单早已存在，且提交的旧密码字段名是
 * `oldpasswd`（见 admin/control/session.ctl.php:51）。若守卫另要求一个
 * reauth_pass 字段，改密功能会立刻 403——而这个功能本轮没有任何任务改前端。
 * 这里复用既有字段，既满足「改密必须验证旧口令」，又不需要动前端。
 *
 * @param string $c 控制器名
 * @param string $a action 名
 * @return string 请求参数名
 */
function csrf_reauth_param($c, $a)
{
	if (strtolower($c) === 'session' && strtolower($a) === 'changepassword') {
		return 'oldpasswd';
	}

	return CSRF_REAUTH_PARAM;
}

/**
 * 取面板当前管理员口令，用于二次密码确认比对
 *
 * 说明（A3）：该口令在 node.cfg.php 中是明文，因为 kangle 是闭源二进制，
 * WHM 鉴权必须拿到明文口令，结构上无法改为 password_hash()。
 * 因此二次确认只能做明文恒定时间比对，口令本体靠 C3（不入库 + chmod 600 +
 * 安装期强随机 + 环境变量注入）保护。
 *
 * @return string|null 取不到时返回 null（表示无法校验）
 */
function csrf_admin_password()
{
	if (!isset($GLOBALS['node_cfg']['localhost']['passwd'])) {
		return null;
	}

	$passwd = $GLOBALS['node_cfg']['localhost']['passwd'];

	if (!is_string($passwd) || $passwd === '') {
		return null;
	}

	return $passwd;
}

/**
 * 执行二次密码确认校验
 *
 * @param string $c 控制器名
 * @param string $a action 名
 * @return int 1 = 通过；0 = 提供了但错误；2 = 未提供（或面板口令不可知，无法校验）
 */
function csrf_reauth_check($c, $a)
{
	$param = csrf_reauth_param($c, $a);

	if (!isset($_REQUEST[$param])) {
		return 2;
	}

	$given = $_REQUEST[$param];

	// 数组型输入（?reauth_pass[]=x）按「错误」处理，不按「未提供」放行
	if (!is_string($given)) {
		return 0;
	}

	$expected = csrf_admin_password();

	if ($expected === null) {
		// 面板口令尚未初始化（首次安装流程），无从比对，交给业务层自行处理
		return 2;
	}

	return hash_equals($expected, $given) ? 1 : 0;
}

/**
 * 写一条 CSRF 维度的审计（5.5：module='csrf'，失败静默）
 *
 * @param string $result success | deny | error | observe
 * @param array  $detail 附加信息
 * @return bool
 */
function csrf_audit_result($result, $detail = array())
{
	return audit('csrf', csrf_current_action(), csrf_current_control() . '/' . csrf_current_action(), $result, $detail);
}

/**
 * CSRF 中央守卫（AD-2 / D1）
 *
 * 由 Control::__construct() 调用。选择这里作为唯一挂载点，是因为所有控制器
 * 都继承 Control 且构造函数被无条件调用（dispatch 里 `new $class()`），
 * 一处改动即可覆盖全部 31 个 ctl 文件，不需要逐个改造。
 *
 * 执行顺序（2.3.3）：
 *   1. 非 HTTP（CLI / 守护进程）→ SKIP
 *   2. 空间用户侧 VHOST_PATH → SKIP
 *   3. 非 POST → 只确保 token 已生成（供视图输出 meta），放行
 *   4. 查策略表：enforce → 校验失败 403 + 审计 deny；observe → 审计 + 响应头，放行
 *   5. 需要二次密码确认 → 按强度档位校验
 *
 * @return void
 */
function csrf_guard()
{
	// 一个请求只守一次：控制器之间可能通过 ctlcall/dispatch 互相嵌套实例化，
	// 重复守卫会造成重复审计与不必要的开销。c/a 取自请求本身，与具体实例无关。
	static $done = false;

	if ($done) {
		return;
	}

	$done = true;

	// 1) 命令行与守护进程没有「跨站请求」的概念
	if (PHP_SAPI === 'cli' || defined('CORE_DAEMON')) {
		return;
	}

	// 2) 空间用户侧本轮不动
	if (defined('VHOST_PATH')) {
		return;
	}

	/*
	 * 3) 机器对机器通道（webftp/api/index.php、webftp/api/da.php）跳过。
	 *
	 * 为什么：这两个入口在 dispatch 之前就由 verificationSkey() 做了
	 * md5(action + skey + random) 签名校验（共享密钥 HMAC 的等价物），
	 * 其认证强度高于浏览器 CSRF token；它们是 kangle ↔ 面板的服务端通道，
	 * 既没有浏览器环境，也不存在可被第三方页面借用的会话凭据。
	 * 对它们加 CSRF 没有安全收益，却会被 kangle 自身的同步流量刷满审计表。
	 *
	 * 判定方式：只有 api 入口会把 APPLICATON_ROOT 指向 .../webftp/api，
	 * admin / vhost 入口分别指向 .../webftp/admin 与 .../webftp/vhost。
	 * （shell.php / crontab.php 把该常量置为空串，不受影响。）
	 */
	if (defined('APPLICATON_ROOT')) {
		$entry_dir = basename(rtrim(strval(APPLICATON_ROOT), '/'));

		if ($entry_dir === 'api') {
			return;
		}
	}

	$c = csrf_current_control();
	$a = csrf_current_action();
	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

	// 3) 非写操作：仅确保 token 存在，使视图能输出 <meta name="csrf-token">
	if ($method !== 'POST' && $method !== 'PUT' && $method !== 'DELETE' && $method !== 'PATCH') {
		csrf_token();
		return;
	}

	$policy = csrf_policy($c, $a);

	if ($policy === 'enforce') {
		// 必须在 csrf_token() 之前取：csrf_token() 会就地创建 token，
		// 之后就无法区分「客户端没收到过 token」与「客户端丢弃了 token」了。
		$had_token = csrf_token_existed();
		$token = csrf_token();
		$given = csrf_request_token();

		/**
		 * 登录特例（session/login）：
		 * 若本次请求之前会话里根本没有 token，说明客户端从未从我们这里拿到过
		 * token——此时若直接拒绝，管理员会被彻底锁在门外（没有任何页面还能
		 * 再生成 token），属于不可接受的自伤。
		 *
		 * 放行条件因此收紧为三条同时成立：
		 *   ① 会话此前从未下发过 token（排除「已登录会话被劫持」这一登录 CSRF 前提）；
		 *   ② 客户端完全没提交 token（一旦提交，哪怕是错的，也回到严格比较）；
		 *   ③ 请求同源 —— 补上 token 缺席时的判据，挡住来自攻击者页面的跨站 POST。
		 * 放行一律写审计 result='observe'，去向可度量。
		 */
		if (!$had_token && $given === '' && $c === 'session' && $a === 'login') {
			if (!csrf_same_origin()) {
				csrf_deny('CSRF 校验失败', 'cross-origin-login');
			}

			csrf_audit_result('observe', array('reason' => 'login-fresh-session'));

			if (!headers_sent()) {
				header('X-EP-CSRF: observe');
			}

			return;
		}

		if ($given === '' || !hash_equals($token, $given)) {
			csrf_deny('CSRF 校验失败', 'token');
		}

		// token 通过后再看二次密码确认
		$level = csrf_reauth_level($c, $a);

		if ($level > CSRF_REAUTH_NONE) {
			$ret = csrf_reauth_check($c, $a);

			if ($ret === 0) {
				csrf_deny('二次密码确认失败', 'reauth');
			}

			if ($ret === 2 && $level === CSRF_REAUTH_STRICT) {
				csrf_deny('二次密码确认失败', 'reauth-missing');
			}

			if ($ret === 2 && $level === CSRF_REAUTH_GRACE) {
				// 前端口令弹层尚未落地 → 放行但留痕，下一轮据此翻转为 STRICT
				csrf_audit_result('observe', array('reason' => 'reauth-pending', 'c' => $c, 'a' => $a));

				if (!headers_sent()) {
					header('X-EP-CSRF: reauth-pending');
				}
			}
		}

		return;
	}

	if ($policy === 'observe') {
		// OBSERVE：不阻断，只量化敞口。
		// 因为 csrf.js 的 $.ajaxPrefilter 是全局注入，绝大多数管理端 POST 会自动
		// 带上 token，命中量预计很低——命中清单就是下一轮提升到 ENFORCE 的排序依据。
		$had_token = csrf_token_existed();
		$token = csrf_token();
		$given = csrf_request_token();

		if ($given === '' || !hash_equals($token, $given)) {
			csrf_audit_result('observe', array(
				'reason' => 'missing-or-invalid-token',
				'c'      => $c,
				'a'      => $a,
				'had'    => $had_token ? 1 : 0,
			));

			if (!headers_sent()) {
				header('X-EP-CSRF: observe');
			}
		}

		return;
	}
}

/* --------------------------------------------------------------------------
 * 凭据读取（C3 / AD-6）
 * ------------------------------------------------------------------------ */

/**
 * 环境变量 → node.cfg.php 字段的映射表
 *
 * 命名统一 EP_ 前缀（5.2）。映射集中在此，避免散落到各调用点后口径漂移。
 *
 * @return array array(array(环境变量, 节点名, 字段名), ...)
 */
function node_cfg_env_map()
{
	static $map = array(
		array('EP_ADMIN_USER', 'user'),
		array('EP_ADMIN_PASS', 'passwd'),
		array('EP_DB_HOST', 'db_host'),
		array('EP_DB_USER', 'db_user'),
		array('EP_DB_PASS', 'db_passwd'),
		array('EP_WHM_PORT', 'port'),
	);

	return $map;
}

/**
 * 用环境变量覆盖 node.cfg.php 中的凭据（C3 / SEC-17）
 *
 * 优先级链：环境变量 → node.cfg.php 既有值 → （安装期）.example 模板默认值。
 *
 * 注入点为什么是 config.php：config.php 是 node.cfg.php 的唯一加载点，
 * 且由 runtime.php 在所有 runtime 函数定义完成之后加载，所以这里可以安全地
 * 调用本文件的函数。这样「已部署环境」与「全新安装」两条路径共用同一段逻辑。
 *
 * 只覆盖 localhost 节点：node.cfg.php 只在安装期/首次登录时被读取与回写，
 * 其余节点信息由面板业务库承载，不涉及仓库内的明文凭据。
 *
 * @return int 实际被环境变量覆盖的字段数量
 */
function node_cfg_apply_env()
{
	// 节点尚未载入（未安装）时无从覆盖，交给安装流程自行生成
	if (!isset($GLOBALS['node_cfg']) || !is_array($GLOBALS['node_cfg'])) {
		return 0;
	}

	$map = node_cfg_env_map();
	$applied = 0;

	foreach ($map as $item) {
		$env_name = $item[0];
		$field = $item[1];
		$value = getenv($env_name);

		// getenv 在变量不存在时返回 false；空字符串视为「未配置」，
		// 否则 `EP_ADMIN_PASS=` 会把口令清成空，导致面板再也登不进去。
		if ($value === false || $value === '') {
			continue;
		}

		if (!isset($GLOBALS['node_cfg']['localhost']) || !is_array($GLOBALS['node_cfg']['localhost'])) {
			$GLOBALS['node_cfg']['localhost'] = array();
		}

		$GLOBALS['node_cfg']['localhost'][$field] = $value;
		++$applied;
	}

	return $applied;
}

/**
 * 读取节点配置项的统一入口
 *
 * 供后续任务（T02+）取值使用，避免各处直接访问 $GLOBALS 导致口径分散。
 *
 * @param string $field 字段名，如 user / passwd / db_host
 * @param string $node  节点名，默认 localhost
 * @param mixed  $default 取不到时的默认值
 * @return mixed
 */
function node_cfg_get($field, $node = 'localhost', $default = null)
{
	if (!isset($GLOBALS['node_cfg'][$node]) || !is_array($GLOBALS['node_cfg'][$node])) {
		return $default;
	}

	if (!array_key_exists($field, $GLOBALS['node_cfg'][$node])) {
		return $default;
	}

	return $GLOBALS['node_cfg'][$node][$field];
}

/* --------------------------------------------------------------------------
 * 审计日志便捷入口（FM-23 / HARD-07 的载体）
 * ------------------------------------------------------------------------ */

/**
 * 写一条审计日志
 *
 * 这是全项目推荐的审计写入方式（5.5）。**必须失败静默**：
 * 审计是旁路设施，绝不允许因为写审计失败而阻断业务。
 *
 * 做了三层防护保证不阻断业务：
 *   ① 无法加载 audit.api.php 时直接返回 false（避免 new 一个不存在的类导致致命错误）；
 *   ② 整个调用包在 try/catch(Throwable) 里，任何异常都吞掉；
 *   ③ AuditApi::write() 内部还会再做一次兜底。
 *
 * @param string $module host|docker|filemanager|session|ssl|cdn|csrf|vhost|acmessl|manynode
 * @param string $action 操作名，与 csrf_policy 的 a 保持一致
 * @param string $target 操作对象：路径 / 容器 ID / 域名 / 账号名 / '-'
 * @param string $result success|deny|error|observe
 * @param array  $detail 附加信息，会被 json_encode
 * @return bool 写入成功返回 true；任何失败返回 false
 */
function audit($module, $action, $target, $result, $detail = array())
{
	if (!defined('SYS_ROOT') || !function_exists('apicall')) {
		return false;
	}

	// 静态缓存「审计模块是否可用」，避免每次写入都做一次文件存在性判断
	static $available = null;

	if ($available === null) {
		load_api('audit');
		$available = class_exists('AuditApi', false);
	}

	if (!$available) {
		return false;
	}

	try {
		$ret = apicall('audit', 'write', array($module, $action, $target, $result, $detail));
		return $ret ? true : false;
	}
	catch (Throwable $e) {
		return false;
	}
}

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);
define('EASYPANEL_VERSION', '2.6.29');
define('PHP_DEFAULT_VERSION', 'php74');
define('IIS_DEFAULT_VERSION', 'v2.0.50727');
define('ASDF_10_BVCX', 'ZHNhZmRqb2ozbzBqZmQwb2p1WzA0LTIzOT0yMy09aWUtZmpvc2lkZ');
define('S_IFDIR', 16384);
define('EP_KEY_FILE', $GLOBALS['safe_dir'] . '../ep_license.txt');
@set_time_limit(0);

if (!defined('SYS_ROOT')) {
	trigger_error('未定义常量 SYS_ROOT.', E_USER_ERROR);
}

/**
 * 修复 CGI SAPI 下 exec()/system()/proc_close() 退出码恒为 -1 的问题。
 *
 * 现象（远端部署实测，本地静态排查与 CLI 复现均无法发现）：
 *   kangle 派生 php74-cgi（FastCGI worker）时会把 **SIGCHLD 置为 SIG_IGN**
 *   ——实测 /proc/<php74-cgi>/status 的 SigIgn=0x11000，即 SIGPIPE(13) + SIGCHLD(17)。
 *   按 POSIX 语义，SIGCHLD 为 SIG_IGN 时子进程退出状态被内核**直接丢弃**，
 *   PHP 的 waitpid() 随即返回 -1/ECHILD。
 *
 * 影响面（比表象严重得多——它不是「某个功能失效」，而是「所有依赖退出码的判断全部失效」）：
 *   - HostApi::hostOk()   → 恒 false ⇒ SSH 端口/状态/root 密码/一键开关 全部不可用
 *   - DockerApi           → `$r['code'] !== 0` 恒真 ⇒ 容器启停永远报失败
 *   - backup/restore、process.lib.php 等凡用 exec 退出码判成败的路径同样受影响
 *   注意：CLI SAPI（php74 命令行）下 SIGCHLD 处置正常，退出码无误，
 *   因此只在「容器内 Web 请求」路径暴露，本地跑 CLI 自测会得出「没问题」的错误结论。
 *
 * 对策：在公共引导层把 SIGCHLD 恢复为默认处置，让 waitpid() 重新可拿到状态。
 *   这是**根因修复**，一次生效覆盖全部 exec 调用点，无需逐一改造调用方。
 *   实测：调用后 exec("true")/exec("false")/exec("exit 42") 分别返回 0/1/42。
 *
 * 安全性：仅重置信号处置为默认，不安装任何处理器；PHP 的 exec/system/proc_open
 *   自身会 waitpid 回收子进程，因此不会产生僵尸进程。
 * 兜底：pcntl 不可用时静默跳过（退出码问题依旧存在，但不引入新的致命错误）。
 */
if (function_exists('pcntl_signal') && defined('SIGCHLD')) {
	@pcntl_signal(SIGCHLD, SIG_DFL);
}

global $__core_env;
change_to_super();
session_save_path(SYS_ROOT . '/../../tmp/');
/* T05-M1：会话 Cookie 安全属性。secure 跟随 is_https()（已识别代理的
 * X-Forwarded-Proto / X-Client-Scheme），HTTPS 终止于反向代理时也能正确标记；
 * httponly 阻断 JS 读取；samesite=Lax 缓解 CSRF。必须在 session_start 之前设置。 */
session_set_cookie_params(array(
	'lifetime' => 0,
	'path' => '/',
	'domain' => '',
	'secure' => is_https(),
	'httponly' => true,
	'samesite' => 'Lax'
));
session_start();
register_shutdown_function('session_write_close');
@include_once SYS_ROOT . '/../config.php';
$__core_env['DEBUG'] = false;
load_lng('zh');
__load_core('core:control');
__load_core('core:model');
__load_core('core:dao');
__load_core('core:api');
__load_core('core:tpl');
__load_core('core:container');
__load_core('core:dispatch');

?>