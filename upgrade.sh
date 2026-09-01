#!/usr/bin/env bash
#
# upgrade.sh — EasyPanel Heavy 数据安全升级脚本
#
# 职责：
#   1) 前置检查（git 仓库 / Docker Engine / Compose 可用性）。
#   2) 停止容器并全量备份业务数据与本地配置 -> backups/upgrade-backup-<时间戳>.tar.gz。
#      先停容器再备份是为了保证 MySQL 数据文件的一致性；备份失败即中止升级（fail-closed）。
#   3) 本地未提交改动自动 stash，升级成功后尝试恢复；恢复冲突则保留 stash 供手动处理。
#   4) git pull --ff-only 拉取新版本（拒绝分叉历史，绝不 force 覆盖本地提交）。
#   5) docker compose up -d --build 重建镜像与容器 —— bind 挂载的 ./data 全程不动，
#      .env / node.cfg.php / docker-compose.override.yml 均在 .gitignore 中不受影响。
#      （与 install.sh 的关键差异：绝不重写 .env，管理员与数据库密码保持不变。）
#   6) 健康检查（3311 / 3312 / 80 端口 + MySQL 连通）。
#   7) 任一环节失败自动回滚：git reset 回旧版本并重建容器；数据卷自始至终未被改动。
#   8) 成功后清理 Smarty 编译缓存（面板模板更新自动生效）。
#
# 用法：
#   ./upgrade.sh                      # 交互式升级（本地有未提交改动时询问处理方式）
#   ./upgrade.sh --yes                # 非交互：自动 stash 本地改动并升级
#   ./upgrade.sh --no-backup          # 跳过升级前备份（不推荐）
#   ./upgrade.sh --no-pull            # 跳过 git pull，仅备份 + 重建（本地改码后使用）
#   ./upgrade.sh --skip-health-check  # 跳过升级后健康检查（不推荐）
#
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

log()  { echo -e "\033[1;36m[upgrade]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ ok ]\033[0m $*"; }
warn() { echo -e "\033[1;33m[warn]\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

# ───────────────────────── 参数解析 ─────────────────────────
YES=0
DO_BACKUP=1
DO_PULL=1
DO_HEALTH=1
for a in "$@"; do
  case "$a" in
    --yes) YES=1 ;;
    --no-backup) DO_BACKUP=0 ;;
    --no-pull) DO_PULL=0 ;;
    --skip-health-check) DO_HEALTH=0 ;;
    -h|--help) sed -n '3,24p' "$0"; exit 0 ;;
    *) warn "未知参数: $a" ;;
  esac
done

confirm() {
  [[ "$YES" -eq 1 ]] && return 0
  local ans
  read -r -p "$1 [y/N]: " ans
  [[ "$ans" =~ ^[Yy]$ ]]
}

# ───────────────────────── 前置检查 ─────────────────────────
[[ -f docker-compose.yml && -f install.sh ]] || die "请在项目仓库根目录运行本脚本。"
command -v git >/dev/null 2>&1 || die "未检测到 git，请先安装 git。"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "当前目录不是 git 仓库，无法自动升级。"

if [[ "$EUID" -ne 0 ]]; then
  warn "当前非 root 用户，若 Docker 权限不足请改用 root 运行。"
fi

DC=""
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
  elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose"
  fi
fi
[[ -n "$DC" ]] || die "未检测到可用的 Docker Engine 与 Compose 插件，请先安装并启动 Docker。"

OLD_HEAD="$(git rev-parse HEAD)"
OLD_SHORT="$(git rev-parse --short HEAD)"
TS="$(date '+%Y%m%d-%H%M%S')"

# add_php.sh 生成的 PHP 扩展编排一并纳入（与 uninstall.sh 的处理一致）
COMPOSE_FILES=(-f docker-compose.yml)
if [[ -f docker-compose.override.yml ]]; then
  COMPOSE_FILES+=(-f docker-compose.override.yml)
fi

