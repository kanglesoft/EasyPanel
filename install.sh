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
# 支持的发行版（仅 deb 系 + rhel 系；不支持 Alpine / openSUSE）：
#   deb:  Debian 11/12/13、Ubuntu 20.04/22.04/24.04/26.04 LTS（及衍生 deb 系）
#   rhel: CentOS Stream 9/10、RHEL 8/9/10、AlmaLinux 8/9/10、Rocky 8/9/10、
#         Oracle Linux 8/9/10、Amazon Linux 2023、Fedora
#   低于门槛的版本（如 Debian ≤10、Ubuntu ≤18.04、CentOS 6/7/8、CentOS Stream 8、
#   RHEL 7 等）已 EOL，安装脚本会告警但仍可继续；不在清单的发行版若已预装 Docker
#   也可直接运行（否则会提示手动安装 Docker 后重跑）。
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
  [[ -f docker-compose.yml && -f install.sh ]] && return 0
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

# ───────────────────────── phpMyAdmin vendor 自检 ─────────────────────────
# fix(phpmyadmin): Composer vendor 内含若干名为 "cache" 的源码目录
# (phpmyadmin/motranslator/src/Cache、psr/cache、symfony/cache、twig/twig/src/Cache)。
# 历史上 .gitignore 的 `data/kangle/**/cache/` 规则曾误伤它们，导致 git clone 后
# phpMyAdmin 登录页直接 500（"Class 'PhpMyAdmin\MoTranslator\Cache\InMemoryCache'
# not found"）。这里做一遍自愈：若缺失则尝试从已提交的仓库 git checkout 还原。
_verify_phpmyadmin_vendor() {
  local vdir="data/kangle/nodewww/dbadmin/mysql/vendor"
  local needed=(
    "$vdir/phpmyadmin/motranslator/src/Cache/InMemoryCache.php"
    "$vdir/psr/cache"
    "$vdir/symfony/cache"
    "$vdir/twig/twig/src/Cache"
  )
  local missing=0
  for f in "${needed[@]}"; do
    [[ -e "$f" ]] || { missing=1; break; }
  done
  [[ "$missing" -eq 0 ]] && return 0
  warn "检测到 phpMyAdmin vendor 缺失 cache 组件，尝试从仓库还原…"
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git checkout -- "${needed[@]}" 2>/dev/null \
      || git checkout HEAD -- "${needed[@]}" 2>/dev/null \
      || true
  fi
  for f in "${needed[@]}"; do
    [[ -e "$f" ]] || die "phpMyAdmin vendor 仍缺失必要组件: $f（请确认已提交 vendor cache 目录）"
  done
  ok "phpMyAdmin vendor cache 组件已就绪"
}
_verify_phpmyadmin_vendor

# ───────────────────────── 日志工具 ─────────────────────────
log()  { echo -e "\033[1;36m[install]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[ ok ]\033[0m $*"; }
warn() { echo -e "\033[1;33m[warn]\033[0m $*"; }
die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

genpass() {
  LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 16 || true
}

urlencode() {
  # 对 curl 凭证做最小转义：处理 % : @ / 四个会破坏 -u / URL 解析的字符
  local s="$1"
  s="${s//%/%25}"; s="${s//:/%3A}"; s="${s//@/%40}"; s="${s//\//%2F}"
  printf '%s' "$s"
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

# ───────────────────────── 包管理器 / 家族检测 ─────────────────────────
detect_pkg_manager() {
  # 基于已 source 的 ID / ID_LIKE / VERSION_ID 推断家族与包管理器。
  # 设置 FAMILY(deb|rhel|unknown) 与 PKG_MGR(apt|dnf|yum|unknown)。
  FAMILY="unknown"
  PKG_MGR="unknown"

  local hay
  hay="$(echo "${ID:-} ${ID_LIKE:-}" | tr '[:upper:]' '[:lower:]')"

  case "$hay" in
    *debian*|*ubuntu*|*linuxmint*|*raspbian*|*kali*)
      FAMILY="deb"
      PKG_MGR="apt"
      return 0
      ;;
  esac

  case "$hay" in
    *rhel*|*centos*|*fedora*|*ol*|*oracle*|*amzn*|*alma*|*rocky*)
      FAMILY="rhel"
      if command -v dnf >/dev/null 2>&1; then
        PKG_MGR="dnf"
      else
        PKG_MGR="yum"
      fi
      return 0
      ;;
  esac

  FAMILY="unknown"
  PKG_MGR="unknown"
}

