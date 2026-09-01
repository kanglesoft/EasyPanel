#!/usr/bin/env bash
#
# upgrade.sh — EasyPanel Heavy 数据安全升级脚本
#
# 职责（阶段顺序经过安全设计，任何失败路径都不会让服务"停在半路"）：
#   阶段一（不停机）：
#     1) 前置检查（git 仓库 / 分支状态 / Docker / Compose）。
#     2) 本地未提交改动自动 stash，升级成功后恢复；恢复冲突则保留 stash 供手动处理。
#     3) git fetch + git merge --ff-only 拉取新版本（显式 fetch，不依赖上游跟踪配置）；
#        拉取失败直接退出 —— 容器从未停止，服务不受影响。
#     4) 若 upgrade.sh 自身随新版本更新，自动以新脚本重新执行（自更新），
#        并传递升级基线提交与 stash 名称，保证回滚与恢复语义不变。
#   阶段二（停机窗口）：
#     5) 停止容器；离线全量备份业务数据与本地配置 -> backups/upgrade-backup-<时间戳>.tar.gz
#        （先停机再备份保证 MySQL 数据文件一致性；备份失败即恢复原状，fail-closed）。
#   阶段三（重建与验证）：
#     6) docker compose up -d --build 重建 —— bind 挂载的 ./data 全程不动，绝不重写 .env。
#     7) 健康检查（3311 / 3312 / 80 端口 + MySQL 连通；旧版 .env 缺密码变量时降级为跳过）。
#     8) 任一环节失败自动回滚到升级前提交并重建容器，数据卷自始至终未被改动。
#   阶段四（收尾）：
#     9) 恢复 stash、清理 Smarty 编译缓存、输出摘要。
#
# 兼容性设计（可从任意历史版本 / 任意状态的部署升级）：
#   - .env.example / docker-compose.override.yml / templates_c 不存在时自动跳过。
#   - 显式 fetch origin + ff-only merge，不依赖分支上游跟踪配置。
#   - 拒绝 detached HEAD 与进行中的 merge / rebase，避免升级脚本破坏仓库状态。
#   - 容器名（kangle / mysql8）与端口（80 / 3311 / 3312）自首个版本起未变，直接依赖。
#
# 用法：
#   ./upgrade.sh                      # 交互式升级（本地有未提交改动时询问处理方式）
#   ./upgrade.sh --yes                # 非交互：自动 stash 本地改动并升级
#   ./upgrade.sh --no-backup          # 跳过升级前备份（不推荐）
#   ./upgrade.sh --no-pull            # 跳过代码拉取，仅备份 + 重建（本地改码后使用）
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
    -h|--help) sed -n '3,35p' "$0"; exit 0 ;;
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
[[ -f docker-compose.yml ]] || die "请在项目仓库根目录运行本脚本。"
command -v git >/dev/null 2>&1 || die "未检测到 git，请先安装 git。"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "当前目录不是 git 仓库，无法自动升级。"

# 自更新（INNER=1）时基线提交与 stash 名称由外层脚本经环境变量传入
INNER="${UPGRADE_INNER:-0}"
BASE_HEAD="${UPGRADE_BASE_HEAD:-}"
STASH_NAME="${UPGRADE_STASH_NAME:-}"

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
[[ -f .env ]] || die "未找到 .env（compose env_file 依赖它）。请先运行 install.sh 完成安装，或从备份恢复 .env。"

# git 状态门禁：兼容性前置（拒绝会破坏仓库状态或无法回滚的场景）
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" == "HEAD" ]]; then
  die "当前处于 detached HEAD，无法安全升级。请先切回分支（如: git checkout main）后重试。"
fi
GD="$(git rev-parse --git-dir)"
if [[ -f "$GD/MERGE_HEAD" || -d "$GD/rebase-merge" || -d "$GD/rebase-apply" ]]; then
  die "检测到未完成的 merge / rebase，请先处理（git status 查看冲突文件）后重试。"
fi
if [[ "$INNER" -ne 1 && "$DO_PULL" -eq 1 ]]; then
  git remote get-url origin >/dev/null 2>&1 || die "未配置 origin 远程仓库，无法自动拉取新版本。"
fi

OLD_HEAD="$(git rev-parse HEAD)"
if [[ -z "$BASE_HEAD" ]]; then
  BASE_HEAD="$OLD_HEAD"
