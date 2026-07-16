#!/usr/bin/env bash
#
# uninstall.sh — EasyPanel Heavy 干净卸载脚本
#
# 行为：
#   - 停止并移除所有相关容器（kangle / mysql / 各 phpX.X-fpm）
#   - 移除自定义网络（compose 项目网络）
#   - 删除由本项目构建的镜像（kangle / mysql / phpXX，即 compose 本地构建镜像）
#   - 卸载前默认备份全部业务数据（./data/kangle、./data/mysql、./data/homeftp、./data/acme）
#   - 删除 bind 挂载数据卷为“高危”操作，须显式 --delete-data 并二次确认
#   - 外部基础镜像（mysql:8 / php:X.X-fpm）默认保留；--purge-images 才删除
#   - 可选 --purge-docker 卸载 Docker Engine（仅当确认不再需要时使用）
#
# 用法：
#   ./uninstall.sh                      # 交互式：备份 / 询问是否删数据
#   ./uninstall.sh --yes                # 非交互（仍须 --delete-data 才删数据）
#   ./uninstall.sh --delete-data        # 真正删除 ./data 下所有持久化数据
#   ./uninstall.sh --no-backup          # 跳过卸载前备份
#   ./uninstall.sh --purge-images       # 同时删除外部基础镜像
#   ./uninstall.sh --keep-images        # 连本地构建镜像也保留
#   ./uninstall.sh --purge-docker       # 同时卸载 Docker Engine（高危）
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

log()  { echo -e "\033[1;36m[uninstall]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ ok ]\033[0m $*"; }
warn() { echo -e "\033[1;33m[warn]\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

YES=0
DELETE_DATA=0
DO_BACKUP=1
PURGE_IMAGES=0
KEEP_IMAGES=0
PURGE_DOCKER=0
for a in "$@"; do
  case "$a" in
    --yes) YES=1 ;;
    --delete-data) DELETE_DATA=1 ;;
    --no-backup) DO_BACKUP=0 ;;
    --purge-images) PURGE_IMAGES=1 ;;
    --keep-images) KEEP_IMAGES=1 ;;
    --purge-docker) PURGE_DOCKER=1 ;;
    -h|--help) sed -n '3,21p' "$0"; exit 0 ;;
    *) warn "未知参数: $a" ;;
  esac
done

confirm() {
  # $1 = 提示语；返回 0 表示确认
  if [[ "$YES" -eq 1 ]]; then return 0; fi
  local ans
  read -r -p "$1 [y/N]: " ans
  [[ "$ans" =~ ^[Yy]$ ]]
}

# 检测 compose 命令（优先 plugin，回退独立二进制）
DC=""
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  DC="docker-compose"
fi

# 需要备份/关注的数据路径
DATA_PATHS=(
  "data/kangle"
  "data/mysql"
  "data/homeftp"
  "data/acme"
)

# ───────────────────────── 1) 备份 ─────────────────────────
BK_FILE=""
if [[ "$DO_BACKUP" -eq 1 ]]; then
  any_data=0
  for d in "${DATA_PATHS[@]}"; do
    [[ -d "$d" ]] && any_data=1 && break
  done

  if [[ "$any_data" -eq 1 ]]; then
    BK_DIR="backups"
    mkdir -p "$BK_DIR"
    TS="$(date '+%Y%m%d-%H%M%S')"
    BK_FILE="$BK_DIR/uninstall-backup-$TS.tar.gz"
    log "卸载前备份业务数据 -> $BK_FILE"
    tar -czf "$BK_FILE" "${DATA_PATHS[@]}" 2>/dev/null \
      && ok "备份完成: $BK_FILE" \
      || warn "备份失败，请手动检查 ./data"
  else
    log "未发现 ./data 业务数据，跳过备份"
  fi
else
  log "已跳过备份（--no-backup）"
fi

# ───────────────────────── 2) 停止并移除容器 / 网络 ─────────────────────────
if [[ -n "$DC" ]]; then
  log "停止并移除容器、网络（保留 bind 数据卷）..."
  $DC -f docker-compose.yml -f docker-compose.override.yml down --remove-orphans >/dev/null 2>&1 \
    || $DC down --remove-orphans >/dev/null 2>&1 \
    || true
  ok "容器与项目网络已移除"

  # 清理可能残留的旧网络名（项目前缀）
  docker network ls --format '{{.Name}}' | grep -E 'kangle.*kangle_net|easypanel.*kangle_net' | while read -r net; do
    docker network rm "$net" >/dev/null 2>&1 && ok "移除网络: $net" || true
  done

  # ───────────────────────── 3) 删除镜像 ─────────────────────────
  if [[ "$KEEP_IMAGES" -eq 0 ]]; then
    log "删除由本项目本地构建的镜像（kangle / mysql / phpXX）..."
    # --rmi local 仅删除 compose 本地构建的镜像，不影响外部拉取的 mysql:8 / php:* 基础镜像
    $DC -f docker-compose.yml -f docker-compose.override.yml down --rmi local >/dev/null 2>&1 \
      || $DC down --rmi local >/dev/null 2>&1 || true
    ok "本地构建镜像已删除"
  else
    log "保留所有镜像（--keep-images）"
  fi
