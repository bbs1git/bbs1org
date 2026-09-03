# bbs1.org 插件开发 AI 规则

本文件是 AI 新建、修改和审查 bbs1.org 插件时的完整规范。开始工作前先读完本文件，再检查核心函数和功能最接近的现有插件；实现时以当前代码为准，不臆造接口。

## 执行顺序

1. 明确插件 ID、功能边界、配置项、数据归属、页面入口、权限要求、外部请求和计划任务。
2. 优先复用核心函数、Hook、路由、后台标签和相邻插件的成熟模式；插件机制能够完成时，不修改 `index.php` 或核心资源。
3. 只在 `app/plugins/插件ID/plugin.php` 内实现插件逻辑，固定 CSS 和 JavaScript 由 manifest 的 `assets` 提供。
4. 新建插件时验证默认配置、安装、启用、停用和卸载；修改插件时兼容旧配置与旧数据，并至少递增补丁版本；涉及用户可感知能力时同步更新描述。
5. 完成后执行 PHP 语法检查、差异检查，并按本文件末尾的清单复核。

## 基础约束

- 兼容 PHP 8.1、SQLite、MySQL 和 PostgreSQL，不引入框架、Composer 包或构建依赖。
- 插件目录和 manifest `id` 使用相同 ID，只包含小写字母、数字、下划线或短横线。插件自有的 PHP、CSS、JavaScript、浏览器存储和文件名称必须以该 ID 开头，禁止使用无前缀的通用名称，防止与核心或其他插件冲突。
- 命名空间前缀固定：PHP 函数使用 `foo_bar_`，常量使用 `FOO_BAR_`，类、接口、Trait、Enum 使用 `FooBar` 前缀或包含 `FooBar` 的命名空间；JavaScript 函数、顶层变量和全局变量使用 `foo_bar_`；私有 Hook 使用 `foo_bar.`。CSS 类、CSS ID、CSS 变量、`data-*` 属性和自定义事件使用插件 ID 的连字符形式，例如 `foo-bar-*`、`--foo-bar-*`、`data-foo-bar-*`。
- manifest 中注册已有核心 Hook 时，必须使用核心定义的原始名称，例如 `topic.after_save`；只有插件自行定义并通过 `hook()` 或 `fire()` 调用的私有 Hook 才必须以插件 ID 开头，例如 `foo_bar.after_import`。不得以兼容为由定义或保留无前缀的插件私有别名。
- 禁止以 `function_exists()`、`class_exists()` 或类似兼容分支定义无前缀的插件函数、类或常量。插件之间不得直接调用函数或依赖 CSS/JavaScript；确需共享的代码能力必须移入核心，并使用正式的核心函数或 Hook。
- `plugin.php` 开头必须包含 `if (!defined('APP_ROOT')) exit;`。
- manifest 必须准确声明 `id`、`name`、`version`、`description`、`author`；按需声明 `assets`、`hooks`、`routes`、`admin_tabs`、`cron`、`install`、`uninstall`。
- `description` 面向普通用户，只说明用户可感知的功能和收益，语言简短易懂；不得写技术实现、协议或依赖、数据库与任务调度等技术名词，也不得写版本更新点或开发说明。
- 所有插件源码修改，无论是功能、修复、样式、脚本还是重构，都必须同步提升该插件 manifest 的 `version`，至少递增补丁版本；不得沿用原版本号。涉及用户可感知能力时，`description` 也必须与当前能力一致。
- 插件读写路径使用 `DATA_DIR`、`PLUGIN_DIR`、`UPLOAD_DIR` 等核心常量，禁止硬编码部署目录；缓存和运行数据统一放入 `DATA_DIR`，公开附件地址由附件所属插件生成，共享上传目录分片使用 `upload_hash_dir()`。

最小插件结构：

