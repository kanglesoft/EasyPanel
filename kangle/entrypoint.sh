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

# ── /vhs/kangle/bin MySQL 客户端与 wget 软链自愈（备份空壳缺陷修复的一部分）──
# data/kangle/bin/{mysqldump,mysql,mysqlbinlog,wget} 是指向 /usr/bin/… 的绝对软链
# （backup/restore/cron API 以 kangle_bin_dir 拼路径调用它们），真实二进制由镜像层
# 提供：MySQL 8.4.7 community client RPM（Dockerfile）+ yum wget。
# 历史镜像未装 mysql 客户端 → 软链悬空 → mysqldump 127 被管道吞 → .sql.7z 全为空壳。
# 此处每次启动按镜像实际存在性重建缺失/悬空的链接（ln 幂等），镜像缺二进制时仅告警，
# 不阻断启动（备份属尽力而为功能）。老部署若 bin/ 目录被手工放过真实文件则不动。
for _bin in mysqldump mysql mysqlbinlog wget; do
    _link="/vhs/kangle/bin/${_bin}"
    _target="/usr/bin/${_bin}"
    if [ -e "$_link" ] && [ ! -L "$_link" ]; then
        continue  # 已是真实文件（老镜像遗留），尊重现状不覆盖
    fi
    if [ -x "$_target" ]; then
        if [ ! -x "$_link" ]; then
            rm -f "$_link" 2>/dev/null || true
            if ln -s "$_target" "$_link" 2>/dev/null; then
                echo "[entrypoint] repaired /vhs/kangle/bin/${_bin} -> ${_target}"
            else
                echo "[entrypoint] WARN: cannot repair /vhs/kangle/bin/${_bin} (read-only bind mount?)"
            fi
        fi
    elif [ -L "$_link" ]; then
        echo "[entrypoint] WARN: ${_target} missing in image, /vhs/kangle/bin/${_bin} is dangling"
    fi
done

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

# ── 系统计划任务重建通道（单元 admin-system-cron）──
# /etc/cron.d 属容器运行时层，镜像重建/容器 recreate 即丢失；面板的「计划任务」
# （admin_cron 表 → /etc/cron.d/ep_admincron）与其他单元的定时任务定义持久化在
# vhs.db（bind 挂载），因此每次启动都要按库重放一次。cronresync.php 依次：
#   1) @apicall('admincron','install_all')（归属单一：admincron.api.php，评审#6）
#   2) framework/api/{healthprobe,certtask,remotestorage}.api.php 存在才调 install_cron
# 只依赖 sqlite（vhs.db），放在 MySQL 等待之前安全；任何失败仅告警不阻断启动
# （entrypoint 处于 set -e 之下，必须 || 兜底）。
CRONRESYNC="/vhs/kangle/nodewww/webftp/framework/cronresync.php"
if [ -n "$PHP_BIN" ] && [ -f "$CRONRESYNC" ]; then
    # set -e 之下必须用 if 保护：cronresync 失败只告警，绝不阻断容器启动。
    # 不用 `php | sed`：管道退出码是 sed 的，php 的失败会被吞掉。
    CRONRESYNC_LOG=$(mktemp 2>/dev/null || echo /tmp/cronresync.log)
    if "$PHP_BIN" "$CRONRESYNC" >"$CRONRESYNC_LOG" 2>&1; then
        sed 's/^/[entrypoint][cronresync] /' "$CRONRESYNC_LOG"
        echo "[entrypoint] cronresync done"
    else
        CRONRESYNC_RC=$?
        sed 's/^/[entrypoint][cronresync] /' "$CRONRESYNC_LOG"
        echo "[entrypoint] WARN: cronresync exited rc=$CRONRESYNC_RC, system crons may be missing until next save"
    fi
    rm -f "$CRONRESYNC_LOG" 2>/dev/null || true
else
    echo "[entrypoint] WARN: skip cronresync (php bin or cronresync.php not found)"
fi

