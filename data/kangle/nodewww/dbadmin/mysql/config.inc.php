<?php
/**
 * phpMyAdmin configuration for EasyPanel Docker environment
 * --------------------------------------------------------------------------
 * 双认证模式（按请求上下文自动切换）：
 *
 *   1) signon —— 用户面板（vhost）单点登录
 *      vhost 面板 dbadmin() 处理器授权专用 SSO 账号访问该 vhost 数据库，下发
 *      HMAC 签名的短时效令牌（HttpOnly Cookie pma_sso_token），phpMyAdmin 经
 *      SignonScript 校验后自动登录，用户无需再输密码。
 *
 *   2) cookie —— 管理面板（admin）/ 直接访问 :3313
 *      管理员点击「mysql管理」时链接带 pma_admin=1，此处清除可能残留的 SSO
 *      令牌并切换为 phpMyAdmin 自带登录页，由管理员输入 MySQL 账号密码登录。
 *
 * 为什么必须区分：signon 模式在令牌缺失时会强制跳转 SignonURL（用户面板
 * /vhost dbadmin 入口）。管理员本不该被送进用户面板，此前表现为「打开 MySQL
 * 管理却莫名跳到陌生地址」。现在只要没有 SSO 令牌，一律走 cookie 登录页，
 * 不再发生跨面板跳转。
 */

/* --------------------------------------------------------------------------
 * blowfish_secret：cookie 认证用于加密会话中的 MySQL 凭据，phpMyAdmin 5.x
 * 要求长度恰好 32 字节。为避免多机共用同一硬编码密钥带来的解密风险，此处
 * 优先读取机器本地密钥文件（位于 Web 根之外，不可通过 HTTP 访问），缺失时
 * 自动生成并持久化；无写权限时退回内置常量，保证功能不中断。
 * ------------------------------------------------------------------------ */
$pmaSecret = '';
$pmaSecretFile = '/vhs/kangle/etc/pma_blowfish.key';
if (@is_readable($pmaSecretFile)) {
    $pmaSecret = trim((string) @file_get_contents($pmaSecretFile));
}
if (strlen($pmaSecret) !== 32) {
    $pmaSecret = '';
    if (function_exists('random_bytes')) {
        try {
            $pmaSecret = substr(bin2hex(random_bytes(16)), 0, 32);
        } catch (Exception $e) {
            $pmaSecret = '';
        }
    }
    if ($pmaSecret === '') {
        $pmaSecret = substr(md5(uniqid('pma', true)), 0, 32);
    }
    if (@file_put_contents($pmaSecretFile, $pmaSecret) !== false) {
        @chmod($pmaSecretFile, 0600);
    } else {
        /* 无写权限：退回固定 32 字符常量，保证 cookie 认证可用 */
        $pmaSecret = 'EasyPanelDockerKanglePmaSecret32';
    }
}
$cfg['blowfish_secret'] = $pmaSecret;

/* --------------------------------------------------------------------------
 * 会话存储路径：kangle 容器 php.ini 未配置 session.save_path（为空），导致
 * PHP 会话无法落盘，进而使 cookie 模式登录无法持久化凭据——表现为「登录后
 * 立即被重定向回登录页、不下发会话 Cookie」。此处强制指向可写目录，确保
 * 管理面板「直接打开登录」的 cookie 认证可用。signon（SSO）模式不依赖 PHP
 * 会话，不受影响。
 * ------------------------------------------------------------------------ */
if (function_exists('ini_set')) {
    $_pmaSp = (string) @ini_get('session.save_path');
    if ($_pmaSp === '' || !@is_writable($_pmaSp)) {
        @ini_set('session.save_path', '/tmp');
    }
}

/* --------------------------------------------------------------------------
 * 认证模式判定
 * ------------------------------------------------------------------------ */
$pmaWantAdmin = isset($_REQUEST['pma_admin']);
$pmaHasSso = (!empty($_COOKIE['pma_sso_token']) || !empty($_REQUEST['pma_token']));

if ($pmaWantAdmin) {
    /*
     * 管理面板直连：主动过期 SSO 令牌 Cookie。
     * 必须清除——否则管理员若先用过用户面板的 MySQL 入口，浏览器仍持有令牌，
     * 会被判定为 signon；一旦令牌超时（TTL 120s）便再次跳转到用户面板。
     */
    if (!headers_sent()) {
        setcookie('pma_sso_token', '', array(
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    unset($_COOKIE['pma_sso_token']);
    $pmaHasSso = false;
}

$pmaAuthType = $pmaHasSso ? 'signon' : 'cookie';

/* --------------------------------------------------------------------------
 * Servers
 * ------------------------------------------------------------------------ */
$i = 0;

/* First server: MySQL 8 container */
$i++;
$cfg['Servers'][$i]['auth_type'] = $pmaAuthType;

if ($pmaAuthType === 'signon') {
    $cfg['Servers'][$i]['SignonScript'] = '/vhs/kangle/nodewww/dbadmin_signon.php';
    /*
     * SignonURL：令牌失效（超过 TTL）时回退至用户面板 dbadmin 入口重新签发。
     * 仅在「携带过 SSO 令牌」的会话中才可能触发，管理员直连不会走到这里。
     */
    $signonScheme = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') ? 'https' : 'http';
    $signonHost = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']) : '127.0.0.1';
    $signonPort = $signonScheme === 'https' ? '4412' : '3312';
    $cfg['Servers'][$i]['SignonURL'] = $signonScheme . '://' . $signonHost . ':' . $signonPort . '/vhost/?c=index&a=dbadmin';
} else {
    /* cookie 模式：展示 phpMyAdmin 自带登录页，管理员自行输入账号密码 */
    $cfg['Servers'][$i]['verbose'] = 'MySQL 8 (kangle)';
}

$cfg['Servers'][$i]['host'] = 'mysql';
$cfg['Servers'][$i]['port'] = '3306';
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['AllowRoot'] = true;

$cfg['ServerDefault'] = 1;

/* Directories for saving/loading files from server */
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['TempDir'] = '/tmp/';

/* 关闭外部版本检查：避免面板页面向 phpmyadmin.net 发起出站请求 */
$cfg['VersionCheck'] = false;

/* Session validity */
$cfg['LoginCookieValidity'] = 1440;