```php
<?php
if (!defined('APP_ROOT')) exit;

function hello_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_hello_items', "id {$t['id']},item_key {$t['key']} NOT NULL UNIQUE,title {$t['string']} NOT NULL,created_at {$t['uint']} NOT NULL");
}

function hello_css(): string
{
    return '.hello-message{color:var(--brand);font-weight:600}';
}

function hello_footer($html, array $ctx): string
{
    return (string)$html . '<span class="hello-message">Hello</span>';
}

return [
    'id' => 'hello',
    'name' => 'Hello',
    'version' => '1.0.0',
    'description' => '在页脚显示问候信息。',
    'author' => 'your-name',
    'assets' => ['css' => 'hello_css'],
    'hooks' => ['page.footer' => 'hello_footer'],
    'install' => 'hello_install',
];
```

## 核心约束：禁止循环内数据库查询（N+1）

这是插件开发的**最上位性能约束**。它限制的**不是“渲染钩子里查一次库”**，而是**“查库次数随页内条目数线性放大”**。判据是量级：查询数若为 **O(页内行数)**（列表逐行、回帖逐条、同一页重复触发）则为违规；若恒为 **O(1)**（整页单次触发、批量预取、内存映射）则合规。

平台对帖子列表、回帖等以“逐条触发渲染钩子”的方式渲染（如 `topic.after_render` 在列数 / 首页每行、`reply.after_render` 在每个回帖）。若这些反复触发的路径里每渲染一条就查一次库，整页产生与行数相等的 N+1 查询，量级从 O(1) 退化为 O(行数)。

判红标准（触犯即不合格）：
- 在 `for` / `foreach` / `while` / 列表循环 / 回帖逐条循环内，每渲染一条就查一次库（即使用了缓存，只要每个不同 key 的首次查询仍发生在循环里，仍属结构性 N+1）。
- 在帖子、回帖、勋章、列表、用户或统计的循环体中逐条查询关联数据。

不属违规的情形：某个钩子整页只触发一次且不随行数放大（如 `topic.after_render` 在**主题查看页只对主楼执行一次**、受 `list`/`marker` 限定、只落在详情页单点），允许单次查询；这类“单点渲染”虽可用，仍建议批量预取。不要把「详情页单次查询」误当成违规主体。

任何合规场景一律遵循**“先收集、再批查、后映射”**三步：

1. 先收集整批对象 ID（内存）；
2. 用分块 `IN (...)` 一次批量读取（SQLite/MySQL/PostgreSQL 通用）；
3. 在内存中按 ID 建立映射，渲染阶段只读内存映射，不再查库。

当“逐条渲染钩子拿不到整页对象集合”（无法在一条查询里覆盖整页）时，使用「唯一占位符 + 页面级批量回填」（见下），而**不是**退回在循环内逐条查库。

常用方案速查：

| 场景 | 首选方案 |
| --- | --- |
| 列表 / 批处理 | 收集 ID → 分块 `IN (...)` → 内存映射 |
| 同一请求重复读同批数据 | 请求级缓存（`$GLOBALS`） |
| 跨请求持久缓存 | `save_settings_values()` + `settings_rows_cache()` |
| 逐条渲染钩子、整页对象不可预知 | 唯一占位符 + 页面级钩子整页一次性回填 |
| 绕开整页流程的片段（AJAX 返回局部 HTML） | 就地少量查询直接渲染，不得遗留占位符 |

## 占位符标准规范

用于“逐条渲染钩子拿不到整页对象集合，却必须禁止循环内查库”的场景。

**格式**：

```
<!--{插件ID}-{token}-{主键ID}-->
```

- `插件ID`：manifest 的 `id`，默认小写字母/数字/下划线/短横线，例如 `medal`。
- `token`：请求内随机串；**同一请求内所有占位符共用一个**。用 `random_bytes()` 生成 6~12 字节再 hex 化，防止把用户输入反馈中伪造的同形注释误当占位符，也避免碰撞。
- `主键ID`：待回填对象的无符号整数主键（uid、帖子 ID 等）。
- 占位符整体只能由插件按白名单生成，绝不允许直接用用户输入拼接。

**回填流程**（必须在“拿到完整整页 HTML”的钩子里完成，例如核心 `page.before_render`）：