check_version_gate() {
  # 设置版本门禁标志：
  #   DEPRECATED=1 表示系统已 EOL（仍允许安装，仅告警）
  #   UNTESTED=1   表示超出本项目测试矩阵上限（软警告，不阻断）
  DEPRECATED=0
  UNTESTED=0

  if [[ "$FAMILY" == "deb" ]]; then
    # Ubuntu 及 ubuntu 衍生按 Ubuntu 规则（VERSION_ID >= 20.04）
    local is_ubuntu=0
    case " $(echo "${ID:-} ${ID_LIKE:-}" | tr '[:upper:]' '[:lower:]') " in
      *ubuntu*) is_ubuntu=1 ;;
    esac

    if [[ "$is_ubuntu" -eq 1 ]]; then
      local major minor ver
      major="$(echo "${OS_VERSION:-}" | cut -d. -f1)"
      minor="$(echo "${OS_VERSION:-}" | cut -d. -f2)"
      major="${major:-0}"; minor="${minor:-0}"
      ver=$(( major * 100 + minor ))
      if (( ver < 2004 )); then
        DEPRECATED=1
      elif (( ver > 2604 )); then
        UNTESTED=1
      fi
    else
      # Debian 及衍生（含 linuxmint / raspbian / kali 等）要求 major >= 11
      local major="${OS_MAJOR:-0}"
      major="${major:-0}"
      if (( major < 11 )); then
        DEPRECATED=1
      elif (( major > 13 )); then
        UNTESTED=1
      fi
    fi
  elif [[ "$FAMILY" == "rhel" ]]; then
    local major="${OS_MAJOR:-0}"
    major="${major:-0}"
    if [[ "$OS_ID" == "centos" || "$OS_ID" == "centos-linux" ]]; then
      # CentOS Linux（非 Stream）已整体 EOL
      DEPRECATED=1
    elif [[ "$OS_ID" == "centos-stream" || "$OS_ID" == "centos_stream" ]] && (( major <= 8 )); then
      # CentOS Stream 8 EOL；Stream 9/10 受支持
      DEPRECATED=1
    elif (( major < 8 )); then
      DEPRECATED=1
    elif (( major > 10 )) && [[ "$OS_ID" != "fedora" && "$OS_ID" != "amzn" ]]; then
      # Fedora（滚动版本号）与 Amazon Linux（日期版本号）不计入 EOL 上限，已在支持清单
      UNTESTED=1
    fi
  fi
}

if [[ -z "$OS_ID" ]]; then
  die "无法识别操作系统，请手动安装 Docker 后重试。"
fi

log "检测到系统: $OS_NAME $OS_VERSION"

# ── 检测包管理器 / 家族 ──
SKIP_DOCKER_INSTALL=0
detect_pkg_manager

if [[ "$FAMILY" == "unknown" ]]; then
  # 未知发行版兜底：若已装且可用的 Docker 则直接进入安装，否则给出文档链接并优雅退出
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    warn "当前发行版不在支持清单，但检测到 Docker 已安装，将直接进入安装"
    SKIP_DOCKER_INSTALL=1
  else
    echo -e "\033[1;31m[error]\033[0m 未识别的发行版，请参考 https://docs.docker.com/engine/install/ 手动安装 Docker Engine 与 Compose 插件后重跑本脚本。"
    exit 1
  fi
else
  # 已知家族：执行版本门禁检查（EOL 仅告警，不阻断安装）
  check_version_gate
  if [[ "$DEPRECATED" -eq 1 ]]; then
    echo -e "\033[1;33m[DEPRECATED]\033[0m 当前系统 $OS_NAME $OS_VERSION 已 EOL，存在安全风险，建议升级到受支持版本"
  fi
  if [[ "$UNTESTED" -eq 1 ]]; then
    warn "当前版本超出本项目测试矩阵，如遇问题请反馈"
  fi