# ── 健壮的 MySQL 就绪等待（修复问题[1]：避免 kangle/easypanel 初始化时 MySQL 尚未真正可用，
#    出现 "dependency mysql failed to start" / easypanel 连库竞态）──
# 说明：docker-compose 的 depends_on: condition: service_healthy 仅保证 mysqladmin ping 通过，
#    但 mysql 在“healthy”后仍需极短时间为 root 应用权限/刷新授权表，此时 easypanel 立即连库可能瞬时失败。
#    这里用 kangle 内置 php74（带 mysqli）真正连一次库并跑 SELECT 1，连不上就重试最多 N 次（默认 120s），
#    全部失败也只是告警后继续启动（不阻断容器），由 easypanel 后续自愈重试。
wait_for_mysql()
{
	local max_attempts=60          # 60 * 2s ≈ 120s
	local attempt=0
	local host="${MYSQL_HOST:-mysql}"
	local port="${MYSQL_PORT:-3306}"
	local pass="${MYSQL_ROOT_PASSWORD:-}"
	local php="$PHP_BIN"
	[ -z "$php" ] && php="$(command -v php74 2>/dev/null || command -v php 2>/dev/null || true)"

	# CDN-only 模式：未安装网站环境（无 MySQL 容器），连库等待无意义，直接跳过。
	# 面板自身的数据层是 sqlite（vhs.db），不依赖 MySQL，故不影响面板与 CDN 功能。
	if [ "${CDN_ONLY:-0}" = "1" ]; then
		echo "[entrypoint] CDN_ONLY=1：未安装网站环境，跳过 MySQL 就绪等待"
		return 0
	fi

	if [ -z "$php" ]; then
		echo "[entrypoint] WARN: 未找到 PHP 运行时，跳过 MySQL 就绪等待"
		return 0
	fi
	if [ -z "$pass" ]; then
		echo "[entrypoint] WARN: MYSQL_ROOT_PASSWORD 未设置，跳过 MySQL 就绪等待"
		return 0
	fi

	echo "[entrypoint] 等待 MySQL ($host:$port) 就绪（最多 ${max_attempts} 次）..."
	while [ "$attempt" -lt "$max_attempts" ]; do
		attempt=$((attempt + 1))
		if "$php" -r "try { \$c = new mysqli('$host','root','$pass','','', (int)$port); if (\$c->connect_error) exit(1); \$c->query('SELECT 1'); exit(0);} catch (\Throwable \$e) { exit(1);}" >/dev/null 2>&1; then
			echo "[entrypoint] MySQL 已就绪（第 ${attempt} 次尝试）"
			return 0
		fi
		sleep 2
	done
	echo "[entrypoint] WARN: 等待 MySQL 超时，仍尝试启动 kangle（easypanel 会自行重试连库）"
	return 0
}
wait_for_mysql

# ── 启动 pure-ftpd（修复 webftp 文件管理白屏问题）──
# webftp 底层通过容器内部 localhost:21 访问 pure-ftpd；easypanel 的 FTP 认证
# 由 pure-authd 调用 /vhs/kangle/bin/pureftp_auth（从 sqlite vhs.db 按 vhost name
# 校验 md5 密码、返回 doc_root 作为家目录）完成。FTP 服务缺失则 webftp 白屏。
# 镜像内已通过 Dockerfile 预置 /vhs/pure-ftpd/sbin/{pure-ftpd,pure-authd}。
start_ftp()
{
    local FT="${PURE_FTPD_BIN:-/vhs/pure-ftpd/sbin/pure-ftpd}"
    local AUTH="${PURE_AUTH_BIN:-/vhs/pure-ftpd/sbin/pure-authd}"
    local AUTH_SCRIPT="${PUREFTP_AUTH:-/vhs/kangle/bin/pureftp_auth}"
    local SOCK="/var/run/ftpd.sock"

    if [ ! -x "$FT" ] || [ ! -x "$AUTH" ]; then
        echo "[entrypoint] WARN: pure-ftpd 未安装（镜像缺少二进制），跳过 FTP 启动（webftp 将不可用）"
        return 0
    fi
    if [ ! -x "$AUTH_SCRIPT" ]; then
        echo "[entrypoint] WARN: pureftp_auth 验证器缺失（$AUTH_SCRIPT），跳过 FTP 启动"
        return 0
    fi

    # 清理上一次遗留的进程与 socket，避免重建容器后重复拉起 / socket 被占用
    pkill -x pure-ftpd 2>/dev/null || true
    pkill -x pure-authd 2>/dev/null || true
    sleep 1
    rm -f "$SOCK" 2>/dev/null || true

    # 先拉起认证代理（extauth 后端），再拉起 pure-ftpd 主服务
    "$AUTH" --daemonize -s "$SOCK" -r "$AUTH_SCRIPT" 2>/dev/null
    # pure-ftpd: -l extauth 走外部认证；-B 后台守护；-H 不解析 DNS；
    # -A 限制用户在家目录（chroot）；-E 禁止匿名登录
    "$FT" --daemonize -l extauth:"$SOCK" -A -E -H -B 2>/dev/null

    # 确认 21 端口已就绪（容器内 ss/netstat 常返回空，用 /dev/tcp 探活）
    sleep 1
    if (exec 3<>/dev/tcp/127.0.0.1/21) 2>/dev/null; then
        exec 3>&- 2>/dev/null || true
        echo "[entrypoint] pure-ftpd started (port 21 listening, extauth backend)"
    else
        echo "[entrypoint] WARN: pure-ftpd 启动后 21 端口未监听，webftp 可能不可用"
    fi
}
start_ftp

