#!/usr/bin/env bash
#
# install.sh — EasyPanel Heavy 一键安装脚本
#
# 职责：
#   1) 检测 Linux 发行版并自动安装 Docker Engine + Compose 插件（如未安装）。
#   2) 设置密码 —— kangle / easypanel 后台管理员（两者共用同一 kangle WHM 凭证）、
#      MySQL root。任意一项留空则自动生成 16 位随机强密码。
#   3) 生成 .env（KANGLE_ADMIN_PASSWORD / MYSQL_ROOT_PASSWORD）。
#   4) 构建并启动 docker compose 编排（kangle + mysql + 可选 phpX.X-fpm）。
#   5) 首次启动自动初始化 easypanel（写入 node 配置 + 建库 + install.lock）。
#   6) 可选启用 TCP BBR（提升网络吞吐，内核 >= 4.9 时可用）。
#   7) 集成 acme.sh（SSL 证书申请 / 自动续期底层工具）并注册容器内固定续期任务。
#   8) 安装时可选择额外 PHP-FPM 版本（PHP 7.4 已内置在主容器，无需额外安装）。
#
# 支持的发行版：
#   Debian 12 / 11
#   Ubuntu 24.04 / 22.04 / 20.04
#   CentOS 7 / 8
#   CentOS Stream 10 / 9
#   AlmaLinux 10 / 9 / 8
#   Rocky Linux 10 / 9 / 8
#
# 用法：
#   ./install.sh                              # 交互式（逐项询问，可留空随机）
#   ./install.sh --auto                       # 全部随机密码，启用 BBR，不选额外 PHP
#   ./install.sh --kangle-pass=XXX --mysql-pass=YYY
#   ./install.sh --php-versions=8.2,8.5       # 非交互安装额外 PHP 版本
#   ./install.sh --enable-bbr                 # 非交互启用 BBR
#   ./install.sh --force-recreate             # 先 down 再 up（干净重建，bind 数据卷不受影响）
#   注：也可经官网一键命令 bash <(curl -fsSL https://raw.githubusercontent.com/kanglesoft/EasyPanel/main/install.sh) 直接执行，脚本会自动克隆本仓库后再安装。
#
set -euo pipefail

# ───────────────────────── 自举：确保在仓库内 ─────────────────────────
# 官网一键脚本（bash <(curl -fsSL .../install.sh)）通常运行于任意目录且不在 git 仓库中，
# 这里自动克隆本仓库并进入后执行；若已克隆或本身就在仓库内，则跳过克隆、绝不重复克隆。
REPO_URL="https://github.com/kanglesoft/EasyPanel.git"
REPO_DIR="EasyPanel"

_in_our_repo() {
  git rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 1
  local remote
  remote="$(git remote get-url origin 2>/dev/null || true)"
  if [[ "$remote" == *"kanglesoft/EasyPanel"* ]]; then return 0; fi
  [[ -f docker-compose.yml && -f install.sh ]] && return 0
  return 1
}

if ! _in_our_repo; then
  if [[ -d "$REPO_DIR/.git" ]]; then
    echo "[install] 检测到已克隆的仓库，直接进入 $REPO_DIR"
    cd "$REPO_DIR" || { echo "[error] 无法进入 $REPO_DIR" >&2; exit 1; }
  else
    command -v git >/dev/null 2>&1 || { echo "[error] 未检测到 git，请先安装 git 后重试。" >&2; exit 1; }
    echo "[install] 未处于仓库内，正在克隆 $REPO_URL"
    git clone "$REPO_URL" "$REPO_DIR" || { echo "[error] 克隆仓库失败，请检查网络或手动克隆：$REPO_URL" >&2; exit 1; }
    cd "$REPO_DIR" || { echo "[error] 无法进入 $REPO_DIR" >&2; exit 1; }
  fi
  exec bash "./install.sh" "$@"
