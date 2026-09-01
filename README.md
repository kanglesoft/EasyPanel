# EasyPanel Heavy

基于 EasyPanel Heavy 的网站管理面板 Docker 化部署方案。项目将 Kangle Web 服务器、EasyPanel 管理后台、MySQL 数据库以及多个 PHP-FPM 版本封装为 Docker Compose 编排，支持一键安装、自动初始化与灵活扩展。

## 主要特性

- 容器化部署：Kangle、EasyPanel、MySQL、phpMyAdmin 全部通过 Docker Compose 管理。
- 多 PHP 版本：默认内置 PHP 7.4，可通过 `add_php.sh` 快速接入 PHP 8.x 独立容器。
- 自动安装 Docker：在支持的 Linux 发行版上，安装脚本会自动检测并安装 Docker Engine 与 Compose 插件。
- SSL 支持：集成 acme.sh，可在容器内申请与自动续期 Let's Encrypt 证书。
- 数据持久化：所有业务数据通过 bind 挂载保存在 `./data` 目录，容器重建不丢失。

## 支持的发行版

本项目仅支持 **deb 系** 与 **rhel 系** 发行版（包管理器为 `apt` / `dnf` / `yum`）。**不支持 Alpine、不支持 openSUSE。**

**deb 系（Debian / Ubuntu 及衍生，如 Linux Mint）**

- ✅ Debian 11 / 12 / 13
- ✅ Ubuntu 20.04 / 22.04 / 24.04 / 26.04 LTS（及衍生 deb 系）
- ⚠️ Debian ≤ 10、Ubuntu ≤ 18.04：已 EOL，仍可用但不推荐

**rhel 系（CentOS / RHEL / AlmaLinux / Rocky / Oracle Linux / Amazon Linux / Fedora）**

- ✅ CentOS Stream 9 / 10
- ✅ RHEL 8 / 9 / 10
- ✅ AlmaLinux 8 / 9 / 10
- ✅ Rocky Linux 8 / 9 / 10
- ✅ Oracle Linux 8 / 9 / 10
- ✅ Amazon Linux 2023
- ✅ Fedora
- ⚠️ CentOS 6 / 7 / 8、CentOS Stream 8、RHEL 7：已 EOL，仍可用但不推荐

**说明**

- 内核版本 < 4.9 时，安装脚本会**自动隐藏 BBR 选项**（TCP BBR 需内核 ≥ 4.9）。
- 未在上述清单中的发行版：若已预装 Docker Engine + Compose 插件，可直接运行安装脚本；否则需参考 [Docker 官方文档](https://docs.docker.com/engine/install/) 手动安装 Docker 后重跑。

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
├── upgrade.sh              # 数据安全升级脚本（备份 → 升级 → 健康检查 → 失败回滚）
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

## 升级

从旧版本升级到新版本（数据不丢失）：

```bash
./upgrade.sh
```

脚本会依次完成以下操作：

- 前置检查（git 仓库 / Docker / Compose 可用性）。
- 停止容器并全量备份业务数据与本地配置到 `backups/upgrade-backup-<时间戳>.tar.gz`（先停机再备份，保证 MySQL 数据文件一致性；备份失败即中止升级）。
- 本地未提交改动自动 stash，升级成功后尝试恢复。
- `git pull --ff-only` 拉取新版本（拒绝分叉历史，绝不 force 覆盖本地提交）。
- `docker compose up -d --build` 重建镜像与容器。
- 健康检查（3311 / 3312 / 80 端口 + MySQL 连通），任一环节失败自动回滚到旧版本并重建容器。
- 清理 Smarty 编译缓存，面板模板更新自动生效。

常用参数：

```bash
./upgrade.sh --yes               # 非交互：自动 stash 本地改动并升级
./upgrade.sh --no-backup         # 跳过升级前备份（不推荐）
./upgrade.sh --no-pull           # 跳过 git pull，仅备份 + 重建（本地改码后使用）
./upgrade.sh --skip-health-check # 跳过升级后健康检查（不推荐）
```

### 升级数据安全说明

- 站点文件（`./data/homeftp/`）、MySQL 数据（`./data/mysql/`）、面板配置（`./data/kangle/`）、证书数据（`./data/acme/`）均为 bind 挂载，升级全程不删除、不移动；回滚也不影响数据。
- `.env`、`node.cfg.php`、`docker-compose.override.yml` 均在 `.gitignore` 中，`git pull` 不会触碰；`upgrade.sh` 绝不重写 `.env`，管理员与数据库密码保持不变。
- **日常升级请勿重跑 `install.sh`**——它会重新生成 `.env` 与随机密码，导致面板和数据库密码全部失效。升级请使用 `upgrade.sh`。
- MySQL 跨大版本升级（如 8.0 → 8.4）涉及数据目录格式变更，需先 `mysqldump` 全量导出、再以新版本镜像初始化导入，不能仅重建容器。
- 手动回滚：`git checkout <旧提交>` 后重跑 `docker compose up -d --build` 即可；升级失败时脚本会自动完成上述动作。

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