else
  warn "未检测到 docker compose，跳过容器/网络/镜像清理（可能已手动卸载 Docker）"
fi

if [[ "$PURGE_IMAGES" -eq 1 && -n "$DC" ]]; then
  log "删除外部基础镜像（mysql:8 / php:*-fpm）..."
  docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '^(mysql:8|php:.*-fpm)' | while read -r img; do
    docker rmi -f "$img" >/dev/null 2>&1 && ok "删除镜像: $img" || true
  done
fi

# ───────────────────────── 4) 删除 bind 数据卷（高危）─────────────────────────
if [[ "$DELETE_DATA" -eq 1 ]]; then
  if confirm "⚠️  确认永久删除 ./data 下全部业务数据（不可恢复）?"; then
    for d in "${DATA_PATHS[@]}"; do
      rm -rf "$d"
    done
    # 同时清理 add_php.sh 生成的 override 与扩展配置
    rm -f docker-compose.override.yml
    ok "数据卷与 override 已删除"
  else
    warn "已取消删除数据卷，数据保留在 ./data"
  fi
fi

# ───────────────────────── 5) 可选：卸载 Docker Engine（高危）─────────────────────────
if [[ "$PURGE_DOCKER" -eq 1 ]]; then
  warn "即将卸载 Docker Engine。这将影响本机所有 Docker 容器与镜像！"
  if confirm "确认完全卸载 Docker Engine（docker-ce / docker-compose-plugin 等）?"; then
    log "卸载 Docker Engine..."
    if command -v dnf >/dev/null 2>&1; then
      dnf remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-compose 2>/dev/null || true
    elif command -v yum >/dev/null 2>&1; then
      yum remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-compose 2>/dev/null || true
    elif command -v apt-get >/dev/null 2>&1; then
      apt-get remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-compose 2>/dev/null || true
    else
      warn "无法识别包管理器，请手动卸载 Docker。"
    fi
    ok "Docker Engine 已卸载"
  else
    warn "已取消卸载 Docker Engine。"
  fi
fi

# ───────────────────────── 6) 完成提示（重点提示备份路径）─────────────────────────
echo
echo "=================================================================="
echo -e "  \033[1;32m卸载完成\033[0m"
echo "=================================================================="
echo "  容器 / 网络：$( [[ -n "$DC" ]] && echo '已移除' || echo '未清理（无 Docker）' )"
echo "  镜像：本地构建镜像$( [[ "$KEEP_IMAGES" -eq 1 ]] && echo '（已保留）' || echo '（已删除）' )"
echo "  数据卷：./data $( [[ "$DELETE_DATA" -eq 1 ]] && echo '（已删除）' || echo '（已保留）' )"
echo "  Docker Engine：$( [[ "$PURGE_DOCKER" -eq 1 ]] && echo '（已卸载）' || echo '（已保留）' )"
[[ "$DO_BACKUP" -eq 1 && -f "${BK_FILE:-}" ]] && echo "  备份文件: $BK_FILE"
echo
if [[ "$DELETE_DATA" -ne 1 ]]; then
  echo -e "  \033[1;33m重要提示：业务数据仍保留在以下路径，请自行做好备份，否则卸载后若删除目录将无法恢复。\033[0m"
  echo
  echo "    1) PHP 网站程序 / 站点文件："
  echo "       $(pwd)/data/homeftp/"
  echo "       （每个站点对应 data/homeftp/<vhost>/wwwroot/ 下的文件）"
  echo
  echo "    2) MySQL 数据库文件："
  echo "       $(pwd)/data/mysql/"
  echo
  echo "    3) kangle / EasyPanel 配置与扩展："
  echo "       $(pwd)/data/kangle/"
  echo "       （含站点配置、SSL 证书、ext 扩展模板等）"
  echo
  echo "    4) acme.sh SSL 证书账户与订单数据："
  echo "       $(pwd)/data/acme/"
  echo
  echo "  如需彻底删除这些数据，请执行："
  echo "    ./uninstall.sh --delete-data"
else
  echo -e "  \033[1;31m./data 下全部业务数据已删除。若未提前备份，数据不可恢复。\033[0m"
fi
echo "=================================================================="