fi
# ───────────────────────────────────────────────────────────────────

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# ───────────────────────── 日志工具 ─────────────────────────
log()  { echo -e "\033[1;36m[install]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ ok ]\033[0m $*"; }
warn() { echo -e "\033[1;33m[warn]\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

genpass() {
  LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 16
}

# ───────────────────────── 参数解析 ─────────────────────────
AUTO=0
FORCE_RECREATE=0
ENABLE_BBR=0
KANGLE_PASS=""
MYSQL_PASS=""
EASYPANEL_PASS=""
PHP_VERSIONS=""   # 逗号分隔，如 8.2,8.5
while [[ $# -gt 0 ]]; do
  case "$1" in
    --auto) AUTO=1 ;;
    --enable-bbr) ENABLE_BBR=1 ;;
    --force-recreate) FORCE_RECREATE=1 ;;
    --kangle-pass=*) KANGLE_PASS="${1#*=}" ;;
    --mysql-pass=*)  MYSQL_PASS="${1#*=}" ;;
    --easypanel-pass=*) EASYPANEL_PASS="${1#*=}" ;;
    --php-versions=*) PHP_VERSIONS="${1#*=}" ;;
    -h|--help) sed -n '3,31p' "$0"; exit 0 ;;
    *) warn "未知参数: $1" ;;
  esac
  shift
done

# ───────────────────────── 权限检查 ─────────────────────────
if [[ "$EUID" -ne 0 ]]; then
  die "请使用 root 用户运行此脚本（需要安装 Docker 与系统服务）。"
fi

# ───────────────────────── 发行版检测 ─────────────────────────
OS_ID=""
OS_NAME=""
OS_VERSION=""
OS_MAJOR=0

if [[ -f /etc/os-release ]]; then
  # shellcheck source=/dev/null
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_NAME="${NAME:-}"
  OS_VERSION="${VERSION_ID:-}"
  OS_MAJOR="${OS_VERSION%%.*}"
fi

if [[ -z "$OS_ID" ]]; then
  die "无法识别操作系统，请手动安装 Docker 后重试。"
fi

log "检测到系统: $OS_NAME $OS_VERSION"

# 支持的发行版白名单
case "$OS_ID" in
  debian)
    [[ "$OS_MAJOR" =~ ^(11|12)$ ]] || die "不支持的 Debian 版本: $OS_VERSION（仅支持 11/12）。"
    ;;
  ubuntu)
    [[ "$OS_VERSION" =~ ^(20\.04|22\.04|24\.04)$ ]] || die "不支持的 Ubuntu 版本: $OS_VERSION（仅支持 20.04/22.04/24.04）。"
    ;;
  centos)
    [[ "$OS_MAJOR" =~ ^(7|8)$ ]] || die "不支持的 CentOS 版本: $OS_VERSION（仅支持 7/8）。"
    ;;
  centos-stream|centos_stream)
    [[ "$OS_MAJOR" =~ ^(9|10)$ ]] || die "不支持的 CentOS Stream 版本: $OS_VERSION（仅支持 9/10）。"
    ;;
  almalinux|alma)
    [[ "$OS_MAJOR" =~ ^(8|9|10)$ ]] || die "不支持的 AlmaLinux 版本: $OS_VERSION（仅支持 8/9/10）。"
    ;;
  rocky|rockylinux)
    [[ "$OS_MAJOR" =~ ^(8|9|10)$ ]] || die "不支持的 Rocky Linux 版本: $OS_VERSION（仅支持 8/9/10）。"
    ;;
  *)
    die "不支持的发行版: $OS_NAME（支持 Debian/Ubuntu/CentOS/CentOS Stream/AlmaLinux/Rocky Linux）。"
    ;;
esac

