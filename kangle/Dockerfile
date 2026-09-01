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
# 注意：CentOS 7 已 EOL，官方 base/vault 源在线，但 EPEL 需走阿里云镜像
# （archives.fedoraproject.org 已归档且常损坏）。此处追加阿里云 EPEL 以便安装 lftp 等工具。
RUN yum install -y --setopt=tsflags=nodocs \
        openssl sqlite memcached cronie tar which tzdata \
    && yum install -y --setopt=tsflags=nodocs \
        "https://mirrors.aliyun.com/epel/7/x86_64/Packages/e/epel-release-7-14.noarch.rpm" \
    && yum install -y --setopt=tsflags=nodocs lftp \
    && yum clean all

# vendoring 的第三方库（libmcrypt 2.5.8，标准库；来自本仓库 kangle/vendor/）。
COPY vendor/libmcrypt.so* /usr/lib64/

# vendoring 的 PHP 7.4 运行时（Remi PHP 7.4.33，含全部依赖库）。
# 解包到镜像对应绝对路径并刷新动态链接缓存。
COPY vendor/php74.tar.gz /tmp/php74.tar.gz
RUN tar xzf /tmp/php74.tar.gz -C / \
    && ldconfig \
    && rm -f /tmp/php74.tar.gz

# 停用 PHP memcached 扩展（远端部署实测修复）。
#
# 现象：容器内**每次**调用 php74（含每个 HTTP 请求）都向 stderr 输出
#   PHP Warning: PHP Startup: Unable to load dynamic library 'memcached.so'
#     (… undefined symbol: memcached_destroy_sasl_auth_data)
#
# 根因：memcached.so 来自 Remi 仓库（编译时链接较新的 libmemcached），而镜像里实际
#   生效的是 php74.tar.gz 中 vendoring 的旧版 libmemcached，符号缺失 → 加载失败。
#
# 影响面核实（确认无功能损失后才停用，而非简单屏蔽告警）：
#   ① 面板代码全量检索 `new Memcached` / `Memcache(` —— **0 处命中**，无代码依赖；
#   ② session.save_handler=files（非 memcached），登录会话不依赖它；
#   ③ 容器内 memcached 服务仍作为独立进程保留，仅 PHP 侧扩展不再加载。
#
# 处置：注释掉 50-memcached.ini 的 extension 行（保留 .so 文件），
#   便于将来补齐版本匹配的 libmemcached 时一行改回。最后 grep 做 fail-closed 断言，
#   防止 sed 未命中时构建静默通过。
RUN sed -i 's|^extension=memcached\.so|;extension=memcached.so ; 已停用：vendored libmemcached ABI 不匹配，且面板代码无依赖（见 Dockerfile 注释）|' \
        /etc/opt/remi/php74/php.d/50-memcached.ini \
    && grep -q '^;extension=memcached.so' /etc/opt/remi/php74/php.d/50-memcached.ini

# Docker CLI（静态二进制，仅客户端、不含 daemon）：用于面板内容器管理，并经宿主
# docker.sock 与宿主 Docker 守护进程通信；宿主级操作（SSH 端口 / root 密码 / 服务启停）
# 通过一次性特权子容器（busybox + nsenter）进入宿主命名空间执行，故本镜像无需 nsenter。
#
# 供应链加固（与 install.sh 的 acme.sh 同类问题）：
#   这个二进制以 root 身份安装进镜像，若下载内容被替换，等同于在镜像里预置后门。
#   Docker 官方**不提供**该 tarball 的 .sha256 文件（实测
#   .../docker-27.3.1.tgz.sha256 返回 404），因此下面的校验值是从官方源实测下载后
#   计算得到——它能防住 CDN 缓存投毒、传输篡改与中间人替换，但不构成对上游本身的
#   背书（上游信任由 HTTPS + download.docker.com 官方域名承担）。
#
#     版本   : 27.3.1
#     字节数 : 75324256
#     sha256 : 9b4f6fe406e50f9085ee474c451e2bb5adb119a03591f467922d3b4e2ddf31d3
#
#   ⚠️ 升级 Docker CLI 版本时必须同步重算 sha256，否则构建会在此处中断。
#      这是有意的 fail-closed：宁可构建失败，也不要静默装入未校验的二进制。
#      若确因上游重新打包导致哈希变化，请先人工核验新包来源再更新本值。
RUN curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-27.3.1.tgz -o /tmp/docker.tgz \
    && echo '9b4f6fe406e50f9085ee474c451e2bb5adb119a03591f467922d3b4e2ddf31d3  /tmp/docker.tgz' | sha256sum -c - \
    && tar xzf /tmp/docker.tgz -C /tmp \
    && install -m 0755 /tmp/docker/docker /usr/bin/docker \
    && rm -rf /tmp/docker /tmp/docker.tgz

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# 预编译的 pure-ftpd 服务（v1.0.52，CentOS 7，--without-capabilities 编译，
# 避免容器内 cap_set_proc 失败导致 extauth 启动崩溃）。
# 二进制从上游 kangle 镜像层提取并 vendoring 到本仓库 kangle/vendor/pure-ftpd/sbin/，
# 与“webftp 文件管理”底层 FTP 服务强相关：webftp 通过容器内部 localhost:21 访问
# pure-ftpd（extauth 后端对接 easypanel vhost 认证），FTP 服务缺失会导致 webftp 白屏。
# entrypoint.sh 在启动 kangle 前负责拉起 pure-authd + pure-ftpd。
COPY vendor/pure-ftpd/sbin/pure-ftpd /vhs/pure-ftpd/sbin/pure-ftpd
COPY vendor/pure-ftpd/sbin/pure-authd /vhs/pure-ftpd/sbin/pure-authd
RUN chmod 755 /vhs/pure-ftpd/sbin/pure-ftpd /vhs/pure-ftpd/sbin/pure-authd

# kangle 程序文件由 compose bind 挂载到 /vhs/kangle，不在镜像内打包
EXPOSE 21 80 3311 3312 3313

ENTRYPOINT ["/entrypoint.sh"]
