# bbs1org

一个极简 PHP 论坛，基于 PHP + SQLite 实现，单文件入口，依赖少，适合个人站点、小型社区和轻量二次开发。

## 特点

- 单文件核心逻辑，结构直接
- PHP + SQLite，无框架、无构建流程
- 首页、版块页、主题页、个人页、后台管理
- 主题、回帖、收藏、用户资料、头像选择
- AJAX 回复，交互更顺
- 用户组、版块、站点设置、用户管理
- 支持站点关闭、注册开关、保留用户名、发帖间隔等基础控制
- 缓存版块、用户组、站点设置、统计信息，减少重复查询
- 响应式界面，PC 和移动端都可用

## 环境

- PHP 8.1+
- SQLite 扩展

## 演示
https://bbs1.org

## Docker 部署

```bash
cd /opt
git clone https://github.com/bbs1org/bbs1org.git
cd bbs1org
docker compose up -d
```

启动完成后，访问 `install.php` 完成安装。

## 手动部署

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
mkdir -p data cache
chown -R www-data:www-data data cache
```

1. 将站点根目录指向项目目录
2. 确保 `data/` 和 `cache/` 可写
3. 访问 `install.php` 完成安装

## 升级

更新代码后，使用管理员账号登录论坛，然后访问 `update.php` 同步数据库结构和索引。`update.php` 只给管理员执行；未登录访问会提示先登录。

手动部署：

1. 上传或拉取新代码
2. 登录管理员账号
3. 浏览器访问 `https://你的域名/update.php`
4. 点击“执行升级”

Docker 部署：

```bash
cd /opt/bbs1org
git pull
docker compose up -d
```

然后登录管理员账号，浏览器访问 `http://服务器地址/update.php` 或你的正式域名下的 `update.php`，点击“执行升级”。

## 目录

```text
index.php           论坛主程序
index.css           页面样式
index.js            前端脚本
install.php         安装脚本
update.php          数据更新脚本
docker-compose.yml  Compose 部署
docker/             Nginx 配置
data/               数据文件
cache/              运行缓存
avatars/            本地头像镜像
```

## 说明

`data/` 和 `cache/` 都属于运行目录，生产环境应避免直接暴露给公网。`avatars/` 用于本地头像镜像，可通过静态缓存加速访问。