# ───────────────────────── Docker 安装 ─────────────────────────
install_docker_debian() {
  local repo="$1"
  local codename
  codename="$(lsb_release -cs 2>/dev/null || echo "")"
  [[ -z "$codename" ]] && die "无法获取 Debian/Ubuntu 发行版代号（lsb_release 失败）。"

  apt-get update
  apt-get install -y ca-certificates curl gnupg lsb-release

  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/${repo}/gpg" \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg

  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
    https://download.docker.com/linux/${repo} ${codename} stable" \
    > /etc/apt/sources.list.d/docker.list

  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

install_docker_rhel() {
  local pkg_manager="dnf"
  command -v dnf >/dev/null 2>&1 || pkg_manager="yum"

  if [[ "$pkg_manager" == "yum" ]]; then
    yum install -y yum-utils
    yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    $pkg_manager -y install dnf-plugins-core
    $pkg_manager config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    $pkg_manager install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  fi
}

ensure_docker() {
  if command -v docker >/dev/null 2>&1; then
    if docker info >/dev/null 2>&1; then
      ok "Docker 已安装且运行"
      return 0
    fi
  fi

  log "开始安装 Docker Engine + Compose 插件..."
  case "$OS_ID" in
    debian|ubuntu)
      install_docker_debian "$OS_ID"
      ;;
    centos|centos-stream|centos_stream|almalinux|alma|rocky|rockylinux)
      install_docker_rhel
      ;;
    *)
      die "当前发行版未实现自动安装 Docker，请手动安装后重试。"
      ;;
  esac

  systemctl enable --now docker

  if ! docker info >/dev/null 2>&1; then
    die "Docker 安装后无法启动，请检查系统日志。"
  fi
  ok "Docker 安装完成并已启动"
}

ensure_docker

if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  DC="docker-compose"
else
  die "未检测到 docker compose 插件，请检查 Docker 安装是否完整。"
fi

# ───────────────────────── BBR 启用 ─────────────────────────
enable_bbr() {
  local kernel_major kernel_minor
  kernel_major="$(uname -r | cut -d. -f1)"
  kernel_minor="$(uname -r | cut -d. -f2)"

  if [[ "$kernel_major" -lt 4 || ( "$kernel_major" -eq 4 && "$kernel_minor" -lt 9 ) ]]; then
    warn "当前内核 $(uname -r) 不支持 BBR（需 >= 4.9），跳过。"
    return 1
  fi

  if ! modprobe tcp_bbr >/dev/null 2>&1; then
    warn "无法加载 tcp_bbr 模块，跳过 BBR。"
    return 1
  fi

  cat >> /etc/sysctl.conf <<'EOF'

# 启用 TCP BBR（由 install.sh 添加）
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
EOF
  sysctl -p >/dev/null 2>&1

  if [[ "$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null)" == "bbr" ]]; then
    ok "BBR 已启用"
    return 0
  fi

  warn "BBR 配置已写入，但当前未生效（可能需要重启）。"
}

# ───────────────────────── 密码收集 ─────────────────────────
# 说明：easypanel 后台管理员与 kangle 管理员是同一凭证（登录时经 kangle WHM 校验），
#       因此两者必须一致；若分别指定且不一致，以 kangle 为准并提示。
if [[ "$AUTO" -eq 1 ]]; then
  KANGLE_PASS="$(genpass)"
  MYSQL_PASS="$(genpass)"
  log "自动模式：已生成随机密码"
