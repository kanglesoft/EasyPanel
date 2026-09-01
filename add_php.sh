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
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$PROJECT_DIR/data/kangle/ext"
OVERRIDE="$PROJECT_DIR/docker-compose.override.yml"
PHP_FPM_DIR="$PROJECT_DIR/php-fpm"

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

# 防重复
if [[ -d "$EXT_DIR/tpl_$PHP_KEY" ]]; then
  echo "提示: $PHP_KEY 已存在，跳过。"
  exit 0
fi

echo "==> 接入 PHP $VERSION (标识 $PHP_KEY)"

# 1. 生成 ext 配置（server 指向同网络容器 phpXX:9000）
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

# 2. 写/更新 compose override（持久化，便于 docker compose up 复现）
if [[ ! -f "$OVERRIDE" ]]; then
  cat > "$OVERRIDE" <<'EOF'
# 由 add_php.sh 自动生成：动态接入的额外 PHP 版本（独立 fpm 容器）
# 复现: docker compose -f docker-compose.yml -f docker-compose.override.yml up -d
services:
EOF
fi
if ! grep -q "^  $PHP_KEY:" "$OVERRIDE"; then
  cat >> "$OVERRIDE" <<EOF
  $PHP_KEY:
    build:
      context: ./php-fpm
      args:
        PHP_VERSION: "$VERSION"
    container_name: $PHP_KEY
    restart: unless-stopped
    # 标记基础设施容器，宿主容器管理功能自动隐藏，避免误操作
    labels:
      - "com.kangle.role=infra"
    # 仅 kangle_net 内部可达，不暴露宿主机端口
    volumes:
      - ./data/kangle:/vhs/kangle:rw
      - ./data/homeftp:/home/ftp:rw
    networks:
      - kangle_net
EOF
  echo "    已更新 $OVERRIDE"
fi

# 3. 启动容器（走 compose，自动构建带扩展的镜像）
$DC -f "$PROJECT_DIR/docker-compose.yml" -f "$OVERRIDE" up -d "$PHP_KEY"
echo "    容器 $PHP_KEY 已启动"

# 4. 重载 kangle 使其加载新 ext 配置
if docker exec kangle sh -c 'kill -HUP "$(cat /var/run/kangle.pid 2>/dev/null)" 2>/dev/null' 2>/dev/null; then
  echo "    已发送 HUP 重载 kangle"
else
  echo "    HUP 失败，尝试重启 kangle 容器..."
  docker restart kangle >/dev/null
fi

echo "完成: PHP $VERSION 已接入。easypanel 后台“默认PHP版本”下拉现已可选 $PHP_KEY。"
echo "      在站点或节点设置中将 PHP 版本切换为 $PHP_KEY 即可生效。"
