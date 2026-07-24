<?php
/**
 * phpMyAdmin 单点登录（SSO）共享密钥配置
 * --------------------------------------------------------------------------
 * 该文件位于 dbadmin 的 doc_root（nodewww/dbadmin）之外，不会被 Web 直接访问。
 * 同时被以下两处包含：
 *   1) easypanel vhost 面板 dbadmin() 处理器（签发令牌 + 授权 SSO 账号）
 *   2) phpMyAdmin 的 SignonScript（校验令牌、返回 SSO 账号凭据）
 * 注意：SSO 账号密码与 HMAC 密钥仅存于服务端，绝不下发到浏览器 / 令牌中。
 */

/* phpMyAdmin 单点登录专用 MySQL 账号（仅被授予对应 vhost 数据库权限） */
define('PMA_SSO_USER', 'easypanel_pma');

/* SSO 账号密码（服务端密钥，不在令牌 / 浏览器中出现） */
define('PMA_SSO_PASS', '6d904f05a873e0feea4ae7e89b8b2c4c');

/* 令牌签名密钥（HMAC-SHA256） */
define('PMA_SSO_SECRET', '7b690c3de3174bc5ae917c928888c3fc41f7de8da5726837');

/* 令牌有效期（秒） */
define('PMA_SSO_TOKEN_TTL', 120);