else
  if [[ -z "$KANGLE_PASS" ]]; then
    read -r -p "kangle / easypanel 管理员密码 [留空随机生成]: " KANGLE_PASS
    KANGLE_PASS="${KANGLE_PASS:-$(genpass)}"
  fi
  if [[ -z "$EASYPANEL_PASS" ]]; then
    read -r -p "easypanel 管理员密码 [留空等同 kangle]: " EASYPANEL_PASS
    EASYPANEL_PASS="${EASYPANEL_PASS:-$KANGLE_PASS}"
  fi
  if [[ "$EASYPANEL_PASS" != "$KANGLE_PASS" ]]; then
    warn "easypanel 后台与 kangle 共用同一管理员凭证，已统一使用 kangle 密码。"
    EASYPANEL_PASS="$KANGLE_PASS"
  fi
  if [[ -z "$MYSQL_PASS" ]]; then
    read -r -p "MySQL root 密码 [留空随机生成]: " MYSQL_PASS
    MYSQL_PASS="${MYSQL_PASS:-$(genpass)}"
  fi

  # ── 额外 PHP 版本选择 ──
  if [[ -z "$PHP_VERSIONS" ]]; then
    echo
    log "PHP 版本选择"
    echo "  - PHP 7.4 将默认安装在主容器（kangle）中。"
    echo "  - 其他版本（如 8.2 / 8.5）会以独立 FPM 容器运行，与 add_php.sh 效果相同。"
    echo "  - 版本号可参考 https://www.php.net/supported-versions 或 https://www.php.net/releases"
    read -r -p "请输入需要额外安装的 PHP 版本（多个用逗号分隔，如 8.2,8.5；留空则不安装）: " PHP_VERSIONS
  fi

  # ── BBR 选择 ──
  if [[ "$ENABLE_BBR" -ne 1 ]]; then
    echo
    read -r -p "是否启用 TCP BBR 拥塞控制算法？[y/N]: " bbr_ans
    [[ "$bbr_ans" =~ ^[Yy]$ ]] && ENABLE_BBR=1
  fi
fi

# 解析并校验额外 PHP 版本
EXTRA_PHP=()
if [[ -n "$PHP_VERSIONS" ]]; then
  IFS=',' read -ra raw_versions <<< "$PHP_VERSIONS"
  for v in "${raw_versions[@]}"; do
    v="$(echo "$v" | tr -d '[:space:]')"
    [[ -z "$v" ]] && continue
    if [[ ! "$v" =~ ^[0-9]+\.[0-9]+$ ]]; then
      warn "忽略非法版本号: $v"
      continue
    fi
    if [[ "$v" == "7.4" ]]; then
      warn "PHP 7.4 已内置在主容器，无需额外指定，已跳过。"
      continue
    fi
    EXTRA_PHP+=("$v")
  done
fi

# ───────────────────────── 前置检查 ─────────────────────────
[[ "$(docker info --format '{{.ServerVersion}}' 2>/dev/null)" ]] || die "docker 守护进程未运行。"

# 确保数据目录存在（bind 挂载点）
mkdir -p data/kangle data/mysql data/homeftp data/acme

# ───────────────────────── 启用 BBR（如选择）────────────────────────
if [[ "$ENABLE_BBR" -eq 1 ]]; then
  log "检查并启用 BBR..."
  enable_bbr
fi

# ───────────────────────── 写 .env ─────────────────────────
cat > .env <<EOF
# 由 install.sh 生成 — $(date '+%Y-%m-%d %H:%M:%S')
# kangle 管理员 / easypanel 后台管理员（同一凭证）
KANGLE_ADMIN_PASSWORD=$KANGLE_PASS
# MySQL root（及 easypanel 专用库用户）密码
MYSQL_ROOT_PASSWORD=$MYSQL_PASS
EOF
ok ".env 已生成"

# ───────────────────────── 构建并启动 ─────────────────────────
if [[ "$FORCE_RECREATE" -eq 1 ]]; then
  log "清理旧容器 / 网络（bind 数据卷不受影响）..."
  $DC down --remove-orphans >/dev/null 2>&1 || true
fi

log "构建并启动服务（kangle + mysql）..."
$DC up -d --build

# ───────────────────────── 等待端口就绪 ─────────────────────────
wait_port() {
  local port="$1" i
  for i in $(seq 1 60); do
    if curl -s -o /dev/null -m 2 "http://localhost:$port/"; then return 0; fi
    sleep 2
  done
  return 1
}
for p in 3311 3312 80; do
  if wait_port "$p"; then ok "端口 $p 已就绪"; else warn "端口 $p 未在预期时间内响应（将继续）"; fi
done

# ───────────────────────── 初始化 easypanel ─────────────────────────
log "初始化 / 登录 easypanel 以完成首次安装..."
INIT_HTTP=$(curl -s -c /tmp/ep_install_cookie.txt -o /tmp/ep_install.html -w "%{http_code}" -m 20 \
  -X POST "http://localhost:3312/index.php?c=session&a=login" \
  --data-urlencode "username=admin" \
  --data-urlencode "passwd=$KANGLE_PASS" 2>/dev/null || echo 000)
