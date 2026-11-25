# 参数化 PHP-FPM 镜像：用于 add_php.sh 动态接入额外 PHP 版本
# 构建（远端低内存主机务必 -j2，-j$(nproc) 会 OOM）：
#   docker build -f php-fpm/Dockerfile --build-arg PHP_VERSION=8.3 -t easypanel-php83 .
# 实际部署由 add_php.sh 经 compose 触发；容器名 = phpfpm-<phpXX>，
# compose 服务名保留 <phpXX>（如 php83），kangle ext/tpl_<phpXX>/config.xml 的
# fastcgi host 指向服务名别名，因此面板/验收既可用 `docker exec phpfpm-php83`
# 也可用 `docker compose restart php83` 操作同一容器。
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-fpm

# 站点常用扩展；pdo_mysql / mysqli 满足“额外 PHP 需可访问 mysql”的要求。
# 基线扩展含 exif：docker-php-ext-install 产物带 /usr/local/etc/php/conf.d/
# docker-php-ext-exif.ini，属镜像级常驻启用——面板删除自己的 zz-panel ini 并
# 重启容器后 `php -m` 仍应出现 exif（“镜像级基线不受面板开关影响”验收断言）。
# 注意：OPcache 自 PHP 5.5 起已内置在官方 php:*-fpm 镜像中，且 PHP 8.5+ 再次执行
# docker-php-ext-install opcache 会因无法生成共享模块而失败，因此不再重复安装。
#
# soap 是“面板动态扩展开关”的演示靶：与 redis 同模式——镜像内预编好 soap.so，
# 但构建末尾删除其出厂启用 ini，基线 `php -m` 为 0；面板开关在 zz-panel 挂载
# 目录写 enable-soap.ini 并重启容器 → 1，删文件再重启 → 0（0→1→0 闭环验收）。
# 选 soap 而非 redis：源码随 PHP 自带，离线可构建，无需 PECL/网络。
#
# zz-panel 子目录由面板（phpset 控制器）经宿主挂载写入，容器内只读。
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 \
        pdo_mysql mysqli gd zip intl mbstring exif \
    && docker-php-ext-install -j2 soap \
    && rm -f /usr/local/etc/php/conf.d/docker-php-ext-soap.ini \
    && mkdir -p /usr/local/etc/php/conf.d/zz-panel \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP 只扫描 PHP_INI_SCAN_DIR 列出的目录（不递归子目录）：zz-panel 是挂载进来的
# 子目录，必须显式加入扫描路径，面板写入的 enable-*.ini 才会被加载；同时保留
# 默认两级，确保 php.ini 与 docker-php-ext-*.ini（镜像级基线）照常生效。
# 目录不存在时 PHP 自动跳过，未挂载 zz-panel 的裸容器不受影响。
ENV PHP_INI_SCAN_DIR="/usr/local/etc/php:/usr/local/etc/php/conf.d:/usr/local/etc/php/conf.d/zz-panel"

# 创建与 kangle 站点同组（GID 1100），使共享 /home/ftp 的站点目录对 fpm worker 可读
RUN groupadd -g 1100 epftpuser 2>/dev/null || true

# 覆盖默认 pool：worker 使用 www-data 用户 + epftpuser 组，
# 以便读取 /home/ftp/t/<vhost>/wwwroot（该目录组为 1100，权限 750）
RUN { \
    echo '[global]'; \
    echo 'daemonize = no'; \
    echo '[www]'; \
    echo 'user = www-data'; \
    echo 'group = epftpuser'; \
    echo 'listen = 9000'; \
    echo 'pm = dynamic'; \
    echo 'pm.max_children = 5'; \
    echo 'pm.start_servers = 2'; \
    echo 'pm.min_spare_servers = 1'; \
    echo 'pm.max_spare_servers = 3'; \
} > /usr/local/etc/php-fpm.d/zz-easypanel.conf

# php-fpm 默认监听 0.0.0.0:9000(tcp)，仅容器网络内可达，不暴露宿主机端口
EXPOSE 9000
