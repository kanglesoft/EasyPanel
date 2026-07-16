# 参数化 PHP-FPM 镜像：用于 add_php.sh 动态接入额外 PHP 版本
# 构建: docker build -f php-fpm/Dockerfile --build-arg PHP_VERSION=8.2 -t phpfpm-php82 .
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-fpm

# 站点常用扩展；pdo_mysql / mysqli 满足“额外 PHP 需可访问 mysql”的要求
# 注意：OPcache 自 PHP 5.5 起已内置在官方 php:*-fpm 镜像中，且 PHP 8.5+ 再次执行
# docker-php-ext-install opcache 会因无法生成共享模块而失败，因此不再重复安装。
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd zip intl mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

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