fi
BASE_SHORT="$(git rev-parse --short "$BASE_HEAD")"
OLD_SHORT="$(git rev-parse --short HEAD)"
TS="$(date '+%Y%m%d-%H%M%S')"

# add_php.sh 生成的 PHP 扩展编排一并纳入（与 uninstall.sh 的处理一致；旧部署无此文件时自动跳过）
COMPOSE_FILES=(-f docker-compose.yml)
if [[ -f docker-compose.override.yml ]]; then
  COMPOSE_FILES+=(-f docker-compose.override.yml)
fi

# 恢复函数：回退到升级前提交并重建容器（数据卷全程 bind 挂载，不受影响）
restore_service() {
  warn "正在恢复到升级前状态（$BASE_SHORT）..."
  git reset --hard "$BASE_HEAD" >/dev/null 2>&1 || true
  if $DC "${COMPOSE_FILES[@]}" up -d --build >/dev/null 2>&1; then
    ok "已恢复到 $BASE_SHORT 并重建容器（数据卷自始至终未被改动）"
  else
    warn "自动恢复失败，请手动执行: $DC ${COMPOSE_FILES[*]} up -d --build"
  fi
}

rollback() {
  echo
  warn "升级失败，开始回滚 ..."
  # 回滚前把当前工作区状态存入 stash，避免 reset --hard 丢弃任何未提交内容
  git stash push -m "pre-rollback-$TS" >/dev/null 2>&1 || true
  restore_service
  if [[ -n "$BK_FILE" && -f "$BK_FILE" ]]; then
    warn "如需恢复数据，备份位于: $BK_FILE"
  fi
  if [[ -n "$STASH_NAME" ]]; then
    warn "升级前的本地改动仍保留在 stash 中（git stash list 查看，git stash pop 恢复）。"
  fi
  die "升级失败，已回滚到 $BASE_SHORT。"
}

# 中止时把阶段一弹出的 stash 恢复原状（保证"取消升级 = 一切照旧"）
restore_stash_on_abort() {
  if [[ -n "$STASH_NAME" ]] && git stash list 2>/dev/null | head -1 | grep -qF "$STASH_NAME"; then
    git stash pop >/dev/null 2>&1 || true
  fi
}

# ───────────────────────── 阶段一：拉取代码（不停机）─────────────────────────
# 容器此时仍在运行；拉取失败直接退出，服务不受任何影响。
if [[ "$INNER" -eq 1 ]]; then
  log "自更新模式：代码拉取已由外层完成，跳过"
elif [[ "$DO_PULL" -eq 1 ]]; then
  # 只关心已跟踪文件的改动（untracked / gitignore 的运行期数据不影响 merge）
  if ! git diff --quiet || ! git diff --cached --quiet; then
    if confirm "检测到本地未提交改动，自动 stash 后升级（升级成功后尝试恢复）?"; then
      if git stash push -m "upgrade-auto-stash-$TS" >/dev/null 2>&1; then
        STASH_NAME="upgrade-auto-stash-$TS"
        ok "本地改动已 stash（$STASH_NAME）"
      else
        die "git stash 失败，已中止升级。请手动提交或 stash 本地改动后重试。"
      fi
    else
      die "已取消升级。请先提交或 stash 本地改动后重试。"
    fi
  fi

  log "拉取最新代码（git fetch + merge --ff-only，显式指定 origin/$BRANCH，不依赖上游配置）..."
  if ! git fetch origin "$BRANCH" 2>&1 | sed 's/^/[git] /'; then
    restore_stash_on_abort
    die "git fetch 失败（网络异常或远端分支 origin/$BRANCH 不存在）。服务未受影响，请检查后重试。"
  fi
  if ! git merge --ff-only "origin/$BRANCH" 2>&1 | sed 's/^/[git] /'; then
    restore_stash_on_abort
    die "本地提交与远端 origin/$BRANCH 分叉，已拒绝自动合并（服务未受影响）。请手动 git pull --rebase 处理后重试。"
  fi
  ok "代码已就绪"

  # 自更新：若 upgrade.sh 自身随本次拉取被更新，立即切换到新脚本重新执行。
  # 传递：升级基线提交（回滚目标）、stash 名称（收尾恢复）、INNER 标记（跳过重复拉取）。
  if ! git diff --quiet "$OLD_HEAD" HEAD -- upgrade.sh 2>/dev/null; then
    log "检测到 upgrade.sh 已随新版本更新，切换到新脚本重新执行..."
    # 显式 export（VAR=x exec 的导出语义在 POSIX 中未指定，不依赖）
    export UPGRADE_INNER=1
    export UPGRADE_BASE_HEAD="$OLD_HEAD"
    export UPGRADE_STASH_NAME="$STASH_NAME"
    exec bash "$PROJECT_DIR/upgrade.sh" "$@"
  fi
