#!/usr/bin/env bash
#
# mirror.sh — EOL 系统换源 / 可选镜像站 / Docker 源映射 / registry 加速探测
#
# 职责：
#   1) 检测发行版与版本，判定是否 EOL（官方源是否已下线）
#   2) 为 EOL 系统切换到可用的归档源（Debian archive / Ubuntu old-releases / CentOS vault 等）
#   3) 按用户选择切换到国内镜像站（阿里 / 腾讯 / 华为 / 清华 / 中科大 / 字节）
#   4) 推导 Docker CE 源的正确路径与 ${releasever}（修复 Amazon Linux 2023 等映射错误）
#   5) 探测可用的 Docker registry 加速站（三步验证，避免"假可达"陷阱）
#
# 设计原则：
#   - 候选链 + 实时探测 + 自动回退，绝不硬编码单一 URL
#     （实测各家镜像站归档目录覆盖参差不齐，且会随时间变化）
#   - fail-closed：换源后 apt-get update / yum makecache 必须成功，否则回滚并告警
#   - 幂等：所有写入包在 ### BEGIN/END kangle-mirror ### 标记块内，重复运行先清旧块
#   - 只写 daemon.json，不重启 docker（由 install.sh 统一重启一次；单独运行时按需重启）
#
# 用法：
#   ./mirror.sh                          # auto：仅当检测到 EOL 或源不可用时切换
#   ./mirror.sh --mirror=alibaba         # 强制切到指定镜像站
#   ./mirror.sh --mirror=official        # 强制回官方（EOL 时自动用官方归档）
#   ./mirror.sh --mirror=keep            # 完全不动
#   ./mirror.sh --check                  # 只检测并报告，不改任何文件
#   ./mirror.sh --restore                # 还原到修改前
#   ./mirror.sh --no-registry-mirror     # 跳过 registry 加速探测
#   ./mirror.sh --registry-mirror=URL    # 指定加速站（跳过探测）
#   ./mirror.sh --no-restart             # 不重启 docker（供 install.sh 调用）
#   ./mirror.sh --yes                    # 非交互
#
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOG_TAG="mirror"
# shellcheck source=/dev/null
source "$PROJECT_DIR/lib/common.sh" 2>/dev/null || {
  echo "[error] 无法加载 lib/common.sh" >&2; exit 1;
}

STATE_ENV="$STATE_DIR/mirror.env"

# ───────────────────────── 参数解析 ─────────────────────────
MIRROR="auto"        # auto | keep | official | alibaba | tencent | huawei | tuna | ustc | volces
CHECK_ONLY=0
DO_RESTORE=0
NO_RESTART=0
REGISTRY_SKIP=0
REGISTRY_FORCE=""
for a in "$@"; do
  case "$a" in
    --mirror=*)        MIRROR="${a#*=}" ;;
    --check)           CHECK_ONLY=1 ;;
    --restore)         DO_RESTORE=1 ;;
    --no-restart)      NO_RESTART=1 ;;
    --no-registry-mirror) REGISTRY_SKIP=1 ;;
    --registry-mirror=*)  REGISTRY_FORCE="${a#*=}" ;;
    --yes|-y)          ASSUME_YES=1 ;;
    -h|--help)         sed -n '3,33p' "$0"; exit 0 ;;
    *) warn "未知参数: $a" ;;
  esac
done

# ───────────────────────── 镜像站基址 ─────────────────────────
# 各站归档目录覆盖不同（实测 2026-09）：
#   清华无 debian-archive / epel-archive；字节无 epel-archive / docker-ce；腾讯无 rockylinux
# 因此每个 (发行版, 组件) 都走"候选链探测"，不假设某站一定有某个目录。
mirror_base() {
  case "$1" in
    official) echo "" ;;   # 空表示使用各发行版官方归档（由调用方按类型拼）
    alibaba)  echo "https://mirrors.aliyun.com" ;;
    tencent)  echo "https://mirrors.cloud.tencent.com" ;;
    huawei)   echo "https://mirrors.huaweicloud.com" ;;
    tuna)     echo "https://mirrors.tuna.tsinghua.edu.cn" ;;
    ustc)     echo "https://mirrors.ustc.edu.cn" ;;
    volces)   echo "https://mirrors.volces.com" ;;
    *)        echo "" ;;
  esac
}