fi

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

  # Docker 官方 repo 按发行族选择：Fedora 使用 fedora repo，其余 RHEL 系沿用 centos repo，
  # 避免 Fedora 直接使用 centos repo 引发依赖冲突（中风险项修复）。
  local docker_repo_url
  if [[ "$OS_ID" == "fedora" ]]; then
    docker_repo_url="https://download.docker.com/linux/fedora/docker-ce.repo"
  else
    docker_repo_url="https://download.docker.com/linux/centos/docker-ce.repo"
  fi

  if [[ "$pkg_manager" == "yum" ]]; then
    yum install -y yum-utils
    yum-config-manager --add-repo "$docker_repo_url"
    yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    $pkg_manager -y install dnf-plugins-core
    $pkg_manager config-manager --add-repo "$docker_repo_url"
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

  # 未知家族但已检测到可用 Docker（SKIP_DOCKER_INSTALL=1）时不再尝试安装
  if [[ "${SKIP_DOCKER_INSTALL:-0}" -eq 1 ]]; then
    ok "检测到可用 Docker，跳过自动安装"
    return 0
  fi

  log "开始安装 Docker Engine + Compose 插件..."
  case "$FAMILY" in
    deb)
      install_docker_debian "$OS_ID"
      ;;
    rhel)
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
kernel_supports_bbr() {
  # 内核 >= 4.9 才支持 TCP BBR；返回 0 表示支持，1 表示不支持
  local k_major k_minor
  k_major="$(uname -r | cut -d. -f1)"
  k_minor="$(uname -r | cut -d. -f2)"
  k_major="${k_major:-0}"; k_minor="${k_minor:-0}"
  if (( k_major > 4 )) || { (( k_major == 4 )) && (( k_minor >= 9 )); }; then
    return 0
  fi
  return 1
}

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

  if ! grep -qxF 'net.core.default_qdisc = fq' /etc/sysctl.conf && ! grep -qxF 'net.ipv4.tcp_congestion_control = bbr' /etc/sysctl.conf; then
    cat >> /etc/sysctl.conf <<'EOF'

# 启用 TCP BBR（由 install.sh 添加）
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
EOF
  else
    log "BBR 配置已存在 /etc/sysctl.conf，跳过重复追加"
  fi
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

# ───────────────────────── BBR 内核门控（选项层前置）─────────────────────────
# 内核 < 4.9 时 BBR 不可用：强制关闭，并隐藏交互提问（--auto 保持静默、无变化）
if ! kernel_supports_bbr; then
  if [[ "$ENABLE_BBR" -eq 1 ]]; then
    warn "内核 $(uname -r) 低于 4.9，--enable-bbr 已自动跳过"
  elif [[ "$AUTO" -ne 1 ]]; then
    warn "内核 $(uname -r) 低于 4.9，跳过 BBR 增强"
  fi
  ENABLE_BBR=0
fi

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

  # ── BBR 选择（内核 < 4.9 已由内核门控隐藏选项）──
  if kernel_supports_bbr && [[ "$ENABLE_BBR" -ne 1 ]]; then
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
KANGLE_ADMIN_PASSWORD="$KANGLE_PASS"
# MySQL root（及 easypanel 专用库用户）密码
MYSQL_ROOT_PASSWORD="$MYSQL_PASS"
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

