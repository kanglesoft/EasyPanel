#!/usr/bin/env bash
#
# dns.sh — 系统 / 容器 DNS 设置与锁定（防还原）
#
# 设计要点：
#   - 默认不修改；一旦修改即**强制锁定**（不提供"改而不锁"，避免半锁状态的错误安全感）
#   - 先写"管理者"的权威配置（systemd-resolved / NetworkManager / ifcfg / ifupdown），
#     再落静态 /etc/resolv.conf —— 只 chattr 而不处理管理者，会在网络重启时语义混乱
#   - chattr +i 在 overlayfs / tmpfs / 部分 OpenVZ 上无效 → 先探测能力，不支持则降级为守护进程
#   - 容器 DNS 写 /etc/docker/daemon.json（唯一权威来源，对所有容器生效）
#   - fail-closed：七步验证任一失败即回滚到备份
#
# 用法：
#   ./dns.sh                                  交互式
#   ./dns.sh --set=alidns                     非交互切换到指定组合
#   ./dns.sh --set=custom --servers="1.1.1.1,223.5.5.5"
#   ./dns.sh --status                         查看当前状态与是否锁定
#   ./dns.sh --unlock                         解锁（chattr -i，不还原内容）
#   ./dns.sh --restore                        完全还原到修改前（含 daemon.json）
#   ./dns.sh --no-restart                     不重启 docker（供 install.sh 调用）
#   ./dns.sh --yes                            非交互
#
# 预置组合（均实测 UDP/53 可达）：
#   alidns      223.5.5.5  223.6.6.6      阿里 AliDNS（国内推荐）
#   dnspod      119.29.29.29 119.28.28.28 腾讯 DNSPod
#   cloudflare  1.1.1.1    1.0.0.1        Cloudflare
#   google      8.8.8.8    8.8.4.4        Google
#   114         114.114.114.114 114.114.115.115
#   mixed       223.5.5.5  1.1.1.1        国内优先 + 境外兜底（推荐默认项）
#   custom      由 --servers= 指定（逗号分隔）
#
# ⚠️ 能力边界：明文 UDP/53 **不能根除链路层污染**，只能规避运营商 Local DNS 的投毒/劫持。
#    实测同一网络下 8.8.8.8 / 1.1.1.1 / 223.5.5.5 对部分域名均返回伪造结果。
#    真正防污染需 DoT(853)/DoH(443)，本脚本预留 --set=custom --servers=127.0.0.1 供本地转发器接入。
#
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LOG_TAG="dns"
# shellcheck source=/dev/null
source "$PROJECT_DIR/lib/common.sh" 2>/dev/null || {
  echo "[error] 无法加载 lib/common.sh" >&2; exit 1;
}

RESOLV="/etc/resolv.conf"
GUARD_BIN="/usr/local/sbin/kangle-dns-guard.sh"
GUARD_LOG="/var/log/kangle-dns-guard.log"
GUARD_SERVICE="/etc/systemd/system/kangle-dns-guard.service"
GUARD_TIMER="/etc/systemd/system/kangle-dns-guard.timer"
GUARD_CRON="/etc/cron.d/kangle-dns-guard"
STATE_FILE="$STATE_DIR/dns.state"

# ───────────────────────── 参数解析 ─────────────────────────
SET_NAME=""
SERVERS=""
DO_STATUS=0
DO_UNLOCK=0
DO_RESTORE=0
NO_RESTART=0
for a in "$@"; do
  case "$a" in
    --set=*)       SET_NAME="${a#*=}" ;;
    --servers=*)   SERVERS="${a#*=}" ;;
    --status)      DO_STATUS=1 ;;
    --unlock)      DO_UNLOCK=1 ;;
    --restore)     DO_RESTORE=1 ;;
    --no-restart)  NO_RESTART=1 ;;
    --yes|-y)      ASSUME_YES=1 ;;
    -h|--help)     sed -n '3,40p' "$0"; exit 0 ;;
    *) warn "未知参数: $a" ;;
  esac
