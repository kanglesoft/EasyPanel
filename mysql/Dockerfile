# mysql/Dockerfile
# MySQL 8 容器，与 kangle + easypanel 配合。
# 关键点：easypanel 使用的旧版 PHP mysql 驱动不支持 caching_sha2_password，
# 因此 root 与专用用户统一改为 mysql_native_password（见 init/01-init.sh）。
FROM mysql:8

# 初始化脚本：在首次建库后执行，按 MYSQL_ROOT_PASSWORD 设置 native 密码并建专用用户。
COPY init/01-init.sh /docker-entrypoint-initdb.d/01-init.sh
RUN chmod +x /docker-entrypoint-initdb.d/01-init.sh

EXPOSE 3306
