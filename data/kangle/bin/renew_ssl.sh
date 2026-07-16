#!/bin/sh
# renew_ssl.sh —— 每天由 crond 调用一次
# 1. 让 acme.sh 检查并续签即将到期的证书
# 2. 把 /root/.acme.sh 下所有证书同步到对应 vhost，并 reload kangle

export HOME=/root
ACME_BIN=/root/.acme.sh/acme.sh
APPLY_SCRIPT=/vhs/kangle/bin/apply_all_acme_certs.php

if [ -x "$ACME_BIN" ]; then
    "$ACME_BIN" --cron --home /root/.acme.sh >/dev/null 2>&1
else
    echo "[renew_ssl] acme.sh not found, skip renewal"
fi

if [ -f "$APPLY_SCRIPT" ]; then
    /usr/bin/php74 "$APPLY_SCRIPT"
else
    echo "[renew_ssl] apply script not found: $APPLY_SCRIPT"
fi
