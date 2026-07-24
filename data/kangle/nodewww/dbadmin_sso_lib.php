<?php
/**
 * phpMyAdmin 单点登录令牌工具（签发 / 校验）
 * 被 vhost 面板 dbadmin() 与 phpMyAdmin SignonScript 共同包含。
 * 令牌格式： base64url(dbName) . base64url(exp) . HMAC_SHA256(payload, SECRET)
 * 令牌仅携带 db 名与过期时间，绝不携带任何密码。
 */

if (!function_exists('pma_sso_base64url_encode')) {
    /**
     * base64url 编码（URL 安全，去填充）
     *
     * @param string $data 原始数据
     * @return string
     */
    function pma_sso_base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('pma_sso_base64url_decode')) {
    /**
     * base64url 解码
     *
     * @param string $data base64url 字符串
     * @return string|false
     */
    function pma_sso_base64url_decode($data)
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

if (!function_exists('pma_sso_sign')) {
    /**
     * 签发单点登录令牌
     *
     * @param string $dbName 目标 vhost 数据库名
     * @return string
     */
    function pma_sso_sign($dbName)
    {
        $payload = pma_sso_base64url_encode($dbName) . '.'
            . pma_sso_base64url_encode((string) (time() + PMA_SSO_TOKEN_TTL));
        $sig = hash_hmac('sha256', $payload, PMA_SSO_SECRET);
        return $payload . '.' . $sig;
    }
}

if (!function_exists('pma_sso_verify')) {
    /**
     * 校验单点登录令牌
     *
     * @param string $token     令牌
     * @param string $expectDb  期望的数据库名（可选，用于防越权）
     * @return string|false     校验通过返回 dbName，否则返回 false
     */
    function pma_sso_verify($token, $expectDb = '')
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }
        list($b64Db, $b64Exp, $sig) = explode('.', $token);
        $payload = $b64Db . '.' . $b64Exp;
        $expected = hash_hmac('sha256', $payload, PMA_SSO_SECRET);
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $dbName = pma_sso_base64url_decode($b64Db);
        $exp = (int) pma_sso_base64url_decode($b64Exp);
        if ($exp < time()) {
            return false;
        }
        if ($expectDb !== '' && $expectDb !== $dbName) {
            return false;
        }
        return $dbName;
    }
}