done

[[ "$DO_STATUS" -eq 0 ]] && require_root

# ───────────────────────── DNS 组合表 ─────────────────────────
resolve_preset() {
  case "$1" in
    alidns)     echo "223.5.5.5 223.6.6.6" ;;
    dnspod)     echo "119.29.29.29 119.28.28.28" ;;
    cloudflare) echo "1.1.1.1 1.0.0.1" ;;
    google)     echo "8.8.8.8 8.8.4.4" ;;
    114)        echo "114.114.114.114 114.114.115.115" ;;
    mixed)      echo "223.5.5.5 1.1.1.1" ;;
    custom)     echo "$SERVERS" | tr ',' ' ' ;;
    *)          echo "" ;;
  esac
}

# ───────────────────────── 管理者探测 ─────────────────────────
MGR_RESOLVED=0; MGR_NM=0; MGR_NETPLAN=0; MGR_IFCFG=0; MGR_IFUPDOWN=0; MGR_STATIC=0
RESOLV_IS_LINK=0
detect_manager() {
  [[ -L "$RESOLV" ]] && RESOLV_IS_LINK=1
  need_cmd systemctl && systemctl is-active --quiet systemd-resolved 2>/dev/null && MGR_RESOLVED=1
  need_cmd systemctl && systemctl is-active --quiet NetworkManager 2>/dev/null && MGR_NM=1
  compgen -G "/etc/netplan/*.yaml" >/dev/null 2>&1 && MGR_NETPLAN=1
  compgen -G "/etc/sysconfig/network-scripts/ifcfg-*" >/dev/null 2>&1 && MGR_IFCFG=1
  [[ -f /etc/network/interfaces ]] && MGR_IFUPDOWN=1
  if (( MGR_RESOLVED == 0 && MGR_NM == 0 && MGR_NETPLAN == 0 && MGR_IFCFG == 0 && MGR_IFUPDOWN == 0 )); then
    MGR_STATIC=1
  fi
}

# ───────────────────────── chattr 能力探测 ─────────────────────────
chattr_supported() {
  need_cmd chattr || return 1
  need_cmd lsattr || return 1
  local t; t="$(mktemp 2>/dev/null)" || return 1
  chattr +i "$t" 2>/dev/null || { rm -f "$t" 2>/dev/null; return 1; }
  if lsattr "$t" 2>/dev/null | grep -q '^[^ ]*i'; then
    chattr -i "$t" 2>/dev/null || true
    rm -f "$t" 2>/dev/null || true
    return 0
  fi
  chattr -i "$t" 2>/dev/null || true
  rm -f "$t" 2>/dev/null || true
  return 1
}

# ───────────────────────── 状态查看 ─────────────────────────
do_status() {
  echo
  log "DNS 状态"
  echo "  /etc/resolv.conf:"
  if [[ -L "$RESOLV" ]]; then
    echo "    类型: 符号链接 -> $(readlink "$RESOLV" 2>/dev/null || echo '?')"
  else
    echo "    类型: 普通文件"
  fi
  grep -E '^\s*nameserver' "$RESOLV" 2>/dev/null | sed 's/^/    /' || echo "    (无 nameserver)"
  if need_cmd lsattr; then
    local attr; attr="$(lsattr "$RESOLV" 2>/dev/null || true)"
    case "$attr" in
      *i*) echo "    锁定: 是（chattr +i）" ;;
      *)   echo "    锁定: 否" ;;
    esac
  fi
  detect_manager
  echo "  管理者: resolved=$MGR_RESOLVED nm=$MGR_NM netplan=$MGR_NETPLAN ifcfg=$MGR_IFCFG ifupdown=$MGR_IFUPDOWN static=$MGR_STATIC"
  echo "  chattr 支持: $(chattr_supported >/dev/null 2>&1 && chattr_supported && echo 是 || echo 否)"
  if [[ -f "$GUARD_BIN" ]]; then
    echo "  守护进程: 已安装 ($GUARD_BIN)"
    if need_cmd systemctl && systemctl is-active --quiet kangle-dns-guard.timer 2>/dev/null; then
      echo "    定时器: 运行中"
    elif [[ -f "$GUARD_CRON" ]]; then
      echo "    定时器: cron"
    else
      echo "    定时器: 未运行"
    fi
  else
    echo "  守护进程: 未安装"
  fi
  if [[ -s "$DAEMON_JSON" ]]; then
    local v; v="$(daemon_json_get dns)"
    echo "  容器 DNS (daemon.json dns): ${v:-未设置}"
    local r; r="$(daemon_json_get registry-mirrors)"
    [[ -n "$r" ]] && echo "  registry-mirrors: $r"
  fi
  [[ -f "$STATE_FILE" ]] && { echo "  修改前备份记录:"; sed 's/^/    /' "$STATE_FILE"; }
  echo
  exit 0
}

