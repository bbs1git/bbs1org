# BBS1Org

极其轻量级的纯原生 PHP 论坛系统。BBS1Org 使用 PHP + SQLite 构建，不依赖框架、不依赖构建工具，核心代码仅 60 多 KB，小巧、高效、易部署，适合个人社区、小团队论坛、内容讨论站和二次开发。

## 特性

- 首页、版块页、个人页、主题页统一论坛布局
- 纯原生 PHP + SQLite，核心代码仅 60 多 KB
- 无框架、无构建流程、无复杂依赖
- 主题列表支持新评论、新帖子排序
- 主题、回帖、收藏、个人主页、个人资料
- DiceBear 头像选择器，支持多风格头像网格
- AJAX 回帖，回复体验更顺滑
- 后台独立入口，支持用户、用户组、版块、主题、回帖管理
- 用户组支持管理员、禁止访问、禁止发言
- 站点设置支持网站名、SEO 信息、页头页脚 HTML、关闭站点、注册开关、保留用户名、默认用户组
- 版块、用户组、站点统计、站点设置缓存，减少数据库查询
- SQLite WAL + 全字段命中索引，列表与主题页排序零临时排序
- Docker 镜像内置 OPcache + JIT，消除每次请求的 PHP 编译开销
- 响应式界面，PC 与移动端均可用

## 预览

首页

![首页](docs/screenshots/home.png)

版块

![版块](docs/screenshots/forum.png)

主题查看

![主题查看](docs/screenshots/topic.png)

## 性能评估

BBS1Org 把热点数据(版块、用户组、统计、站点设置)缓存为 PHP 文件，数据库只做真正必要的查询，且所有列表/主题页排序都命中索引。以下为实测数据(单核，PHP 8 + SQLite WAL)：

| 环节 | 实测耗时 | 吞吐(单核) |
|---|---|---|
| 首页列表查询(30 主题，走索引) | 0.032 ms | ~31000 q/s |
| 主题页查询(1 主题 + 50 回帖) | 0.024 ms | ~41000 q/s |
| 业务逻辑 + HTML 渲染(OPcache 热态) | 0.043 ms | ~23000 req/s |

数据库与渲染逻辑几乎零成本，单请求的主要开销是 PHP 编译——这正是内置 OPcache 要消除的部分。

**容量结论(以 4 核 PHP-FPM 为参考)：**

- 开启 OPcache 后单请求约 0.35 ms CPU，单机约 **10000 req/s**，对应**日 PV 千万级**
- 读取几乎无上限：WAL 模式下读不阻塞，全部命中索引
- 真正的天花板是 SQLite 单写者(写操作全站串行)，约 **500–1000 写/s**，对绝大多数社区的发帖/回帖量绰绰有余
- 数据量到百万级主题、千万级回帖仍可平稳运行；写并发极高时可考虑迁移 PostgreSQL/MySQL

Docker 镜像已默认开启 OPcache + JIT，无需额外配置。自建环境建议在 `php.ini` 中开启 `opcache.enable=1`。

## 环境

- PHP 8.1+
- PDO SQLite 扩展
- Web 服务器支持 PHP

## 快速开始（推荐，小白首选）

只要装了 [Docker](https://docs.docker.com/get-docker/)，复制下面整段命令粘贴到终端回车，即可一键部署（自带 Nginx、PHP、OPcache，无需单独安装环境）：

```bash
git clone https://github.com/bbs1org/bbs1org.git
cd bbs1org
docker compose up -d --build
```

等待构建完成后，按顺序打开下面两个地址即可：

1. 打开 `http://127.0.0.1:8080/install.php`，确认安装表单后点击“开始安装”
2. 安装完成后会直接显示管理员用户名和随机密码，先保存密码，再打开 `http://127.0.0.1:8080/admin.php` 进入后台

常用命令：

```bash
docker compose logs -f     # 查看运行日志
docker compose down        # 停止
docker compose up -d        # 再次启动
```

数据库保存在 Docker volume `bbs1org-data`，升级或重建容器都不会丢数据。缓存保存在容器内 `cache/`，可随时删除并自动重建。Nginx 已默认禁止访问 `/data/`、`/cache/`、隐藏文件和非入口文件。

## 自建 Nginx/PHP 安装

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
mkdir -p data cache
chown -R www-data:www-data data cache
```

1. 安装 PHP、PHP-FPM、PDO SQLite 扩展
2. 将 Nginx 站点根目录指向 `/var/www/bbs1org`
3. 按下方 Nginx 示例禁止访问 `/data/` 与 `/cache/`
4. 访问 `install.php` 完成初始化
5. 安装完成后保存管理员用户名和随机密码
6. 访问 `admin.php` 配置站点

本地预览：

```bash
php -S 127.0.0.1:8000
```

## 安全

`data/` 目录只保存 SQLite 数据库文件，必须禁止公网访问。`cache/` 目录保存可随时删除的 PHP 缓存文件，也必须禁止公网访问。生产环境不要把站点根目录直接暴露为整个项目目录，建议只允许访问 PHP、CSS 等入口文件，并拦截 `/data/` 与 `/cache/`。

Nginx 示例：

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/bbs1org;
    index index.php;

    location ^~ /data/ {
        deny all;
    }

    location ^~ /cache/ {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Docker 构成

「快速开始」用到的 Docker 部署由项目内置文件提供，开箱即用，默认监听宿主机 `8080` 端口：

- `Dockerfile` —— PHP 8.3-FPM 镜像，内置 `pdo_sqlite` 与 OPcache + JIT
- `docker/opcache.ini` —— OPcache 调优配置
- `docker/nginx.conf` —— Nginx 站点配置，已禁止访问 `/data/`、`/cache/`、隐藏文件和非入口文件
- `docker-compose.yml` —— 编排 app + nginx，数据持久化到 volume `bbs1org-data`

修改端口、域名等可直接编辑 `docker-compose.yml` 与 `docker/nginx.conf`。

## 目录

```text
index.php           前台入口
admin.php           后台入口
function.php        公共函数与页面逻辑
install.php         全新安装
update.php          结构更新与缓存刷新
style.css           全站样式
Dockerfile          PHP-FPM 镜像（含 OPcache）
docker-compose.yml  Compose 部署
docker/             Nginx 与 OPcache 配置
data/               SQLite 数据库文件
cache/              可删除的运行缓存
```

## 后台设置

后台默认进入“站点设置”，可配置网站名、关键字、网站介绍、页头 HTML、页脚 HTML、关闭站点、允许注册、保留用户名、新用户默认用户组。

## 数据

数据库保存在 `data/forum.sqlite`，缓存保存在 `cache/` 并可随时删除重建。迁移或备份时只需处理 `data/forum.sqlite`。
