#!/usr/bin/env bash
#
# add_php.sh — 为 easypanel 动态接入一个额外的 PHP 版本
#
# 原理：
#   启动一个独立的 phpX.X-fpm 容器（与 kangle 同网络 kangle_net、共享 /vhs/kangle 配置和 /home/ftp 站点目录），
#   并在 kangle 的 ext/ 下生成 tpl_phpXX/config.xml，注册一个指向该容器的 FastCGI server；
#   easypanel 后台“默认PHP版本”下拉即可选择该版本。
#
# 用法：
#   ./add_php.sh 8.2        # 接入 PHP 8.2
#   ./add_php.sh 8.1        # 接入 PHP 8.1
#
# 说明：
#   - 主容器已内置 php7.4，故 7.4 被排除。
#   - 额外 PHP 仅提供 web(FastCGI) 运行；CLI 仍为内置 php7.4。
#   - 容器不暴露宿主机端口，仅 kangle_net 内部可达。
#   - 配置与容器均写入 compose override，便于 docker compose up 复现。
#   - 本脚本独占生成/重写 docker-compose.override.yml：文件内只允许出现
#     由 ext/tpl_phpXX 目录推导出的 fpm 服务；每次执行按 ext 现状全量重建，
#     因此对历史版本（旧 override 缺 zz-panel 挂载 / 旧容器名）是幂等升级。
#   - 面板 PHP 扩展开关（vhost/control/phpset.ctl.php）落盘在
#     ./data/kangle/etc/php-fpm-ini/<phpXX>/（kangle 容器内即
#     /vhs/kangle/etc/php-fpm-ini/<phpXX>/），各版本子目录以只读绑定进对应
#     fpm 容器的 /usr/local/etc/php/conf.d/zz-panel，容器重启后 enable-*.ini
#     即生效——所以这里必须 mkdir 并按版本挂载。
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$PROJECT_DIR/data/kangle/ext"
OVERRIDE="$PROJECT_DIR/docker-compose.override.yml"
PHP_FPM_DIR="$PROJECT_DIR/php-fpm"
# 面板扩展开关 ini 落点根目录（phpset.ctl.php 使用按版本划分的子目录
# /vhs/kangle/etc/php-fpm-ini/<phpXX>/enable-<ext>.ini，PHP 扫描 conf.d 不递归，
# 因此每个 fpm 容器挂载各自的版本子目录到 zz-panel）
FPM_INI_ROOT="$PROJECT_DIR/data/kangle/etc/php-fpm-ini"
FPM_INI_CONTAINER_PATH="/usr/local/etc/php/conf.d/zz-panel"
# 容器名前缀：container_name=phpfpm-<phpXX>；compose 服务名仍是 <phpXX>，
# kangle ext 配置以 host='<phpXX>' 走 compose 网络别名解析，二者指向同一容器。
CONTAINER_PREFIX="phpfpm-"

# CDN-only 模式禁止接入额外 PHP：
#   该模式不安装网站环境（无 MySQL、无站点目录用途），额外 php-fpm 属于网站环境的一部分。
#   放行会导致容器白跑、且 override 文件在 upgrade.sh 中被 cdn 模式忽略，产生"配置了却不生效"的错觉。
if [[ -f "$PROJECT_DIR/lib/common.sh" ]]; then
  # shellcheck source=lib/common.sh
  . "$PROJECT_DIR/lib/common.sh"
  if declare -F mode_detect >/dev/null 2>&1 && [[ "$(cd "$PROJECT_DIR" && mode_detect)" == "cdn" ]]; then
    echo "错误: 当前为 CDN-only 安装模式（未安装网站环境），无法接入额外 PHP。"
    echo "      如需 MySQL 与站点 PHP，请重新运行 install.sh 并选择「全量模式」。"
    exit 1
  fi
fi

# docker compose 命令适配（v2 插件优先，回退 v1）
if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
else
  DC="docker-compose"
fi

usage() { echo "用法: $0 <php版本, 如 8.2>"; exit 1; }

VERSION="${1:-}"
[[ -z "$VERSION" ]] && usage
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "错误: 版本格式应为 X.X (如 8.2)"; exit 1; }
[[ "$VERSION" == "7.4" ]] && { echo "php 7.4 已内置在主容器，无需添加。"; exit 1; }

PHP_KEY="php${VERSION/./}"   # 8.2 -> php82

# phpXX -> X.X（用于重建 override 时还原 build arg）
key_to_version() {
  local v="${1#php}"
  echo "${v:0:1}.${v:1}"
}

echo "==> 接入 PHP $VERSION (标识 $PHP_KEY)"

# 0. 面板扩展开关落点目录（版本子目录必须存在于宿主，bind 挂载才不会由
#    docker 以 root 目录代建；重复执行无害）
mkdir -p "$FPM_INI_ROOT/$PHP_KEY"
echo "    已确保 $FPM_INI_ROOT/$PHP_KEY 存在"

# 1. 生成 ext 配置（server 指向同网络服务名 phpXX:9000，compose 网络别名可解析）
mkdir -p "$EXT_DIR/tpl_$PHP_KEY"
cat > "$EXT_DIR/tpl_$PHP_KEY/config.xml" <<EOF
<!--#start 600 -->
<config>
<server name='$PHP_KEY' proto='fastcgi' host='$PHP_KEY' port='9000' life_time='60' max_error_count='10'/>
<vh_templete name='php:$PHP_KEY' templete='html' index='index.php'>
<map file_ext='php' extend='server:$PHP_KEY' allow_method='*'/>
</vh_templete>
</config>
EOF
echo "    已写入 $EXT_DIR/tpl_$PHP_KEY/config.xml"

