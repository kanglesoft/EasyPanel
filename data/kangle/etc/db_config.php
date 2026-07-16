<?php
$skey = '';
$db_cfg['driver'] = 'sqlite';
$db_cfg['sql'] = "SELECT passwd,uid,gid,doc_root FROM vhost WHERE name='%s'";
$db_cfg['db'] = dirname(__FILE__).'/vhs.db';