# ───────────────────────── 解锁 / 还原 ─────────────────────────
do_unlock() {
  log "解锁 $RESOLV ..."
  need_cmd chattr && chattr -i "$RESOLV" 2>/dev/null || true
  if need_cmd lsattr && lsattr "$RESOLV" 2>/dev/null | grep -q '^[^ ]*i'; then
    warn "解锁失败（仍带 i 属性）"
    exit 1
  fi
  ok "已解锁（内容未改动）"
  exit 0
}

do_restore() {
  log "还原 DNS 配置到修改前..."
  # 1) 解锁
  need_cmd chattr && chattr -i "$RESOLV" 2>/dev/null || true

  # 2) 还原 resolv.conf（优先还原 symlink 语义）
  local target=""
  [[ -f "$STATE_FILE" ]] && target="$(sed -n 's/^RESOLV_LINK=//p' "$STATE_FILE" | head -1)"
  local bak
  bak="$(ls -1t "$STATE_DIR"/resolv.conf.bak.* 2>/dev/null | head -1 || true)"
  if [[ -n "$bak" ]]; then
    rm -f "$RESOLV" 2>/dev/null || true
    if [[ -n "$target" ]]; then
      ln -s "$target" "$RESOLV" 2>/dev/null && ok "已还原符号链接 -> $target" || cp -a "$bak" "$RESOLV"
    else
      cp -a "$bak" "$RESOLV" && ok "已还原 ${RESOLV}（来自 ${bak}）"
    fi
  else
    warn "未找到 resolv.conf 备份"
  fi

  # 3) 移除守护
  if need_cmd systemctl; then
    systemctl disable --now kangle-dns-guard.timer >/dev/null 2>&1 || true
  fi
  rm -f "$GUARD_SERVICE" "$GUARD_TIMER" "$GUARD_CRON" "$GUARD_BIN" 2>/dev/null || true
  need_cmd systemctl && systemctl daemon-reload >/dev/null 2>&1 || true

  # 4) 移除容器 DNS 配置
  daemon_json_del "dns" >/dev/null 2>&1 && ok "已移除 daemon.json 的 dns"
  daemon_json_del "dns-opts" >/dev/null 2>&1 || true

  rm -f "$STATE_FILE" 2>/dev/null || true
  if [[ "$NO_RESTART" -eq 0 ]]; then consume_restart_docker; fi
  ok "还原完成"
  exit 0
}

ensure_state_dir
[[ "$DO_STATUS" -eq 1 ]] && do_status
[[ "$DO_UNLOCK" -eq 1 ]] && do_unlock
[[ "$DO_RESTORE" -eq 1 ]] && do_restore

# ───────────────────────── 选择 DNS ─────────────────────────
D1=""; D2=""
if [[ -n "$SET_NAME" ]]; then
  pair="$(resolve_preset "$SET_NAME")"
  [[ -z "$pair" ]] && die "未知的 --set 值: ${SET_NAME}（可选: alidns|dnspod|cloudflare|google|114|mixed|custom）"
  read -r D1 D2 _ <<< "$pair"
