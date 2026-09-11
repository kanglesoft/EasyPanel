#!/usr/bin/env bash
#
# lib/common.sh — install.sh / upgrade.sh / uninstall.sh / dns.sh / mirror.sh 的共享函数库
#
# 提供：
#   - 统一日志（log / ok / warn / die / info）
#   - 命令与工具前置检查（need_cmd / ensure_json_tool）
#   - HTTP 探测（http_code / http_ok）
#   - /etc/docker/daemon.json 的**安全合并**写入（daemon_json_set / daemon_json_del / daemon_json_get）
#   - 备份与还原原语（backup_path / restore_latest）
#   - docker 重启需求标记（mark_restart_docker / consume_restart_docker）
#   - 安装模式识别（mode_detect / mode_compose_file / mode_write）
#
# 设计约束：
#   - 兼容 bash 4.2（CentOS 7），不使用 bash 5 特性
#   - 被 source，不直接执行任何副作用
#   - daemon.json 只允许"合并"，绝不整体覆盖（宿主机常已有 registry-mirrors / log-driver 等配置）
#
# shellcheck shell=bash

# 防止重复 source
[[ -n "${_KANGLE_COMMON_LOADED:-}" ]] && return 0
_KANGLE_COMMON_LOADED=1

# ───────────────────────── 日志 ─────────────────────────
# 各调用脚本可通过设置 LOG_TAG 改变前缀
LOG_TAG="${LOG_TAG:-net}"

if ! declare -F log >/dev/null 2>&1; then
  # 重要：状态日志必须写到 stderr，绝不能写 stdout。
  # 否则任何 `$(func)` 命令替换都会把日志行吞进返回值，导致下游解析出错
  # （实测：select_registry_mirror 内部调用 ok/info 打到 stdout，被 `m="$(...)"` 捕获，
  #  使 daemon_json_set 拿到形如 "[ ok ] ...\nhttps://..." 的非法 JSON，registry 加速写入被静默跳过）。
  log()  { echo -e "\033[1;36m[$LOG_TAG]\033[0m $*" >&2; }
  ok()   { echo -e "\033[1;32m[ ok ]\033[0m $*" >&2; }
  warn() { echo -e "\033[1;33m[warn]\033[0m $*" >&2; }
  info() { echo -e "       $*" >&2; }
  die()  { echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }
fi

# ───────────────────────── 状态目录 ─────────────────────────
STATE_DIR="${STATE_DIR:-/var/lib/kangle-net}"
RESTART_FLAG="$STATE_DIR/.need-docker-restart"

ensure_state_dir() { mkdir -p "$STATE_DIR" 2>/dev/null || true; }

# ───────────────────────── 命令检查 ─────────────────────────
need_cmd() {
  # $1=命令；缺失则返回 1（不退出，交由调用方决定）
  command -v "$1" >/dev/null 2>&1
}

require_root() {
  [[ "${EUID:-$(id -u)}" -eq 0 ]] || die "请以 root 运行本脚本（需要修改系统级配置）。"
}

# ───────────────────────── HTTP 探测 ─────────────────────────
http_code() {
  # $1=URL, $2=超时秒数(默认 8)；输出 HTTP 状态码，失败输出 000
  #
  # -L（跟随重定向）是**必需**的，不是可选项。实测（CentOS 7 真机，2026-09）：
  #   探测归档源时传入的是**目录 URL 且不带尾斜杠**（如
  #   https://vault.centos.org/7.9.2009/os/x86_64 ），绝大多数 http 服务器会返回
  #   301 → 目标目录。原实现不带 -L，拿到 301 后 ^2xx$ 判定失败，导致
  #   mirror.sh 在所有候选源**实际都可用**的情况下误报"未能定位可用的 yum 归档源"，
  #   使 EOL 换源（v3 的 P0 能力）在 centos/centos-stream/rhel/epel 上全部失效。
  #   加 -L 后同一 URL 返回 200，候选可被正确选中。
  local url="$1" to="${2:-8}"
  curl -sL -o /dev/null -m "$to" -w "%{http_code}" "$url" 2>/dev/null || printf '000'
}

