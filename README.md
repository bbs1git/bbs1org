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
- SQLite WAL 与关键索引，适合轻量高并发场景
- 响应式界面，PC 与移动端均可用

## 环境

- PHP 8.1+
- PDO SQLite 扩展
- Web 服务器支持 PHP

## 自建 Nginx/PHP 安装

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
mkdir -p data
chown -R www-data:www-data data
```

1. 安装 PHP、PHP-FPM、PDO SQLite 扩展
2. 将 Nginx 站点根目录指向 `/var/www/bbs1org`
3. 按下方 Nginx 示例禁止访问 `/data/`
4. 访问 `install.php` 完成初始化
5. 创建管理员账号后，将账号用户组设为“管理员”
6. 访问 `admin.php` 配置站点

本地预览：

```bash
php -S 127.0.0.1:8000
```

## 安全

`data/` 目录保存 SQLite 数据库、缓存和安装锁，必须禁止公网访问。生产环境不要把站点根目录直接暴露为整个项目目录，建议只允许访问 PHP、CSS 等入口文件，并拦截 `/data/`。

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

## Docker Compose

项目已内置可直接使用的 `Dockerfile`、`docker-compose.yml` 和 Nginx 配置，默认监听宿主机 `8080` 端口。

部署：

```bash
git clone https://github.com/bbs1org/bbs1org.git
cd bbs1org
docker compose up -d --build
```

访问：

```text
http://127.0.0.1:8080/install.php
```

初始化完成后访问：

```text
http://127.0.0.1:8080/
http://127.0.0.1:8080/admin.php
```

停止：

```bash
docker compose down
```

数据保存在 Docker volume `bbs1org-data`，升级容器不会丢数据。Nginx 配置已默认禁止访问 `/data/`、隐藏文件和非入口文件。

## 目录

```text
index.php           前台入口
admin.php           后台入口
function.php        公共函数与页面逻辑
install.php         全新安装
update.php          结构更新与缓存刷新
style.css           全站样式
Dockerfile          PHP-FPM 镜像
docker-compose.yml  Compose 部署
docker/             Nginx 配置
data/               SQLite 数据与缓存
```

## 后台设置

后台默认进入“站点设置”，可配置网站名、关键字、网站介绍、页头 HTML、页脚 HTML、关闭站点、允许注册、保留用户名、新用户默认用户组。

## 数据

运行数据保存在 `data/`，数据库和缓存文件不会提交到 Git。迁移或备份时请单独处理 `data/forum.sqlite`。
