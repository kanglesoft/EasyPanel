# EasyPanel Heavy

基于 EasyPanel Heavy 的网站管理面板 Docker 化部署方案。项目将 Kangle Web 服务器、EasyPanel 管理后台、MySQL 数据库以及多个 PHP-FPM 版本封装为 Docker Compose 编排，支持一键安装、自动初始化与灵活扩展。

## 主要特性

- 容器化部署：Kangle、EasyPanel、MySQL、phpMyAdmin 全部通过 Docker Compose 管理。
- 多 PHP 版本：默认内置 PHP 7.4，可通过 `add_php.sh` 快速接入 PHP 8.x 独立容器。
- 自动安装 Docker：在支持的 Linux 发行版上，安装脚本会自动检测并安装 Docker Engine 与 Compose 插件。
- SSL 支持：集成 acme.sh，可在容器内申请与自动续期 Let's Encrypt 证书。
- 数据持久化：所有业务数据通过 bind 挂载保存在 `./data` 目录，容器重建不丢失。

### v3 新增能力

- **EOL 老系统自动换源**（`mirror.sh`）：检测到系统已 EOL 时自动切换至可用的归档源（实测 2026-09 各站可达性），避免 `yum` / `apt` 因官方源下线而失败；非 EOL 系统也可用 `--mirror=` 主动选择国内镜像站（阿里 / 腾讯 / 华为 / 清华 / 中科大 / 字节）。
- **DNS 修改与锁定**（`dns.sh`）：默认**不改**系统 DNS；仅在显式指定 `--dns-set` 时修改，且"改必加锁"（`chattr +i` 或守护进程看护），防止被 `dhclient` / `NetworkManager` 还原（防 DNS 污染）。
- **CDN-only 安装模式**（`--mode=cdn`）：只装面板与 CDN 能力（Kangle 反向代理 + 缓存 + easypanel 后台 + CDN 主从同步），不装 MySQL / php-fpm / phpMyAdmin。面板自身数据层是 sqlite，故不装数据库不影响面板与 CDN 功能，适合纯 CDN 用户。
- **Docker registry 镜像加速**：多站三步探测（`/v2/` → 取该站自身 token → 拉真实 manifest）后择优写入 `daemon.json`，避免只探 `/v2/` 的"假可达"站点。

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

### 支持边界（EOL / 换源 / 装 Docker 三栏对照）

> 对外承诺以此为最终标准，详见 `v3更新方案.md` §2.8。

| 系统 | 换源 | 装 Docker CE | 结论 |
|---|---|---|---|
| CentOS 7 / 8、CentOS Stream 8 | ✅ 自动 | ✅（centos/7、centos/8 实测 200） | **完整支持** |
| Debian 9 / 10、Ubuntu 16.04 / 18.04 LTS | ✅ 自动（走 archive / old-releases） | ✅（实测 200） | **完整支持** |
| Oracle Linux 6 / 7 / 8 | ⬜ 官方源仍 200，无需换 | ✅ | **完整支持** |
| AlmaLinux / Rocky 8 / 9 / 10、Fedora | ⬜ 未 EOL | ✅ | **完整支持** |
| RHEL 8 / 9 / 10 | ⬜ 订阅源 | ✅ | **完整支持** |
| 阿里云 Linux 3（Alibaba Cloud Linux） | ⬜ 未 EOL | ✅（RHEL 8 兼容，走 centos/8 仓库，v3 已修误判） | **完整支持** |
| Anolis OS 8 | ⬜ 未 EOL | ✅（RHEL 8 兼容，走 centos/8 仓库） | **完整支持** |
| RHEL 6 / 7 | ⚠️ 映射 CentOS vault + `gpgcheck=0` | ✅（7 走 centos/7） | **尽力而为，明确告知风险** |
| Amazon Linux 2023 | ⬜ 未 EOL | 🔴 需 releasever 修正（v3 已修） | **支持** |
| **CentOS 6、Debian ≤ 8、Ubuntu < 16.04** | ✅ 自动（archive） | ❌ Docker CE 官方无对应仓库 | **UNSUPPORTED：命中即终止**（除非 `--allow-unsupported` 或本机已有 Docker） |

> 说明：少数系统（CentOS 6 / Debian ≤ 8 / Ubuntu < 16.04）Docker CE 官方已无对应仓库，装上源也装不了 Docker，安装脚本会**明确打印原因后终止**，不会卡在半路让用户猜。确需继续可用 `--allow-unsupported`，风险自负。

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
- 构建并启动服务：`--mode=full` 含 kangle + mysql + phpMyAdmin；`--mode=cdn` 仅含面板与 CDN（无 MySQL / phpMyAdmin）。
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

