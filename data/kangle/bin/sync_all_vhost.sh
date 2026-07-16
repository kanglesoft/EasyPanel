#!/bin/sh
# 同步所有虚拟主机到 kangle（兼容仅安装 php74 的容器）
PHP_BIN="/usr/bin/php74"
[ -x "$PHP_BIN" ] || PHP_BIN="/usr/bin/php"
exec "$PHP_BIN" -c /vhs/kangle/ext/tpl_php74/php-templete.ini -f /vhs/kangle/nodewww/webftp/framework/shell.php sync_all_vhost