1. **提点**：逐条渲染钩子内只插入唯一占位符，并把对象主键记入请求作用域内存；页内无已启用对象时整页不埋占位符。
2. **合并取回**：在整页钩子里收集对象 ID 去重，用一条 `IN (...)` 批量取回，构建“主键 → 渲染结果”映射。
3. **一次替换**：用 `preg_replace_callback()` 以 `<!--插件ID-token-(\d+)-->` 为模式（`preg_quote()` 转义 token）整页替换一次；对象不复存在或不应显示的替换为空字符串（让占位符就地消失）。
4. **收敛**：返回的最终 HTML 必须不含有未替换的占位符。

**约束与安全**：
- 禁止按“用户名 / 作者名 / 任意字符串”做回填匹配；占位符自带的唯一主键是唯一可靠的定位手段，避免回填错位或破坏结构。
- 对绕过整页模板的响应（AJAX / `json_response` 直接返回局部 HTML），整页回填钩子不会触发，此时必须**就地做少量查询的直接渲染**（允许单次小查询），绝不能留下未回填占位符。
- 回填内容与普通内容一样在输出前经 `h()` 转义；占位符由前缀 + token + 数字组成，不含可注入文本。
- 优先级顺序：能整段 `IN` 预取 + 内存映射、请求级/持久缓存时优先；占位符只用于逐条钩子无法整批的例外，且必须配套整页回填钩子。

## 生命周期与配置

- 新插件放入目录后，需要在后台“插件”页执行“同步插件”。插件注册信息保存在 `app_plugins`，普通请求不会扫描插件目录。
- 新插件默认停用；只有启用后才执行。市场安装、更新或重新安装后插件会自动停用，再次启用时执行当前版本的 `install`。
- `install` 负责建表、补列和创建索引，且必须可重复执行；Schema 函数只能由 `install` 调用，不能出现在普通请求、页面渲染或业务函数中。
- `uninstall` 只能删除插件明确拥有的表、缓存和文件。无法确认归属的用户内容或附件不得连带删除。
- 修改现有插件时，旧配置缺少新字段不能报错；读取配置时集中补默认值，并归一化布尔值、枚举、字符串长度和数值上下限。
- 保存配置前验证 `$_POST`、`$_GET` 和 JSON 输入，不能把原始请求数据直接交给数据库、文件系统或外部接口。

推荐把配置入口集中为一个函数：

```php
function hello_config(): array
{
    $raw = plugin_config('hello', []);
    return [
        'enabled' => (int)($raw['enabled'] ?? 0) === 1,
        'interval_minutes' => min(1440, max(1, (int)($raw['interval_minutes'] ?? 60))),
    ];
}
```

## 数据库与性能

- 表结构和跨数据库写入使用 `app_db_*`；普通参数化查询使用 `q()`、`one()`、`val()`，多步关联写入使用 `tx()` 保证原子性。
- 插件表使用 `plugin_插件ID_` 前缀，系统表保持 `app_` 前缀。字段类型来自 `app_db_types()`：ID、外键、计数和时间使用 `uint`，状态位使用普通 `INTEGER`。
- 插件原则上不得修改 `app_*` 核心表结构。确有必要扩展核心表时，新增字段必须使用 `plugin_插件ID_字段名` 前缀，禁止使用 `tags`、`status` 等无插件归属的通用字段名；插件自有 `plugin_*` 表内部字段不需要重复插件前缀。
- 核心表扩展字段由 `install` 使用 `app_db_ensure_columns()` 创建，并在“不保留数据”的 `uninstall` 中使用 `app_db_drop_column()` 删除；创建和删除都必须可重复执行，禁止直接拼接 `ALTER TABLE ... ADD/DROP COLUMN`。
- 建表、补列、删列、索引和卸载分别使用 `app_db_create_table()`、`app_db_ensure_columns()`、`app_db_drop_column()`、`app_db_create_index()`、`app_db_drop_index()`、`app_db_drop_table()`。
- Upsert 键必须有主键或唯一索引。新增或更新使用 `app_db_upsert()`，只防重复使用 `app_db_insert_ignore()`；新增后使用 `app_db_last_insert_id('表名')`，不要直接调用 PDO 的 `lastInsertId()`。
- 业务去重条件必须与唯一约束完全一致。按规则、频道或周期隔离的数据，唯一键应包含 `group_key`、`channel`、`period_key` 等范围字段，不能查询按复合范围判断而表结构只约束单列。
- 多个时间表达式取最大值使用 `app_db_greatest()`，不要写只适配某一种数据库的 SQL。
- 列表和批处理禁止循环逐条查询。先收集 ID，使用分块 `IN (...)` 一次读取，再在内存中建立映射。
- Hook 优先复用 `$ctx` 和 `$value`。同一请求重复读取的数据使用请求级缓存；允许短暂延迟的数据可使用短期 Cookie 缓存。写入后主动失效相关缓存，不为验证缓存额外查询数据库。
- 需要跨请求持久保存的缓存使用 `save_settings_values()` 写入并通过 `setting()` 读取，不生成 PHP 缓存文件。
- 新增或更新主题后调用 `topic_fts_sync()`，新增或更新回帖后调用 `reply_fts_sync()`；禁止直接读写 `app_topics_fts` 和 `app_replies_fts`。
- 需要保存可搜索的结构化正文时使用标准 Markdown 表格。单元格换行转为空格，`|` 写成 `\|`；不要用 Base64 或私有编码隐藏可搜索内容。

