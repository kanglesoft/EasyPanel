<?php
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');
define('APPLICATON_ROOT', dirname(__FILE__));
define('SYS_ROOT', dirname(dirname(__FILE__)) . '/framework');
define('DEFAULT_CONTROL', 'index');
include SYS_ROOT . '/runtime.php';
$tpl = TPL::singleton();
$tpl->assign('title', getTitle());
/*
 * 管理面板的 MySQL 管理入口固定带 pma_admin=1：
 * phpMyAdmin 侧据此切换为自带 cookie 登录页（管理员输入 MySQL 账号密码），
 * 并清除可能残留的用户面板 SSO 令牌，避免被 signon 模式重定向到 /vhost。
 */
$dbadmin_url = 'http://' . $_SERVER['SERVER_NAME'] . ':3313/?pma_admin=1';
if (function_exists('is_https') && is_https()) {
    $dbadmin_url = 'https://' . $_SERVER['SERVER_NAME'] . ':4413/?pma_admin=1';
}
$tpl->assign('dbadmin_url', $dbadmin_url);
loadSetting($tpl);
startFramework();

?>