http_ok() {
  # 2xx 视为成功
  local c; c="$(http_code "$1" "${2:-8}")"
  [[ "$c" =~ ^2[0-9][0-9]$ ]]
}

http_body() {
  # 输出响应体（用于探测 manifest 是否非空）
  curl -s -m "${2:-10}" "$1" 2>/dev/null || true
}

# ───────────────────────── JSON 工具保障 ─────────────────────────
# daemon.json 必须"合并"写入，因此需要 jq 或 python3。
# 两者皆无时尝试安装（此时系统源应已由 mirror.sh 修好）；仍失败则 fail-closed。
ensure_json_tool() {
  need_cmd jq && { echo jq; return 0; }
  need_cmd python3 && { echo python3; return 0; }
  # CentOS 7 等老系统默认只有 python2（命令名 python，无 python3）。json 标准库自 2.6 起即存在，
  # 下方 heredoc 在 py2 / py3 下均可用，故这里也认 python2，避免在仅有 python2 的机器上
  # daemon.json 写入被静默跳过（Bug C：原实现只认 python3，导致 CentOS 7 上 registry-mirrors 写不进去）。
  need_cmd python && { echo python; return 0; }

  warn "未检测到 jq / python3 / python，尝试安装（用于安全合并 /etc/docker/daemon.json）..."
  if need_cmd apt-get; then
    apt-get install -y jq >/dev/null 2>&1 && need_cmd jq && { echo jq; return 0; }
    apt-get install -y python3 >/dev/null 2>&1 && need_cmd python3 && { echo python3; return 0; }
  elif need_cmd dnf; then
    dnf install -y jq >/dev/null 2>&1 && need_cmd jq && { echo jq; return 0; }
    dnf install -y python3 >/dev/null 2>&1 && need_cmd python3 && { echo python3; return 0; }
  elif need_cmd yum; then
    yum install -y jq >/dev/null 2>&1 && need_cmd jq && { echo jq; return 0; }
    yum install -y python3 >/dev/null 2>&1 && need_cmd python3 && { echo python3; return 0; }
  fi
  return 1
}

# ───────────────────────── daemon.json 安全合并 ─────────────────────────
DAEMON_JSON="${DAEMON_JSON:-/etc/docker/daemon.json}"

daemon_json_init() {
  mkdir -p "$(dirname "$DAEMON_JSON")" 2>/dev/null || return 1
  if [[ ! -s "$DAEMON_JSON" ]]; then
    printf '{}\n' > "$DAEMON_JSON" 2>/dev/null || return 1
  fi
  return 0
}

daemon_json_backup() {
  [[ -s "$DAEMON_JSON" ]] || return 0
  local ts; ts="$(date '+%Y%m%d-%H%M%S')"
  cp -a "$DAEMON_JSON" "${DAEMON_JSON}.bak.$ts" 2>/dev/null || true
  echo "${DAEMON_JSON}.bak.$ts"
}

daemon_json_set() {
  # $1=键名（如 dns / registry-mirrors）
  # $2=**合法 JSON 值**（如 '["223.5.5.5","1.1.1.1"]'）
  # 语义：合并。已有其它键保持不变。成功返回 0，失败返回 1（不改动原文件）。
  local key="$1" val="$2" tool tmp

  daemon_json_init || return 1
  daemon_json_backup >/dev/null 2>&1 || true

  tool="$(ensure_json_tool)" || return 1

  if [[ "$tool" == "jq" ]]; then
    tmp="$(mktemp)" || return 1
    if jq --argjson v "$val" ". + {(\$k): \$v}" --arg k "$key" "$DAEMON_JSON" >"$tmp" 2>/dev/null \
       && [[ -s "$tmp" ]] && jq -e . "$tmp" >/dev/null 2>&1; then
      cat "$tmp" > "$DAEMON_JSON"; rm -f "$tmp"; return 0
    fi
    rm -f "$tmp"
    return 1
  fi

  # python 路径（python3 或 python2，由 ensure_json_tool 返回的工具名决定）
  if "$tool" - "$DAEMON_JSON" "$key" "$val" <<'PY'
import json, sys, os
path, key, raw = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    val = json.loads(raw)
except Exception:
    sys.exit(1)
try:
    with open(path, 'r', encoding='utf-8') as f:
        data = json.load(f) if os.path.getsize(path) else {}
except Exception:
    data = {}
if not isinstance(data, dict):
    sys.exit(1)
data[key] = val
tmp = path + '.tmp'
with open(tmp, 'w', encoding='utf-8') as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
    f.write('\n')
os.replace(tmp, path)
PY
  then
    return 0
  fi
  return 1
}