# ── v3 新增 ──
./install.sh --mode=cdn             # CDN-only：只装面板 + CDN，不装网站环境（MySQL / php-fpm / phpMyAdmin）
./install.sh --mirror=alibaba       # 镜像站：auto|keep|official|alibaba|tencent|huawei|tuna|ustc|volces
./install.sh --dns-set=alidns       # 修改并锁定 DNS：alidns|dnspod|cloudflare|google|114|mixed|custom
./install.sh --dns-set=custom --dns-servers=1.1.1.1,9.9.9.9
./install.sh --no-registry-mirror   # 不配置 Docker registry 镜像加速
./install.sh --allow-unsupported    # 在 UNSUPPORTED 系统上强制继续（风险自负）
```

> **DNS 默认不修改**：v3 引入的 `--dns-set` 需显式指定才生效，不传则保持系统 DNS 不变（防 DNS 污染是可选增强，而非强制行为）。一旦指定，会自动加锁防止被还原。
>
> **换源默认自动**：`--mirror` 缺省为 `auto` —— 仅当检测到系统已 EOL 才换源，非 EOL 系统保持官方源（国家局域网用户可显式 `--mirror=alibaba` 提速）。

## CDN-only 模式（纯 CDN 部署）

部分用户只用 CDN 做反向代理 / 缓存，不需要 PHP、MySQL 等网站环境。`--mode=cdn` 即可只装面板与 CDN 能力。

**保留（面板与 CDN 能力全部在线）**

- Kangle 主服务（80 / 443 反代 + 缓存 —— CDN 的本体就是这台反向代理）
- easypanel 后台（3312）、kangle 管理（3311）、hostcdn 管理界面、CDN 主从同步、CDN 节点管理
- 面板运行所需的 php74-cgi（**这是面板自身的运行时，不是站点 PHP**，移除会导致面板 500）
- pure-ftpd + webftp、acme.sh 证书、memcached、crond

**不安装（网站环境）**

- MySQL 容器、额外 phpX.X-fpm 容器、phpMyAdmin（3313）

**可行性依据**：easypanel 自身数据层是 sqlite（`webftp/config.php` 的 `node_db='sqlite'`），FTP 认证同样查 sqlite；`framework/lib/mysqlDbProduct.lib.php` 的 `connect()` 有 `try/catch`，无库时降级而非致命。故移除 MySQL 不影响面板与 CDN 功能。

```bash
./install.sh --mode=cdn          # 安装时选择
# 之后所有与管理相关的命令自动按模式选择编排文件（upgrade / uninstall / add_php 均已感知）
```

> 模式以项目根 `.install_mode` 文件为准（同时写入 `.env` 的 `KANGLE_MODE`）。`upgrade.sh` / `uninstall.sh` / `add_php.sh` 均读取该标记：CDN 模式下 `add_php.sh` 会拒绝接入额外 PHP（该操作属于网站环境，放行后会"配置了却不生效"）。

## 系统源 / DNS / 镜像加速（v3 能力）

三件能力都由独立脚本实现，安装脚本在装 Docker **之前**自动调用，也可单独运行。

### 1. EOL 老系统换源（`lib/mirror.sh`）

```bash
./lib/mirror.sh --check                          # 仅检测并报告，不改任何文件
./lib/mirror.sh --mirror=alibaba                 # 强制切到指定镜像站
./lib/mirror.sh --mirror=official                # 强制回官方归档
./lib/mirror.sh --restore                        # 还原到修改前的源
```

- 自动模式（`install.sh` 默认）：仅当检测到系统已 EOL 才换源，非 EOL 系统保持官方源。
- 各镜像站归档目录覆盖不同（清华无 debian-archive / epel-archive，字节无 epel-archive / docker-ce 等），脚本采用"候选链 + 实时探测 + 自动回退"，不假设某站一定有某个目录。

### 2. DNS 修改与锁定（`lib/dns.sh`）

```bash
./lib/dns.sh --status                             # 查看当前 DNS 与锁定状态
./lib/dns.sh --set=alidns                         # 改用阿里 DNS 并加锁（防还原）
./lib/dns.sh --set=custom --servers=1.1.1.1,9.9.9.9
./lib/dns.sh --unlock                             # 解除锁定（恢复系统原 DNS 管理）
./lib/dns.sh --restore                            # 还原到修改前的 DNS 配置
```

- **默认不改 DNS**：不传 `--set` 时系统 DNS 完全不变。
- **改必加锁**：指定 `--set` 后会用 `chattr +i` 锁定 `/etc/resolv.conf`、`systemd-resolved` 的 DNS 配置；若宿主机文件系统不支持 `chattr`（如 overlayfs / OpenVZ），自动降级为守护进程定期看护。解锁用 `--unlock`。

### 3. Docker registry 镜像加速

由 `mirror.sh` 顺带完成（写入 `/etc/docker/daemon.json` 的 `registry-mirrors`）。采用三步探测筛选可用加速站：取该站自身的认证入口 → 换它自己的 token → 拉真实 manifest（要求 200 且响应体非空），避免只探 `/v2/` 的"假可达"站点（如 `dockerhub.azk8s.cn` 的 `/v2/` 返回 200 但拉不了镜像）。可用 `--no-registry-mirror` 关闭。

## 解锁与还原命令速查

| 场景 | 命令 |
|---|---|
| 查看 DNS 锁定状态 | `./lib/dns.sh --status` |
| 解除 DNS 锁定 | `./lib/dns.sh --unlock` |
| DNS 还原到修改前 | `./lib/dns.sh --restore` |
| 系统源还原到修改前 | `./lib/mirror.sh --restore` |
| 删除 registry 加速 | `./lib/dns.sh --restore`（其一并移除 `registry-mirrors`）或手改 `/etc/docker/daemon.json` |

> 注意：`uninstall.sh` **不会**还原 DNS 与系统源（这是宿主机级配置，且卸载时已失活容器），如曾修改 DNS 请按需手动 `--unlock` / `--restore`。

## 目录结构

```
.
├── install.sh              # 一键安装脚本（含换源 / DNS / registry 加速 / 模式选择）
├── upgrade.sh              # 数据安全升级脚本（备份 → 升级 → 健康检查 → 失败回滚）
├── uninstall.sh            # 卸载脚本
├── docker-compose.yml      # 主编排文件（full：含 MySQL / phpMyAdmin）
├── docker-compose.cdn.yml  # CDN-only 主编排文件（不含 mysql / 3313）
├── docker-compose.override.yml   # add_php.sh 生成的 PHP 扩展编排
├── lib/
│   ├── common.sh           # 共享函数库：daemon.json 安全合并 / docker 重启单次化 / 模式识别
│   ├── mirror.sh           # EOL 换源 + Docker registry 镜像加速
│   └── dns.sh              # DNS 修改与锁定 / 解锁
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