典型写入：

```php
app_db_upsert('plugin_hello_items', [
    'item_key' => $key,
    'title' => $title,
    'created_at' => now(),
], ['item_key']);
```

## Hook、路由与界面

- Hook 函数通常接收 `($value, array $ctx)` 并返回修改后的值，返回 `null` 表示不修改。识别插件自有主题或回帖时，先检查专属内容标识，命中后才查询插件表。
- 顶部栏入口使用 `top.bar.actions` Hook（每页执行一次），在 `$value` 的 `left`、`right_before_search` 或 `right_after_search` 数组中以插件 ID 为 key 写入 HTML；不要引入 `slot` 属性，不使用 CSS `order`、`:has()` 或根据其他插件存在性调整位置。`left` 位于版块导航之后，`right_before_search` 位于搜索框左侧，`right_after_search` 位于搜索框右侧。入口 HTML 应由服务端直接输出，插件 JavaScript 只绑定交互和状态；如需后台控制显示，manifest 使用 `entries.top_actions`。
- 前台页面通过 manifest `routes` 注册，链接使用 `route_url()`；后台页面通过 `admin_tabs` 注册。不要硬编码 `index.php` 查询串。
- 涉及发帖、回帖、管理或用户数据的路由必须显式检查登录和权限，后台入口调用 `need_admin()`。修改状态的操作只接受 POST，并调用 `require_post()`；表单包含 `form_token()`。
- 所有外部数据和用户数据输出到 HTML 前使用 `h()`。URL 先由核心 URL 函数生成，再转义。
- 固定 CSS 和 JavaScript 只能通过 manifest `assets` 声明。资源函数无参数并返回源码，不包含 `<style>`、`<script>` 标签，也不能依赖当前用户、页面、CSRF 或实时请求数据；动态值通过插件 HTML 的 `data-*` 属性传递。
- 插件 JavaScript 需要定位核心 Hook 的页面承载元素时，使用 `[data-slot~="hook.name"]`；同一元素可用空格声明多个 Hook 插槽。重复插槽先通过帖子 ID、`data-floor`、`data-plugin-id` 或插件自有根容器缩小范围，不依赖核心内部层级选择器。
- 不直接修改自动生成的 `app/assets/plugins.css` 和 `app/assets/plugins.js`。启用、停用、卸载、市场安装、更新和后台同步插件时，系统会重建这些资源。
- CSS 类名、ID、变量、`data-*` 属性、`@keyframes`、`@property` 和 `container-name` 必须使用插件 ID 前缀；JavaScript 函数、顶层变量、全局变量、自定义事件名、HTML `id` 和锚点也必须使用对应前缀。生成后的插件 JavaScript 在同一作用域执行，除必要的前缀化导出外，必须用具名 IIFE 隔离，避免顶层 `const`、`let` 或状态变量冲突。Cookie、`localStorage`、`sessionStorage`、`BroadcastChannel` 的插件键名同样必须前缀化。选择器限制在插件自己的根容器内；不要覆盖 `body`、通用标签、核心通用类或其他插件类，也不能依赖其他插件的样式。
- 颜色优先使用系统变量：背景和边框使用 `--bg`、`--panel`、`--line`、`--line-soft`；文字使用 `--text`、`--text-muted`、`--text-subtle`、`--text-disabled`；品牌和交互使用 `--brand`、`--brand-hover`、`--brand-soft`、`--focus-ring`；状态使用 `--success`、`--danger`、`--warning`、`--info` 及对应 `*-soft`；反色、遮罩和阴影使用 `--inverse`、`--inverse-border`、`--inverse-text`、`--backdrop`、`--shadow-base`、`--shadow-medium`。
- 界面字号统一使用 CSS 变量：`--font-size-xxs` 为 10px、`--font-size-xs` 为 11px、`--font-size-sm` 为 12px、`--font-size-md` 为 14px、`--font-size-lg` 为 16px、`--font-size-xl` 为 18px。`font-size` 与 `font` 中的字号禁止直接写数字；需要例外字号时先定义语义化变量，再使用该变量。
- 只有还原第三方品牌或表达数据类别时才能在插件作用域内使用额外颜色；禁止无理由使用 `!important`。
- 前后台界面都要处理窄屏、长文本、空数据、失败、权限不足和交互状态，避免固定宽度导致溢出。

