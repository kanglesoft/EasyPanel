<?php
$GLOBALS['safe_dir'] = '/vhs/kangle/etc/';
$GLOBALS['db_cfg']['default']=array('dsn'=>'sqlite:'.$GLOBALS['safe_dir'].'vhs.db');
$GLOBALS['node_db']='sqlite';
define(DAO_SQLITE_DRIVER,1);
@include_once $GLOBALS['safe_dir'].'node.cfg.php';
