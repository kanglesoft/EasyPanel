# kangle/Dockerfile
# 主容器：kangle + easypanel（easypanel 后台 webftp/dbadmin 走系统 PHP 7.4 CGI，
#     站点 PHP 可经内嵌 tpl_php 或 compose 内的独立 php7.4 容器处理）。
#
# 基础镜像：CentOS 7。
#   - 与 kangle 发行版所用基础系统（CentOS 7）一致，确保 kangle 二进制及内嵌 PHP
#     扩展的 ABI / glibc 兼容（已在干净 centos:7 内 ldd 校验）。
#   - CentOS 7 已 EOL，官方源下线，改走 vault.centos.org 归档源。
#
# 设计：镜像只提供“运行时环境”（CentOS7 + kangle 依赖库 + cron + memcached +
#      PHP 7.4 运行时），kangle/easypanel 程序文件由 compose 以 bind 方式挂到
#      /vhs/kangle（来自本仓库 data/kangle/）。
#
# 依赖库说明（ldd 校验结论，均不依赖任何外部镜像，全部以“文件 vendoring”方式打入）：
#   - libmcrypt 2.5.8（标准 LGPL 库）：kangle 内嵌 tpl_php56 的 NEEDED 库，基础仓库
#     不含，已 vendoring 到本仓库 kangle/vendor/（libmcrypt.so.4 -> libmcrypt.so.4.4.8）。
#   - PHP 7.4 运行时（Remi PHP 7.4.33）：easypanel 后台 webftp/dbadmin 通过
#     /usr/bin/php74-cgi 执行，其全部依赖库（libgd / ImageMagick / libmemcached /
#     oniguruma / libpq / libsodium / libxslt / libzip / freetype / libjpeg / libpng /
#     X11 等）已自发行版抽取并 vendoring 到 kangle/vendor/php74.tar.gz，在构建期解包
#     到镜像对应绝对路径并 ldconfig，与源项目行为一致。

FROM centos:7

# CentOS 7 已 EOL，官方镜像源已下线，改走 vault 归档源。
RUN sed -i 's/^mirrorlist/#mirrorlist/; s|^#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-Base.repo

# kangle 及其内嵌 php(tpl_php) 扩展所需的运行时库（干净 centos:7 下 ldd 校验，仅需
# 少量 CentOS 7 基础仓库包）。freetype/libjpeg/libpng/libtool-ltdl 由 php74.tar.gz 提供，
# 此处不再重复安装，避免同名 soname 冲突。
RUN yum install -y --setopt=tsflags=nodocs \
        openssl sqlite memcached cronie tar which tzdata \
    && yum clean all

# vendoring 的第三方库（libmcrypt 2.5.8，标准库；来自本仓库 kangle/vendor/）。
COPY vendor/libmcrypt.so* /usr/lib64/

# vendoring 的 PHP 7.4 运行时（Remi PHP 7.4.33，含全部依赖库）。
# 解包到镜像对应绝对路径并刷新动态链接缓存。
COPY vendor/php74.tar.gz /tmp/php74.tar.gz
RUN tar xzf /tmp/php74.tar.gz -C / \
    && ldconfig \
    && rm -f /tmp/php74.tar.gz

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# kangle 程序文件由 compose bind 挂载到 /vhs/kangle，不在镜像内打包
EXPOSE 80 3311 3312 3313

ENTRYPOINT ["/entrypoint.sh"]
