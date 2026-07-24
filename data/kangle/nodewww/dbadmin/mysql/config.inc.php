<?php
/**
 * phpMyAdmin configuration for EasyPanel Docker environment
 * Auto-generated during UI refactor deployment.
 */

/* Blowfish secret for cookie auth (must be 32 chars for phpMyAdmin 5.x) */
$cfg['blowfish_secret'] = 'EasyPanel-Docker-kangle-phpMyAdmin-Secret';

/* Servers configuration */
$i = 0;

/* First server: MySQL 8 container */
$i++;
/*
 * 单点登录（signon）：点击 vhost 面板“MySQL”菜单时，easypanel 的 dbadmin()
 * 处理器会先授权专用 SSO 账号访问该 vhost 数据库，并下发一个 HMAC 签名的短时效
 * 令牌（pma_token）。phpMyAdmin 通过 SignonScript 校验令牌后自动登录，
 * 不再展示自身登录墙。令牌仅含 db 名与过期时间，密码不下发到浏览器。
 */
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonScript'] = '/vhs/kangle/nodewww/dbadmin_signon.php';
/*
 * SignonURL：当令牌缺失/失效（例如用户直接打开 :3313 未带 pma_token）时，
 * phpMyAdmin 会回退跳转至此重新发起单点登录；正常带令牌访问时不会触发本跳转。
 * 回退到 vhost 面板的 dbadmin 入口，由其重新签发令牌并跳回 phpMyAdmin。
 */
$signonScheme = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') ? 'https' : 'http';
$signonHost = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '127.0.0.1';
$signonPort = $signonScheme === 'https' ? '4412' : '3312';
$cfg['Servers'][$i]['SignonURL'] = $signonScheme . '://' . $signonHost . ':' . $signonPort . '/vhost/?c=index&a=dbadmin';
$cfg['Servers'][$i]['host'] = 'mysql';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = true;

/* Directories for saving/loading files from server */
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';

/* Session validity */
$cfg['LoginCookieValidity'] = 1440;