# ── geo-region-blocking：地区封禁内核侧就位（幂等，每次启动检查）──
# 内核 mark_geo（KGeoMark）数据文件是文本格式：
#   *大洲*国家*省份   ← 标签行（段用 * 分隔，段名须与面板地区树一致）
#   起始IP-结束IP      ← 其后 IP 范围行
# 全局 request BEGIN 链挂 <mark_geo file=... name='_geo'/> 后面板
# 「地区封禁」页（vhost/?c=geoip）才会开放。两处随仓预置：
#   data/kangle/etc/geo-ipdata.txt（种子库）与 config.xml（链）。
# 但既有部署的 /vhs/kangle/etc 是 bind 挂载、面板升级/rsync 从不覆盖
# config.xml 与运行时 etc 内容，所以这里兜底：缺文件补写内联种子库、
# config.xml 无 mark_geo 时在首个 <table name='BEGIN'> 后插入链。
# 全新部署随仓即得，本函数两项探测都会直接跳过。
ensure_geo_mark() {
    GEO_DATA=/vhs/kangle/etc/geo-ipdata.txt
    GEO_CFG=/vhs/kangle/etc/config.xml
    [ -f "$GEO_CFG" ] || return 0

    if [ ! -f "$GEO_DATA" ]; then
        # 注意：内核解析器只认 *标签行 与 IP范围行 两种，注释/空行会被当成
        # 范围行破坏上一条标签的归属，故种子内容不得含任何非数据行。
        cat > "$GEO_DATA" << 'GEODATA'
*亚洲*中国大陆*北京
1.2.4.0-1.2.4.255
223.5.5.0-223.5.5.255
*亚洲*中国大陆*广东
203.107.1.0-203.107.1.255
*亚洲*中国大陆
114.114.0.0-114.114.255.255
*亚洲*日本
133.11.0.0-133.11.255.255
*北美洲*美国*加利福尼亚
8.8.4.0-8.8.4.255
*北美洲*美国
8.8.8.0-8.8.8.255
*欧洲*德国
78.46.102.0-78.46.102.255
*欧洲*英国
213.171.200.0-213.171.200.255
GEODATA
        echo "[entrypoint] geo seed data written to $GEO_DATA"
    fi

    if ! grep -q 'mark_geo' "$GEO_CFG"; then
        # 位置硬约束：必须挂在 mark_replace_ip（X-Real-Ip 还原）之后，否则
        # mark_geo 拿连接 IP 查库，反代/伪造头场景下标签全错（远端实测：
        # 挂 BEGIN 首位时 X-Real-Ip  spoof 不生效，挂 mark_replace_ip 后正常）。
        # GNU sed 0,/re/ 只改首个匹配。config 无 mark_replace_ip（老配置）时
        # 回落插入首个 BEGIN 表头。
        if grep -q 'mark_replace_ip' "$GEO_CFG"; then
            sed -i "0,/<\/mark_replace_ip>/s//<\/mark_replace_ip>\n\t\t\t\t<mark_geo  file='\/vhs\/kangle\/etc\/geo-ipdata.txt'  flush_time='86400' name='_geo' > <\/mark_geo>/" "$GEO_CFG" \
                && echo "[entrypoint] mark_geo chain injected after mark_replace_ip" \
                || echo "[entrypoint] WARN: mark_geo injection failed"
        else
            sed -i "0,/<table name='BEGIN'>/s//<table name='BEGIN'>\n\t\t\t<chain  action='continue' >\n\t\t\t\t<mark_geo  file='\/vhs\/kangle\/etc\/geo-ipdata.txt'  flush_time='86400' name='_geo' > <\/mark_geo>\n\t\t\t<\/chain>/" "$GEO_CFG" \
                && echo "[entrypoint] mark_geo chain injected at BEGIN head (no mark_replace_ip present)" \
                || echo "[entrypoint] WARN: mark_geo injection failed"
        fi
    fi
}
ensure_geo_mark

# 拉起 kangle（与上游镜像行为一致：先拉起 kangle，再用 tail 保持容器存活）
echo "[entrypoint] starting kangle ..."
/vhs/kangle/bin/kangle 2>&1 || echo "[entrypoint] WARN: kangle exited with code $?"

exec tail -f /dev/null