### 前端 `data-slot` 接口

核心页面会在稳定的承载元素上输出 `data-slot`，供插件 JavaScript 查找和绑定交互。属性值以空格分隔；选择单个插槽必须使用 `[data-slot~="..."]`，不能使用模糊的 `[data-slot*="..."]`。下表是当前核心提供的插槽，名称以源码为准：

| `data-slot` 值 | 页面位置 / 用途 | 相关 PHP Hook |
| --- | --- | --- |
| `sidebar.feature_links` | 首页侧栏“快捷功能”链接列表 | `sidebar.feature_links` |
| `top.menu_links` | 桌面端顶部版块导航；移动端菜单也复用 | `top.menu_links` |
| `user.menu_links` | 用户侧栏菜单；移动端菜单也复用 | `user.menu_links` |
| `sidebar.stack` | 整个侧栏容器 | `sidebar.stack` |
| `mainpanel_extra` | 主内容面板，扩展内容追加在主内容之后 | `mainpanel_extra` |
| `topic.actions` | 主题主楼操作区（引用、管理等） | `topic.actions` |
| `topic.after_render` | 主题列表项或主题/回帖帖子项 | `topic.after_render` |
| `topic.content_after` | 主题主楼内容之后的扩展区域 | `topic.content_after` |
| `topic.title_suffix` | 主题列表标题链接之后 | `topic.title_suffix` |
| `top.actions` | 顶部操作栏整体 | `top.bar.actions` |
| `top.actions.left` | 顶部版块导航右侧的操作区 | `top.bar.actions` |
| `top.actions.right.before-search` | 顶部搜索框左侧操作区 | `top.bar.actions` |
| `top.actions.right.after-search` | 顶部搜索框右侧操作区 | `top.bar.actions` |
| `page.before_render` | 页面主内容 `<main>` 容器 | `page.before_render` |
| `page.footer` | 页面页脚容器 | `page.footer` |
| `login.after_form` | 登录面板（登录表单之后可追加内容） | `login.after_form` |
| `login.form_extra` | 登录表单内部扩展字段 | `login.form_extra` |
| `register.form_extra` | 注册表单内部扩展字段 | `register.form_extra` |
| `profile.after_form` | 个人资料面板（资料表单之后可追加内容） | `profile.after_form` |
| `user.profile_tabs` | 用户资料页标签栏 | `user.profile_tabs` |
| `topic.index_tabs` | 首页 / 版块主题列表标签栏 | `topic.index_tabs` |
| `topic.toolbar_actions` | 首页 / 版块主题列表工具栏操作区 | `topic.toolbar_actions` |
| `reply.form_extra` | 回帖表单内部扩展字段 | `reply.form_extra` |
| `attachment.uploader` | 发帖或回帖表单的附件上传区域 | `attachment.uploader` |
| `admin.plugin.actions` | 后台每个插件条目的操作区 | `admin.plugins.view`（数据来源） |

