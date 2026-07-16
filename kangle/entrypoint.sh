#!/bin/bash
# kangle/entrypoint.sh
# 主容器启动脚本（CentOS 7，无 systemd/supervisord）：
#   1) 重建运行时目录（data/kangle 的 tar 已排除 var/tmp）
#   2) 启动依赖服务：memcached + crond
#   3) 可选注入 kangle admin 密码（覆盖默认 'kangle'）
#   4) 注册 easypanel sync_flow 定时任务（用主容器内置 /usr/bin/php74 执行，自带 mysql 驱动）
#   5) 拉起 kangle，并用 tail -f 保持容器存活
set -e

# 运行时目录（tar 包已排除，需重建）
# 注意：easypanel 框架在 framework/runtime.php 中运行时强制
#   session_save_path(SYS_ROOT . '/../../tmp/')
# 即 /vhs/kangle/nodewww/tmp，该目录必须存在否则会话无法落盘、
# 后台登录后会被踢回登录页。一并预建 /vhs/kangle/sessions 作为通用兜底。
mkdir -p /vhs/kangle/var /vhs/kangle/tmp /vhs/kangle/nodewww/tmp /vhs/kangle/sessions
chmod 777 /vhs/kangle/nodewww/tmp /vhs/kangle/sessions 2>/dev/null || true
chown -R root:root /vhs/kangle 2>/dev/null || true
chmod -R u+w /vhs/kangle 2>/dev/null || true

# 确保 vhs.db 使用 easypanel 完整表结构。
# kangle 内置的 vhs_sqlite 在发现空库时会创建极简的 vhost/vhost_info 表，
# 缺少 certificate/sync_seq 等 easypanel 必需字段，导致后台 SSL、同步等功能异常。
# 因此在启动 kangle 前，若检测到 vhost 表缺少 certificate 字段，则备份后重新初始化。
VHS_DB="/vhs/kangle/etc/vhs.db"
VHS_SQL="/vhs/kangle/nodewww/webftp/admin/control/kangle.sql"
if [ -f /usr/bin/sqlite3 ] && [ -f "$VHS_SQL" ]; then
    HAS_CERT=$(sqlite3 "$VHS_DB" "PRAGMA table_info(vhost);" 2>/dev/null | grep -c '|certificate|' || true)
    if [ "${HAS_CERT:-0}" -eq 0 ]; then
        echo "[entrypoint] vhs.db schema incomplete (missing certificate column), reinitializing from kangle.sql..."
        [ -s "$VHS_DB" ] && cp "$VHS_DB" "$VHS_DB.bak.$(date +%s)"
        sqlite3 "$VHS_DB" "DROP TABLE IF EXISTS vhost; DROP TABLE IF EXISTS vhost_info;" 2>/dev/null || true
        sqlite3 "$VHS_DB" < "$VHS_SQL" 2>/dev/null || true
    fi
fi

# 生成默认 HTTPS 证书，保证 443 监听可以正常建立 TLS（SNI 会替换为各站真实证书）
DEFAULT_SSL_CRT="/vhs/kangle/etc/default_ssl.crt"
DEFAULT_SSL_KEY="/vhs/kangle/etc/default_ssl.key"
if [ ! -s "$DEFAULT_SSL_CRT" ] || [ ! -s "$DEFAULT_SSL_KEY" ]; then
    if command -v openssl >/dev/null 2>&1; then
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout "$DEFAULT_SSL_KEY" -out "$DEFAULT_SSL_CRT" \
            -subj /CN=default.kangle.local 2>/dev/null
        echo "[entrypoint] default SSL cert generated"
    else
        echo "[entrypoint] WARN: openssl not found, skip default SSL cert generation"
    fi
fi

# 安装 acme.sh（如尚未安装）
if [ ! -d /root/.acme.sh ]; then
    if command -v curl >/dev/null 2>&1; then
        echo "[entrypoint] installing acme.sh ..."
        curl -sL https://get.acme.sh | sh -s email=admin@example.com
        /root/.acme.sh/acme.sh --set-default-ca --server letsencrypt >/dev/null 2>&1 || true
    else
        echo "[entrypoint] WARN: curl not found, skip acme.sh install"
    fi
fi

# 安装 acme.sh 自动续签 cron（容器 crontab 不持久化，每次启动写入一次）
RENEW_SSL_CRON="0 3 * * * /vhs/kangle/bin/renew_ssl.sh >> /vhs/kangle/var/log/renew_ssl.log 2>&1"
if command -v crontab >/dev/null 2>&1; then
    (crontab -l 2>/dev/null | grep -v '/vhs/kangle/bin/renew_ssl.sh'; echo "$RENEW_SSL_CRON") | crontab -
    echo "[entrypoint] acme.sh renewal cron installed"
else
    echo "[entrypoint] WARN: crontab not found, skip renewal cron"
fi

# 启动依赖服务：memcached + crond（kangle 运行时依赖）
memcached -u root -d -m 64 2>/dev/null || echo "[entrypoint] WARN: memcached start failed"
/usr/sbin/crond 2>/dev/null || echo "[entrypoint] WARN: crond start failed"

# 可选：通过环境变量注入 admin 密码，覆盖默认 'kangle'（幂等：无论当前值是什么都改写为目标值）
if [ -n "$KANGLE_ADMIN_PASSWORD" ]; then
    sed -i -E "s/(<admin [^>]*password=')[^']*(')/\1$KANGLE_ADMIN_PASSWORD\2/" /vhs/kangle/etc/config.xml 2>/dev/null || true
    # 同步更新 easypanel 节点配置文件中的 WHM 密码
    if [ -f /vhs/kangle/etc/node.cfg.php ]; then
        sed -i -E "s#('passwd'=>')[^']*(')#\1$KANGLE_ADMIN_PASSWORD\2#g" /vhs/kangle/etc/node.cfg.php 2>/dev/null || true
    fi
    echo "[entrypoint] admin password injected"
fi

# 同步更新 easypanel 节点配置文件中的 MySQL root 密码
if [ -n "$MYSQL_ROOT_PASSWORD" ] && [ -f /vhs/kangle/etc/node.cfg.php ]; then
    sed -i -E "s#('db_passwd'=>')[^']*(')#\1$MYSQL_ROOT_PASSWORD\2#g" /vhs/kangle/etc/node.cfg.php 2>/dev/null || true
    echo "[entrypoint] db password injected"
fi

# 注册 easypanel sync_flow 定时任务（内嵌 php，自带 mysql 驱动，可连 mysql 容器）
PHP_BIN=""
for cand in /usr/bin/php74 /usr/bin/php; do
    if [ -x "$cand" ]; then PHP_BIN="$cand"; break; fi
done
if [ -n "$PHP_BIN" ] && [ -f /vhs/kangle/nodewww/webftp/framework/shell.php ]; then
cat > /etc/cron.d/ep_sync_flow <<EOF
*/5 * * * * root $PHP_BIN /vhs/kangle/nodewww/webftp/framework/shell.php sync_flow
EOF
    chmod 644 /etc/cron.d/ep_sync_flow
    echo "[entrypoint] sync_flow cron registered ($PHP_BIN)"
else
    echo "[entrypoint] WARN: skip sync_flow cron (php bin or shell.php not found)"
fi

# 拉起 kangle（与上游镜像行为一致：先拉起 kangle，再用 tail 保持容器存活）
echo "[entrypoint] starting kangle ..."
/vhs/kangle/bin/kangle 2>&1 || echo "[entrypoint] WARN: kangle exited with code $?"

exec tail -f /dev/null