else
  log "已跳过代码拉取（--no-pull）"
fi

NEW_SHORT="$(git rev-parse --short HEAD)"

# ───────────────────────── 阶段二：停机与备份 ─────────────────────────
log "停止容器（bind 数据卷不受影响）..."
$DC "${COMPOSE_FILES[@]}" down --remove-orphans >/dev/null 2>&1 || true
ok "容器已停止"

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
      restore_service
      die "备份失败，已中止升级并恢复到 $BASE_SHORT（数据未做任何改动）。请检查磁盘空间后重试。"
    fi
  fi
else
  log "已跳过备份（--no-backup，不推荐）"
fi

# .env 变量完整性提示：新版本若新增变量，提醒手动补充（绝不自动改写 .env）。
# 旧部署的 .env 没有新变量属正常现象，只提示、不判失败。
if [[ -f .env.example && -f .env ]]; then
  MISSING_KEYS="$(comm -23 \
    <(grep -oE '^[A-Z_]+=' .env.example | tr -d '=' | sort -u) \
    <(grep -oE '^[A-Z_]+=' .env | tr -d '=' | sort -u) || true)"
  if [[ -n "$MISSING_KEYS" ]]; then
    warn ".env 缺少以下变量（新版可能新增），如需启用相关功能请参考 .env.example 手动补充："
    warn "  $MISSING_KEYS"
  fi
fi

# ───────────────────────── 阶段三：重建与健康检查 ─────────────────────────
log "重建镜像并启动容器（bind 数据卷不受影响）..."
if ! $DC "${COMPOSE_FILES[@]}" up -d --build; then
  warn "镜像构建或容器启动失败"
  rollback
fi

wait_port() {
  local port="$1" i
  for i in $(seq 1 60); do
    if curl -s -o /dev/null -m 2 "http://localhost:$port/"; then return 0; fi
    sleep 2
  done
  return 1
}

# 从 .env 读取变量值（兼容带/不带双引号的历史格式），绝不回显
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
  # 兼容旧版本部署：.env 缺 MYSQL_ROOT_PASSWORD 时降级为跳过，不判失败、不触发回滚
  MYSQL_PASS="$(read_env_var MYSQL_ROOT_PASSWORD || true)"
  if [[ -z "$MYSQL_PASS" ]]; then
    warn "未在 .env 中找到 MYSQL_ROOT_PASSWORD（旧版本部署），跳过 MySQL 连通检查"
  elif docker exec mysql8 mysql -uroot -p"$MYSQL_PASS" -e "SELECT 1" >/dev/null 2>&1; then
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

# ───────────────────────── 阶段四：收尾 ─────────────────────────
# 恢复升级前的本地改动（含自更新场景：外层 stash 的名称经环境变量传入）
if [[ -n "$STASH_NAME" ]]; then
  log "恢复升级前的本地改动（stash: $STASH_NAME）..."
  if git stash list 2>/dev/null | head -1 | grep -qF "$STASH_NAME"; then
    if git stash pop 2>&1 | sed 's/^/[git] /'; then
      ok "本地改动已恢复"
    else
      warn "stash 恢复存在冲突，改动保留在 stash 中（git stash list 查看），请手动处理。"
    fi
  else
    warn "未在 stash 栈顶找到 $STASH_NAME，请手动执行 git stash list 检查恢复。"
  fi
fi

# 面板模板更新后，旧编译缓存会导致页面未生效（README「常见问题」同款处理；旧部署无此目录时跳过）
if [[ -d data/kangle/nodewww/webftp/framework/templates_c ]]; then
  find data/kangle/nodewww/webftp/framework/templates_c -mindepth 1 -delete 2>/dev/null || true
  ok "已清理 Smarty 编译缓存（模板更新自动生效）"
fi

# ───────────────────────── 完成摘要 ─────────────────────────
echo
echo "=================================================================="
echo -e "  \033[1;32m升级完成\033[0m"
echo "=================================================================="
if [[ "$NEW_SHORT" == "$BASE_SHORT" ]]; then
  echo "  版本: $BASE_SHORT（无代码更新，仅重建容器）"
else
  echo "  版本: $BASE_SHORT -> $NEW_SHORT"
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