# ───────────────────────── 本地未提交改动处理 ─────────────────────────
# 只关心已跟踪文件的改动（untracked / gitignore 的运行期数据不影响 pull）。
STASHED=0
if ! git diff --quiet || ! git diff --cached --quiet; then
  if confirm "检测到本地未提交改动，自动 stash 后升级（升级成功后尝试恢复）?"; then
    if git stash push -m "upgrade-auto-stash-$TS" >/dev/null 2>&1; then
      STASHED=1
      ok "本地改动已 stash（upgrade-auto-stash-$TS）"
    else
      die "git stash 失败，已中止升级。请手动提交或 stash 本地改动后重试。"
    fi
  else
    die "已取消升级。请先提交或 stash 本地改动后重试。"
  fi
fi

# ───────────────────────── 停止容器 ─────────────────────────
log "停止容器（bind 数据卷不受影响）..."
$DC "${COMPOSE_FILES[@]}" down --remove-orphans >/dev/null 2>&1 || true
ok "容器已停止"

# ───────────────────────── 升级前备份 ─────────────────────────
# 停机后备份：MySQL 数据文件离线拷贝，保证一致性（热拷贝可能得到损坏的备份）。
BK_FILE=""
if [[ "$DO_BACKUP" -eq 1 ]]; then
  mkdir -p backups
  BK_FILE="backups/upgrade-backup-$TS.tar.gz"
  BACKUP_TARGETS=()
  for d in data/kangle data/mysql data/homeftp data/acme; do
    if [[ -d "$d" ]]; then BACKUP_TARGETS+=("$d"); fi
  done
  if [[ -f .env ]]; then BACKUP_TARGETS+=(".env"); fi
  if [[ -f docker-compose.override.yml ]]; then BACKUP_TARGETS+=("docker-compose.override.yml"); fi

  if [[ ${#BACKUP_TARGETS[@]} -eq 0 ]]; then
    warn "未发现任何业务数据，跳过备份"
    BK_FILE=""
  else
    log "升级前备份业务数据与本地配置 -> $BK_FILE"
    if tar -czf "$BK_FILE" "${BACKUP_TARGETS[@]}" 2>/dev/null; then
      ok "备份完成: $BK_FILE ($(du -h "$BK_FILE" | cut -f1))"
    else
      rm -f "$BK_FILE"
      die "备份失败，已中止升级（数据安全优先）。请检查磁盘空间后重试。"
    fi
  fi
else
  log "已跳过备份（--no-backup，不推荐）"
fi

# ───────────────────────── .env 变量完整性提示 ─────────────────────────
# 新版本若在 .env.example 中新增了变量，提醒手动补充（绝不自动改写 .env）。
if [[ -f .env.example && -f .env ]]; then
  MISSING_KEYS="$(comm -23 \
    <(grep -oE '^[A-Z_]+=' .env.example | tr -d '=' | sort -u) \
    <(grep -oE '^[A-Z_]+=' .env | tr -d '=' | sort -u) || true)"
  if [[ -n "$MISSING_KEYS" ]]; then
    warn ".env 缺少以下变量（新版可能新增），如需启用相关功能请参考 .env.example 手动补充："
    warn "  $MISSING_KEYS"
  fi
fi

# ───────────────────────── 回滚函数 ─────────────────────────
rollback() {
  echo
  warn "升级失败，开始回滚到 $OLD_SHORT ..."
  # 回滚前把当前工作区状态存入 stash，避免 reset --hard 丢弃任何未提交内容
  git stash push -m "pre-rollback-$TS" >/dev/null 2>&1 || true
  git reset --hard "$OLD_HEAD" >/dev/null 2>&1 || true
  log "以旧版本重建容器..."
  if $DC "${COMPOSE_FILES[@]}" up -d --build >/dev/null 2>&1; then
    ok "已回滚到 $OLD_SHORT 并重建容器（数据卷自始至终未被改动）"
  else
    warn "回滚重建失败，请手动执行: $DC ${COMPOSE_FILES[*]} up -d --build"
  fi
  if [[ -n "$BK_FILE" && -f "$BK_FILE" ]]; then
    warn "如需恢复数据，备份位于: $BK_FILE"
  fi
  die "升级失败，已回滚。"
}

# ───────────────────────── 拉取新版本 ─────────────────────────
NEW_SHORT="$OLD_SHORT"
if [[ "$DO_PULL" -eq 1 ]]; then
  log "拉取最新代码（git pull --ff-only）..."
  if ! git pull --ff-only 2>&1 | sed 's/^/[git] /'; then
    die "git pull 失败（本地分支与远端分叉或网络异常）。请手动执行 git pull --rebase 处理后重试。"
  fi
  NEW_SHORT="$(git rev-parse --short HEAD)"
  if [[ "$NEW_SHORT" == "$OLD_SHORT" ]]; then
    ok "代码已是最新版本（$OLD_SHORT）"
  else
    ok "代码已更新: $OLD_SHORT -> $NEW_SHORT"
  fi
else
  log "已跳过 git pull（--no-pull）"
fi

# ───────────────────────── 重建镜像与容器 ─────────────────────────
log "重建镜像并启动容器（bind 数据卷不受影响）..."
if ! $DC "${COMPOSE_FILES[@]}" up -d --build; then
  rollback
fi

# ───────────────────────── 健康检查 ─────────────────────────
wait_port() {
  local port="$1" i
  for i in $(seq 1 60); do
    if curl -s -o /dev/null -m 2 "http://localhost:$port/"; then return 0; fi
    sleep 2
  done
  return 1
}

# 从 .env 读取变量值（兼容带双引号的写法），绝不回显
read_env_var() {
  [[ -f .env ]] || return 1
  local line
  line="$(grep -E "^${1}=" .env | head -1 || true)"
  line="${line#*=}"
  line="${line%\"}"
  line="${line#\"}"
  printf '%s' "$line"
}

HEALTH_OK=1
if [[ "$DO_HEALTH" -eq 1 ]]; then
  for p in 3311 3312 80; do
    if wait_port "$p"; then
      ok "端口 $p 已就绪"
    else
      warn "端口 $p 未在预期时间内响应"
      HEALTH_OK=0
    fi
  done
  MYSQL_PASS="$(read_env_var MYSQL_ROOT_PASSWORD)"
  if [[ -n "$MYSQL_PASS" ]] && docker exec mysql8 mysql -uroot -p"$MYSQL_PASS" -e "SELECT 1" >/dev/null 2>&1; then
    ok "MySQL 连接正常"
  else
    warn "MySQL 连接失败（mysql8 容器状态或 .env 密码异常）"
    HEALTH_OK=0
  fi
else
  log "已跳过健康检查（--skip-health-check）"
fi

if [[ "$HEALTH_OK" -ne 1 ]]; then
  rollback
fi

# ───────────────────────── 恢复本地改动 ─────────────────────────
if [[ "$STASHED" -eq 1 ]]; then
  log "恢复升级前的本地改动（git stash pop）..."
  if git stash pop 2>&1 | sed 's/^/[git] /'; then
    ok "本地改动已恢复"
  else
    warn "stash 恢复存在冲突，改动保留在 stash 中（git stash list 查看），请手动处理。"
  fi
fi

# ───────────────────────── 清理 Smarty 编译缓存 ─────────────────────────
# 面板模板更新后，旧编译缓存会导致页面未生效（README「常见问题」同款处理）。
if [[ -d data/kangle/nodewww/webftp/framework/templates_c ]]; then
  find data/kangle/nodewww/webftp/framework/templates_c -mindepth 1 -delete 2>/dev/null || true
  ok "已清理 Smarty 编译缓存（模板更新自动生效）"
fi

# ───────────────────────── 完成摘要 ─────────────────────────
echo
echo "=================================================================="
echo -e "  \033[1;32m升级完成\033[0m"
echo "=================================================================="
if [[ "$NEW_SHORT" == "$OLD_SHORT" ]]; then
  echo "  版本: $OLD_SHORT（无代码更新，仅重建容器）"
else
  echo "  版本: $OLD_SHORT -> $NEW_SHORT"
fi
if [[ -n "$BK_FILE" ]]; then
  echo "  备份: $BK_FILE"
fi
echo "  数据: ./data 全程未改动（bind 挂载），.env 密码保持不变"
echo
echo "  面板访问："
echo "    kangle 管理:   http://<服务器IP>:3311/"
echo "    easypanel 后台: http://<服务器IP>:3312/admin/"
echo "=================================================================="