daemon_json_del() {
  # 删除指定键（用于 --restore）
  local key="$1" tool tmp
  [[ -s "$DAEMON_JSON" ]] || return 0
  tool="$(ensure_json_tool)" || return 1

  if [[ "$tool" == "jq" ]]; then
    tmp="$(mktemp)" || return 1
    if jq "del(.[\"$key\"])" "$DAEMON_JSON" >"$tmp" 2>/dev/null && [[ -s "$tmp" ]]; then
      cat "$tmp" > "$DAEMON_JSON"; rm -f "$tmp"; return 0
    fi
    rm -f "$tmp"; return 1
  fi

  "$tool" - "$DAEMON_JSON" "$key" <<'PY'
import json, sys, os
path, key = sys.argv[1], sys.argv[2]
try:
    with open(path, 'r', encoding='utf-8') as f:
        data = json.load(f) if os.path.getsize(path) else {}
except Exception:
    data = {}
if isinstance(data, dict) and key in data:
    data.pop(key)
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
        f.write('\n')
    os.replace(tmp, path)
PY
}

daemon_json_get() {
  # 输出指定键的 JSON 值（不存在则空）
  [[ -s "$DAEMON_JSON" ]] || return 0
  if need_cmd jq; then
    jq -c --arg k "$1" '.[$k] // empty' "$DAEMON_JSON" 2>/dev/null || true
    return 0
  fi
  # python3 / python2 皆可（json 标准库自 2.6 起存在）
  local py=
  if need_cmd python3; then py=python3
  elif need_cmd python; then py=python
  else return 0
  fi
  "$py" - "$DAEMON_JSON" "$1" <<'PY'
import json, sys, os
path, key = sys.argv[1], sys.argv[2]
try:
    with open(path, 'r', encoding='utf-8') as f:
        data = json.load(f) if os.path.getsize(path) else {}
    if isinstance(data, dict) and key in data:
        print(json.dumps(data[key], ensure_ascii=False))
except Exception:
    pass
PY
}

# ───────────────────────── docker 重启标记 ─────────────────────────
# 多个脚本都可能写 daemon.json，但只允许重启一次：
# 各脚本只"打标记"，由 install.sh 统一消费。
mark_restart_docker() {
  ensure_state_dir
  : > "$RESTART_FLAG" 2>/dev/null || true
}

consume_restart_docker() {
  # 若被标记则重启 docker 一次，并清除标记。返回 0=已重启/无需重启，1=重启失败
  if [[ ! -f "$RESTART_FLAG" ]]; then
    return 0
  fi
  rm -f "$RESTART_FLAG" 2>/dev/null || true
  log "应用 Docker 配置变更（重启 docker 守护进程，将中断容器）..."
  if need_cmd systemctl && systemctl restart docker >/dev/null 2>&1; then
    ok "docker 已重启"
    return 0
  fi
  if service docker restart >/dev/null 2>&1; then
    ok "docker 已重启（service）"
    return 0
  fi
  warn "docker 重启失败，请手动执行: systemctl restart docker"
  return 1
}

# ───────────────────────── 备份 / 还原原语 ─────────────────────────
backup_path() {
  # $1=路径；备份到 ${STATE_DIR}，回显备份文件路径
  local src="$1"
  [[ -e "$src" ]] || return 1
  ensure_state_dir
  local base ts
  base="$(basename "$src")"
  ts="$(date '+%Y%m%d-%H%M%S')"
  local dst="$STATE_DIR/${base}.bak.$ts"
  cp -a "$src" "$dst" 2>/dev/null || return 1
  echo "$dst"
}

