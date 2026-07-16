# EasyPanel Heavy

基于 EasyPanel Heavy 的网站管理面板 Docker 化部署方案。项目将 Kangle Web 服务器、EasyPanel 管理后台、MySQL 数据库以及多个 PHP-FPM 版本封装为 Docker Compose 编排，支持一键安装、自动初始化与灵活扩展。

## 主要特性

- 容器化部署：Kangle、EasyPanel、MySQL、phpMyAdmin 全部通过 Docker Compose 管理。
- 多 PHP 版本：默认内置 PHP 7.4，可通过 `add_php.sh` 快速接入 PHP 8.x 独立容器。
- 自动安装 Docker：在支持的 Linux 发行版上，安装脚本会自动检测并安装 Docker Engine 与 Compose 插件。
- SSL 支持：集成 acme.sh，可在容器内申请与自动续期 Let's Encrypt 证书。
- 数据持久化：所有业务数据通过 bind 挂载保存在 `./data` 目录，容器重建不丢失。

## 支持的发行版

- Debian 12 / 11
- Ubuntu 24.04 / 22.04 / 20.04
- CentOS 7 / 8
- CentOS Stream 10 / 9
- AlmaLinux 10 / 9 / 8
- Rocky Linux 10 / 9 / 8

## 环境要求

- 一台干净的 Linux 服务器（建议 2 核 4G 内存以上）。
- 可访问互联网（用于拉取 Docker 镜像与安装 acme.sh）。
- root 权限运行安装脚本。

## 快速开始

1. 克隆仓库到服务器：

```bash
git clone https://github.com/kanglesoft/EasyPanel.git /opt/kangle-build
cd /opt/kangle-build
```

2. 运行安装脚本：

```bash
./install.sh
```

安装脚本会依次完成以下操作：

- 检测系统发行版与版本。
- 如未安装 Docker，自动安装 Docker Engine 与 Compose 插件。
- 交互式设置 kangle / easypanel 管理员密码与 MySQL root 密码（留空则随机生成）。
- 可选选择额外 PHP-FPM 版本（PHP 7.4 已内置在主容器）。
- 可选启用 TCP BBR 拥塞控制算法。
- 生成 `.env` 环境变量文件。
- 构建并启动 kangle、mysql、phpMyAdmin 服务。
- 自动登录 easypanel 完成首次初始化。
- 集成 acme.sh 并注册证书续期任务。

3. 安装完成后，根据终端输出的密码访问面板：

| 服务 | 地址 | 默认账号 |
|---|---|---|
| kangle 管理后台 | `http://<服务器IP>:3311/` | admin / 安装时设置的密码 |
| EasyPanel 管理后台 | `http://<服务器IP>:3312/admin/` | admin / 同上 |
| phpMyAdmin | `http://<服务器IP>:3313/` | MySQL root / 安装时设置的密码 |

## 安装脚本参数

```bash
./install.sh --auto                 # 全自动模式，所有密码随机生成，不选额外 PHP
./install.sh --kangle-pass=XXX      # 指定管理员密码
./install.sh --mysql-pass=YYY       # 指定 MySQL root 密码
./install.sh --php-versions=8.2,8.5 # 非交互安装额外 PHP 版本（PHP 7.4 已内置）
./install.sh --enable-bbr           # 非交互启用 TCP BBR
./install.sh --force-recreate       # 先停止并移除旧容器，再重新创建
```

## 目录结构

```
.
├── install.sh              # 一键安装脚本
├── uninstall.sh            # 卸载脚本
├── docker-compose.yml      # 主编排文件
├── docker-compose.override.yml   # add_php.sh 生成的 PHP 扩展编排
├── add_php.sh              # 添加额外 PHP 版本容器
├── php-fpm/                # PHP-FPM 容器 Dockerfile
├── data/                   # 持久化数据目录（bind 挂载）
│   ├── kangle/             # Kangle / EasyPanel 配置与站点数据
│   ├── mysql/              # MySQL 数据文件
│   ├── acme/               # acme.sh 数据
│   └── homeftp/            # 站点家目录（/home/ftp）
└── .trae/documents/        # 部署与访问参考文档
```

## 添加 PHP 版本

默认已内置 PHP 7.4。如需添加 PHP 8.2：

```bash
./add_php.sh 8.2
```

执行后会自动：

- 生成 PHP 8.2-FPM 容器配置。
- 创建 `docker-compose.override.yml` 并注册服务。
- 重启 Docker Compose 使配置生效。

之后可在 EasyPanel 后台“服务器设置”中为站点选择 PHP 8.2。

## 卸载

```bash
./uninstall.sh
```

常用选项：

```bash
./uninstall.sh --yes              # 非交互模式（默认保留数据）
./uninstall.sh --delete-data      # 同时删除 ./data 下所有持久化数据
./uninstall.sh --purge-images     # 同时删除 mysql:8 / php:*-fpm 基础镜像
./uninstall.sh --purge-docker     # 同时卸载 Docker Engine（高危，谨慎使用）
```

卸载前默认会备份 `./data/kangle`、`./data/mysql`、`./data/homeftp`、`./data/acme` 到 `./backups/uninstall-backup-<时间戳>.tar.gz`。卸载完成后会提示仍需自行备份的业务数据路径，包括 PHP 网站程序（`./data/homeftp/`）、MySQL 数据（`./data/mysql/`）、kangle 配置与扩展（`./data/kangle/`）、acme.sh 数据（`./data/acme/`）。

## 常见问题

### 安装后无法访问面板

检查容器状态：

```bash
docker compose ps
```

查看 kangle 日志：

```bash
docker logs kangle
```

### 修改模板后未生效

清除 Smarty 编译缓存：

```bash
rm -rf data/kangle/nodewww/webftp/framework/templates_c/*
```

### 忘记管理员密码

直接修改 `.env` 文件中的 `KANGLE_ADMIN_PASSWORD`，然后重新执行：

```bash
./install.sh --force-recreate
```

注意：MySQL 密码若已变更，需要同步修改 `data/kangle/etc/node.cfg.php` 中的 `db_passwd`。

### SSL 证书

进入 kangle 容器后使用 acme.sh 申请：

```bash
docker exec -it kangle bash
acme.sh --issue -d example.com --nginx
```

续期任务已默认注册到容器 crontab，每日自动检查。

## 安全提示

- `.env` 文件包含明文密码，请勿提交到 Git。项目 `.gitignore` 已默认忽略该文件。
- 生产环境建议通过反向代理或防火墙限制 3311/3312/3313 端口的访问来源。
- 定期备份 `./data` 目录。

## 许可证

本项目基于 Kangle 与 EasyPanel 进行二次开发，相关二进制与原始代码的许可证归各自版权方所有。新增脚本与配置遵循 MIT 许可证开源。

## 致谢

本项目参考 [funnycups/kangle](https://github.com/funnycups/kangle) 的实现，特此鸣谢。

## 声明

本项目并非 Kangle / EasyPanel 官方产品，仅供学习、讨论与互相交流使用；如涉及侵权，请联系后下架。