脚本会按以下安全阶段依次完成（任何失败路径都会自动恢复，服务不会停在半路）：

- 前置检查（git 仓库状态 / Docker / Compose / `.env` 存在性），拒绝 detached HEAD 与进行中的 merge / rebase。
- **阶段一（不停机）**：本地未提交改动自动 stash；显式 `git fetch` + `git merge --ff-only` 拉取新版本（不依赖上游跟踪配置，兼容旧部署）。拉取失败直接退出，容器从未停止、服务不受影响。若 `upgrade.sh` 自身被更新，会自动切换到新脚本重新执行。
- **阶段二（停机窗口）**：停止容器后离线全量备份业务数据与本地配置到 `backups/upgrade-backup-<时间戳>.tar.gz`（先停机再备份，保证 MySQL 数据文件一致性；**备份失败即自动恢复到升级前状态**）。
- **阶段三（重建与验证）**：按安装模式加载对应编排文件（`docker compose up -d --build`）重建镜像与容器；健康检查（3311 / 3312 / 80 端口，**full 模式追加 MySQL 连通**；cdn 模式跳过 MySQL 检查）。
- **阶段四（收尾）**：恢复 stash、清理 Smarty 编译缓存、输出摘要。
- 任一环节失败自动回滚到升级前提交并重建容器，数据卷自始至终未被改动。

常用参数：

```bash
./upgrade.sh --yes               # 非交互：自动 stash 本地改动并升级
./upgrade.sh --no-backup         # 跳过升级前备份（不推荐）
./upgrade.sh --no-pull           # 跳过 git pull，仅备份 + 重建（本地改码后使用）
./upgrade.sh --skip-health-check # 跳过升级后健康检查（不推荐）
```

### 升级数据安全说明

- 站点文件（`./data/homeftp/`）、MySQL 数据（`./data/mysql/`）、面板配置（`./data/kangle/`）、证书数据（`./data/acme/`）均为 bind 挂载，升级全程不删除、不移动；回滚也不影响数据。
- `.env`、`node.cfg.php`、`docker-compose.override.yml` 均在 `.gitignore` 中，拉取代码不会触碰；`upgrade.sh` 绝不重写 `.env`，管理员与数据库密码保持不变。
- **兼容旧部署**：不依赖 `.env.example` / `docker-compose.override.yml` / 上游跟踪配置是否存在，可从任意历史版本直接升级；旧版 `.env` 缺少新变量时只提示不判失败。若仓库中尚无 `upgrade.sh`（很旧的部署），先手动 `git pull` 一次即可使用。
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