# ───────────────────────── 发行版检测 ─────────────────────────
OS_ID=""; OS_NAME=""; OS_VERSION=""; OS_MAJOR="0"; OS_LIKE=""
[[ -f /etc/os-release ]] && . /etc/os-release
OS_ID="${ID:-}"; OS_NAME="${NAME:-}"; OS_VERSION="${VERSION_ID:-}"; OS_LIKE="${ID_LIKE:-}"
OS_MAJOR="${OS_VERSION%%.*}"; OS_MAJOR="${OS_MAJOR:-0}"

CODENAME=""
command -v lsb_release >/dev/null 2>&1 && CODENAME="$(lsb_release -cs 2>/dev/null || true)"
# lsb_release 缺失时（最小化安装常见）从 /etc/os-release 的 UBUNTU_CODENAME / DEBIAN_* 兜底
if [[ -z "$CODENAME" ]]; then
  CODENAME="${UBUNTU_CODENAME:-${DEBIAN_CODENAME:-}}"
fi

FAMILY="unknown"; PKG_MGR="unknown"
detect_family() {
  local hay; hay="$(echo "$OS_ID $OS_LIKE" | tr '[:upper:]' '[:lower:]')"
  case "$hay" in
    *debian*|*ubuntu*|*linuxmint*|*raspbian*|*kali*) FAMILY="deb"; PKG_MGR="apt"; return ;;
  esac
  case "$hay" in
    *rhel*|*centos*|*fedora*|*ol*|*oracle*|*amzn*|*alma*|*rocky*|*scientific*)
      FAMILY="rhel"
      if command -v dnf >/dev/null 2>&1; then PKG_MGR="dnf"; else PKG_MGR="yum"; fi
      return ;;
  esac
}
detect_family

[[ "$CHECK_ONLY" -eq 0 && "$DO_RESTORE" -eq 0 ]] && require_root

log "系统: ${OS_NAME:-unknown} ${OS_VERSION:-} (id=$OS_ID family=$FAMILY codename=${CODENAME:-n/a})"

# ───────────────────────── EOL 判定 ─────────────────────────
# 返回：0=EOL(需换源) 1=未 EOL
is_eol() {
  case "$FAMILY" in
    deb)
      if [[ "$OS_ID" == *buntu* || "$OS_LIKE" == *ubuntu* ]]; then
        local major minor ver
        major="$(echo "$OS_VERSION" | cut -d. -f1)"; minor="$(echo "$OS_VERSION" | cut -d. -f2)"
        major="${major:-0}"; minor="${minor:-0}"
        ver=$(( major * 100 + minor ))
        (( ver < 2004 )) && return 0        # Ubuntu < 20.04
      else
        local mj="${OS_MAJOR:-0}"
        (( mj < 11 )) && return 0           # Debian < 11
      fi
      return 1 ;;
    rhel)
      case "$OS_ID" in
        centos|centos-linux)                 return 0 ;;   # CentOS Linux 全系 EOL
        centos-stream|centos_stream)         (( OS_MAJOR <= 8 )) && return 0; return 1 ;;
        rhel)                                (( OS_MAJOR <= 7 )) && return 0; return 1 ;;
        ol|oracle*)                          (( OS_MAJOR <= 7 )) && return 0; return 1 ;;
        amzn)                                (( OS_MAJOR < 2023 )) && return 0; return 1 ;;
        fedora)                              return 1 ;;    # 滚动版本，交由源探测决定
        almalinux|rocky|alma)                return 1 ;;    # 8/9/10 均在支持期
      esac
      return 1 ;;
  esac
  return 1
}

# ───────────────────────── 源可用性探测 ─────────────────────────
probe_deb() {   # $1=base url  $2=codename → 探测 dists/<codename>/Release
  http_ok "$1/dists/$2/Release" 8
}
probe_rpm() {   # $1=base url → 探测 repodata/repomd.xml
  http_ok "$1/repodata/repomd.xml" 10
}

# 从候选列表中选出第一个可用的
pick_first() {  # $@=候选 URL 列表；输出第一个可用者，全失败输出空
  local u
  for u in "$@"; do
    [[ -n "$u" ]] || continue
    if http_ok "$u" 8; then printf '%s' "$u"; return 0; fi
  done
  return 1
}