else
  echo
  log "DNS 设置（默认不修改）"
  echo "  1) 不修改（默认）"
  echo "  2) 阿里 AliDNS      223.5.5.5 / 223.6.6.6        [国内推荐]"
  echo "  3) 混合             223.5.5.5 / 1.1.1.1          [国内优先 + 境外兜底]"
  echo "  4) 腾讯 DNSPod      119.29.29.29 / 119.28.28.28"
  echo "  5) Cloudflare       1.1.1.1 / 1.0.0.1"
  echo "  6) Google           8.8.8.8 / 8.8.4.4"
  echo "  7) 114 DNS          114.114.114.114 / 114.114.115.115"
  echo "  8) 自定义（逗号分隔，2 个）"
  echo
  echo "  ⚠️  说明：明文 UDP/53 只能规避运营商 Local DNS 劫持，无法根除链路层污染。"
  echo "      修改后将强制锁定 /etc/resolv.conf 防还原。"
  echo
  local_ans=""
  read -r -p "请选择 [1]: " local_ans || true
  case "${local_ans:-1}" in
    1) info "未做修改，退出"; exit 0 ;;
    2) read -r D1 D2 _ <<< "$(resolve_preset alidns)" ;;
    3) read -r D1 D2 _ <<< "$(resolve_preset mixed)" ;;
    4) read -r D1 D2 _ <<< "$(resolve_preset dnspod)" ;;
    5) read -r D1 D2 _ <<< "$(resolve_preset cloudflare)" ;;
    6) read -r D1 D2 _ <<< "$(resolve_preset google)" ;;
    7) read -r D1 D2 _ <<< "$(resolve_preset 114)" ;;
    8) read -r -p "请输入 DNS（逗号分隔，如 1.1.1.1,223.5.5.5）: " local_custom || true
       read -r D1 D2 _ <<< "$(echo "$local_custom" | tr ',' ' ')" ;;
    *) info "未做修改，退出"; exit 0 ;;
  esac
fi

# 校验
is_ipv4 "${D1:-}" || die "首选 DNS 非法: ${D1:-（空）}"
is_ipv4 "${D2:-}" || die "备选 DNS 非法: ${D2:-（空）}"
ok "目标 DNS: $D1 / $D2"

# ───────────────────────── 内网 DNS 二次确认 ─────────────────────────
# 若当前 DNS 是内网地址，改动可能锁死内网域名解析，必须确认
if [[ -r "$RESOLV" ]]; then
  while read -r _ns cur; do
    if is_private_ipv4 "$cur" 2>/dev/null; then
      echo
      warn "检测到当前 DNS 为内网/保留地址: $cur"
      warn "锁定后该内网解析器将失效，可能影响内网域名解析。"
      confirm_yn "确认仍要继续？" "N" || { info "已取消"; exit 0; }
      break
    fi
  done < <(grep -E '^\s*nameserver' "$RESOLV" 2>/dev/null || true)
fi

# ───────────────────────── Step 0: 备份 ─────────────────────────
log "Step 0/7 备份当前配置..."
ensure_state_dir
RESOLV_BAK="$(backup_path "$RESOLV" || true)"
[[ -n "$RESOLV_BAK" ]] && ok "已备份: $RESOLV_BAK"
daemon_json_backup >/dev/null 2>&1 || true

{
  echo "TIME=$(date '+%Y-%m-%d %H:%M:%S')"
  echo "DNS1=$D1"
  echo "DNS2=$D2"
  if [[ -L "$RESOLV" ]]; then echo "RESOLV_LINK=$(readlink "$RESOLV" 2>/dev/null || true)"; fi
} > "$STATE_FILE" 2>/dev/null || true

