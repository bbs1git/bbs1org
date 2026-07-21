# bbs1org

一个极简 PHP 论坛。同时支持 SQLite、MySQL 和 PostgreSQL数据库。单页面仅3～4个查询，支持高负载。核心代码文件一个，仅200多KB；原生无依赖，适合所有社区站点和AI二次开发。

## 特点

- 纯原生 PHP，单核心代码文件，仅200多KB，无依赖，核心逻辑集中，部署简单，适合AI二次开发
- 支持 SQLite、MySQL 和 PostgreSQL数据库，单页面仅3～4个查询数，高负载下数据库无压力
- 包含首页、版块、主题、回帖、收藏、个人主页和后台管理等完整论坛功能
- 支持用户组、版块权限、站点设置、注册控制、发帖限制和附件管理
- 插件机制支持 Hook、路由、前后台，在线安装更新，实现邀请、审核、签到、投票、皮肤等各种个性需求
- 使用缓存减少版块、用户组、站点设置和统计信息的重复查询
- 支持 AJAX 交互和响应式布局，兼顾 PC 与移动端使用体验

## 环境

- PHP 8.1+
- PDO SQLite、PDO MySQL 或 PDO PostgreSQL 扩展

## 演示

https://bbs1.org

## Docker 部署

服务器需先安装 Docker Engine 和 Docker Compose 插件，并确保 `80` 端口未被占用。

### 1. 下载并准备配置

```bash
cd /opt
git clone https://github.com/bbs1org/bbs1org.git
cd /opt/bbs1org/docker
cp .env.example .env
```

### 2. 选择数据库

启动前编辑 `/opt/bbs1org/docker/.env`：

| `COMPOSE_PROFILES` | 数据库 | 安装时填写 |
|---|---|---|
| `sqlite` | SQLite | 无需连接信息 |
| `mysql` | MySQL 8.4 | 主机 `mysql`，端口 `3306` |
| `pgsql` | PostgreSQL 18 | 主机 `postgres`，端口 `5432` |

MySQL/PostgreSQL 的数据库名、用户名和密码使用 `.env` 中的 `DB_NAME`、`DB_USER`、`DB_PASSWORD`，HTTP 端口使用 `HTTP_PORT`；启用 MySQL 或 PostgreSQL 时必须修改默认密码。

### 3. 完成安装

```bash
docker compose up -d
```

访问 `http://服务器地址/index.php?a=install`，按 `.env` 选择数据库并填写连接信息。

### 4. 常用命令

以下命令均在 `/opt/bbs1org/docker` 中执行：

```bash
cd /opt/bbs1org/docker
# 查看容器状态
docker compose ps
# 查看实时日志
docker compose logs -f
# 重启服务
docker compose restart
# 停止服务但保留数据卷
docker compose down
# 拉取最新镜像
docker compose pull
# 后台启动服务
docker compose up -d
```

更新代码后重启 PHP 以清除生产 OPcache：

```bash
cd /opt/bbs1org
git pull
cd docker
docker compose restart php
```

`docker compose down` 会保留数据；不要在未备份时使用 `docker compose down -v`。

## 手动部署

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
chown -R www-data:www-data .
```

1. 将站点根目录指向项目目录，并将 PHP 请求交给 PHP-FPM
2. 配置不存在文件回退到 `/index.php?$query_string`，禁止公网访问 `app/data/`、`app/cache/`、`app/plugins/`、点文件及 `app/upload/` 中的脚本文件；Nginx 可参考 `docker/nginx.conf`，并将 `fastcgi_pass php:9000` 改为本机 PHP-FPM 地址
3. 确保项目根目录和 `app/` 可写
4. MySQL/PostgreSQL 需提前创建空数据库；然后访问 `http://服务器地址/index.php?a=install`，选择已安装 PDO 驱动对应的数据库并完成安装

## 升级

在后台设置底部升级入口点击“升级”，检测更新后，勾选文件后点击“在线升级”，由系统从 GitHub 下载代码并同步数据库结构。

## 数据库迁移

先在新数据库完成安装并登录管理员账号，再从升级页进入“数据迁入”，或访问 `index.php?a=migrate`。选择旧数据库类型并填写连接信息，程序会迁入旧库的全部普通数据表；当前库没有的表会自动复制字段、主键和索引后再导入数据，同名表则清空后替换，并保留原 ID。
插件数据表会一并迁入。附件、头像和插件程序文件不在数据库中，需要另外复制 `app/upload/`、`app/avatars/` 和 `app/plugins/`。迁移前请备份新旧数据库。

## 文件目录权限

### 公网开放访问

```text
index.php                       论坛唯一主程序
app/assets/                     静态资源
app/avatars/                    头像镜像，需持久存储
app/upload/                     附件，需持久存储
```
### 公网禁止访问

