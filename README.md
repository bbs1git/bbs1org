# bbs1org

一个极简 PHP 论坛。核心代码仅200多KB，纯原生、无框架、无依赖；支持 SQLite、MySQL 和 PostgreSQL；适合社区站点、低成本部署和 AI 二次开发。

## 特点

- 纯原生 PHP，单核心代码文件，无框架和 Composer 依赖，部署与维护简单
- 支持 SQLite、MySQL 和 PostgreSQL，数据库结构和搜索能力保持跨引擎兼容
- 包含首页、版块、主题、回帖、收藏、个人主页和后台管理等完整论坛功能
- 支持用户组、版块权限、站点设置、注册控制、发帖限制和附件管理
- 插件机制支持 Hook、路由、后台页面、资源合并、在线安装更新和统一计划任务
- 站点设置、版块和用户组按需懒加载，数据库结构简单，负载能力强
- 支持 AJAX 交互和响应式布局，兼顾 PC 与移动端使用体验

## 环境

- PHP 8.1+
- PDO SQLite、PDO MySQL 或 PDO PostgreSQL 扩展

## 演示

https://bbs1.org

## Docker 部署

服务器需先安装 Docker Engine 和 Docker Compose 插件，并确保 `80` 端口未被占用。

### GHCR 镜像部署（推荐）

适合生产环境部署。镜像包含 bbs1org、Nginx、PHP 和计划任务配置，不需要在宿主机挂载源码。

克隆项目后，使用 [container/docker-compose.yml](container/docker-compose.yml) 启动服务：

```bash
git clone https://github.com/bbs1org/bbs1org.git
cd bbs1org
docker compose -f container/docker-compose.yml up -d
```

默认端口为 `8080`；可通过 `HTTP_PORT=80 docker compose -f container/docker-compose.yml up -d` 修改。未创建 `.env` 时不会报错，Compose 只启动 PHP、Nginx 和 cron，安装页直接使用 SQLite。`cron` 容器会每分钟执行一次站点和插件计划任务。

#### 使用 MySQL 或 PostgreSQL

`container/docker-compose.yml` 已包含可选的 MySQL 8.4 与 PostgreSQL 18 服务。创建项目根目录的 `.env`，填写：

```dotenv
# mysql 或 pgsql
COMPOSE_PROFILES=mysql
DB_NAME=forum
DB_USER=forum
DB_PASSWORD=请替换为高强度密码
```

启动后访问安装页。数据库类型选择 MySQL 时主机填写 `mysql`；选择 PostgreSQL 时将 `COMPOSE_PROFILES` 改为 `pgsql`，主机填写 `postgres`。数据库名、用户名和密码填写 `.env` 中的对应值。

若使用现有的外部 MySQL 或 PostgreSQL，不设置 `COMPOSE_PROFILES`，按 SQLite 方式启动 PHP、Nginx 和 cron 容器；在安装页填写外部数据库的连接信息即可。

### 源码挂载部署

适合开发、直接修改源码，或需要使用 `bbs1org_docker` 提供的 SQLite、MySQL、PostgreSQL Compose profile 的场景。

```bash
cd /opt
git clone https://github.com/bbs1org/bbs1org.git bbs1org
git clone https://github.com/bbs1org/bbs1org_docker.git docker
cd /opt/docker
cp .env.example .env
nano .env
```
编辑 `/opt/docker/.env` 选择 SQLite、MySQL 或 PostgreSQL。

```bash
docker compose up -d
```