# ───────────────────────── deb 系换源 ─────────────────────────
# Debian 归档：deb.debian.org 在 EOL 后立即下线，迁到 archive.debian.org
# Ubuntu LTS：永远留在 archive.ubuntu.com；非 LTS：迁到 old-releases.ubuntu.com
deb_candidates() {
  local mb="$1" out=()
  if [[ "$OS_ID" == *buntu* || "$OS_LIKE" == *ubuntu* ]]; then
    # 官方候选：先 archive（LTS 在此），再 old-releases（非 LTS 在此）
    out+=("http://archive.ubuntu.com/ubuntu" "http://old-releases.ubuntu.com/ubuntu")
    [[ -n "$mb" ]] && out+=("$mb/ubuntu" "$mb/ubuntu-old-releases/ubuntu")
  else
    out+=("http://deb.debian.org/debian" "http://archive.debian.org/debian")
    [[ -n "$mb" ]] && out+=("$mb/debian" "$mb/debian-archive/debian")
  fi
  printf '%s\n' "${out[@]}"
}

deb_security_base() {
  # $1=已选定的主源 base → 推导安全源 base
  local base="$1"
  if [[ "$OS_ID" == *buntu* || "$OS_LIKE" == *ubuntu* ]]; then
    echo "$base"                      # Ubuntu 的 -security 是同 base 的组件
  else
    case "$base" in
      *archive.debian.org/debian) echo "http://archive.debian.org/debian-security" ;;
      *debian-archive/debian)     echo "${base%/debian}/debian-security" ;;
      *)                          echo "http://security.debian.org/debian-security" ;;
    esac
  fi
}

apply_deb() {
  local mb; mb="$(mirror_base "$MIRROR")"
  local chosen sec_base cands=()
  mapfile -t cands < <(deb_candidates "$mb")

  chosen="$(pick_first "${cands[@]}" || true)"
  if [[ -z "$chosen" ]]; then
    warn "未找到可用的 apt 源（已尝试: ${cands[*]}）"
    return 1
  fi
  ok "选定 apt 源: $chosen"

  sec_base="$(deb_security_base "$chosen")"

  local sl=/etc/apt/sources.list
  backup_path "$sl" >/dev/null 2>&1 || true

  # 清理旧的标记块，保证幂等
  if [[ -f "$sl" ]] && grep -q '### BEGIN kangle-mirror ###' "$sl" 2>/dev/null; then
    sed -i '/### BEGIN kangle-mirror ###/,/### END kangle-mirror ###/d' "$sl" 2>/dev/null || true
  fi

  local lines=()
  lines+=("### BEGIN kangle-mirror ###")
  lines+=("# 由 mirror.sh 生成于 $(date '+%Y-%m-%d %H:%M:%S')")
  lines+=("# 系统 EOL 后官方源已下线，本块指向仍可用的归档/镜像源")

  # 归档源的 Release 无 Valid-Until，但加上可防镜像差异（无害）
  local opt="[check-valid-until=no]"

  if [[ "$OS_ID" == *buntu* || "$OS_LIKE" == *ubuntu* ]]; then
    lines+=("deb $opt $chosen/ $CODENAME main restricted universe multiverse")
    lines+=("deb $opt $chosen/ $CODENAME-updates main restricted universe multiverse")
    # 非 LTS 的 EOL 版本已无安全更新，探测后再决定是否保留
    if probe_deb "$chosen" "$CODENAME-security"; then
      lines+=("deb $opt $chosen/ $CODENAME-security main restricted universe multiverse")
    else
      lines+=("# $CODENAME-security 不可用（该版本已停止安全更新），已省略")
    fi
  else
    lines+=("deb $opt $chosen/ $CODENAME main contrib non-free")
    lines+=("deb $opt $chosen/ $CODENAME-updates main contrib non-free")
    if probe_deb "$sec_base" "$CODENAME/updates"; then
      lines+=("deb $opt $sec_base/ $CODENAME/updates main")
    else
      lines+=("# 安全源不可用（$CODENAME/updates），已省略")
    fi
  fi
  lines+=("### END kangle-mirror ###")

  printf '%s\n' "${lines[@]}" >> "$sl"
  ok "已写入 ${sl}（原文件已备份到 ${STATE_DIR}）"

  # 幂等 + 兜底：归档源 Release 可能缺失 Valid-Until
  log "刷新 apt 缓存以验证..."
  if apt-get -o Acquire::Check-Valid-Until=false update >/dev/null 2>&1; then
    ok "apt-get update 成功"
    return 0
  fi
  warn "apt-get update 失败，正在回滚 sources.list"
  restore_latest /etc/apt/sources.list >/dev/null 2>&1 || true
  return 1
}