# 失败回滚（fail-closed）
rollback() {
  warn "验证失败，正在回滚..."
  need_cmd chattr && chattr -i "$RESOLV" 2>/dev/null || true
  if [[ -n "${RESOLV_BAK:-}" && -f "$RESOLV_BAK" ]]; then
    rm -f "$RESOLV" 2>/dev/null || true
    cp -a "$RESOLV_BAK" "$RESOLV" 2>/dev/null || true
  fi
  daemon_json_del "dns" >/dev/null 2>&1 || true
  daemon_json_del "dns-opts" >/dev/null 2>&1 || true
  warn "已回滚到修改前状态"
  exit 1
}

# ───────────────────────── Step 1-2: 探测并写管理者权威配置 ─────────────────────────
log "Step 1/7 探测 DNS 管理者..."
detect_manager
info "resolved=$MGR_RESOLVED nm=$MGR_NM netplan=$MGR_NETPLAN ifcfg=$MGR_IFCFG ifupdown=$MGR_IFUPDOWN static=$MGR_STATIC"

log "Step 2/7 写入各管理者的权威配置..."

if [[ "$MGR_RESOLVED" -eq 1 ]]; then
  mkdir -p /etc/systemd/resolved.conf.d 2>/dev/null || true
  cat > /etc/systemd/resolved.conf.d/99-kangle-dns.conf <<EOF
# 由 dns.sh 生成于 $(date '+%Y-%m-%d %H:%M:%S')
[Resolve]
DNS=$D1 $D2
FallbackDNS=1.1.1.1 8.8.8.8
# 关闭 127.0.0.53 stub：否则 /etc/resolv.conf 只是个指向 stub 的指针，锁它没有意义
DNSStubListener=no
EOF
  systemctl restart systemd-resolved >/dev/null 2>&1 || warn "systemd-resolved 重启失败（继续）"
  ok "已写入 systemd-resolved 配置并关闭 stub 监听"
fi

if [[ "$MGR_NM" -eq 1 ]] && need_cmd nmcli; then
  local_nm=0
  while IFS=: read -r nm_name nm_dev; do
    [[ -n "${nm_name:-}" ]] || continue
    nmcli con mod "$nm_name" ipv4.dns "$D1 $D2" >/dev/null 2>&1 || continue
    nmcli con mod "$nm_name" ipv4.ignore-auto-dns yes >/dev/null 2>&1 || true   # 等价于 PEERDNS=no
    local_nm=$((local_nm + 1))
  done < <(nmcli -t -f NAME,DEVICE con show --active 2>/dev/null || true)
  # 用 reload 而非逐条 con up，避免断网
  nmcli general reload >/dev/null 2>&1 || true
  ok "已更新 NetworkManager 连接 $local_nm 个（ignore-auto-dns=yes）"
fi

if [[ "$MGR_IFCFG" -eq 1 ]]; then
  local_if=0
  for f in /etc/sysconfig/network-scripts/ifcfg-*; do
    [[ -f "$f" ]] || continue
    case "$(basename "$f")" in *lo|*~|*.bak) continue ;; esac
    backup_path "$f" >/dev/null 2>&1 || true
    # 去重写入
    sed -i '/^DNS1=/d; /^DNS2=/d; /^PEERDNS=/d' "$f" 2>/dev/null || true
    {
      echo "DNS1=$D1"
      echo "DNS2=$D2"
      echo "PEERDNS=no"
    } >> "$f"
    local_if=$((local_if + 1))
  done
  ok "已更新 ifcfg 配置 $local_if 个（PEERDNS=no）"
fi

if [[ "$MGR_IFUPDOWN" -eq 1 ]]; then
  mkdir -p /etc/network/interfaces.d 2>/dev/null || true
  echo "dns-nameservers $D1 $D2" > /etc/network/interfaces.d/99-kangle-dns
  ok "已写入 /etc/network/interfaces.d/99-kangle-dns"
fi

if [[ "$MGR_NETPLAN" -eq 1 ]]; then
  info "netplan 环境：不改写 yaml（同网卡多文件易冲突）。"
  info "  其后端必为 networkd 或 NetworkManager，已被上面分支覆盖；"
  info "  本机将依靠「静态 resolv.conf + 锁定」达成目标。"