同一元素可能声明多个值，例如发帖表单的 `data-slot="attachment.uploader topic.form_extra"`。JavaScript 示例：

```js
(function () {
    const form = document.querySelector('[data-slot~="topic.form_extra"]');
    if (!form) return;
    form.addEventListener('change', function (event) {
        // 只处理插件自己的控件。
        if (!event.target.matches('[data-my-plugin-field]')) return;
    });
}());
```

`data-slot` 只保证核心扩展位置和语义，不保证内部子元素层级或每页出现次数。主题列表、主题详情和回帖中的 `topic.after_render` 可能出现多次，必须结合 `id="post-..."`、`data-floor` 或插件自己的根容器缩小范围；页面级插槽通常每页只有一个。通过 AJAX 返回的局部 HTML 也可能重新生成插槽，插件应使用事件委托或在替换后重新初始化。

manifest 注册形式：

```php
'assets' => ['css' => 'hello_css', 'js' => 'hello_js'],
'routes' => ['hello' => 'hello_page'],
'admin_tabs' => ['hello' => 'hello_admin_page'],
```

## 安全、文件与外部请求

- 文件名和路径必须经过白名单验证，防止路径穿越。上传和远程文件限制协议、主机、类型和大小，并拒绝内网地址、凭据 URL 和脚本文件。
- 插件自有的目录、文件、锁、临时文件、日志文件和公开附件命名必须包含插件 ID；不得在共享目录创建 `cache`、`lock`、`log` 等无前缀的通用名称。
- 外部 HTTP 请求设置连接超时、总超时、重定向上限、响应大小和明确的 User-Agent；跟随重定向时逐跳重新验证目标。
- Cookie、Token、密码和密钥不得出现在页面、日志、错误信息、队列键或公开文件中。
- 错误信息保持简短，不暴露凭据、敏感请求头或完整 SQL。远程失败应可重试，但不能无限同步阻塞用户请求。
- 插件权限等同站点代码，只实现任务需要的访问范围，不读取或修改无关数据。

## 计划任务、采集与队列

- 计划任务通过 manifest `cron` 注册。任务名在插件内唯一，`callback` 是已定义的函数名；回调可以不接收参数，也可以接收插件 manifest 和当前任务配置。
- `interval` 可以是 60 至 31536000 的秒数，也可以是返回秒数的插件函数名。后台可配置间隔时使用间隔函数。
- 只有启用的插件进入统一调度。不要依赖普通页面请求触发任务，也不要添加心跳或 cron 部署探测。
- 回调必须可重复运行，并使用互斥锁、唯一来源键和幂等写入。多请求或可续跑任务使用可重试队列，`queue_key` 必须唯一，消费索引使用 `(failure_count,id)`。
- 失败任务达到重试上限后默认暂停 30 分钟；暂停到期后清零失败次数并恢复，不能让失败任务永久阻塞队列。
- 同一批远端记录先收集来源 ID，再批量查询已入库记录和待处理队列；禁止在远端列表循环中逐条查询。
- 去重策略必须匹配数据性质：不可变历史数据按来源键存在即跳过；可刷新数据比较内容指纹，仅在变化时更新内容、搜索索引和 `updated_at`；周期归档把周期加入唯一键。
- 外部记录保存来源 ID、来源 URL、首次创建时间、最后看到时间和最后更新时间。图片先按规范化来源 URL 的 SHA-256 键复用下载结果，再按文件内容哈希复用附件；不要把长度不可控的完整 URL 作为跨数据库主键。
- 采集正文、图片或回帖必须遵循配置。功能关闭时不应先请求远端再丢弃结果。

计划任务示例：

```php
function hello_cron_interval(): int
{
    return hello_config()['interval_minutes'] * 60;
}

function hello_collect(array $plugin, array $task): string
{
    // 加锁后执行可重复运行的采集或清理任务。
    return 'done';
}

// manifest
'cron' => [
    'collect' => [
        'callback' => 'hello_collect',
        'interval' => 'hello_cron_interval',
    ],
],
```

