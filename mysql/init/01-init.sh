#!/bin/bash
# mysql/init/01-init.sh
# 由 mysql 官方镜像在首次初始化后执行（docker-entrypoint-initdb.d）。
# 目标：把 root 与 easypanel 专用用户改为 mysql_native_password，
#       以兼容 kangle/easypanel 使用的旧版 PHP mysql 驱动。
set -e

PASS="${MYSQL_ROOT_PASSWORD:-kangle}"

mysql -u root -p"${PASS}" <<SQL
-- root 本地与远程均改为 native 密码
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED WITH mysql_native_password BY '${PASS}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${PASS}';
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY '${PASS}';

-- easypanel 专用库与用户（native 密码，允许从 compose 网络任意主机连接）
CREATE DATABASE IF NOT EXISTS easypanel DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'easypanel'@'%' IDENTIFIED WITH mysql_native_password BY '${PASS}';
GRANT ALL PRIVILEGES ON *.* TO 'easypanel'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
SQL

echo "[init] mysql native password + easypanel user ready"