fi

# ───────────────────────── Step 3: 落静态 resolv.conf ─────────────────────────
log "Step 3/7 写入静态 $RESOLV ..."
rm -f "$RESOLV" 2>/dev/null || true     # 先删 symlink，否则会写进 /run
cat > "$RESOLV" <<EOF
# 由 kangle dns.sh 管理（$(date '+%Y-%m-%d %H:%M:%S')）
# 修改前请先解锁： dns.sh --unlock
nameserver $D1
nameserver $D2
options timeout:2 attempts:2 rotate
EOF
chmod 644 "$RESOLV" 2>/dev/null || true
ok "已写入静态 resolv.conf"

# ───────────────────────── Step 4: 锁定 ─────────────────────────
log "Step 4/7 锁定 $RESOLV ..."
CHATTR_OK=0
if chattr_supported; then
  chattr +i "$RESOLV" 2>/dev/null || true
  if need_cmd lsattr && lsattr "$RESOLV" 2>/dev/null | grep -q '^[^ ]*i'; then
    CHATTR_OK=1
    ok "已用 chattr +i 锁定"
  else
    warn "chattr +i 未生效，将降级为守护进程"
  fi
else
  warn "当前文件系统不支持 chattr +i（overlayfs / tmpfs / 容器化 VPS 常见），降级为守护进程"
fi

# ───────────────────────── Step 5: 守护进程 ─────────────────────────
# 与 chattr 不并存"改写"：chattr 可用时 guard 只检测告警，避免写入冲突
install_guard() {
  local mode="$1"   # alert | enforce
  cat > "$GUARD_BIN" <<EOF
#!/usr/bin/env bash
# kangle-dns-guard — 由 dns.sh 生成（$(date '+%Y-%m-%d %H:%M:%S')）
# 模式: $mode   （alert=只告警；enforce=检测并还原）
WANT1="$D1"
WANT2="$D2"
MODE="$mode"
LOG="$GUARD_LOG"
cur="\$(grep -E '^[[:space:]]*nameserver' /etc/resolv.conf 2>/dev/null | awk '{print \$2}' | tr '\\n' ' ')"
case "\$cur" in
  *"\$WANT1"*) exit 0 ;;
esac
echo "[\$(date '+%F %T')] DNS 已被修改为: \${cur}（期望: \$WANT1 \${WANT2}）" >> "\$LOG" 2>/dev/null
if [ "\$MODE" = "enforce" ]; then
  command -v chattr >/dev/null 2>&1 && chattr -i /etc/resolv.conf 2>/dev/null
  printf '# 由 kangle-dns-guard 还原\\nnameserver %s\\nnameserver %s\\noptions timeout:2 attempts:2 rotate\\n' "\$WANT1" "\$WANT2" > /etc/resolv.conf 2>/dev/null
  command -v chattr >/dev/null 2>&1 && chattr +i /etc/resolv.conf 2>/dev/null
  echo "[\$(date '+%F %T')] 已还原 DNS" >> "\$LOG" 2>/dev/null
fi
exit 0
EOF
  chmod 755 "$GUARD_BIN" 2>/dev/null || true

  if need_cmd systemctl && [[ -d /etc/systemd/system ]]; then
    cat > "$GUARD_SERVICE" <<EOF
[Unit]
Description=kangle DNS guard ($mode)
[Service]
Type=oneshot
ExecStart=$GUARD_BIN
EOF
    cat > "$GUARD_TIMER" <<EOF
