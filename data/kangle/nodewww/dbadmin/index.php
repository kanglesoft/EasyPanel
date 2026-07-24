<?php
/**
 * phpMyAdmin 入口重定向（修复问题[2]）
 *
 * 内置 _dbadmin vhost 的 doc_root 为 nodewww/dbadmin，而 phpMyAdmin 实际位于
 * nodewww/dbadmin/mysql。easypanel 后台“mysql管理”按钮链接到 :3313/ 根路径，
 * 原先因该目录无索引文件而返回 403。此文件让 :3313/ 与 :3313/index.php 直接
 * 302 跳转到 phpMyAdmin，消除 403 / 404。
 *
 * 注意：必须原样透传查询字符串（如 easypanel 下发的 pma_token 单点登录令牌）。
 * 否则令牌会在此 302 跳转中被丢弃，导致 phpMyAdmin 无法完成自动登录（回退到
 * SignonURL 形成重定向死循环）。
 */
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? '?' . $_SERVER['QUERY_STRING']
    : '';
header('Location: mysql/' . $qs);
exit;