if docker exec kangle test -f /vhs/kangle/nodewww/webftp/framework/install.lock 2>/dev/null; then
  ok "easypanel 已安装（install.lock 存在）"
else
  warn "easypanel 安装标记未生成（HTTP=$INIT_HTTP）。可登录后台继续完成初始化向导。"
fi

# ───────────────────────── 集成 acme.sh（SSL 底层工具）─────────────────────────
log "安装 acme.sh 到 kangle 容器（用于 SSL 证书申请 / 续期）..."
if docker exec kangle sh -c 'command -v acme.sh >/dev/null 2>&1' 2>/dev/null; then
  ok "acme.sh 已存在，跳过安装"
else
  # acme.sh 主目录挂载在 ./data/acme（持久化）；官方安装器写入 /root/.acme.sh
  docker exec kangle sh -c 'curl -sL https://get.acme.sh | sh' >/dev/null 2>&1 \
    && ok "acme.sh 安装完成" \
    || warn "acme.sh 安装失败（需容器可访问外网）。可稍后手动在容器内执行: curl -sL https://get.acme.sh | sh"
fi
# 注册自动续期（容器内 crond 已在运行）
docker exec kangle sh -c '
  if command -v acme.sh >/dev/null 2>&1; then
    ( crontab -l 2>/dev/null | grep -q "acme.sh" ) || \
    ( ( crontab -l 2>/dev/null; echo "0 3 * * * \"/root/.acme.sh/acme.sh\" --cron --home \"/root/.acme.sh\" > /dev/null 2>&1" ) | crontab - )
    echo "[install] acme.sh 续期任务已注册"
  fi
' 2>/dev/null || true
AV=$($DC exec -T kangle acme.sh --version 2>/dev/null | head -1 || true)
[[ -n "$AV" ]] && ok "acme.sh: $AV"

# ───────────────────────── 安装额外 PHP 版本 ─────────────────────────
for v in "${EXTRA_PHP[@]}"; do
  log "安装额外 PHP $v ..."
  if [[ -x ./add_php.sh ]]; then
    ./add_php.sh "$v" || warn "PHP $v 安装失败"
  else
    warn "add_php.sh 不存在或不可执行，跳过 PHP $v"
  fi
done

# ───────────────────────── 完成摘要 ─────────────────────────
echo
echo "=================================================================="
echo -e "  \033[1;32m安装完成\033[0m"
echo "=================================================================="
echo -e "  面板管理员 (kangle 3311 / easypanel 3312): \033[1;33m$KANGLE_PASS\033[0m"
echo -e "  MySQL root 密码:                          \033[1;33m$MYSQL_PASS\033[0m"
echo
echo "  访问地址："
echo "    kangle 管理:  http://<服务器IP>:3311/   (admin / 上述密码)"
echo "    easypanel 后台: http://<服务器IP>:3312/  (admin / 上述密码)"
echo "    phpMyAdmin:    http://<服务器IP>:3313/   (BasicAuth: 同 MySQL root)"
echo
echo "  已安装组件："
echo "    - kangle + easypanel（内置 PHP 7.4）"
[[ ${#EXTRA_PHP[@]} -gt 0 ]] && echo "    - 额外 PHP 版本: $(IFS=,; echo "${EXTRA_PHP[*]}")"
[[ ${#EXTRA_PHP[@]} -eq 0 ]] && echo "    - 未安装额外 PHP 版本"
[[ "$ENABLE_BBR" -eq 1 ]] && echo "    - TCP BBR 已启用" || echo "    - TCP BBR 未启用"
echo "    - acme.sh 已集成（用于 SSL 证书）"
echo
echo -e "  \033[1;31m请妥善保管以上密码（尤其是随机生成时，仅此一处可见）。\033[0m"
echo "=================================================================="