访问 `http://服务器地址/index.php?a=install` 完成安装。
Compose 会自动启动 `cron` 容器，每分钟以 CLI 方式执行 `php index.php cron`；无需配置宿主机 crontab 或第三方 URL 定时服务。
完整配置、更新、备份和维护说明见 [bbs1org_docker](https://github.com/bbs1org/bbs1org_docker)。

## 手动部署

使用宝塔面板部署请参考 [宝塔部署指南](https://github.com/bbs1org/bbs1org_docker/blob/main/README_BT.md)；使用 1Panel 请参考 [1Panel 部署指南](https://github.com/bbs1org/bbs1org_docker/blob/main/README_1PANEL.md)。

```bash
git clone https://github.com/bbs1org/bbs1org.git /var/www/bbs1org
cd /var/www/bbs1org
chown -R www-data:www-data .
```

1. 将站点根目录指向项目目录，并将 PHP 请求交给 PHP-FPM
2. 配置不存在文件回退到 `/index.php?$query_string`，禁止公网访问 `app/data/`、`app/cache/`、`app/plugins/`、点文件及 `app/upload/` 中的脚本文件；Nginx 可参考 [bbs1org_docker/nginx.conf](https://github.com/bbs1org/bbs1org_docker/blob/main/nginx.conf)，并将 `fastcgi_pass php:9000` 改为本机 PHP-FPM 地址
3. 确保项目根目录和 `app/` 可写
4. MySQL/PostgreSQL 需提前创建空数据库；然后访问 `http://服务器地址/index.php?a=install`，选择已安装 PDO 驱动对应的数据库并完成安装

若重新部署程序后连接到已有完整站点数据的数据库，安装器会恢复本地数据库配置和安装锁，并跳转登录页使用原账号登录，不会重复初始化数据。

完成安装后，为运行 PHP 的系统用户配置每分钟一次的 CLI 计划任务：

```cron
* * * * * cd /var/www/bbs1org && /usr/bin/php index.php cron >> app/data/cron.log 2>&1
```

可通过 `crontab -e` 添加；`/usr/bin/php` 请按服务器上的 `command -v php` 结果调整。
若主机不支持 CLI 计划任务，可使用云监控、cron-job.org 等定时 URL 服务每分钟访问：

```text
https://你的域名/index.php?a=cron
```

## 升级

在后台设置底部点击“升级”，检测更新后选择文件并执行“在线升级”。升级前请先备份数据库、附件、头像和插件目录。

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
app/cache/                      插件及临时缓存
app/setup/                      安装升级与数据迁入
```

## 插件开发指南

### AI 插件开发规则

使用 AI 新建、修改或审查插件前，应先让 AI 完整读取项目根目录的 [`.ai-rules.md`](.ai-rules.md)。该文件将本节开发规范提炼为短句强约束，重点覆盖插件结构、跨数据库兼容、Schema、查询性能、Hook、资源、安全、外部采集和交付检查，用于减少 AI 在长文档中遗漏关键规则。入口位于项目根目录 `.ai-rules.md`，可在提示词中直接写“先读取并遵守 `.ai-rules.md`，再开发插件”。

插件放在 `app/plugins/插件ID/plugin.php`，然后在后台“插件”页点击“同步插件”。注册信息保存到 `app_plugins`，普通请求不扫描插件目录。新插件默认停用，启用后才会执行；从插件市场更新或重新安装后也会自动停用，再次启用时执行新版插件的 `install`。插件 ID 必须使用小写字母、数字、下划线或短横线。

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

启用、停用、卸载、市场安装或更新插件后，系统会自动重新生成资源；后台“同步插件”会同时同步插件目录并重建资源。

### 插件计划任务

插件通过 manifest 的 `cron` 注册一个或多个任务，任务名在插件内唯一，最短间隔为 60 秒：

```php
function hello_collect(array $plugin, array $task): string
{
    // 执行可重复运行的任务，并自行对业务写入加锁、去重。
    return 'done';
}

function hello_cron_interval(): int
{
    $config = plugin_config('hello', ['cron_interval_minutes' => 60]);
    return max(1, (int)$config['cron_interval_minutes']) * 60;
}

return [
    // ...
    'cron' => [
        'collect' => [
            'callback' => 'hello_collect',
            'interval' => 'hello_cron_interval',
        ],
    ],
];
```

- `callback` 必须是插件中已定义的函数名；回调可不声明参数，也可接收插件 manifest 和当前任务配置。
- `interval` 可以直接填写 60 至 31536000 的秒数，也可以填写返回秒数的插件函数名；使用函数即可让间隔由插件管理页配置。
- 只有启用的插件会进入调度。失败任务会短间隔重试；连续失败达到默认 3 次后暂停 30 分钟，暂停结束时自动清零失败次数并恢复调度。
- 回调应保证可重复执行，并为采集、队列处理等耗时写入使用插件自己的互斥锁和唯一来源键。

### 插件数据库

插件涉及数据库结构和跨引擎写入时，必须使用核心 `app_db_*` 函数；普通查询仍使用 `q()`、`one()`、`val()`。
系统数据表固定使用 `app_` 前缀；插件自己的 `plugin_*` 表名保持不变。

```php
function hello_schema(): void
{
    $t = app_db_types();
    app_db_create_table('plugin_hello_items', "id {$t['id']},item_key {$t['key']} NOT NULL UNIQUE,title {$t['string']} NOT NULL,body {$t['text']} NOT NULL,created_at {$t['uint']} NOT NULL");
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
- 插件运行异常时会被自动停用，并在后台插件卡片显示原因；涉及数据库结构变更时，应重新安装或再次启用插件以执行新版 `install`。
- `app_db_upsert()` 用于按唯一键新增或更新；只需防止重复时使用 `app_db_insert_ignore()`。
- 新增记录后使用 `app_db_last_insert_id('表名')`，不要直接调用 `db()->lastInsertId()`。
- 需要计算多个时间值的最大值时使用 `app_db_greatest('表达式1', '表达式2')`，不要直接写 SQLite 专用的 `MAX(a,b)`。
- 插件表名建议使用 `plugin_插件ID_` 前缀，并为 Upsert 的键建立 `UNIQUE` 或主键。
- 主题和回帖搜索不要直接操作 `app_topics_fts`、`app_replies_fts`。新增或更新内容后分别调用 `topic_fts_sync($topic_id, $title, $body)`、`reply_fts_sync($reply_id, $body)`；系统会按数据库使用 SQLite FTS5、MySQL ngram FULLTEXT 或 PostgreSQL `pg_trgm` 索引。
- 插件需要在正文中保存结构化且可搜索的内容时，优先使用标准 Markdown 表格；不要用 Base64 或自定义标记封装需要搜索的字段。表格单元格中的换行应转为空格，`|` 应写成 `\|`。

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
