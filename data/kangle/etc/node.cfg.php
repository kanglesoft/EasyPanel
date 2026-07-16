<?php
// node.cfg.php —— easypanel 节点（localhost）配置
// 预置说明：kangle + easypanel 同容器运行，whm 通信走 localhost:3311；
//   实际的 vhost 数据库由独立 mysql 容器承载，故 db_host 指向 compose 服务名 "mysql"。
// 此文件在 easypanel 首次登录（安装）时被读取并沿用，db_host/db_user/db_passwd 保持不变。
$GLOBALS['node_cfg']['localhost']=array('name'=>"localhost",'host'=>"localhost",'port'=>"3311",'user'=>"admin",'passwd'=>"b4a65bcb790cae4b9c0e7fedbbf5f603cc95118edc5027cc",'db_type'=>"mysql",'db_host'=>"mysql",'db_user'=>"root",'db_passwd'=>"bea24c89d7949951699c206af01b322f5538275ce5ab6a09",'win'=>"0",'dev'=>"",'type'=>"kangle");
?>
