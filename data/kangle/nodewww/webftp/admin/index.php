<?php
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');
define('APPLICATON_ROOT', dirname(__FILE__));
define('SYS_ROOT', dirname(dirname(__FILE__)) . '/framework');
define('DEFAULT_CONTROL', 'index');
include SYS_ROOT . '/runtime.php';
$tpl = TPL::singleton();
$tpl->assign('title', getTitle());
$dbadmin_url = 'http://' . $_SERVER['SERVER_NAME'] . ':3313/';
if (function_exists('is_https') && is_https()) {
    $dbadmin_url = 'https://' . $_SERVER['SERVER_NAME'] . ':4413/';
}
$tpl->assign('dbadmin_url', $dbadmin_url);
loadSetting($tpl);
startFramework();

?>