# 2. 全量重建 compose override（幂等）：以 ext/tpl_phpXX 目录为唯一真源，
#    每个服务都带 zz-panel 只读挂载与新容器名 phpfpm-<phpXX>。
#    旧部署升级场景：此前 override 缺挂载 / 容器名为裸 phpXX，仅追加新服务无法修复，
#    故整体重写；该文件本来就只由本脚本生成，不存在需保留的手工内容。
{
  cat <<'EOF'
# 由 add_php.sh 自动生成：动态接入的额外 PHP 版本（独立 fpm 容器）
# 复现: docker compose -f docker-compose.yml -f docker-compose.override.yml up -d
# 注意: 本文件由 add_php.sh 独占维护，请勿手工编辑（会被全量重建覆盖）。
# zz-panel 卷: 面板「PHP扩展开关」在宿主 ./data/kangle/etc/php-fpm-ini/<phpXX>/ 写
#             enable-<ext>.ini，经该只读挂载进入对应 fpm 容器的 php conf.d 扫描目录
#             （镜像 PHP_INI_SCAN_DIR 已包含 zz-panel，见 php-fpm/Dockerfile）。
services:
EOF
  found=0
  for d in "$EXT_DIR"/tpl_php[0-9]*; do
    [[ -d "$d" ]] || continue
    key="$(basename "$d")"; key="${key#tpl_}"
    # 只认 phpXX（两位版本号，与 VERSION 校验 ^[0-9]+\.[0-9]+$ 同宽）；
    # php74 内置于主容器无独立服务；异常目录跳过
    [[ "$key" =~ ^php[0-9]{2}$ ]] || continue
    [[ "$key" == "php74" ]] && continue
    v="$(key_to_version "$key")"
    # 自愈：为存量版本补建开关子目录（老部署由旧脚本生成时不存在该目录）
    mkdir -p "$FPM_INI_ROOT/$key"
    cat <<EOF
  $key:
    build:
      context: ./php-fpm
      args:
        PHP_VERSION: "$v"
    container_name: $CONTAINER_PREFIX$key
    restart: unless-stopped
    # 集成步（3.5G 主机防 OOM）：每个 fpm 容器限内存 768M
    mem_limit: 805306368
    # 标记基础设施容器，宿主容器管理功能自动隐藏，避免误操作
    labels:
      - "com.kangle.role=infra"
    # 仅 kangle_net 内部可达，不暴露宿主机端口
    volumes:
      - ./data/kangle:/vhs/kangle:rw
      - ./data/homeftp:/home/ftp:rw
      # 面板 PHP 扩展开关落点（按版本子目录，只读）；镜像内 PHP_INI_SCAN_DIR 已包含该目录
      - ./data/kangle/etc/php-fpm-ini/${key}:${FPM_INI_CONTAINER_PATH}:ro
    networks:
      - kangle_net
EOF
    found=1
  done
  if [[ "$found" != "1" ]]; then
    echo "错误: 未推导出任何 fpm 服务（内部状态异常）" >&2
    exit 1
  fi
} > "$OVERRIDE.tmp"
mv "$OVERRIDE.tmp" "$OVERRIDE"
echo "    已重建 $OVERRIDE"

# 3. 启动/收敛容器（走 compose，自动构建带扩展的镜像；配置未变时 up -d 为 no-op）
#    存量升级自愈：旧镜像没有 PHP_INI_SCAN_DIR（zz-panel 不会被扫描）也没有
#    exif/soap 基线，`up -d` 不会因 Dockerfile 变化自动重建，必须显式 build。
COMPOSE_CMD=($DC -f "$PROJECT_DIR/docker-compose.yml" -f "$OVERRIDE")
if docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "${CONTAINER_PREFIX}${PHP_KEY}" 2>/dev/null \
   | grep -q '^PHP_INI_SCAN_DIR='; then
  : # 镜像已具备面板扩展开关所需的 ini 扫描目录，无需重建
else
  echo "    检测到 ${CONTAINER_PREFIX}${PHP_KEY} 缺失或为旧镜像（无 PHP_INI_SCAN_DIR），重建镜像..."
  "${COMPOSE_CMD[@]}" build "$PHP_KEY"
fi
"${COMPOSE_CMD[@]}" up -d "$PHP_KEY"
echo "    容器 ${CONTAINER_PREFIX}${PHP_KEY} 已启动（服务名 ${PHP_KEY}）"

# 4. 重载 kangle 使其加载新 ext 配置
if docker exec kangle sh -c 'kill -HUP "$(cat /var/run/kangle.pid 2>/dev/null)" 2>/dev/null' 2>/dev/null; then
  echo "    已发送 HUP 重载 kangle"
else
  echo "    HUP 失败，尝试重启 kangle 容器..."
  docker restart kangle >/dev/null
fi

echo "完成: PHP ${VERSION} 已接入。easypanel 后台“默认PHP版本”下拉现已可选 ${PHP_KEY}。"
echo "      在站点或节点设置中将 PHP 版本切换为 $PHP_KEY 即可生效。"
echo "      面板「PHP扩展开关」落点: ./data/kangle/etc/php-fpm-ini/${PHP_KEY}/ -> 容器 ${FPM_INI_CONTAINER_PATH} (ro)"