# ───────────────────────── 版本比较 ─────────────────────────
ver_major() { # 取版本号主版本
  printf '%s' "${1%%.*}"
}

# ───────────────────────── 输入校验 ─────────────────────────
is_ipv4() {
  local ip="$1" IFS=. a b c d
  [[ "$ip" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]] || return 1
  read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    (( o >= 0 && o <= 255 )) || return 1
  done
  # 禁止前导零造成的歧义（如 010）
  [[ "$a" == 0* && ${#a} -gt 1 ]] && return 1
  return 0
}

# 私网 / 保留地址判定（用于"锁定前二次确认"，避免锁死内网 DNS）
is_private_ipv4() {
  local ip="$1" IFS=. a b
  is_ipv4 "$ip" || return 1
  read -r a b _ _ <<< "$ip"
  case "$a" in
    10) return 0 ;;
    192) [[ "$b" == 168 ]] && return 0 ;;
    172) (( b >= 16 && b <= 31 )) && return 0 ;;
    127) return 0 ;;
    169) [[ "$b" == 254 ]] && return 0 ;;   # link-local
  esac
  # CGNAT 100.64.0.0/10
  [[ "$a" == 100 ]] && (( b >= 64 && b <= 127 )) && return 0
  return 1
}

# ───────────────────────── 安装模式（full / cdn）─────────────────────────
#   full：全量 —— kangle + easypanel + MySQL + 网站环境（默认，与 v2 行为一致）
#   cdn ：仅 CDN —— 保留面板与 CDN 全部能力，不安装 MySQL / php-fpm / phpMyAdmin
#
# 判定顺序：项目根 .install_mode 文件 → .env 中的 KANGLE_MODE → 回落 full。
#
# ⚠️ 为什么缺失时回落 full 而不是 cdn：v3 之前的所有存量部署都没有模式标记，
#    若回落为 cdn，upgrade.sh 会加载 docker-compose.cdn.yml 而不含 mysql，
#    导致升级后数据库容器消失 —— 这是不可逆的数据可用性事故。
#    回落 full 的最坏情况是"多装了网站环境"，属于可恢复的一侧。
#
# 注意：以下函数依赖当前工作目录为项目根（调用方均先 cd 到 PROJECT_DIR）。
MODE_FILE="${MODE_FILE:-.install_mode}"

mode_detect() {
  local m=""
  if [[ -f "$MODE_FILE" ]]; then
    m="$(tr -d '[:space:]' < "$MODE_FILE" 2>/dev/null || true)"
  fi
  if [[ -z "$m" && -f .env ]]; then
    m="$(sed -n 's/^KANGLE_MODE=//p' .env 2>/dev/null | head -1 | tr -d '[:space:]' || true)"
  fi
  case "$m" in
    full|cdn) printf '%s\n' "$m" ;;
    *) printf 'full\n' ;;
  esac
}

# 输出该模式对应的 compose 主文件名（不带 -f，便于调用方用数组拼接）
mode_compose_file() {
  if [[ "$(mode_detect)" == "cdn" ]]; then
    printf 'docker-compose.cdn.yml\n'
  else
    printf 'docker-compose.yml\n'
  fi
}

# 持久化模式标记（install.sh 切换模式时使用）
mode_write() {
  local m="$1"
  case "$m" in
    full|cdn) ;;
    *) warn "未知安装模式: $m（仅支持 full / cdn）"; return 1 ;;
  esac
  printf '%s\n' "$m" > "$MODE_FILE" 2>/dev/null || return 1
  return 0
}

# ───────────────────────── 交互 ─────────────────────────
confirm_yn() {
  # $1=提示语, $2=默认值(y/N)，返回 0 表示确认
  local prompt="$1" def="${2:-N}" ans
  if [[ "${ASSUME_YES:-0}" -eq 1 ]]; then return 0; fi
  read -r -p "$prompt [${def}]: " ans || true
  ans="${ans:-$def}"
  [[ "$ans" =~ ^[Yy]$ ]]
}