## 交付检查

- 使用当前项目已有函数和相邻插件模式，没有复制功能重复的基础设施。
- 保持原生 PHP 风格、参数类型明确、分支可读、错误信息简短，不为减少行数牺牲可维护性。
- 普通页面没有新增不必要的数据库查询、外部请求或同步耗时操作。
- 已按 N+1 判据核对：列表循环 / 回帖逐条 / 同一页重复触发的路径内无逐条投库查询；详情页单次渲染与批量预取不算违规；使用占位符的路径已确认最终 HTML 无残留占位符。
- 配置默认值、非法输入、边界值、旧配置和旧数据均可正常处理。
- 数据库占位符、唯一约束、索引、事务和 SQLite/MySQL/PostgreSQL 兼容性已检查。
- 插件版本号和描述已更新，未修改插件职责之外的核心文件或生成资源。
- 已运行 `php -l app/plugins/插件ID/plugin.php` 和 `git diff --check`。
- 交付说明列出行为变化、迁移影响、验证结果，以及未执行的外部副作用操作。

## 界面与图标速查

优先使用核心 `svg_icon()`，图标继承 `currentColor` 并自动适配主题。当前常用图标包括：
`user`（用户）、`id`（身份）、`reply`（回复）、`notify`（通知）、`forum`（版块）、
`topic`（主题）、`view`（浏览）、`settings`（设置）、`admin`（管理）和 `pages`（文档）。

插件自带 SVG 应使用 `viewBox="0 0 24 24"`、`fill="none"`、`stroke="currentColor"`，主轮廓使用 `stroke-width="2"`，尺寸交由 CSS 控制。需要新图标时优先反馈给核心加入 `svg_icon()`，避免重复实现。

## 官方资源