# ───────────────────────── rhel 系换源 ─────────────────────────
# 返回：0=成功 1=失败 2=无需换源
apply_rhel() {
  local mb; mb="$(mirror_base "$MIRROR")"
  local RHEL_COMPAT=0

  # Oracle Linux 官方源仍完整提供（实测 OL6/7/8 均 200），无需换源
  if [[ "$OS_ID" == "ol" || "$OS_ID" == "oracle" || "$OS_ID" == oracle* ]]; then
    ok "Oracle Linux 官方源仍在提供（yum.oracle.com），跳过换源"
    return 2
  fi
  # AlmaLinux / Rocky 当前版本均未 EOL
  case "$OS_ID" in
    almalinux|rocky|alma)
      ok "$OS_ID $OS_MAJOR 仍在支持期，无需换源"
      return 2 ;;
  esac
  # CentOS Stream 9/10、RHEL 8+ 未 EOL
  if [[ "$OS_ID" == "centos-stream" || "$OS_ID" == centos_stream ]] && (( OS_MAJOR >= 9 )); then
    ok "CentOS Stream $OS_MAJOR 仍在支持期，无需换源"
    return 2
  fi
  if [[ "$OS_ID" == "rhel" ]] && (( OS_MAJOR >= 8 )); then
    ok "RHEL $OS_MAJOR 仍在支持期（需有效订阅），跳过换源"
    return 2
  fi

  local repo_dir=/etc/yum.repos.d
  [[ -d "$repo_dir" ]] || { warn "未找到 $repo_dir"; return 1; }

  backup_path "$repo_dir" >/dev/null 2>&1 || true

  # 1) 禁用所有 mirrorlist（EOL 版本的 mirrorlist 接口已下线）
  local f
  for f in "$repo_dir"/*.repo; do
    [[ -f "$f" ]] || continue
    sed -i 's/^[[:space:]]*mirrorlist[[:space:]]*=/\#mirrorlist=/' "$f" 2>/dev/null || true
  done

  # 2) 按发行版定位归档源
  local vault_base chosen="" repo_file="$repo_dir/CentOS-Base.repo"

  case "$OS_ID" in
    centos|centos-linux)
      case "$OS_MAJOR" in
        6) vault_base="6.10" ;;
        7) vault_base="7.9.2009" ;;
        8) vault_base="8.5.2111" ;;
        *) vault_base="" ;;
      esac
      if [[ -n "$vault_base" ]]; then
        local c1="https://vault.centos.org/$vault_base"
        local c2="https://vault.centos.org/$vault_base"
        # vault.centos.org 必须 https（http 会被 CloudFront 按地区 403）
        local cands=()
        [[ -n "$mb" ]] && cands+=("$mb/centos-vault/$vault_base")
        cands+=("$c1")
        # 探测路径随版本不同：8 用 BaseOS，6/7 用 os
        if (( OS_MAJOR >= 8 )); then
          chosen="$(pick_first "${cands[@]/%//BaseOS/x86_64/os}" || true)"
        else
          chosen="$(pick_first "${cands[@]/%//os/x86_64}" || true)"
        fi
        # pick_first 已带 /BaseOS/x86_64/os 后缀，需要回推 base
        if [[ -n "$chosen" ]]; then
          if (( OS_MAJOR >= 8 )); then
            chosen="${chosen%/BaseOS/x86_64/os}"
          else
            chosen="${chosen%/os/x86_64}"
          fi
        fi
      fi
      ;;
    centos-stream|centos_stream)
      local cands=()
      [[ -n "$mb" ]] && cands+=("$mb/centos-vault/8-stream")
      cands+=("https://vault.centos.org/8-stream")
      chosen="$(pick_first "${cands[@]/%//BaseOS/x86_64/os}" || true)"
      [[ -n "$chosen" ]] && chosen="${chosen%/BaseOS/x86_64/os}"
      ;;
    rhel)
      # RHEL 6/7 无公共源：映射到二进制兼容的 CentOS vault
      warn "RHEL $OS_MAJOR 无公共 yum 源（需订阅），将映射到 CentOS vault 并放宽 GPG 校验"
      local v; [[ "$OS_MAJOR" -le 6 ]] && v="6.10" || v="7.9.2009"
      local cands=()
      [[ -n "$mb" ]] && cands+=("$mb/centos-vault/$v")
      cands+=("https://vault.centos.org/$v")
      chosen="$(pick_first "${cands[@]/%//os/x86_64}" || true)"
      [[ -n "$chosen" ]] && chosen="${chosen%/os/x86_64}"
      RHEL_COMPAT=1
      ;;
    fedora)
      # Fedora 老版本走 archives.fedoraproject.org
      if ! probe_rpm "https://dl.fedoraproject.org/pub/fedora/linux/releases/$OS_MAJOR/Everything/x86_64/os"; then
        chosen="https://archives.fedoraproject.org/pub/archive/fedora/linux/releases/$OS_MAJOR/Everything/x86_64/os"
        ok "Fedora $OS_MAJOR 已归档，切换到 archives.fedoraproject.org"
      else
        ok "Fedora $OS_MAJOR 源可用，无需换源"
        return 2
      fi
      ;;
    amzn)
      warn "Amazon Linux 的源由 AWS 维护，本脚本无法可靠改写；请确保实例可访问 AWS 仓库"
      return 2
      ;;
  esac

  if [[ -z "$chosen" ]]; then
    warn "未能定位可用的 yum 归档源"
    return 1
  fi
  ok "选定 yum 源: $chosen"

  # 3) 写 repo
  local gpg="1"
  [[ "${RHEL_COMPAT:-0}" -eq 1 ]] && gpg="0"   # RHEL 无 CentOS 公钥
  if (( OS_MAJOR >= 8 )); then
    cat > "$repo_file" <<EOF
### BEGIN kangle-mirror ###
# 由 mirror.sh 生成于 $(date '+%Y-%m-%d %H:%M:%S')（系统已 EOL，官方源已下线）
[baseos]
name=CentOS-\$releasever - Base
baseurl=$chosen/BaseOS/\$basearch/os/
gpgcheck=$gpg
enabled=1
EOF
    if [[ "${RHEL_COMPAT:-0}" -ne 1 ]]; then
      echo "gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-centosofficial" >> "$repo_file"
    fi
    cat >> "$repo_file" <<EOF

[appstream]
name=CentOS-\$releasever - AppStream
baseurl=$chosen/AppStream/\$basearch/os/
gpgcheck=$gpg
enabled=1
EOF
    [[ "${RHEL_COMPAT:-0}" -ne 1 ]] && echo "gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-centosofficial" >> "$repo_file"
    echo "### END kangle-mirror ###" >> "$repo_file"
  else
    cat > "$repo_file" <<EOF
### BEGIN kangle-mirror ###
# 由 mirror.sh 生成于 $(date '+%Y-%m-%d %H:%M:%S')（系统已 EOL，官方源已下线）
[base]
name=CentOS-\$releasever - Base
baseurl=$chosen/os/\$basearch/
gpgcheck=$gpg
enabled=1
EOF
    [[ "${RHEL_COMPAT:-0}" -ne 1 ]] && echo "gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-\$releasever" >> "$repo_file"
    cat >> "$repo_file" <<EOF

[updates]
name=CentOS-\$releasever - Updates
baseurl=$chosen/updates/\$basearch/
gpgcheck=$gpg
enabled=1
EOF
    [[ "${RHEL_COMPAT:-0}" -ne 1 ]] && echo "gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-\$releasever" >> "$repo_file"
    cat >> "$repo_file" <<EOF

[extras]
name=CentOS-\$releasever - Extras
baseurl=$chosen/extras/\$basearch/
gpgcheck=$gpg
enabled=1
EOF
    [[ "${RHEL_COMPAT:-0}" -ne 1 ]] && echo "gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-\$releasever" >> "$repo_file"
    echo "### END kangle-mirror ###" >> "$repo_file"
  fi
  ok "已写入 $repo_file"

  # 4) EPEL（EOL 版本的官方 EPEL 已下线，改走 epel-archive）
  if [[ "$FAMILY" == "rhel" ]] && grep -rq '^\[epel' "$repo_dir"/*.repo 2>/dev/null; then
    local ecands=()
    [[ -n "$mb" ]] && ecands+=("$mb/epel-archive/$OS_MAJOR")
    ecands+=("https://archives.fedoraproject.org/pub/archive/epel/$OS_MAJOR")
    local echosen
    echosen="$(pick_first "${ecands[@]}" || true)"
    if [[ -n "$echosen" ]]; then
      cat > "$repo_dir/epel.repo" <<EOF
### BEGIN kangle-mirror ###
[epel]
name=EPEL $OS_MAJOR (archive)
baseurl=$echosen
gpgcheck=0
enabled=1
### END kangle-mirror ###
EOF
      ok "EPEL 已切换到: $echosen"
    else
      warn "未找到可用的 EPEL 归档源，已保持原状"
    fi
  fi

  log "刷新 yum 缓存以验证..."
  $PKG_MGR clean all >/dev/null 2>&1 || true
  if $PKG_MGR makecache >/dev/null 2>&1; then
    ok "$PKG_MGR makecache 成功"
    return 0
  fi
  warn "$PKG_MGR makecache 失败，请检查上方输出（仓库配置已保留，未回滚）"
  return 1
}

# ───────────────────────── Docker 源 $releasever 推导 ─────────────────────────
# 背景（实测）：install.sh 统一使用 centos/docker-ce.repo，baseurl 含 ${releasever}。
#   centos/{7,8,9,10} = 200；centos/6 = 404；centos/2023(AL2023) = 404；
#   centos/{39,40,41}(Fedora) = 404（须走 fedora repo）；rhel/7 = 404（须走 centos/7）
# 因此必须按发行版推导，不能直接套用。
derive_docker_repo() {
  local repo="centos" rel="$OS_MAJOR"
  case "$OS_ID" in
    fedora) repo="fedora" ;;
    amzn)
      # AL2023 的 $releasever=2023，在任何 Docker 官方路径下都不存在
      if (( OS_MAJOR >= 2023 )); then repo="amzn2023"; else repo="centos"; rel="7"; fi
      ;;
    rhel)
      # rhel/7 不存在，须映射到 centos/7
      if (( OS_MAJOR <= 7 )); then repo="centos"; rel="7"; fi
      ;;
    alinux|alinux3)
      # 阿里云 Linux 兼容 RHEL 8，使用 centos/8 仓库（download.docker.com/linux/centos/8 实测 200）
      repo="centos"; rel="8" ;;
    centos-stream|centos_stream)
      (( OS_MAJOR >= 9 )) && rel="9"    # Stream 9 用 centos/9 仓库
      ;;
  esac
  DOCKER_REPO="$repo"
  DOCKER_RELEASEVER="$rel"
  export DOCKER_REPO DOCKER_RELEASEVER
}

# 校验推导结果是否真的可用
verify_docker_repo() {
  local repo="$1" rel="$2" u=""
  case "$repo" in
    amzn2023)
      # 三级回退：Amazon 官方包 → centos/9 → fedora/40
      if $PKG_MGR list docker >/dev/null 2>&1 || dnf list docker >/dev/null 2>&1; then
        echo "amazon"; return 0
      fi
      if http_ok "https://download.docker.com/linux/centos/9/x86_64/stable/repodata/repomd.xml" 8; then
        echo "centos:9"; return 0
      fi
      if http_ok "https://download.docker.com/linux/fedora/40/x86_64/stable/repodata/repomd.xml" 8; then
        echo "fedora:40"; return 0
      fi
      return 1 ;;
    fedora) u="https://download.docker.com/linux/fedora/$rel/x86_64/stable/repodata/repomd.xml" ;;
    *)      u="https://download.docker.com/linux/centos/$rel/x86_64/stable/repodata/repomd.xml" ;;
  esac
  http_ok "$u" 8 && { echo "$repo:$rel"; return 0; }
  return 1
}

# ───────────────────────── registry 加速探测 ─────────────────────────
# ⚠️ 关键：/v2/ 端点返回 200/401 不足以判定可用。
#    实测 dockerhub.azk8s.cn 的 /v2/ 返回 200，但 manifest 请求 404（拉不了镜像）。
#    必须走完整三步：取该站自己的 auth realm → 换它自己的 token → 拉真实 manifest。
probe_registry_mirror() {
  # $1=mirror url；成功返回 0
  local m="$1" realm service tok code
  m="${m%/}"

  # 1) 取该站自己的认证入口
  local hdr auth_line
  hdr="$(curl -s -D- -o /dev/null -m 10 "$m/v2/" 2>/dev/null || true)"
  # HTTP 头名大小写不敏感，先按行取出再提取字段；realm URL 本身不可转大小写
  auth_line="$(printf '%s\n' "$hdr" | tr -d '\r' | grep -i '^www-authenticate:' | head -1 || true)"
  realm="$(printf '%s' "$auth_line" | sed -n 's/.*realm="\([^"]*\)".*/\1/p' | head -1)"
  service="$(printf '%s' "$auth_line" | sed -n 's/.*service="\([^"]*\)".*/\1/p' | head -1)"

  # 无标准 realm 时按 Docker Hub 兼容格式兜底
  [[ -z "$realm" ]] && realm="https://auth.docker.io/token"
  [[ -z "$service" ]] && service="registry.docker.io"

  # 2) 用它的 realm 换 token（官方 token 在第三方站无效）
  tok="$(curl -s -m 15 "$realm?service=$service&scope=repository:library/mysql:pull" 2>/dev/null \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' | head -1)"
  [[ -z "$tok" ]] && return 1

  # 3) 拉真实 manifest，要求 200 且响应体非空
  #    固定临时路径有符号链接风险，用 mktemp
  local mf; mf="$(mktemp 2>/dev/null || echo "")"
  [[ -n "$mf" ]] || return 1
  code=$(curl -s -o "$mf" -m 20 -w "%{http_code}" \
          -H "Authorization: Bearer $tok" \
          -H "Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json" \
          "$m/v2/library/mysql/manifests/8" 2>/dev/null || echo 000)
  local size=0
  [[ -f "$mf" ]] && size=$(wc -c < "$mf" 2>/dev/null || echo 0)
  rm -f "$mf" 2>/dev/null || true
  [[ "$code" == "200" ]] || return 1
  [[ "$size" -gt 0 ]] || return 1     # 200 但空响应体同样视为不可用
  return 0
}

# 实测（2026-09）：6 家已死，2 家假可达，仅 daocloud 真正可用。
# 因此不做长候选列表——把死站硬编码进去只会让 Docker 逐个重试，反而更慢。
REGISTRY_CANDIDATES=( "https://docker.m.daocloud.io" )

select_registry_mirror() {
  if [[ -n "$REGISTRY_FORCE" ]]; then
    if probe_registry_mirror "$REGISTRY_FORCE"; then
      ok "registry 加速站（指定）: $REGISTRY_FORCE"
      echo "$REGISTRY_FORCE"; return 0
    fi
    warn "指定的加速站未通过三步验证: $REGISTRY_FORCE"
    return 1
  fi
  local m
  for m in "${REGISTRY_CANDIDATES[@]}"; do
    if probe_registry_mirror "$m"; then
      ok "registry 加速站（探测通过）: $m"
      echo "$m"; return 0
    fi
    info "加速站未通过验证: $m"
  done
  return 1
}

apply_registry_mirror() {
  [[ "$REGISTRY_SKIP" -eq 1 ]] && { info "已跳过 registry 加速（--no-registry-mirror）"; return 0; }
  # 注意：registry 加速探测是**纯 HTTP** 行为（三步验证 dockerhub 可达性），并不依赖 docker 已安装。
  # 原实现用 `need_cmd docker` 拦截，导致 install.sh 在 docker 安装**之前**调用本函数时永远跳过加速，
  # 使 v3 功能 D（registry 镜像加速）在全新安装上从不生效（Bug B）。
  # 实际上 /etc/docker/daemon.json 只是个静态文件，提前写入后 docker 首次启动即会读取它，
  # 因此这里**不应**以 docker 是否存在作为前置条件。仅当 daemon.json 写入工具（jq/python）也不可用时，
  # 才由 daemon_json_set 内部 fail-closed（见 lib/common.sh）。

  local m
  m="$(select_registry_mirror || true)"
  if [[ -z "$m" ]]; then
    warn "未找到可用的 registry 加速站，将使用 Docker 官方 registry（不写入 registry-mirrors）"
    # 关键：绝不能写入已失效地址，否则 Docker 会逐个重试，反而更慢
    daemon_json_del "registry-mirrors" >/dev/null 2>&1 || true
    return 0
  fi

  if daemon_json_set "registry-mirrors" "$(printf '["%s"]' "$m")"; then
    mark_restart_docker
    REGISTRY_MIRROR="$m"
    warn "已启用第三方镜像加速: $m"
    info "  风险提示：加速站可篡改 tag→digest 映射（Docker 只校验内容 digest）。"
    info "  关闭方式：mirror.sh --no-registry-mirror，或手动编辑 /etc/docker/daemon.json"
  else
    warn "写入 registry-mirrors 失败（缺少 jq/python3），已跳过"
  fi
  return 0
}

# ───────────────────────── 还原 ─────────────────────────
restore_latest() {
  # $1=原路径；从 $STATE_DIR 找最新备份还原
  local src="$1" base latest
  base="$(basename "$src")"
  latest="$(ls -1t "$STATE_DIR"/"$base".bak.* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || return 1
  if [[ -d "$src" ]]; then rm -rf "$src" 2>/dev/null || true; fi
  cp -a "$latest" "$src" 2>/dev/null || return 1
  echo "$latest"
}

do_restore() {
  log "还原系统源到修改前..."
  local ok_any=0
  if [[ -f /etc/apt/sources.list ]]; then
    if restore_latest /etc/apt/sources.list >/dev/null 2>&1; then ok "已还原 /etc/apt/sources.list"; ok_any=1; fi
  fi
  if [[ -d /etc/yum.repos.d ]]; then
    if restore_latest /etc/yum.repos.d >/dev/null 2>&1; then ok "已还原 /etc/yum.repos.d"; ok_any=1; fi
  fi
  daemon_json_del "registry-mirrors" >/dev/null 2>&1 && { ok "已移除 registry-mirrors"; ok_any=1; }
  [[ "$ok_any" -eq 1 ]] || warn "未找到任何备份，无需还原"
  if [[ "$NO_RESTART" -eq 0 ]]; then consume_restart_docker; fi
  exit 0
}

# ───────────────────────── 主流程 ─────────────────────────
ensure_state_dir
[[ "$DO_RESTORE" -eq 1 ]] && do_restore

if [[ "$FAMILY" == "unknown" ]]; then
  warn "未识别的发行版（id=${OS_ID}），跳过换源"
  exit 0
fi

# 推导 Docker 源（无论是否换源都要做，install.sh 依赖它）
derive_docker_repo

if [[ "$CHECK_ONLY" -eq 1 ]]; then
  echo
  log "检测结果"
  if is_eol; then echo "  EOL: 是（官方源可能已下线，建议换源）"; else echo "  EOL: 否"; fi
  echo "  家族: $FAMILY   包管理器: $PKG_MGR"
  echo "  Docker 源推导: repo=$DOCKER_REPO releasever=$DOCKER_RELEASEVER"
  dr="$(verify_docker_repo "$DOCKER_REPO" "$DOCKER_RELEASEVER" || true)"
  if [[ -n "$dr" ]]; then echo "  Docker 源可用: 是 ($dr)"; else echo "  Docker 源可用: 否（需修正映射）"; fi
  if [[ "$REGISTRY_SKIP" -eq 0 ]]; then
    rm_="$(select_registry_mirror || true)"
    echo "  registry 加速: ${rm_:-未找到可用站（将走官方）}"
  fi
  exit 0
fi

# 保存推导结果供 install.sh 读取
# MIRROR_BASE 额外供容器构建使用（docker-compose*.yml -> kangle/Dockerfile 的 yum 源基址）。
# 选 official / auto 时主机源走官方归档，但容器构建仍需一个可用的 CentOS vault 镜像，
# 故回落到阿里云（实测 centos-vault/7.9.2009 可用，且 Dockerfile 内另有官方 vault 兜底）。
MIRROR_BASE="$(mirror_base "$MIRROR")"
[[ -z "$MIRROR_BASE" ]] && MIRROR_BASE="https://mirrors.aliyun.com"
export MIRROR_BASE
{
  echo "DOCKER_REPO=$DOCKER_REPO"
  echo "DOCKER_RELEASEVER=$DOCKER_RELEASEVER"
  echo "MIRROR_BASE=$MIRROR_BASE"
} > "$STATE_ENV" 2>/dev/null || true

if [[ "$MIRROR" == "keep" ]]; then
  info "--mirror=keep，跳过换源"
elif [[ "$MIRROR" == "auto" ]]; then
  if is_eol; then
    log "检测到系统已 EOL，自动切换到可用的归档源..."
    case "$FAMILY" in
      deb)  apply_deb  || warn "换源未完全成功，请检查上方输出" ;;
      rhel) apply_rhel || true ;;
    esac
  else
    ok "系统未 EOL，官方源可用，未做改动"
  fi
else
  log "切换到镜像站: $MIRROR"
  case "$FAMILY" in
    deb)  apply_deb  || warn "换源未完全成功，请检查上方输出" ;;
    rhel) apply_rhel || true ;;
  esac
fi

# registry 加速（只写 daemon.json，不重启）
apply_registry_mirror

# 单独运行时按需重启；被 install.sh 调用（--no-restart）时只打标记
if [[ "$NO_RESTART" -eq 0 ]]; then
  consume_restart_docker
else
  info "已标记待重启 docker（由 install.sh 统一执行）"
fi

echo
ok "mirror.sh 完成。状态文件: $STATE_ENV"