# ───────────────────────── MySQL root 密码幂等处理（修复问题[2]）─────────────────────────
# 背景：带持久化数据（./data/mysql）重装时，MySQL 仅在首次初始化设置 root 密码；
#       .env 里的 MYSQL_ROOT_PASSWORD 是本次安装生成的，可能与实际 root 密码脱节，
#       导致 phpMyAdmin / easypanel 连库失败 (Access denied)。
# 处理：MySQL 就绪后，若用 MYSQL_ROOT_PASSWORD 连不上，则启动一个临时实例以
#       --init-file 方式重置 root@'%' / root@'localhost' / easypanel@'%' 密码，
#       再按 compose 定义（已带 --mysql-native-password=ON）正常重启 mysql。
# 注意：MySQL 8.4 默认不再加载 mysql_native_password 插件，--skip-grant-tables 下
#       也无法 ALTER ... IDENTIFIED WITH mysql_native_password（报
#       "Plugin 'mysql_native_password' is not loaded"）。故必须用 --init-file 让
#       实例以正常方式（插件已加载）启动并执行重置 SQL。
ensure_mysql_password() {
  local pass="$MYSQL_PASS"
  [ -z "$pass" ] && { log "MYSQL_PASS 为空，跳过 MySQL 密码幂等处理"; return 0; }
  log "校验 MySQL root 密码与 .env 是否一致..."
  local i ok_conn=0
  for i in $(seq 1 60); do
    if docker exec mysql8 mysql -uroot -p"$pass" -e "SELECT 1" >/dev/null 2>&1; then
      ok_conn=1; break
    fi
    sleep 2
  done
  if [ "$ok_conn" -eq 1 ]; then ok "MySQL root 密码与 .env 一致"; return 0; fi

  warn "MySQL root 密码与 .env 不一致，将以 .env 密码重置（临时 --init-file 容器）..."
  # 停止常驻 mysql8，避免与临时容器争用同一数据卷的锁
  $DC stop mysql8 >/dev/null 2>&1 || docker stop mysql8 >/dev/null 2>&1 || true
  docker rm -f mysql_reset_tmp >/dev/null 2>&1 || true

  # 写 init-file：镜像 01-init.sh 的密码设置逻辑
  # （root@localhost / root@% / easypanel@% 均改为 mysql_native_password）
  local reset_sql="$PROJECT_DIR/data/mysql_reset_tmp.sql"
  cat > "$reset_sql" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$pass';
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED WITH mysql_native_password BY '$pass';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY '$pass';
CREATE DATABASE IF NOT EXISTS easypanel DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'easypanel'@'%' IDENTIFIED WITH mysql_native_password BY '$pass';
ALTER USER 'easypanel'@'%' IDENTIFIED WITH mysql_native_password BY '$pass';
GRANT ALL PRIVILEGES ON *.* TO 'easypanel'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
  chmod 644 "$reset_sql"

  # 临时实例挂载同一 mysql 数据卷 + reset.sql，并显式启用 native_password 插件
  docker run --rm -d --name mysql_reset_tmp \
    -v "$PROJECT_DIR/data/mysql:/var/lib/mysql" \
    -v "$reset_sql:/reset.sql:ro" \
    mysql:8 mysqld --mysql-native-password=ON --init-file=/reset.sql >/dev/null 2>&1 || true

  # 等待重置生效（用新密码连接）
  local ok_reset=0
  for i in $(seq 1 40); do
    if docker exec mysql_reset_tmp mysql -uroot -p"$pass" -e "SELECT 1" >/dev/null 2>&1; then
      ok_reset=1; break
    fi
    sleep 2
  done
  docker stop mysql_reset_tmp >/dev/null 2>&1 || true
  docker rm -f mysql_reset_tmp >/dev/null 2>&1 || true
  rm -f "$reset_sql" >/dev/null 2>&1 || true

  if [ "$ok_reset" -ne 1 ]; then
    warn "MySQL 密码重置失败，请手动检查 ./data/mysql"; return 1
  fi

  # 按 compose 定义重启常驻 mysql8（compose 已带 --mysql-native-password=ON）
  $DC up -d mysql >/dev/null 2>&1 || docker start mysql8 >/dev/null 2>&1 || true
  for i in $(seq 1 60); do
    if docker exec mysql8 mysql -uroot -p"$pass" -e "SELECT 1" >/dev/null 2>&1; then
      ok "MySQL root 密码已重置为 .env 密码"; return 0
    fi
    sleep 2
  done
  warn "MySQL 密码重置后仍无法连接（请手动检查 ./data/mysql）"
}
ensure_mysql_password

# ───────────────────────── 初始化 easypanel ─────────────────────────
log "初始化 / 登录 easypanel 以完成首次安装..."
INIT_HTTP=$(curl -s -c /tmp/ep_install_cookie.txt -o /tmp/ep_install.html -w "%{http_code}" -m 20 \
  -X POST "http://localhost:3312/admin/index.php?c=session&a=login" \
  --data-urlencode "username=admin" \
  --data-urlencode "passwd=$KANGLE_PASS" 2>/dev/null || echo 000)