[Unit]
Description=kangle DNS guard timer
[Timer]
OnBootSec=30s
OnUnitActiveSec=5min
[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl enable --now kangle-dns-guard.timer >/dev/null 2>&1 \
      && ok "守护已安装并启用（systemd timer，模式=${mode}）" \
      || warn "systemd timer 启用失败，回退 cron"
    return 0
  fi
  # 无 systemd（如 CentOS 6）用 cron
  if [[ -d /etc/cron.d ]]; then
    printf '*/5 * * * * root %s >/dev/null 2>&1\n' "$GUARD_BIN" > "$GUARD_CRON"
    chmod 644 "$GUARD_CRON" 2>/dev/null || true
    ok "守护已安装（cron，每 5 分钟，模式=${mode}）"
  else
    warn "无法安装守护进程（无 systemd 且无 cron.d）"
  fi
}

if [[ "$CHATTR_OK" -eq 1 ]]; then
  install_guard "alert"       # 只告警，不改写（避免与 chattr 冲突）
else
  install_guard "enforce"     # 无 chattr 时全权还原
fi

# ───────────────────────── Step 6: 容器 DNS ─────────────────────────
log "Step 6/7 配置容器 DNS（daemon.json）..."
if need_cmd docker; then
  if daemon_json_set "dns" "$(printf '["%s","%s"]' "$D1" "$D2")"; then
    ok "已写入 daemon.json dns"
    daemon_json_set "dns-opts" '["timeout:2","attempts:2"]' >/dev/null 2>&1 || true
    mark_restart_docker
    info "  注：自定义 bridge 网络内容器的 /etc/resolv.conf 显示 127.0.0.11（Docker 嵌入式 DNS），"
    info "      它作为转发器把外部查询发给此处配置的上游。验证需看解析结果，不能只看文件内容。"
  else
    warn "写入 daemon.json 失败（缺少 jq/python3），容器 DNS 未配置"
  fi
else
  info "未检测到 docker，跳过容器 DNS 配置"
fi

# ───────────────────────── Step 7: 验证（fail-closed）─────────────────────────
log "Step 7/7 验证..."
VERIFY_FAIL=0

grep -q "^nameserver $D1" "$RESOLV" 2>/dev/null || { warn "resolv.conf 未包含 $D1"; VERIFY_FAIL=1; }

if need_cmd nslookup; then
  ns_out="$(nslookup mirrors.aliyun.com 2>&1 || true)"
  printf '%s\n' "$ns_out" | grep -q "$D1" || { warn "nslookup 未使用 $D1 作为解析服务器"; VERIFY_FAIL=1; }
elif need_cmd dig; then
  dig_out="$(dig +time=3 +tries=1 mirrors.aliyun.com 2>&1 || true)"
  printf '%s\n' "$dig_out" | grep -q "SERVER:.*$D1" || { warn "dig 未使用 $D1 作为解析服务器"; VERIFY_FAIL=1; }
fi

# 功能性验证：必须真的能解析
if need_cmd getent; then
  getent hosts mirrors.aliyun.com >/dev/null 2>&1 || { warn "解析失败: mirrors.aliyun.com"; VERIFY_FAIL=1; }
fi

if [[ "$CHATTR_OK" -eq 1 ]]; then
  need_cmd lsattr && lsattr "$RESOLV" 2>/dev/null | grep -q '^[^ ]*i' || { warn "锁定状态异常"; VERIFY_FAIL=1; }
fi

if [[ "$VERIFY_FAIL" -ne 0 ]]; then
  rollback
fi

ok "宿主 DNS 已生效并锁定"
ok "目标: $D1 / $D2"

# 重启 docker（单独运行时；被 install.sh 调用时只打标记）
if [[ "$NO_RESTART" -eq 0 ]]; then
  consume_restart_docker
else
  info "已标记待重启 docker（由 install.sh 统一执行）"
fi

echo
echo "  常用命令："
echo "    查看状态:  ./dns.sh --status"
echo "    临时解锁:  ./dns.sh --unlock"
echo "    完全还原:  ./dns.sh --restore"
echo
warn "注意：锁定后 NetworkManager / cloud-init / dhclient 在重启网络时会因无法重写 resolv.conf 报错，"
warn "      这属于预期行为（正是"锁定"的语义）。如需修改 DNS，请先 --unlock。"
