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
touch plugins.css plugins.js plugins-admin.css plugins-admin.js
chown -R www-data:www-data data cache avatars upload plugins
chown www-data:www-data plugins.css plugins.js plugins-admin.css plugins-admin.js
```

1. 将站点根目录指向项目目录
2. 确保 `data/`、`cache/`、`avatars/`、`upload/`、`plugins/` 和四个 `plugins*.css/js` 生成文件可写
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
plugins.css         自动生成的前台插件样式
plugins.js          自动生成的前台插件脚本
plugins-admin.css   自动生成的后台插件样式
plugins-admin.js    自动生成的后台插件脚本
```

## 说明

`data/`、`cache/` 和 `plugins/` 都属于运行目录，生产环境应避免直接暴露给公网；Docker 部署会持久化 `data/`、`avatars/`、`upload/` 和 `plugins/`。`avatars/` 用于本地头像镜像，可通过静态缓存加速访问。`upload/` 用于帖子附件，附件数量和单个大小可在后台站点设置中调整；文件按 `substr(文件hash,0,2)` 自动分目录保存，同一文件全站只存一份；图片保存为 `文件hash.原后缀` 并以图片方式插入帖子，其他附件保存为 `文件hash.attach` 并通过 PHP 下载为原文件名。

## 插件

插件放在 `plugins/插件ID/plugin.php`，后台“插件”页会自动扫描。新插件默认停用，启用后才会执行。插件 ID 建议使用小写字母、数字、下划线或短横线。

最小示例：

```php
<?php

function hello_css(): string
{
    return '.hello-message{color:var(--brand);font-weight:600}';
}

function hello_js(): string
{
    return 'document.querySelectorAll(".hello-message").forEach(el=>el.dataset.ready="1");';
}

function hello_footer($html, array $ctx): string
{
    return (string)$html . '<span class="hello-message">Hello</span>';
}

return [
    'id' => 'hello',
    'name' => 'Hello',
    'version' => '1.0.0',
    'description' => '给页脚追加内容',
    'author' => 'your-name',
    'assets' => [
        'css' => 'hello_css',
        'js' => 'hello_js',
    ],
    'hooks' => [
        'page.footer' => 'hello_footer',
    ],
];
```

### 插件 CSS 和 JavaScript

插件的固定 CSS、JavaScript 必须通过 `assets` 声明，不要通过 `page.head`、`page.footer` 输出 `<style>` 或内联 `<script>`：

```php
'assets' => [
    'css' => 'hello_css',
    'js' => 'hello_js',
    'scope' => 'all',
],
```

- `css`、`js` 都是可选项，值是插件内资源函数名；资源函数不接收参数并返回字符串。
- CSS 函数只返回 CSS 源码，不包含 `<style>` 标签。
- JavaScript 函数只返回 JavaScript 源码，不包含 `<script>` 标签。
- `scope` 可选：`all`（默认）、`frontend`（仅前台）、`admin`（仅后台）。皮肤类插件应使用 `frontend`，只服务后台管理页的插件资源可使用 `admin`。
- 资源不得依赖当前用户、当前页面、CSRF 或每次请求才确定的数据。动态值应输出到插件 HTML 的 `data-*` 属性，再由合并后的 JavaScript 读取。
- 按配置条件加载的第三方外部资源，例如 Turnstile 的 `<script src="...">`，可以继续通过 `page.head` 输出；插件自己的固定代码仍应放入 `assets`。
- 只有已启用插件的资源会进入合并文件。不要直接编辑根目录的四个 `plugins*.css/js` 文件，资源重建时会覆盖它们。

启用、停用、卸载、市场安装或更新插件后，系统会自动重新生成资源。直接上传或修改本地插件后，进入后台“插件”页面会检测文件路径和修改时间，仅在发生变化时重新生成。后台“插件”页面也提供“重建资源”按钮用于手动强制生成。

旧插件仍可继续使用原有 Hook，但新写或修改插件必须采用上述资源机制。

### Hook 和页面

`hooks` 用来挂载核心位置，函数签名通常是 `function xxx($value, array $ctx)`，返回新值；返回 `null` 表示不修改。常用 Hook 有 `page.footer`、`page.head`、`sidebar.stack`、`topic.before_save`、`topic.title_suffix`、`topic.toolbar_actions`、`topic.after_render`。

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
