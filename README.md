# BBS1org

极其轻量级的纯原生 PHP 论坛系统。BBS1org 使用 PHP + SQLite 构建，不依赖框架、不依赖构建工具，核心入口仅约 100KB，单个文件实现论坛全部功能，小巧、高效、易部署，适合个人社区、小团队论坛、内容讨论站和二次开发。

## 特性

- 首页、版块页、个人页、主题页统一论坛布局
- 纯原生 PHP + SQLite，核心入口仅约 100KB
- 单个文件实现论坛全部功能
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

![首页](docs/screenshots/home.png)

## 核心亮点

- 约 100KB 的单入口实现，部署极简
- 论坛核心功能集中在一个文件里，便于理解、修改、迁移
- PHP + SQLite 原生组合，开箱即用
- 热点数据做文件缓存，列表与详情页查询更轻
- 后台、安装、更新、权限、限流、Markdown、代码块全部内置
- Docker、自建 Nginx/PHP、本地预览三种方式都能直接跑

Docker 镜像已默认开启 OPcache + JIT，无需额外配置。自建环境建议在 `php.ini` 中开启 `opcache.enable=1`。

## 环境

- PHP 8.1+
- PDO SQLite 扩展
- Web 服务器支持 PHP

## Docker 安装（Debian 示例）

Debian 建议按官方安装页操作：<https://docs.docker.com/engine/install/debian/>

开发环境也可以直接用官方一键脚本：

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

安装后执行 `docker --version` 和 `sudo docker run hello-world` 确认可用。

## 一键部署（Docker，推荐）

只要装了 [Docker](https://docs.docker.com/get-docker/)，复制下面整段命令粘贴到终端回车，即可一键部署（自带 Nginx、PHP、OPcache，无需单独安装环境）：

```bash
cd /opt
git clone https://github.com/BBS1org/BBS1org.git
cd BBS1org
docker compose up -d --build
```

等待构建完成后，按顺序打开下面两个地址即可：

1. 打开 `http://127.0.0.1/install.php`，确认安装表单后点击“开始安装”
2. 安装完成后会直接显示管理员用户名和密码，先保存密码，再打开 `http://127.0.0.1/index.php?a=admin` 进入后台

## 已有 Docker 时

如果你已经有 Docker，只需要启动环境，再用 `install.php` 完成站点初始化：

```bash
docker compose up -d --build
```

然后打开 `http://127.0.0.1/install.php`，按页面提示完成安装。

常用命令：

```bash
docker compose logs -f     # 查看运行日志
docker compose down        # 停止
docker compose up -d        # 再次启动
```

数据库保存在 Docker volume `BBS1org-data`，升级或重建容器都不会丢数据。缓存保存在容器内 `cache/`，可随时删除并自动重建。Nginx 已默认禁止访问 `/data/`、`/cache/`、隐藏文件和非入口文件。

## 自建 Nginx/PHP 安装

```bash
git clone https://github.com/BBS1org/BBS1org.git /var/www/BBS1org
cd /var/www/BBS1org
mkdir -p data cache
chown -R www-data:www-data data cache
```

1. 安装 PHP、PHP-FPM、PDO SQLite 扩展
2. 将 Nginx 站点根目录指向 `/var/www/BBS1org`
3. 按下方 Nginx 示例禁止访问 `/data/` 与 `/cache/`
4. 访问 `install.php` 完成初始化
5. 安装完成后保存管理员用户名和随机密码
6. 访问 `index.php?a=admin` 配置站点

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
    root /var/www/BBS1org;
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
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Docker 构成

「快速开始」用到的 Docker 部署由项目内置文件提供，开箱即用，默认监听宿主机 `80` 端口：

- `Dockerfile` —— PHP 8.3-FPM 镜像，内置 `pdo_sqlite` 与 OPcache + JIT
- `docker/opcache.ini` —— OPcache 调优配置
- `docker/nginx.conf` —— Nginx 站点配置，已禁止访问 `/data/`、`/cache/`、隐藏文件和非入口文件
- `docker-compose.yml` —— 编排 php + nginx，数据持久化到 volume `BBS1org-data`

修改端口、域名等可直接编辑 `docker-compose.yml` 与 `docker/nginx.conf`。

## 目录

```text
index.php           前台入口
install.php         全新安装
update.php          结构更新与缓存刷新
index.css           全站样式
index.js            全站脚本
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