- [bbs1org 源码下载](https://bbs1.org/plugin_market_source?path=bbs1org.zip&download=1)
- [bbs1org 安装方法](https://bbs1.org/topic/21)
- [官方推荐插件专辑](https://bbs1.org/topic_collection/1)
- [图文教程](https://bbs1.org/topic/365)

## 核心 API 速查

| 分组 | 函数 |
| --- | --- |
| 数据库 | `q()` `one()` `val()` `rows_by_ids()` `row()` `del()` `tx(callable)` `app_db_upsert()` `app_db_insert_ignore()` |
| 表结构 | `app_db_create_table()` `app_db_drop_table()` `app_db_create_index()` `app_db_drop_index()` `app_db_table_exists()` `app_db_columns()` `app_db_ensure_columns()` `app_db_index_exists()` |
| 身份权限 | `uid()` `me()` `need_login()` `need_admin()` `need_manage()` `can_manage()` `can_manage_topic()` `can_manage_reply()` `can_speak()` `is_super_user()` `forum_group_allowed()` |
| 积分 | `user_points_change($user_id, $delta, $reason = '系统调整', $notify = false, $context = [])`（自带事务，勿在 `tx()` 内调用） |
| 页面渲染 | `page()` `shell_html()` `sidebar_stack_html()` `sidebar_user_card_html()` `form_shell()` `paginate()` `page_seo()` `page_head_html()` `page_nav_html()` `page_footer_html()` |
| 表单 | `form_token()` `hidden_inputs()` `input()` `textarea()` `checkbox()` `number_input()` `select_input()` `post_action_form()` `render_form_fields()` |
| 跳转/提示 | `route_url()` `admin_url()` `base_url()` `go()` `set_flash()` `err()` `json_response()` |
| 工具 | `h()` `cut()` `now()` `human_time()` `app_cookie()` `svg_icon()` `avatar_tag()` `avatar_link_tag()` `avatar_remote_url()` |
| 论坛数据 | `forum_by_id()` `forums_cache()` `select_forum()` `refresh_topic_stats()` `pinned_topic_ids()` `create_notification()` `notifications_unread_total()` `mark_notifications_read()` |
| 全文检索 | `topic_fts_sync()` `reply_fts_sync()` `topic_fts_delete()` `reply_fts_delete()` `content_search_condition()` `search_index_available()` `search_index_rebuild()` `search_like_pattern()` |
| 插件 | `plugins()` `plugin_load()` `plugin_config()` `plugin_save_config()` `plugin_id_valid()` `plugin_registry_row()` `plugin_enabled()` `plugin_uses_entry()` `plugin_entry_enabled()` `plugin_call()` |

## Hooks 与展示位置

| Hook | 触发时机 | 备注 |
| --- | --- | --- |
| `app.boot` | 全站每个请求启动一次 | 预加载数据的最佳位置 |
| `topic.before_render` / `reply.before_render` | 主题/回帖渲染前 | 红区，调用链零 DB 读 |
| `topic.after_render` / `reply.after_render` | 主题/回帖渲染后 | 循环内零 DB 读 |
| `topic.before_save` / `topic.after_save` | 主题保存前后 | 处理主题数据 |
| `reply.before_save` / `reply.after_save` | 回帖保存前后 | 处理回帖数据 |
| `topic.replies` | 主题页回帖集合 | 每页一次，可整体预加载 |
| `page.before_render` | 整页输出前 | 占位符批量回填的唯一可靠位置 |
| `sidebar.stack` | 侧栏组件栈 | value 和返回值均为数组 |
| `sidebar.feature_links` | 侧栏快捷功能 | 非循环展示位置 |
| `top.menu_links` | 顶部版块导航/移动端版块列表 | 链接数组，同一请求一次 |
| `top.bar.actions` | 顶部栏插件入口 | `left`、`right_before_search`、`right_after_search` 三个区域 |
| `user.profile_tabs` | 用户资料页标签栏 | 展示位置：个人主页 Tab |
| `user.menu_links` | 个人卡片与移动端我的菜单 | 展示位置：个人卡片 |
| `register.form_extra` / `login.form_extra` | 注册/登录表单附加区 | 仅渲染表单扩展 |
| `profile.after_form` | 个人资料页附加区 | ctx 含 user |
| `admin.tabs` | 后台顶栏标签 | value 为 items 数组 |
| `notification.after_create` | 通知写入后 | 仅入队，勿同步请求外部服务 |
| `markdown.render` / `markdown.after` | Markdown 渲染前后 | after 可能逐行调用，禁止查库 |
| `page.seo` / `page.footer` | SEO 元信息/页脚 | 返回值覆盖或追加 |
| `user.before_save` / `user.after_save` | 用户保存前后 | before 可返回过滤数组 |

### `entries` 展示位置

声明对应 Hook 后，后台“插件 → 本地插件 → 展示位置”会出现开关。未声明 `entries` 时默认勾选；首次同步只补齐缺失值，已有后台选择会保留。

| entries 键 | Hook | 实际显示位置 |
| --- | --- | --- |
| `feature_links` | `sidebar.feature_links` | 首页侧栏和移动端快捷功能区 |
| `sidebar_cards` | `sidebar.stack` | 侧栏卡片区域 |
| `home_tabs` | `topic.index_tabs` | 首页/版块列表顶部 Tab |
| `profile_tabs` | `user.profile_tabs` | 用户主页顶部 Tab |
| `profile_card` | `user.menu_links` | 侧栏个人卡片和移动端我的菜单 |
| `topic_actions` | `topic.actions` | 主题首帖右上角操作区 |
| `admin_tabs` | `admin.tabs` | 后台顶部 Tab |
| `top_menu` | `top.menu_links` | PC 顶部版块区和移动端版块列表 |
| `top_actions` | `top.bar.actions` | 版块导航、搜索框前后 |

## 发布与 AI 协作

1. 完成本地验证后，在后台“插件 → 本地插件列表”找到目标插件并点击“分享”。
2. 在官方发布页设置售价（`0` 为免费）、更新日志和协作者权限，然后提交审核。
3. 后续修改必须提升版本号并重新测试，再重复分享流程。

给 AI 的最小提示：

```text
请先完整阅读插件开发规范：PLUGIN.md
并检查最接近的现有插件。
请在 app/plugins/<插件ID>/plugin.php 开发插件。
需求：<清楚描述功能、入口、设置项和权限>
完成后请提升 version，执行 PHP 语法检查与差异检查。
```
