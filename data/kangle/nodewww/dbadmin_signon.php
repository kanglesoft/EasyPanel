<?php
/**
 * phpMyAdmin 单点登录脚本（SignonScript）
 * --------------------------------------------------------------------------
 * phpMyAdmin 在 auth_type=signon 时会 include 本文件并调用 get_login_credentials()。
 * 本脚本校验 easypanel dbadmin() 下发的令牌（pma_token），校验通过则返回 SSO 账号
 * 凭据，使 phpMyAdmin 以“已被授权访问该 vhost 数据库”的账号自动登录；
 * 校验失败（无令牌 / 伪造 / 过期 / db 不匹配）则回退到 phpMyAdmin 登录页。
 */

if (!function_exists('get_login_credentials')) {
    /**
     * phpMyAdmin signon 回调：返回 [user, password]
     *
     * @param string $user 配置中的 Server user（此处恒为空，凭据由 SSO 决定）
     * @return array
     */
    function get_login_credentials($user)
    {
        $configFile = '/vhs/kangle/nodewww/dbadmin_sso_config.php';
        $libFile = '/vhs/kangle/nodewww/dbadmin_sso_lib.php';

        if (!file_exists($configFile) || !file_exists($libFile)) {
            return array('', '');
        }

        include_once $configFile;
        include_once $libFile;

        /*
         * 令牌优先取自 HttpOnly Cookie（由 easypanel dbadmin() 在跳转到 phpMyAdmin
         * 前种下）。采用 Cookie 而非 URL 参数，是因为 phpMyAdmin 在内部 reload 重定向
         * 时会剥离全部查询参数，URL 令牌无法存活；Cookie 随同域请求自动携带。
         * 同时兼容 URL 参数 pma_token（降级兜底）。
         */
        $token = '';
        if (isset($_COOKIE['pma_sso_token'])) {
            $token = (string) $_COOKIE['pma_sso_token'];
        } elseif (isset($_REQUEST['pma_token'])) {
            $token = (string) $_REQUEST['pma_token'];
        }

        $expectDb = isset($_REQUEST['db']) ? (string) $_REQUEST['db'] : '';

        $dbName = pma_sso_verify($token, $expectDb);
        if ($dbName === false) {
            return array('', '');
        }

        return array(PMA_SSO_USER, PMA_SSO_PASS);
    }
}
