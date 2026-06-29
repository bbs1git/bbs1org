# bbs1org

一个PHP文件实现的极其轻量级论坛系统。bbs1org 使用 PHP + SQLite 构建，不依赖框架、不依赖构建工具，小巧、高效、易部署，适合个人社区、小团队论坛、内容讨论站和二次开发。

## 特性

- 首页、版块页、个人页、主题页统一论坛布局
- 纯原生 PHP + SQLite，单个文件实现论坛全部功能，大小仅 100KB
- 无框架、无构建流程、无复杂依赖
- 主题列表支持新评论、新帖子排序
- 主题、回帖、收藏、个人主页、个人资料
- 支持伪静态访问，兼容 `/forum/1`、`/thread-1.html`、`/user/1/topics` 等路径
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

## 环境

- PHP 8.1+ 需安装 SQLite 扩展

## Docker 安装（Debian 示例）

Debian 建议按官方安装页操作：https://docs.docker.com/engine/install/debian/
开发环境也可以直接用官方一键脚本：

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

## 一键部署（Docker，推荐）

只要装了 [Docker](https://docs.docker.com/get-docker/)，复制下面整段命令粘贴到终端回车，即可一键部署（自带 Nginx、PHP、OPcache，无需单独安装环境）：

```bash
cd /opt
rm -rf bbs1org
git clone https://github.com/bbs1org/bbs1org.git
cd bbs1org
docker compose down -v
docker compose up -d --build
```

等待构建完成后，浏览器访问网站的 `install.php`，开始安装并进入后台设置即可。

## 自建 Nginx/PHP 安装

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
mkdir -p data cache
chown -R www-data:www-data data cache
```

1. 将 Nginx 站点根目录指向 `/var/www/bbs1org`
2. 按下方 Nginx 示例禁止访问 `/data/` 与 `/cache/`
3. 访问 `install.php` 开始安装并进入后台设置即可

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
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

伪静态路由默认启用，可在后台「设置」中关闭。启用后会由 `index.php` 自动转发到原有参数路由。常用格式：

```text
/forum/1
/forum-1.html
/topic/1
/thread-1.html
/thread-1-2.html
/reply/10
/user/1
/user/1/topics
/login
/register
/admin/topics
```

Apache 环境可直接使用项目根目录内置的 `.htaccess`，确保站点启用了 `mod_rewrite` 且允许读取 `.htaccess`：

```apache
AllowOverride All
```

## 目录

```text
index.php           PHP文件
index.css           CSS样式
index.js            JS脚本
.htaccess           Apache 伪静态转发规则

install.php         全新安装脚本
Dockerfile          PHP-FPM 镜像（含 OPcache）
docker-compose.yml  Compose 部署
docker/             Nginx 与 OPcache 配置
data/               SQLite 数据库文件与数据库配置
cache/              可删除的运行缓存
```

## 数据迁移或备份

数据库安装时会随机生成文件名，并由 `data/db.php` 记录当前使用的 SQLite 文件。缓存保存在 `cache/` 并可随时删除重建。迁移或备份时请同时保留 `data/db.php` 和对应的 `data/*.sqlite` 数据库文件。
