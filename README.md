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
mkdir -p data cache avatars upload plugins
chown -R www-data:www-data data cache avatars upload plugins
```

1. 将站点根目录指向项目目录
2. 确保 `data/`、`cache/`、`avatars/`、`upload/` 和 `plugins/` 可写
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
upload/             附件上传目录
plugins/            插件目录
```

## 说明

`data/`、`cache/` 和 `plugins/` 都属于运行目录，生产环境应避免直接暴露给公网；Docker 部署会持久化 `data/`、`avatars/`、`upload/` 和 `plugins/`。`avatars/` 用于本地头像镜像，可通过静态缓存加速访问。`upload/` 用于帖子附件，附件数量和单个大小可在后台站点设置中调整；文件按 `substr(文件hash,0,2)` 自动分目录保存，同一文件全站只存一份；图片保存为 `文件hash.原后缀` 并以图片方式插入帖子，其他附件保存为 `文件hash.attach` 并通过 PHP 下载为原文件名。

## 插件

插件放在 `plugins/插件ID/plugin.php`，后台“插件”页会自动扫描。新插件默认停用，启用后才会执行。插件 ID 建议使用小写字母、数字、下划线或短横线。

最小示例：

```php
<?php

function hello_footer($html, array $ctx): string
{
    return (string)$html . '<span> Hello</span>';
}

return [
    'id' => 'hello',
    'name' => 'Hello',
    'version' => '1.0.0',
    'description' => '给页脚追加内容',
    'author' => 'your-name',
    'hooks' => [
        'page.footer' => 'hello_footer',
    ],
];
```

`hooks` 用来挂载核心位置，函数签名通常是 `function xxx($value, array $ctx)`，返回新值；返回 `null` 表示不修改。常用 Hook 有 `page.footer`、`page.head`、`sidebar.stack`、`topic.before_save`、`topic.title_suffix`、`topic.toolbar_actions`、`topic.page_replies_rendered`、`topic.after_render`。

如果需要前台页面，可在 manifest 中添加：

```php
'routes' => [
    'hello' => 'hello_page',
],
```

然后通过 `route_url('hello')` 访问。

如果需要后台页面，可添加：

```php
'admin_tabs' => [
    'hello' => 'hello_admin_page',
],
```

插件可以调用核心函数，例如 `q()`、`one()`、`uid()`、`me()`、`route_url()`、`page()`、`form_token()`、`plugin_config()`、`plugin_save_config()`。

插件拥有和站点代码相同的权限，可以读写数据库、文件和请求数据，只建议安装可信插件。插件自己的数据表建议使用 `plugin_插件ID_` 前缀。
