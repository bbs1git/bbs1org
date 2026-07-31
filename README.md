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

## Docker 源码部署

服务器需先安装 Docker Engine，并确保 `8080` 端口未被占用。
以部署到 `/opt` 目录为范例。

```bash
cd /opt
git clone https://github.com/bbs1org/bbs1org.git
git clone https://github.com/bbs1org/bbs1org_docker.git
cd bbs1org_docker
mv .env.example .env
docker compose up -d
```

安装完成访问：

```text
http://服务器地址:8080
```

默认管理员账号和密码均为 `admin`，登录后请及时修改密码。

注意：默认使用 SQLite。
如需 MySQL 或 PostgreSQL，启动前修改 `.env` 中的 `COMPOSE_PROFILES` 为 `mysql` 或 `pgsql`，然后执行 `docker compose up -d`。

| 数据库 | 配置值 | 容器内默认地址 |
| --- | --- | --- |
| SQLite | `sqlite` | 无需端口 |
| MySQL | `mysql` | `mysql:3306` |
| PostgreSQL | `pgsql` | `postgres:5432` |

常用操作（均在 `/opt/bbs1org_docker` 执行）：

```bash
docker compose ps                 # 查看状态
docker compose logs -f            # 查看日志
docker compose restart            # 重启
docker compose down               # 停止并保留数据卷
```

## 虚拟机部署（已有 Nginx/Apache + PHP8 环境）

- 下载项目压缩包 `https://github.com/bbs1org/bbs1org/archive/refs/heads/main.zip`
- 解压缩后通过 FTP 上传到网站目录，确保 `index.php` 位于根目录。
- 访问站点域名，根据指示进行安装即可。
- 使用第三方(比如：cron-job.org)定时请求服务，启用定时任务。
每分钟以 `GET` 方式访问：`https://你的域名/index.php?a=cron`

## 面板部署

宝塔和 1Panel 在面板 终端 执行Docker 源码部署代码即可。

## 在线升级

在后台设置底部点击“升级”，检测更新后选择文件并执行“在线升级”。升级前请先备份数据库、附件、头像和插件目录。

## 数据库转换和迁移

先在新数据库完成安装并登录管理员账号，再从升级页进入“数据迁入”，或访问 `index.php?a=migrate`。选择旧数据库类型并填写连接信息，程序会迁入旧库的全部普通数据表；当前库没有的表会自动复制字段、主键和索引后再导入数据，同名表则清空后替换，并保留原 ID。

插件数据表会一并迁入。附件、头像和插件程序文件不在数据库中，需要另外复制 `app/upload/`、`app/avatars/` 和 `app/plugins/`。迁移前请备份新旧数据库。

## 文件目录权限

### 公网开放访问

```text
index.php                       论坛唯一主程序
app/assets/                     静态资源
app/avatars/                    头像镜像，需持久存储
app/upload/                     附件，定期备份，需持久存储
```

### 公网禁止访问

```text
app/data/                       数据文件，定期备份，需持久存储
app/plugins/                    插件，定期备份，需持久存储
app/cache/                      临时缓存
app/setup/                      安装升级与数据迁入函数
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
- CSS 类名必须以插件 ID 为前缀，选择器作用域必须落在插件自己输出的前缀类或根容器内；不要使用 `body`、通用标签或核心通用类选择器覆盖全站样式。
- 不得复用、覆盖或依赖其他插件的 CSS 类；其他插件未启用时，当前插件的界面仍须完整。
- 颜色默认只使用系统变量，不要为插件另建重复的主题调色板：

| 用途 | 系统颜色变量 |
| --- | --- |
| 背景、边框 | `--bg`、`--panel`、`--line`、`--line-soft` |
| 文字 | `--text`、`--text-muted`、`--text-subtle`、`--text-disabled` |
| 品牌、交互 | `--brand`、`--brand-hover`、`--brand-soft`、`--focus-ring` |
| 状态 | `--success`、`--success-soft`、`--danger`、`--danger-soft`、`--warning`、`--warning-soft`、`--info`、`--info-soft` |
| 反色、遮罩、阴影 | `--inverse`、`--inverse-border`、`--inverse-text`、`--backdrop`、`--shadow-base`、`--shadow-medium` |

除必须还原的第三方品牌色或数据可视化色外，不要硬编码十六进制、RGB、HSL 或命名颜色。避免无理由使用 `!important`；布局必须兼容窄屏、长文本、空状态和交互状态。

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