# 校验登录是否真实成功（而非仅看 install.lock 文件是否存在）：
#  1) 登录接口应返回 200；2) 用登录 cookie 访问后台首页应返回 200（非停留在登录页）；3) install.lock 应存在。
if [[ "$INIT_HTTP" == "200" ]] && docker exec kangle test -f /vhs/kangle/nodewww/webftp/framework/install.lock 2>/dev/null; then
  DASH_HTTP=$(curl -s -b /tmp/ep_install_cookie.txt -o /dev/null -w "%{http_code}" -m 20 "http://localhost:3312/" 2>/dev/null || echo 000)
  if [[ "$DASH_HTTP" == "200" ]]; then
    ok "easypanel 已安装并登录成功（登录接口 HTTP=$INIT_HTTP，后台首页 HTTP=$DASH_HTTP，install.lock 存在）"
  else
    warn "easypanel 安装标记存在且登录接口返回 $INIT_HTTP，但后台首页访问返回 $DASH_HTTP（请用生成的密码手动登录确认）。"
  fi
else
  LOCK_EXISTS=$(docker exec kangle test -f /vhs/kangle/nodewww/webftp/framework/install.lock 2>/dev/null && echo 存在 || echo 不存在)
  warn "easypanel 初始化未完成：登录接口 HTTP=$INIT_HTTP，install.lock=$LOCK_EXISTS。可登录后台继续完成初始化向导。"
fi

# ───────────────────────── 强制持久化强口令（防止 kangle 重载/重启回退为弱口令）─────────────────────────
# 背景：kangle 的 admin 口令若仅经 WHM API 注入运行内存，SIGHUP 软重载会重新读取磁盘 config.xml；
#       若磁盘仍是镜像默认弱口令 'kangle'，就会被回退成弱口令。此处把安装时设定的强口令直接写回
#       磁盘 config.xml 与 easypanel 节点配置 node.cfg.php，并 SIGHUP 软重载（此时磁盘已是强口令，重载不会回退）。
if docker exec kangle test -f /vhs/kangle/etc/config.xml 2>/dev/null; then
  log "强制将强口令持久化到磁盘（config.xml / node.cfg.php）..."
  docker exec kangle sed -i -E "s#(<admin [^>]*password=')[^']*(')#\1$KANGLE_PASS\2#" /vhs/kangle/etc/config.xml 2>/dev/null || true
  if docker exec kangle test -f /vhs/kangle/etc/node.cfg.php 2>/dev/null; then
    docker exec kangle sed -i -E "s#('passwd\"=>\")[^\"]*(\")#\1$KANGLE_PASS\2#g" /vhs/kangle/etc/node.cfg.php 2>/dev/null || true
    docker exec kangle sed -i -E "s#('db_passwd\"=>\")[^\"]*(\")#\1$MYSQL_PASS\2#g" /vhs/kangle/etc/node.cfg.php 2>/dev/null || true
  fi
  # SIGHUP 软重载（磁盘已是强口令，安全；不会回退为弱口令）
  if docker exec kangle sh -c 'kill -HUP "$(pgrep -o kangle)"' 2>/dev/null; then
    sleep 2
    ok "kangle 已软重载，磁盘强口令已生效"
  else
    warn "kangle SIGHUP 失败（可忽略，容器下次重启会自动生效）"
  fi
  # 验证：强口令应可登录 WHM，默认弱口令 'kangle' 应被拒绝
  enc_pass="$(urlencode "$KANGLE_PASS")"
  CODE=$(curl -s -o /dev/null -m5 -w "%{http_code}" "http://admin:${enc_pass}@localhost:3311/" 2>/dev/null || echo 000)
  BAD=$(curl -s -o /dev/null -m5 -w "%{http_code}" -u "admin:kangle" http://localhost:3311/ 2>/dev/null || echo 000)
  if [ "$CODE" = "200" ] && [ "$BAD" != "200" ]; then
    ok "验证通过：强口令生效，弱口令 'kangle' 已被拒绝"
  else
    warn "验证异常：强口令HTTP=$CODE 弱口令HTTP=$BAD（请手动检查 /vhs/kangle/etc/config.xml）"
  fi
else
  warn "未找到 kangle config.xml，跳过口令持久化（容器可能未正常启动）"
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
AV=$($DC exec -T kangle /root/.acme.sh/acme.sh --version 2>/dev/null | head -1 || true)
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
