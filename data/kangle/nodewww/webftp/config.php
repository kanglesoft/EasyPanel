<?php
$GLOBALS['safe_dir'] = '/vhs/kangle/etc/';
$GLOBALS['db_cfg']['default']=array('dsn'=>'sqlite:'.$GLOBALS['safe_dir'].'vhs.db');
$GLOBALS['node_db']='sqlite';
define(DAO_SQLITE_DRIVER,1);
@include_once $GLOBALS['safe_dir'].'node.cfg.php';

// ↓ C3 / AD-6：凭据读取的环境变量覆盖钩子（SEC-14 / SEC-17）
// 优先级链：环境变量 → node.cfg.php 中的值 → .example 模板默认值。
//
// 为什么放在这里：本文件是 node.cfg.php 的唯一加载点，且由 framework/runtime.php
// 在所有 runtime 函数定义完成之后才 include，因此这里可以安全调用 runtime.php
// 新增的 node_cfg_apply_env()。
//
// 用 function_exists() 兜底：万一某条路径先于 runtime.php 载入本文件，
// 也只是「不覆盖」，不会致命。
if (function_exists('node_cfg_apply_env')) {
	node_cfg_apply_env();
}