```text
app/data/                       数据文件，需持久存储
app/plugins/                    插件，需持久存储
app/cache/                      缓存
app/setup/                      安装升级与数据迁入
```

## 插件开发指南

插件放在 `app/plugins/插件ID/plugin.php`，后台“插件”页会自动扫描。新插件默认停用，启用后才会执行。插件 ID 建议使用小写字母、数字、下划线或短横线。

插件读写目录应使用 `DATA_DIR`、`CACHE_DIR`、`PLUGIN_DIR`、`UPLOAD_DIR` 等核心常量，附件公开地址使用 `upload_url()`，不要硬编码目录。

最小示例：

```php
<?php
if (!defined('APP_ROOT')) exit;

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

插件的固定 CSS、JavaScript 必须通过 `assets` 声明，不要通过 `page.head`、`page.footer` 输出 `<style>` 或内联 `<script>`。所有启用插件的资源会分别合并到 `app/assets/plugins.css` 和 `app/assets/plugins.js`，前后台统一加载：

```php
'assets' => [
    'css' => 'hello_css',
    'js' => 'hello_js',
],
```

- `css`、`js` 都是可选项，值是插件内资源函数名；资源函数不接收参数并返回字符串。
- CSS 函数只返回 CSS 源码，不包含 `<style>` 标签。
- JavaScript 函数只返回 JavaScript 源码，不包含 `<script>` 标签。
- 资源不得依赖当前用户、当前页面、CSRF 或每次请求才确定的数据。动态值应输出到插件 HTML 的 `data-*` 属性，再由合并后的 JavaScript 读取。

启用、停用、卸载、市场安装或更新插件后，系统会自动重新生成资源。后台也提供“重建资源”按钮用于手动强制生成。

### 插件数据库

插件涉及数据库结构和跨引擎写入时，必须使用核心 `app_db_*` 函数；普通查询仍使用 `q()`、`one()`、`val()`。
系统数据表固定使用 `app_` 前缀；插件自己的 `plugin_*` 表名保持不变。

```php
function hello_schema(): void
{
    $t = app_db_types();
    app_db_create_table('plugin_hello_items', "id {$t['id']},item_key {$t['key']} NOT NULL UNIQUE,title {$t['string']} NOT NULL,body {$t['text']} NOT NULL,created_at INTEGER NOT NULL");
    app_db_create_index('idx_plugin_hello_created', 'plugin_hello_items(created_at DESC)');
}

app_db_upsert('plugin_hello_items', [
    'item_key' => $key,
    'title' => $title,
    'body' => $body,
    'created_at' => now(),
], ['item_key']);
```

- `app_db_types()` 提供跨数据库的 `id`、`uint`、`key`、`string`、`text` 类型；ID、外键、计数和时间字段使用 `uint`，状态位保留普通 `INTEGER`。
- `app_db_create_table()`、`app_db_create_index()`、`app_db_drop_index()`、`app_db_drop_table()` 和 `app_db_ensure_columns()` 用于表结构安装、升级和卸载。
- 插件的 `*_schema()` 只应由 `*_install()` 在安装或启用时调用；普通请求、页面渲染和业务函数中不要重复调用 schema。
- 插件应尽量减少数据库查询：优先复用 Hook 上下文和已有查询结果；需要读取多条关联数据时，先合并 ID 再使用一次 `IN` 查询；不要在主题、回帖等列表循环中逐条查询。
- 插件应尽量使用缓存提高数据库效率：同一请求内重复读取的数据必须使用请求级缓存；允许短暂延迟的统计数字可以使用短期 Session 缓存；新增、更新或删除相关数据后必须主动失效对应缓存。不要为了读取或验证缓存额外查询数据库。
- 插件需要识别自己处理的主题或回帖时，可以在内容中加入插件专属的特征标识；渲染时先检查内容是否包含该标识，命中后再查询插件数据并替换标识，避免为每条内容查询插件表。
- 如果插件运行时因表或字段未升级而发生结构错误，系统会统一提示到后台“插件”页面重新安装该插件。
- `app_db_upsert()` 用于按唯一键新增或更新；只需防止重复时使用 `app_db_insert_ignore()`。
- 新增记录后使用 `app_db_last_insert_id('表名')`，不要直接调用 `db()->lastInsertId()`。
- 需要计算多个时间值的最大值时使用 `app_db_greatest('表达式1', '表达式2')`，不要直接写 SQLite 专用的 `MAX(a,b)`。
- 插件表名建议使用 `plugin_插件ID_` 前缀，并为 Upsert 的键建立 `UNIQUE` 或主键。
- 主题搜索不要直接操作 `app_topics_fts`。新增或更新主题后调用 `topic_fts_sync($topic_id, $title, $body)`；系统会按数据库使用 SQLite FTS5、MySQL ngram FULLTEXT 或 PostgreSQL `pg_trgm` 索引。

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
