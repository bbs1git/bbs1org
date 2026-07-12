<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
define('APP_VERSION', 'v4.0');
define('DATA_DIR', __DIR__ . '/data');
define('DB_CONFIG_FILE', DATA_DIR . '/db.php');
define('DEFAULT_DB_FILE', DATA_DIR . '/forum.sqlite');
define('DB_FILE', db_file_path());
define('INSTALL_LOCK_FILE', DATA_DIR . '/install.lock');
define('CACHE_DIR', __DIR__ . '/cache');
define('AVATAR_DIR', __DIR__ . '/avatars');
define('UPLOAD_DIR', __DIR__ . '/upload');
define('FORUM_CACHE_FILE', CACHE_DIR . '/forums.php');
define('GROUP_CACHE_FILE', CACHE_DIR . '/groups.php');
define('STATS_CACHE_FILE', CACHE_DIR . '/stats.php');
define('SETTING_CACHE_FILE', CACHE_DIR . '/settings.php');
define('PLUGIN_DIR', __DIR__ . '/plugins');
define('PLUGIN_CACHE_FILE', CACHE_DIR . '/plugins.php');
define('DEBUG_LOG_FILE', DATA_DIR . '/debug.log');
define('SEARCH_MIN_CHARS', 3);
define('PLUGIN_MARKET_BASE_URL', 'https://bbs1.org/index.php');
define('PLUGIN_SHARE_BODY_MAX', 200000);
function db_file_path(): string
{
    if (is_file(DB_CONFIG_FILE)) {
        $config = include DB_CONFIG_FILE;
        $name = is_array($config) ? basename((string)($config['db_file'] ?? '')) : '';
        if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) return DATA_DIR . '/' . $name;
    }
    return DEFAULT_DB_FILE;
}
function db(): PDO
{
    static $db;
    if ($db) return $db;
    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    foreach (
        [
            'PRAGMA journal_mode=WAL',
            'PRAGMA synchronous=NORMAL',
            'PRAGMA temp_store=MEMORY',
            'PRAGMA busy_timeout=5000',
            'PRAGMA cache_size=-16000',
            'PRAGMA mmap_size=134217728',
            'PRAGMA wal_autocheckpoint=400',
        ] as $sql
    ) $db->exec($sql);
    return $db;
}
function h(string|int|float|bool|null $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}
function one(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}
function val(string $sql, array $p = [])
{
    return q($sql, $p)->fetchColumn();
}
function cache_write_php(string $file, mixed $value): void
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, "<?php\nreturn " . var_export($value, true) . ";\n", LOCK_EX);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
}
function load_array_cache(string $file, bool $refresh, callable $reload, ?array $fallback = null, ?callable $validate = null): array
{
    static $memory = [];
    if (!$refresh && isset($memory[$file])) return $memory[$file];
    if (!$refresh && is_file($file)) {
        $cached = include $file;
        if (is_array($cached) && (!$validate || $validate($cached))) return $memory[$file] = array_merge($fallback ?? [], $cached);
    }
    $memory[$file] = $fallback;
    try {
        $memory[$file] = array_merge($fallback ?? [], $reload());
        cache_write_php($file, $memory[$file]);
    } catch (Throwable $e) { if ($fallback === null) throw $e; }
    return $memory[$file] ?? [];
}
function tx(callable $fn)
{
    $db = db();
    if ($db->inTransaction()) return $fn();
    $db->beginTransaction();
    try {
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
function secure_session_start(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
function rows_by_ids(string $table, array $ids, string $cols = '*'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = q("SELECT $cols FROM $table WHERE id IN ($marks)", $ids)->fetchAll();
    $map = [];
    foreach ($rows as $row) $map[(int)$row['id']] = $row;
    return $map;
}
function attach_users(array $rows, string $key = 'user_id', string $fallback = '用户删除'): array
{
    $users = rows_by_ids('users', array_column($rows, $key), 'id,username,avatar_style,avatar_seed,group_id,points,is_banned,is_muted');
    foreach ($rows as &$row) $row += ($users[(int)($row[$key] ?? 0)] ?? ['username' => $fallback, 'avatar_style' => '', 'avatar_seed' => '', 'group_id' => 0, 'points' => 0, 'is_banned' => 0, 'is_muted' => 0]);
    unset($row);
    return $rows;
}
function attach_topic_list_users(array $rows): array
{
    $user_ids = array_merge(array_column($rows, 'user_id'), array_column($rows, 'last_reply_user_id'));
    $users = rows_by_ids('users', $user_ids, 'id,username,avatar_style,avatar_seed,group_id,points,is_banned,is_muted');
    foreach ($rows as &$row) {
        $row += ($users[(int)($row['user_id'] ?? 0)] ?? ['username' => '', 'avatar_style' => '', 'avatar_seed' => '', 'group_id' => 0, 'points' => 0, 'is_banned' => 0, 'is_muted' => 0]);
        $last_reply_uid = (int)($row['last_reply_user_id'] ?? 0);
        $row['last_reply_username'] = $last_reply_uid > 0 ? (string)($users[$last_reply_uid]['username'] ?? '') : '';
    }
    unset($row);
    return $rows;
}
function attach_topics(array $rows, string $key = 'topic_id'): array
{
    $topics = rows_by_ids('topics', array_column($rows, $key), 'id,title');
    foreach ($rows as &$row) $row['topic_title'] = (string)($topics[(int)($row[$key] ?? 0)]['title'] ?? '主题已删除');
    unset($row);
    return $rows;
}
function db_schema_ready(): bool
{
    return is_file(INSTALL_LOCK_FILE);
}
function default_settings(): array
{
    return [
        'site_name' => 'FORUM',
        'site_base_url' => '',
        'site_closed' => '0',
        'debug_mode' => '0',
        'pretty_url' => '0',
        'allow_register' => '1',
        'reserved_usernames' => 'admin,administrator,root,system',
        'default_group_id' => '2',
        'topics_per_page' => '30',
        'replies_per_page' => '50',
        'mail_from' => '',
        'mail_virtual' => '0',
        'avatar_mirror_styles' => '',
        'register_per_hour' => '1',
        'login_fail_per_hour' => '5',
        'reset_fail_per_hour' => '5',
        'post_interval_seconds' => '5',
        'attachment_max_count' => '10',
        'attachment_max_mb' => '20',
    ];
}
function settings_cache(bool $refresh = false): array
{
    return load_array_cache(SETTING_CACHE_FILE, $refresh, fn(): array => array_column(q("SELECT name,value FROM settings")->fetchAll(), 'value', 'name'), default_settings());
}
function setting(string $key, string $default = ''): string
{
    $settings = settings_cache();
    return (string)($settings[$key] ?? $default);
}
function save_settings_values(array $values): void
{
    $stmt = db()->prepare("REPLACE INTO settings(name,value) VALUES(?,?)");
    foreach ($values as $name => $value) $stmt->execute([$name, $value]);
    settings_cache(true);
}
function exception_detail(Throwable $e): string
{
    $parts = [];
    do {
        $parts[] = get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        $e = $e->getPrevious();
    } while ($e);
    return implode("\n\nPrevious:\n", $parts);
}
function debug_mode_enabled(): bool
{
    try {
        return db_schema_ready() && setting('debug_mode', '0') === '1';
    } catch (Throwable $e) {
        return false;
    }
}
function debug_log_write(string $message, ?Throwable $e = null): void
{
    if (!debug_mode_enabled()) return;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
    $uri = trim((string)($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . (string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri !== '') $line .= "\n" . $uri;
    if ($e) $line .= "\n" . exception_detail($e);
    $line .= "\n\n";
    @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() === 0) return false;
    debug_log_write('PHP error [' . $severity . '] ' . $message . "\n" . $file . ':' . $line);
    return false;
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    debug_log_write('PHP fatal [' . (int)$error['type'] . '] ' . (string)$error['message'] . "\n" . (string)$error['file'] . ':' . (int)$error['line']);
});
function plugin_id_valid(string $id): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) === 1;
}
function plugin_normalize(array $plugin, string $file = ''): ?array
{
    $id = (string)($plugin['id'] ?? '');
    if (!plugin_id_valid($id)) return null;
    $base = [
        'id' => $id,
        'name' => (string)($plugin['name'] ?? $id),
        'version' => (string)($plugin['version'] ?? ''),
        'description' => (string)($plugin['description'] ?? ''),
        'author' => (string)($plugin['author'] ?? ''),
        'enabled' => !empty($plugin['enabled']),
        'hooks' => is_array($plugin['hooks'] ?? null) ? $plugin['hooks'] : [],
        'routes' => is_array($plugin['routes'] ?? null) ? $plugin['routes'] : [],
        'admin_tabs' => is_array($plugin['admin_tabs'] ?? null) ? $plugin['admin_tabs'] : [],
        'install' => (string)($plugin['install'] ?? ''),
        'uninstall' => (string)($plugin['uninstall'] ?? ''),
        'file' => $file,
    ];
    foreach (['hooks', 'routes', 'admin_tabs'] as $map) {
        $items = [];
        foreach ($base[$map] as $name => $fn) if (is_string($name) && is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $items[$name] = $fn;
        $base[$map] = $items;
    }
    foreach (['install', 'uninstall'] as $key) if ($base[$key] !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base[$key]) !== 1) $base[$key] = '';
    return $base;
}
function plugin_files_state(): array
{
    $files = glob(PLUGIN_DIR . '/*/plugin.php') ?: [];
    sort($files);
    $state = [];
    foreach ($files as $file) if (is_file($file)) $state[$file] = (int)filemtime($file);
    return $state;
}
function plugin_load(array $plugin): void
{
    $file = (string)($plugin['file'] ?? '');
    if ($file !== '' && is_file($file)) include_once $file;
}
function plugins_rebuild_cache(): array
{
    $files_state = plugin_files_state();
    $plugins = [];
    foreach (array_keys($files_state) as $file) {
        $raw = include_once $file;
        if (!is_array($raw)) continue;
        $plugin = plugin_normalize($raw, $file);
        if ($plugin) $plugins[$plugin['id']] = $plugin;
    }
    cache_write_php(PLUGIN_CACHE_FILE, ['files' => $files_state, 'plugins' => $plugins]);
    return $plugins;
}
function plugins(bool $refresh = false): array
{
    static $plugins = null;
    if (!$refresh && $plugins !== null) return $plugins;
    if (!$refresh && is_file(PLUGIN_CACHE_FILE)) {
        $cached = include PLUGIN_CACHE_FILE;
        if (is_array($cached) && is_array($cached['plugins'] ?? null)) {
            return $plugins = $cached['plugins'];
        }
    }
    return $plugins = plugins_rebuild_cache();
}
function plugin_enabled(array $plugin): bool
{
    return setting('plugin_' . (string)$plugin['id'] . '_enabled', '0') === '1';
}
function plugin_entry_hook_name(string $entry): string
{
    return ['feature_links' => 'sidebar.feature_links', 'sidebar_cards' => 'sidebar.stack'][$entry] ?? '';
}
function plugin_uses_entry(array $plugin, string $entry): bool
{
    $hook = plugin_entry_hook_name($entry);
    return $hook !== '' && isset($plugin['hooks'][$hook]);
}
function plugin_entry_enabled(array $plugin, string $entry): bool
{
    if (!plugin_uses_entry($plugin, $entry)) return false;
    return setting('plugin_' . (string)$plugin['id'] . '_entry_' . $entry, '1') === '1';
}
function plugin_set_entry_enabled(string $id, string $entry, bool $enabled): void
{
    if (!plugin_id_valid($id) || plugin_entry_hook_name($entry) === '') err('参数错误');
    $plugin = plugins()[$id] ?? null;
    if (!$plugin || !plugin_uses_entry($plugin, $entry)) err('插件未使用该入口');
    save_settings_values(['plugin_' . $id . '_entry_' . $entry => $enabled ? '1' : '0']);
}
function plugin_config(string $id, array $defaults = []): array
{
    if (!plugin_id_valid($id)) return $defaults;
    $raw = setting('plugin_' . $id . '_config', '{}');
    $config = json_decode($raw, true);
    return array_merge($defaults, is_array($config) ? $config : []);
}
function plugin_save_config(string $id, array $config): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    save_settings_values(['plugin_' . $id . '_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
}
function plugin_set_enabled(string $id, bool $enabled): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    $plugin = plugins()[$id] ?? null;
    if (!$plugin) err('插件不存在');
    if ($enabled) plugin_load($plugin);
    if ($enabled && !plugin_enabled($plugin) && !empty($plugin['install']) && function_exists((string)$plugin['install'])) {
        call_user_func((string)$plugin['install'], $plugin);
    }
    save_settings_values(['plugin_' . $id . '_enabled' => $enabled ? '1' : '0', 'plugin_' . $id . '_version' => (string)($plugin['version'] ?? '')]);
}
function plugin_uninstall(string $id, bool $keep_data = true): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    $plugin = plugins()[$id] ?? null;
    if (!$plugin) err('插件不存在');
    plugin_load($plugin);
    if (!$keep_data) {
        $fn = (string)($plugin['uninstall'] ?? '');
        if ($fn === '' || !function_exists($fn)) $fn = str_replace('-', '_', $id) . '_uninstall';
        if (function_exists($fn)) call_user_func($fn, $plugin);
    }
    q("DELETE FROM settings WHERE name IN (?,?,?)", ['plugin_' . $id . '_enabled', 'plugin_' . $id . '_version', 'plugin_' . $id . '_config']);
    settings_cache(true);
}
function plugin_share_topic_title(array $plugin): string
{
    $id = (string)($plugin['id'] ?? '');
    $name = trim((string)($plugin['name'] ?? ''));
    return '[' . $id . ']' . ($name !== '' ? $name : $id);
}
function plugin_share_markdown_body(string $code): string
{
    if (preg_match('/^\s*```\s*[\w-]*\s*$/m', $code)) err('插件代码包含独立的 Markdown 代码块标记，无法安全分享');
    return "```php\n" . rtrim($code) . "\n```";
}
function plugin_market_url(string $action): string
{
    return append_url_query(PLUGIN_MARKET_BASE_URL, ['a' => $action]);
}
function remote_http_request(string $url, int $timeout = 8, array $headers = [], ?array $post_fields = null): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '服务器未启用 cURL'];
    $ch = curl_init($url);
    if (!$ch) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '无法初始化请求'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => min(4, max(1, $timeout)),
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_USERAGENT => 'bbs1org/' . APP_VERSION,
    ];
    if ($headers) $options[CURLOPT_HTTPHEADER] = $headers;
    if ($post_fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post_fields);
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error !== '' ? $error : '请求失败'];
    if ($status < 200 || $status >= 300) return ['ok' => false, 'status' => $status, 'body' => (string)$body, 'error' => 'HTTP ' . $status];
    return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'error' => ''];
}
function plugin_share_post_page(string $id): void
{
    need_admin();
    if (!plugin_id_valid($id)) err('插件不存在');
    $plugin = plugins()[$id] ?? null;
    if (!$plugin) err('插件不存在');
    $file = (string)($plugin['file'] ?? '');
    if ($file === '' || !is_file($file)) err('插件文件不存在');
    $code = file_get_contents($file);
    if (!is_string($code) || trim($code) === '') err('插件文件为空');
    $title = plugin_share_topic_title($plugin);
    $body = plugin_share_markdown_body($code);
    if (strlen($body) > PLUGIN_SHARE_BODY_MAX) err('插件代码超过分享长度限制');
    $share_form = '<form class="post-action-form" method="post" action="' . h(plugin_market_url('plugin_share_receive')) . '" data-no-ajax="1" data-plugin-share-auto="1"><input type="hidden" name="title" value="' . h($title) . '"><textarea name="body" hidden>' . h($body) . '</textarea><button type="submit" class="plugin-enable">立即继续</button></form>';
    $head = '<div class="admin-plugin-summary"><strong>正在前往插件市场</strong><span>插件代码已准备好，请在官方站点确认后发布。</span></div>';
    $plugin_author = trim((string)($plugin['author'] ?? ''));
    $row = '<li class="admin-list-item admin-object-row plugin-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h((string)($plugin['name'] ?? $id)) . '</strong><span class="admin-flag on">分享</span></div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ((string)($plugin['version'] ?? '') !== '' ? '<span>版本 ' . h((string)$plugin['version']) . '</span>' : '') . ($plugin_author !== '' ? '<span>插件作者 ' . h($plugin_author) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($plugin['description'] ?? '')) . '</div><div class="plugin-file">' . h($file) . '</div></div><div class="admin-inline-ops plugin-ops">' . $share_form . '</div></li>';
    $tip = '<p class="muted" style="margin:8px 0 0">插件安装更新方法：后台 -> 插件 -> 插件市场 -> 搜索插件ID ' . h($id) . '</p>';
    $html = '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, '') . '<ul class="admin-manage-list plugin-list">' . $row . '</ul></div>' . $tip;
    page('分享插件', shell_html($html, sidebar_stack_html([sidebar_user_card_html()])));
}
function plugin_market_fetch(): array
{
    $response = remote_http_request(plugin_market_url('plugin_market_feed'), 8, ['Accept: application/json']);
    if (!$response['ok']) return ['ok' => 0, 'message' => '无法连接插件市场' . ((string)$response['error'] !== '' ? '：' . (string)$response['error'] : ''), 'plugins' => []];
    $json = (string)$response['body'];
    if (!is_string($json) || trim($json) === '') return ['ok' => 0, 'message' => '无法连接插件市场', 'plugins' => []];
    $data = json_decode($json, true);
    if (!is_array($data)) return ['ok' => 0, 'message' => '插件市场返回格式错误', 'plugins' => []];
    $plugins = [];
    foreach ((array)($data['plugins'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        $code = (string)($item['code'] ?? '');
        if (!plugin_id_valid($id) || trim($code) === '') continue;
        $plugins[$id] = [
            'id' => $id,
            'title' => (string)($item['title'] ?? ''),
            'name' => (string)($item['name'] ?? $id),
            'version' => (string)($item['version'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'author' => (string)($item['author'] ?? ''),
            'creator' => (string)($item['creator'] ?? ($item['username'] ?? ($item['author'] ?? ''))),
            'creator_id' => (int)($item['creator_id'] ?? 0),
            'topic_id' => (int)($item['topic_id'] ?? 0),
            'updated_at' => (int)($item['updated_at'] ?? 0),
            'sha256' => (string)($item['sha256'] ?? hash('sha256', $code)),
            'url' => clean_site_base_url((string)($item['url'] ?? '')),
            'code' => $code,
        ];
    }
    return ['ok' => (int)($data['ok'] ?? 1), 'message' => (string)($data['message'] ?? ''), 'plugins' => $plugins];
}
function plugin_dir_require_writable(): void
{
    if (!is_dir(PLUGIN_DIR) && !mkdir(PLUGIN_DIR, 0755, true)) err('插件目录不可写，请检查 plugins 目录权限');
    if (!is_writable(PLUGIN_DIR)) err('插件目录不可写，请检查 plugins 目录权限');
}
function plugin_market_install(string $id): void
{
    need_admin();
    if (!plugin_id_valid($id)) err('插件不存在');
    $market = plugin_market_fetch();
    $item = $market['plugins'][$id] ?? null;
    if (!is_array($item)) err((string)($market['message'] ?? '') ?: '插件市场没有返回该插件');
    $code = (string)($item['code'] ?? '');
    if (!str_starts_with(ltrim($code), '<?php')) err('插件代码格式错误');
    if ((string)($item['sha256'] ?? '') !== '' && !hash_equals((string)$item['sha256'], hash('sha256', $code))) err('插件代码校验失败');
    if (preg_match('/[\'"]id[\'"]\s*=>\s*([\'"])(.*?)\1/s', $code, $m) !== 1 || (string)$m[2] !== $id) err('插件代码 ID 与市场 ID 不一致');
    plugin_dir_require_writable();
    $dir = PLUGIN_DIR . '/' . $id;
    $file = $dir . '/plugin.php';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) err('插件目录创建失败');
    if (is_file($file)) {
        $backup_dir = CACHE_DIR . '/plugin-backups';
        if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) err('插件备份目录创建失败');
        $backup = $backup_dir . '/' . $id . '-' . date('YmdHis') . '.php';
        if (!copy($file, $backup)) err('现有插件备份失败');
    }
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $code, LOCK_EX) === false) err('插件写入失败');
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        err('插件安装失败');
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    plugins(true);
    save_settings_values(['plugin_' . $id . '_market_sha256' => (string)($item['sha256'] ?? hash('sha256', $code)), 'plugin_' . $id . '_market_topic_id' => (string)(int)($item['topic_id'] ?? 0)]);
}
function plugin_market_update_info(array $plugin, ?array $item): ?array
{
    if (!$item) return null;
    $id = (string)($plugin['id'] ?? '');
    if (!plugin_id_valid($id)) return null;
    $remote_sha = (string)($item['sha256'] ?? '');
    $local_sha = setting('plugin_' . $id . '_market_sha256', '');
    $remote_version = trim((string)($item['version'] ?? ''));
    $local_version = trim((string)($plugin['version'] ?? ''));
    $sha_update = $remote_sha !== '' && $local_sha !== '' && !hash_equals($local_sha, $remote_sha);
    $version_update = $remote_version !== '' && $local_version !== '' && version_compare($remote_version, $local_version, '>');
    if (!$sha_update && !$version_update) return null;
    return ['version' => $remote_version, 'sha256' => $remote_sha];
}
function hook(string $name, mixed $value = null, array $ctx = []): mixed
{
    foreach (plugins() as $plugin) {
        if (!is_array($plugin) || !plugin_enabled($plugin)) continue;
        if ($name === 'sidebar.feature_links' && !plugin_entry_enabled($plugin, 'feature_links')) continue;
        if ($name === 'sidebar.stack' && !plugin_entry_enabled($plugin, 'sidebar_cards')) continue;
        $fn = $plugin['hooks'][$name] ?? null;
        if (is_string($fn)) {
            plugin_load($plugin);
            if (function_exists($fn)) {
                $next = $fn($value, $ctx);
                if ($next !== null) $value = $next;
            }
        }
    }
    return $value;
}
function fire(string $name, array $ctx = []): void
{
    hook($name, null, $ctx);
}
function plugin_route(string $action): bool
{
    foreach (plugins() as $plugin) {
        if (!is_array($plugin) || !plugin_enabled($plugin)) continue;
        $fn = $plugin['routes'][$action] ?? null;
        if (is_string($fn)) {
            plugin_load($plugin);
            if (function_exists($fn)) {
                $fn($plugin);
                return true;
            }
        }
    }
    return false;
}
function pinned_topic_ids(): array
{
    return array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', setting('pinned_topic_ids'), -1, PREG_SPLIT_NO_EMPTY) ?: []))));
}
function set_pinned_topic(int $tid, bool $pin): void
{
    $ids = pinned_topic_ids();
    $ids = $pin ? array_values(array_unique(array_merge([$tid], $ids))) : array_values(array_diff($ids, [$tid]));
    save_settings_values(['pinned_topic_ids' => implode(',', $ids)]);
}
function clean_ip(string $value): string
{
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if ($value === '') return '';
    if (in_array(strtolower($value), ['unknown', 'null', 'undefined'], true)) return '';
    if (($p = strpos($value, ';')) !== false) $value = substr($value, 0, $p);
    if (stripos($value, 'for=') === 0) $value = substr($value, 4);
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if (preg_match('/^\[([^\]]+)\](?::\d+)?$|^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $value, $m)) $value = $m[1] ?: $m[2];
    if (($p = strpos($value, '%')) !== false) $value = substr($value, 0, $p);
    return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
}
function ip_addr(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
        foreach (explode(',', (string)($_SERVER[$key] ?? '')) as $value) {
            $ip = clean_ip($value);
            if ($ip !== '') return $ip;
        }
    }
    return '0.0.0.0';
}
function rate_setting(string $key, string $default): int
{
    return max(1, (int)setting($key, $default));
}
function rate_log_row(string $ip): array
{
    $row = one("SELECT * FROM ip_logs WHERE ip=?", [$ip]);
    if ($row) return $row;
    $ts = time();
    q("INSERT INTO ip_logs(ip,created_at,updated_at) VALUES(?,?,?)", [$ip, $ts, $ts]);
    return one("SELECT * FROM ip_logs WHERE ip=?", [$ip]) ?: ['ip' => $ip];
}
function rate_bucket_config(string $bucket): array
{
    $configs = [
        'register' => ['count' => 'register_count', 'time' => 'register_at', 'setting' => 'register_per_hour', 'default' => '1', 'window' => 3600],
        'login_fail' => ['count' => 'login_fail_count', 'time' => 'login_fail_at', 'setting' => 'login_fail_per_hour', 'default' => '5', 'window' => 3600],
        'reset_fail' => ['count' => 'reset_fail_count', 'time' => 'reset_fail_at', 'setting' => 'reset_fail_per_hour', 'default' => '5', 'window' => 3600],
    ];
    if (!isset($configs[$bucket])) err('参数错误');
    return $configs[$bucket];
}
function rate_reset_bucket(array $row, array $bucket): array
{
    $now = time();
    $time_field = (string)$bucket['time'];
    if ((int)($row[$time_field] ?? 0) < $now - (int)$bucket['window']) {
        $row[(string)$bucket['count']] = 0;
    }
    return $row;
}
function rate_allow_bucket(string $ip, string $bucket): bool
{
    $config = rate_bucket_config($bucket);
    $row = rate_reset_bucket(rate_log_row($ip), $config);
    return (int)($row[(string)$config['count']] ?? 0) < rate_setting((string)$config['setting'], (string)$config['default']);
}
function rate_hit_bucket(string $ip, string $bucket): void
{
    $config = rate_bucket_config($bucket);
    $row = rate_reset_bucket(rate_log_row($ip), $config);
    $count_field = (string)$config['count'];
    $time_field = (string)$config['time'];
    $count = (int)($row[$count_field] ?? 0) + 1;
    $ts = time();
    q("UPDATE ip_logs SET {$count_field}=?,{$time_field}=?,updated_at=? WHERE ip=?", [$count, $ts, $ts, $ip]);
}
function post_interval_seconds(): int
{
    return min(3600, max(0, (int)setting('post_interval_seconds', '5')));
}
function check_post_interval(): void
{
    $seconds = post_interval_seconds();
    if ($seconds <= 0 || !uid()) return;
    $wait = $seconds - (time() - (int)(val("SELECT last_post_at FROM users WHERE id=?", [uid()]) ?: 0));
    if ($wait > 0) err('操作太频繁，请 ' . $wait . ' 秒后再试');
}
function clear_opcache_cache(): bool
{
    if (!function_exists('opcache_reset')) return false;
    try {
        return (bool)opcache_reset();
    } catch (Throwable $e) {
        return false;
    }
}
function clean_site_base_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return '';
    return $url;
}
function save_settings(): void
{
    $site_name = post('site_name', 80);
    if ($site_name === '') err('网站名不能为空');
    $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
    if (!group_by_id($gid)) err('默认用户组不存在');
    save_settings_values([
        'site_name' => $site_name,
        'site_base_url' => clean_site_base_url((string)($_POST['site_base_url'] ?? '')),
        'site_keywords' => post('site_keywords', 200),
        'site_description' => post('site_description', 500),
        'header_html' => post('header_html', 20000),
        'footer_html' => post('footer_html', 20000),
        'site_closed' => isset($_POST['site_closed']) ? '1' : '0',
        'debug_mode' => isset($_POST['debug_mode']) ? '1' : '0',
        'pretty_url' => isset($_POST['pretty_url']) ? '1' : '0',
        'topics_per_page' => (string)min(200, max(1, (int)($_POST['topics_per_page'] ?? 30))),
        'replies_per_page' => (string)min(200, max(1, (int)($_POST['replies_per_page'] ?? 50))),
        'mail_from' => post('mail_from', 120),
        'mail_virtual' => isset($_POST['mail_virtual']) ? '1' : '0',
        'avatar_mirror_styles' => avatar_mirror_styles_text((string)($_POST['avatar_mirror_styles'] ?? '')),
        'pinned_topic_ids' => preg_replace('/[^\d,]/', '', (string)($_POST['pinned_topic_ids'] ?? '')) ?: '',
        'allow_register' => isset($_POST['allow_register']) ? '1' : '0',
        'reserved_usernames' => post('reserved_usernames', 2000),
        'default_group_id' => (string)$gid,
        'register_per_hour' => (string)min(100, max(1, (int)($_POST['register_per_hour'] ?? 1))),
        'login_fail_per_hour' => (string)min(100, max(1, (int)($_POST['login_fail_per_hour'] ?? 5))),
        'reset_fail_per_hour' => (string)min(100, max(1, (int)($_POST['reset_fail_per_hour'] ?? 5))),
        'post_interval_seconds' => (string)min(3600, max(0, (int)($_POST['post_interval_seconds'] ?? 5))),
        'attachment_max_count' => (string)max(0, (int)($_POST['attachment_max_count'] ?? 10)),
        'attachment_max_mb' => (string)max(0, (int)($_POST['attachment_max_mb'] ?? 20)),
    ]);
}
function forums_cache(bool $refresh = false): array
{
    return load_array_cache(FORUM_CACHE_FILE, $refresh, fn(): array => q("SELECT id,name,description,sort,allow_view_groups,allow_post_groups,allow_reply_groups,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll());
}
function forum_by_id(int $id): ?array
{
    foreach (forums_cache() as $f) if ((int)$f['id'] === $id) return $f;
    return null;
}
function forum_group_select_options(?array $forum = null, string $field = '', string $label = '', int $size = 5): string
{
    $selected = [];
    if ($forum && $field !== '') $selected = forum_group_ids($forum, $field);
    $html = '<div class="grid"><span>' . h($label) . '</span><div class="forum-group-checks">';
    foreach (groups_cache() as $g) {
        $gid = (int)$g['id'];
        $html .= '<label class="check"><input type="checkbox" name="' . h($field) . '[]" value="' . $gid . '"' . (in_array($gid, $selected, true) ? ' checked' : '') . '><span>' . h($g['name']) . '</span></label>';
    }
    return $html . '</div></div>';
}
function forum_group_ids(array $forum, string $field): array
{
    $raw = trim((string)($forum[$field] ?? ''));
    if ($raw === '') return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []))));
    return $ids;
}
function forum_group_allowed(?array $forum, string $field): bool
{
    if (!$forum) return false;
    $ids = forum_group_ids($forum, $field);
    if (!$ids) return true;
    $me = me();
    $gid = (int)($me['group_id'] ?? 0);
    return in_array($gid, $ids, true);
}
function groups_cache(bool $refresh = false): array
{
    return load_array_cache(GROUP_CACHE_FILE, $refresh, fn(): array => q("SELECT id,name,allow_manage,allow_admin,upload_quota_mb FROM groups ORDER BY id")->fetchAll(),
        null, fn(array $cached): bool => !$cached || array_key_exists('upload_quota_mb', (array)reset($cached)));
}
function group_by_id(int $id): ?array
{
    foreach (groups_cache() as $g) if ((int)$g['id'] === $id) return $g;
    return null;
}
function user_by_id(int $id): ?array
{
    return one("SELECT * FROM users WHERE id=?", [$id]);
}
function notification_badge_html(int $count): string
{
    return $count > 0 ? '<span class="notify-badge">' . (int)$count . '</span>' : '';
}
function notification_excerpt(string $body, int $max = 120): string
{
    $body = preg_replace('/^\s*>.*(?:\n|$)/mu', '', $body) ?? $body;
    $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    return cut($body, $max);
}
function notification_targets(string $body): array
{
    if (!preg_match_all('/@([^\s@,，。！？!?；;:：<>]+)/u', $body, $m)) return [];
    $targets = [];
    foreach ($m[1] as $name) {
        $name = trim((string)$name);
        if ($name !== '') $targets[$name] = true;
    }
    return array_keys($targets);
}
function create_notification(int $recipient_id, int $sender_id, string $kind, string $content, int $topic_id = 0, int $reply_id = 0): bool
{
    $content = trim($content);
    if ($recipient_id <= 0 || $content === '') return false;
    if ($recipient_id === $sender_id && $kind !== 'direct') return false;
    $topic_id = $topic_id > 0 ? $topic_id : null;
    $reply_id = $reply_id > 0 ? $reply_id : null;
    q("INSERT INTO notifications(recipient_id,sender_id,kind,content,topic_id,reply_id,created_at,read_at) VALUES(?,?,?,?,?,?,?,0)", [$recipient_id, $sender_id, $kind, $content, $topic_id, $reply_id, now()]);
    q("UPDATE users SET unread_notifications=COALESCE(unread_notifications,0)+1 WHERE id=?", [$recipient_id]);
    return true;
}
function user_points_change(int $user_id, int $delta, string $reason = '系统调整'): int
{
    if ($user_id <= 0 || $delta === 0) return 0;
    $actual = tx(function () use ($user_id, $delta) {
        $old = (int)(val("SELECT points FROM users WHERE id=?", [$user_id]) ?: 0);
        $new = $old + $delta;
        $actual = $new - $old;
        if ($actual !== 0) q("UPDATE users SET points=? WHERE id=?", [$new, $user_id]);
        return $actual;
    });
    if ($actual === 0) return 0;
    if ($user_id === uid()) $GLOBALS['__me_cache'] = null;
    $now_points = (int)(val("SELECT points FROM users WHERE id=?", [$user_id]) ?: 0);
    $verb = $actual > 0 ? '增加' : '减少';
    create_notification($user_id, 0, 'points', '你的积分' . $verb . ' ' . abs($actual) . '，原因：' . trim($reason) . '。当前积分 ' . $now_points . '。');
    return $actual;
}
function user_points_set(int $user_id, int $points, string $reason = '系统调整'): int
{
    if ($user_id <= 0) return 0;
    $old = (int)(val("SELECT points FROM users WHERE id=?", [$user_id]) ?: 0);
    return user_points_change($user_id, $points - $old, $reason);
}
function create_reply_notifications(int $topic_id, int $reply_id, string $body, int $sender_id): void
{
    $topic = one("SELECT title,user_id FROM topics WHERE id=?", [$topic_id]);
    if (!$topic) return;
    $targets = [];
    foreach (notification_targets($body) as $username) {
        $u = one("SELECT id FROM users WHERE username=?", [$username]);
        if ($u) $targets[(int)$u['id']] = true;
    }
    unset($targets[$sender_id]);
    $excerpt = notification_excerpt($body);
    foreach (array_keys($targets) as $uid) {
        create_notification((int)$uid, $sender_id, 'mention', '在主题《' . (string)$topic['title'] . '》中提到你：' . $excerpt, $topic_id, $reply_id);
    }
}
function notifications_list(int $uid, int $limit, int $offset = 0): array
{
    $rows = q("SELECT * FROM notifications WHERE recipient_id=? ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset])->fetchAll();
    $users = rows_by_ids('users', array_column($rows, 'sender_id'), 'id,username,avatar_style,avatar_seed');
    foreach ($rows as &$row) {
        $u = $users[(int)($row['sender_id'] ?? 0)] ?? null;
        $row['sender_username'] = (string)($u['username'] ?? '');
        $row['sender_avatar_style'] = (string)($u['avatar_style'] ?? '');
        $row['sender_avatar_seed'] = (string)($u['avatar_seed'] ?? '');
    }
    unset($row);
    return $rows;
}
function notifications_total(int $uid): int
{
    return (int)val("SELECT COUNT(*) FROM notifications WHERE recipient_id=?", [$uid]);
}
function notifications_unread_total(int $uid): int
{
    $m = me();
    if ($m && (int)$m['id'] === $uid) return (int)($m['unread_notifications'] ?? 0);
    return (int)val("SELECT COUNT(*) FROM notifications WHERE recipient_id=? AND read_at=0", [$uid]);
}
function mark_notifications_read(int $uid): void
{
    q("UPDATE notifications SET read_at=? WHERE recipient_id=? AND read_at=0", [now(), $uid]);
    q("UPDATE users SET unread_notifications=0 WHERE id=?", [$uid]);
    $GLOBALS['__me_cache'] = null;
}
function notification_link(array $n): string
{
    if ((int)($n['topic_id'] ?? 0) > 0) {
        return route_url('topic', ['id' => (int)$n['topic_id'], 'replyid' => (int)($n['reply_id'] ?? 0) ?: null]);
    }
    if ((int)($n['sender_id'] ?? 0) > 0) return route_url('user', ['id' => (int)$n['sender_id']]);
    return route_url('home');
}
function notification_row_html(array $n): string
{
    $sender_id = (int)($n['sender_id'] ?? 0);
    $sender_name = trim((string)($n['sender_username'] ?? '')) ?: '系统';
    $body = (string)($n['content'] ?? '');
    $content_html = markdown_html($body);
    if ((string)($n['kind'] ?? '') === 'mention' && (int)($n['topic_id'] ?? 0) > 0 && preg_match('/^在主题《(.+?)》中提到你：(.*)$/us', $body, $m)) {
        $content_html = '在主题《<a href="' . h(notification_link($n)) . '">' . h($m[1]) . '</a>》中提到你：' . markdown_html(trim((string)$m[2]));
    }
    $kind = (string)($n['kind'] ?? '') === 'mention' ? '提及' : '通知';
    $unread = (int)($n['read_at'] ?? 0) === 0;
    $quote = notification_excerpt($body, 100);
    $action = (string)($n['kind'] ?? '') === 'direct' && $sender_id > 0 ? '<a class="post-tag post-forum-badge notification-reply-action" href="' . h(route_url('notify', ['id' => $sender_id, 'quote' => $quote])) . '" onclick="openNotify(this.href);return false">回复TA</a>' : '';
    $sender_title = $sender_id > 0 ? '<a class="post-title" href="' . h(route_url('user', ['id' => $sender_id])) . '">' . h($sender_name) . '</a>' : '<span class="post-title">' . h($sender_name) . '</span>';
    return '<li class="post-item notification-item' . ($unread ? ' unread' : '') . '"><div class="post-avatar">' . avatar_tag($sender_id ?: 0, $sender_name, (string)($n['sender_avatar_style'] ?? ''), '', (string)($n['sender_avatar_seed'] ?? '')) . '</div><div class="post-body"><div class="post-title-row notification-head">' . $sender_title . '<span class="post-user-group notification-kind">' . h($kind) . '</span>' . ($unread ? '<span class="notification-unread">未读</span>' : '') . '</div><div class="post-meta"><span>' . human_time((int)$n['created_at']) . '</span></div><div class="post-content notification-content">' . $content_html . '</div></div>' . $action . '</li>';
}
function admin_user_form_data(int $id): array
{
    return $id ? (user_by_id($id) ?: err('用户不存在')) : ['id' => 0, 'username' => '', 'email' => '', 'bio' => '', 'avatar_style' => '', 'avatar_seed' => '', 'group_id' => (int)setting('default_group_id', '2'), 'points' => 0];
}
function admin_search_like(string $q): string
{
    return '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
}
function admin_topic_field(string $field): string
{
    return in_array($field, ['title', 'body', 'author'], true) ? $field : 'title';
}
function admin_reply_field(string $field): string
{
    return in_array($field, ['body', 'author'], true) ? $field : 'body';
}
function admin_filter_sql(string $type, string $query = '', string $field = 'title', int $group_id = 0, int $banned_filter = -1, int $muted_filter = -1, int $forum_id = 0): ?array
{
    $where = [];
    $params = [];
    $like = $query !== '' ? admin_search_like($query) : '';
    if ($type === 'users') {
        if ($query !== '') {
            $where[] = "(username LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR bio LIKE ? ESCAPE '\\')";
            $params = [$like, $like, $like];
        }
        if ($group_id > 0) {
            $where[] = 'group_id=?';
            $params[] = $group_id;
        }
        if ($banned_filter >= 0) {
            $where[] = 'is_banned=?';
            $params[] = $banned_filter;
        }
        if ($muted_filter >= 0) {
            $where[] = 'is_muted=?';
            $params[] = $muted_filter;
        }
    } elseif ($type === 'topics') {
        if ($forum_id > 0) {
            $where[] = 'forum_id=?';
            $params[] = $forum_id;
        }
        if ($query !== '') {
            $field = admin_topic_field($field);
            if ($field === 'author') {
                $uids = array_column(q("SELECT id FROM users WHERE username LIKE ? ESCAPE '\\'", [$like])->fetchAll(), 'id');
                if (!$uids) return null;
                $where[] = 'user_id IN (' . implode(',', array_fill(0, count($uids), '?')) . ')';
                $params = array_merge($params, $uids);
            } else {
                [$condition, $search_params] = topic_search_condition($query, $field);
                $where[] = '(' . $condition . ')';
                $params = array_merge($params, $search_params);
            }
        }
    } elseif ($type === 'replies') {
        if ($query !== '') {
            $field = admin_reply_field($field);
            if ($field === 'author') {
                $uids = array_column(q("SELECT id FROM users WHERE username LIKE ? ESCAPE '\\'", [$like])->fetchAll(), 'id');
                if (!$uids) return null;
                $where[] = 'user_id IN (' . implode(',', array_fill(0, count($uids), '?')) . ')';
                $params = array_merge($params, $uids);
            } else {
                $where[] = "body LIKE ? ESCAPE '\\'";
                $params[] = $like;
            }
        }
    } else {
        return null;
    }
    return ['where' => $where ? ' WHERE ' . implode(' AND ', $where) : '', 'params' => $params];
}
function admin_count(string $type, string $query = '', string $field = 'title', int $group_id = 0, int $banned_filter = -1, int $muted_filter = -1, int $forum_id = 0): int
{
    $filter = admin_filter_sql($type, $query, $field, $group_id, $banned_filter, $muted_filter, $forum_id);
    if (!$filter) return 0;
    return (int)val('SELECT COUNT(*) FROM ' . $type . $filter['where'], $filter['params']);
}
function admin_users_list(string $query = '', int $size = 50, int $offset = 0, int $group_id = 0, int $banned_filter = -1, int $muted_filter = -1): array
{
    $filter = admin_filter_sql('users', $query, 'title', $group_id, $banned_filter, $muted_filter);
    if (!$filter) return [];
    return q('SELECT * FROM users' . $filter['where'] . ' ORDER BY id DESC LIMIT ? OFFSET ?', array_merge($filter['params'], [$size, $offset]))->fetchAll();
}
function admin_topics_list(string $query = '', int $size = 50, int $offset = 0, string $field = 'title', int $forum_id = 0): array
{
    $filter = admin_filter_sql('topics', $query, $field, 0, -1, -1, $forum_id);
    if (!$filter) return [];
    return attach_users(q("SELECT id,forum_id,title,highlight_style,user_id,created_at FROM topics" . $filter['where'] . " ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($filter['params'], [$size, $offset]))->fetchAll());
}
function admin_replies_list(string $query = '', int $size = 50, int $offset = 0, string $field = 'body'): array
{
    $filter = admin_filter_sql('replies', $query, $field);
    if (!$filter) return [];
    return attach_topics(attach_users(q("SELECT id,body,topic_id,user_id,created_at FROM replies" . $filter['where'] . " ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($filter['params'], [$size, $offset]))->fetchAll()));
}
function admin_search_form(string $tab, string $query): string
{
    $field = admin_topic_field((string)($_GET['field'] ?? 'title'));
    $select = $tab === 'topics' ? '<select class="admin-search-select" name="field"><option value="title"' . ($field === 'title' ? ' selected' : '') . '>标题</option><option value="body"' . ($field === 'body' ? ' selected' : '') . '>内容</option><option value="author"' . ($field === 'author' ? ' selected' : '') . '>作者</option></select>' : '';
    if ($tab === 'topics') {
        $forum_id = max(0, (int)($_GET['forum_id'] ?? 0));
        $select .= '<select class="admin-search-select" name="forum_id"><option value="0">全部版块</option>';
        foreach (forums_cache() as $f) $select .= '<option value="' . (int)$f['id'] . '"' . ($forum_id === (int)$f['id'] ? ' selected' : '') . '>' . h($f['name']) . '</option>';
        $select .= '</select>';
    }
    $group_id = (int)($_GET['group_id'] ?? 0);
    if ($tab === 'users') {
        $banned_filter = isset($_GET['is_banned']) && $_GET['is_banned'] !== '' ? (int)$_GET['is_banned'] : -1;
        $muted_filter = isset($_GET['is_muted']) && $_GET['is_muted'] !== '' ? (int)$_GET['is_muted'] : -1;
        $select = '<select class="admin-search-select" name="group_id"><option value="0">全部用户组</option>';
        foreach (groups_cache() as $g) $select .= '<option value="' . (int)$g['id'] . '"' . ($group_id === (int)$g['id'] ? ' selected' : '') . '>' . h($g['name']) . '</option>';
        $select .= '</select><select class="admin-search-select" name="is_muted"><option value="">发言状态</option><option value="1"' . ($muted_filter === 1 ? ' selected' : '') . '>禁止发言</option><option value="0"' . ($muted_filter === 0 ? ' selected' : '') . '>允许发言</option></select><select class="admin-search-select" name="is_banned"><option value="">访问状态</option><option value="1"' . ($banned_filter === 1 ? ' selected' : '') . '>禁止访问</option><option value="0"' . ($banned_filter === 0 ? ' selected' : '') . '>允许访问</option></select>';
    } elseif ($tab === 'replies') {
        $reply_field = admin_reply_field((string)($_GET['reply_field'] ?? 'body'));
        $select = '<select class="admin-search-select" name="reply_field"><option value="body"' . ($reply_field === 'body' ? ' selected' : '') . '>内容</option><option value="author"' . ($reply_field === 'author' ? ' selected' : '') . '>作者</option></select>';
    }
    $has_clear = $query !== '';
    if ($tab === 'users') $has_clear = $has_clear || $group_id > 0 || ($_GET['is_banned'] ?? '') !== '' || ($_GET['is_muted'] ?? '') !== '';
    if ($tab === 'topics') $has_clear = $has_clear || (int)($_GET['forum_id'] ?? 0) > 0 || $field !== 'title';
    $base = '<input type="hidden" name="a" value="admin"><input type="hidden" name="tab" value="' . h($tab) . '">';
    return '<form class="admin-table-search" method="get" action="' . h(index_url()) . '">' . $base . $select . '<div class="admin-search-field"><input name="q" value="' . h($query) . '" placeholder="搜索" minlength="' . SEARCH_MIN_CHARS . '"><button class="admin-search-submit" type="submit">搜索</button></div>' . ($has_clear ? '<a class="admin-search-clear" href="' . h(admin_url(['tab' => $tab])) . '">清空</a>' : '') . '</form>';
}
function admin_bulk_delete_form_open(string $tab, string $query): string
{
    return '<form id="admin-bulk-form" method="post" action="' . h(admin_url(['do' => 'batch_action'])) . '" data-confirm="确定执行批量操作？">' . form_token() . '<input type="hidden" name="tab" value="' . h($tab) . '"><input type="hidden" name="q" value="' . h($query) . '"></form>';
}
function admin_rebuild_fts_form(): string
{
    return '<form class="admin-rebuild-fts-form" method="post" action="' . h(admin_url(['do' => 'rebuild_fts'])) . '" data-prompt-title="重建主题索引" data-prompt-message="请输入起始主题 ID，将重建该 ID 及之后的主题搜索索引。" data-prompt-field="start_id" data-prompt-value="1">' . form_token() . '<input type="hidden" name="start_id" value="1"><button class="admin-search-link" type="submit">重建索引</button></form>';
}
function admin_topics_tools_html(): string
{
    return '<div class="admin-topic-tools">' . admin_rebuild_fts_form() . '<a class="admin-search-link" href="' . h(admin_url(['tab' => 'trash'])) . '">回收站</a></div>';
}
function admin_pagination(string $tab, string $query, int $total, int $page, int $size, string $field = '', int $group_id = 0, int $banned_filter = -1, int $muted_filter = -1): string
{
    $params = ['tab' => $tab];
    if ($query !== '') $params['q'] = $query;
    if ($tab === 'topics' && $field !== '') $params['field'] = admin_topic_field($field);
    if ($tab === 'replies' && $field !== '') $params['reply_field'] = admin_reply_field($field);
    if ($tab === 'topics' && (int)($_GET['forum_id'] ?? 0) > 0) $params['forum_id'] = (int)$_GET['forum_id'];
    if ($tab === 'users' && $group_id > 0) $params['group_id'] = $group_id;
    if ($tab === 'users' && $banned_filter >= 0) $params['is_banned'] = $banned_filter;
    if ($tab === 'users' && $muted_filter >= 0) $params['is_muted'] = $muted_filter;
    $url = admin_url($params);
    $html = paginate($total, $page, $size, $url);
    return $html === '' ? '' : '<div class="pagination-bar">' . $html . '</div>';
}
function admin_flag(int $yes, bool $danger = false): string
{
    return '<span class="admin-flag' . ($yes ? ($danger ? ' danger' : ' on') : '') . '">' . ($yes ? '是' : '否') . '</span>';
}
function admin_list_head(string $left = '', string $right = ''): string
{
    return '<div class="admin-list-head"><div class="admin-head-inline"><div class="admin-head-left-slot">' . $left . '</div><div class="admin-head-right-slot">' . $right . '</div></div></div>';
}
function admin_object_list_html(string $tab, string $query, bool $manageable, callable $count_rows, callable $load_rows, callable $render_pagination, string $head_right = ''): string
{
    $render_row = ['users' => 'admin_user_row', 'topics' => 'admin_topic_row', 'replies' => 'admin_reply_row'][$tab] ?? throw new InvalidArgumentException('不支持的后台列表类型');
    $total = (int)$count_rows();
    $html = $manageable ? admin_bulk_delete_form_open($tab, $query) : '';
    $html .= '<div class="admin-list-panel">' . admin_list_head(admin_search_form($tab, $query), $head_right) . '<ul class="admin-manage-list">';
    foreach ($load_rows() as $row) $html .= $render_row($row, $manageable);
    $html .= '</ul></div>';
    if ($manageable) $html .= admin_bulk_delete_bar($tab);
    return $html . $render_pagination($total);
}
function admin_bulk_delete_bar(string $tab = ''): string
{
    if ($tab === 'users') $actions = '<select class="bulk-action-select" name="batch_action" form="admin-bulk-form"><option value="delete">删除</option><option value="mute">禁止发言</option><option value="unmute">取消禁止发言</option><option value="ban">禁止访问</option><option value="unban">取消禁止访问</option></select>';
    elseif ($tab === 'topics') {
        $forum_select = '<span class="bulk-forum-wrap is-hidden" data-bulk-forum-wrap><select class="bulk-action-select" name="forum_id" form="admin-bulk-form" data-bulk-forum>';
        foreach (forums_cache() as $f) $forum_select .= '<option value="' . (int)$f['id'] . '">' . h($f['name']) . '</option>';
        $forum_select .= '</select></span>';
        $actions = '<div class="bulk-action-group"><select class="bulk-action-select" name="batch_action" form="admin-bulk-form" data-bulk-action onchange="toggleBulkForum(this)"><option value="delete">删除</option><option value="move">批量转移</option></select>' . $forum_select . '</div>';
    } elseif ($tab === 'trash') $actions = '<select class="bulk-action-select" name="batch_action" form="admin-bulk-form"><option value="restore">恢复</option></select>';
    else $actions = '<select class="bulk-action-select" name="batch_action" form="admin-bulk-form"><option value="delete">删除</option></select>';
    return '<div class="bulk-bar"><label class="bulk-select-all"><input type="checkbox" data-select-all><span>全选</span></label>' . $actions . '<button class="danger bulk-delete" type="submit" form="admin-bulk-form">执行</button></div>';
}
function admin_row_check_html(int $id): string
{
    return '<input class="admin-row-check" type="checkbox" name="ids[]" value="' . $id . '" form="admin-bulk-form" aria-label="选择">';
}
function admin_user_row(array $u, bool $manageable = true): string
{
    $g = group_by_id((int)$u['group_id']) ?: ['name' => ''];
    $ops = $manageable ? '<div class="admin-inline-ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'user', 'id' => (int)$u['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'users', 'id' => (int)$u['id'], 'tab' => 'users'], 'danger', '确定删除？') . admin_row_check_html((int)$u['id']) . '</div>' : '';
    $states = array_filter([h($g['name']), '积分 ' . (int)($u['points'] ?? 0), (int)($u['is_banned'] ?? 0) ? '禁访' : '', (int)($u['is_muted'] ?? 0) ? '禁言' : '']);
    return '<li class="admin-list-item admin-object-row admin-user-row"><div class="admin-row-main"><div class="admin-user-cell">' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), 'table-avatar', (string)($u['avatar_seed'] ?? '')) . '<div class="admin-user-text"><strong>' . h($u['username']) . '</strong><span>' . implode(' / ', $states) . ' · ID ' . (int)$u['id'] . '</span></div></div></div>' . $ops . '</li>';
}
function user_state_tag_html(array $u): string
{
    $tags = [];
    if ((int)($u['is_banned'] ?? 0) === 1) $tags[] = '<span class="user-state-tag danger">禁访</span>';
    if ((int)($u['is_muted'] ?? 0) === 1) $tags[] = '<span class="user-state-tag danger">禁言</span>';
    return $tags ? '<span class="user-state-tags">' . implode('', $tags) . '</span>' : '';
}
function admin_topic_row(array $t, bool $manageable = true): string
{
    $url = route_url('topic', ['id' => (int)$t['id']]);
    $ops = $manageable ? '<div class="admin-inline-ops"><a href="' . h(route_url('topic_edit', ['id' => (int)$t['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'topics', 'id' => (int)$t['id'], 'tab' => 'topics'], 'danger', '确定删除？') . admin_row_check_html((int)$t['id']) . '</div>' : '';
    $forum = forum_by_id((int)($t['forum_id'] ?? 0)) ?: ['name' => ''];
    $forum_tag = $forum['name'] !== '' ? '<span class="admin-forum-name">' . h($forum['name']) . '</span>' : '';
    return '<li class="admin-list-item admin-object-row admin-topic-row"><div class="admin-row-main"><a class="admin-content-title" href="' . h($url) . '" target="_blank" rel="noopener">' . h($t['title']) . '</a><div class="admin-row-meta"><span class="admin-author-mini">' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), 'table-avatar', (string)($t['avatar_seed'] ?? '')) . h($t['username']) . '</span><span>ID ' . (int)$t['id'] . '</span><span>' . date('Y-m-d H:i', (int)$t['created_at']) . '</span>' . $forum_tag . '</div></div>' . $ops . '</li>';
}
function admin_reply_row(array $r, bool $manageable = true): string
{
    $topic_url = route_url('topic', ['id' => (int)$r['topic_id'], 'replyid' => (int)$r['id']]);
    $topic_title = (string)($r['topic_title'] ?? '主题已删除');
    $ops = $manageable ? '<div class="admin-inline-ops"><a href="' . h(route_url('reply_edit', ['id' => (int)$r['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'replies', 'id' => (int)$r['id'], 'tab' => 'replies'], 'danger', '确定删除？') . admin_row_check_html((int)$r['id']) . '</div>' : '';
    return '<li class="admin-list-item admin-object-row admin-reply-row"><div class="admin-row-main"><a class="admin-reply-topic-title" href="' . h($topic_url) . '" target="_blank" rel="noopener">' . h($topic_title) . '</a><div class="admin-content-text">' . h(cut($r['body'], 150)) . '</div><div class="admin-row-meta"><span class="admin-author-mini">' . avatar_tag((int)$r['user_id'], (string)$r['username'], (string)($r['avatar_style'] ?? ''), 'table-avatar', (string)($r['avatar_seed'] ?? '')) . h($r['username']) . '</span><span>回帖 #' . (int)$r['id'] . '</span><span>主题 #' . (int)$r['topic_id'] . '</span><span>' . date('Y-m-d H:i', (int)$r['created_at']) . '</span></div></div>' . $ops . '</li>';
}
function deletable_post_row(string $type, int $id): ?array
{
    if ($type === 'topics') return one("SELECT * FROM topics WHERE id=?", [$id]);
    if ($type === 'replies') return one("SELECT * FROM replies WHERE id=?", [$id]);
    return null;
}
function remember_forum(int $fid): void
{
    if (!$fid || !forum_by_id($fid)) return;
    $ids = array_values(array_diff(array_map('intval', $_SESSION['recent_forums'] ?? []), [$fid]));
    array_unshift($ids, $fid);
    $_SESSION['recent_forums'] = array_slice($ids, 0, 8);
}
function recent_forums(): array
{
    $list = [];
    foreach (array_map('intval', $_SESSION['recent_forums'] ?? []) as $fid) {
        $f = forum_by_id($fid);
        if ($f) $list[] = $f;
    }
    return $list ?: forums_cache();
}
function mark_viewed(int $tid): bool
{
    $seen = $_SESSION['viewed_topics'] ?? [];
    if (isset($seen[$tid]) && $seen[$tid] > time() - 3600) return false;
    $seen[$tid] = time();
    $_SESSION['viewed_topics'] = array_slice($seen, -200, null, true);
    return true;
}
function quick_forums_html(): string
{
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">最近浏览版块</div><ul class="quick-links quick-forum-links">';
    foreach (recent_forums() as $f) $html .= '<li><a href="' . h(route_url('forum', ['id' => (int)$f['id']])) . '"><span class="quick-link-text">' . h($f['name']) . '</span></a></li>';
    return $html . '</ul></div></div>';
}
function sidebar_notice_card_html(string $title, array $items): string
{
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">' . h($title) . '</div><ul class="quick-links notice-links">';
    foreach ($items as $item) $html .= '<li>' . h($item) . '</li>';
    return $html . '</ul></div></div>';
}
function sidebar_feature_links_html(array $ctx = []): string
{
    $links = hook('sidebar.feature_links', [], $ctx);
    if (!is_array($links) || !$links) return '';
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">快捷功能</div><ul class="quick-links feature-links">';
    $count = 0;
    foreach ($links as $key => $link) {
        if (is_array($link)) {
            $text = trim((string)($link['text'] ?? $link['title'] ?? $link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? $link['href'] ?? ''));
            $badge = trim((string)($link['badge'] ?? ''));
            $badge_dot = !empty($link['badge_dot']) || !empty($link['dot']);
            $target = !empty($link['target']) ? ' target="' . h((string)$link['target']) . '"' : '';
            $rel = !empty($link['rel']) ? ' rel="' . h((string)$link['rel']) . '"' : '';
        } elseif (is_string($key) && is_string($link)) {
            $text = trim($key);
            $url = trim($link);
            $badge = '';
            $badge_dot = false;
            $target = $rel = '';
        } else continue;
        if ($text === '' || $url === '') continue;
        $badge_html = !$badge_dot && $badge !== '' ? '<span class="notify-badge">' . h($badge) . '</span>' : '';
        $html .= '<li' . ($badge_dot ? ' class="notify-dot-link"' : '') . '><a href="' . h($url) . '"' . $target . $rel . '><span class="feature-link-text">' . h($text) . '</span>' . $badge_html . '</a></li>';
        $count++;
    }
    return $count > 0 ? $html . '</ul></div></div>' : '';
}
function mobile_menu_link_html(string $url, string $text): string
{
    return '<a class="mobile-menu-link" href="' . h($url) . '">' . h($text) . '</a>';
}
function mobile_menu_section_html(string $title, array $links): string
{
    $html = '<section class="mobile-menu-section"><h3>' . h($title) . '</h3><nav class="mobile-menu-links">';
    foreach ($links as $link) {
        $url = (string)($link['url'] ?? '');
        $text = trim((string)($link['text'] ?? ''));
        if ($url === '' || $text === '') continue;
        $html .= mobile_menu_link_html($url, $text);
    }
    return $html . '</nav></section>';
}
function mobile_menu_html(?array $mine = null): string
{
    $forum_links = [['text' => '全部版块', 'url' => route_url('home')]];
    foreach (forums_cache() as $f) {
        if (!forum_group_allowed($f, 'allow_view_groups')) continue;
        $forum_links[] = ['text' => (string)$f['name'], 'url' => route_url('forum', ['id' => (int)$f['id']])];
    }
    $my_links = [];
    if ($mine) {
        $uid = (int)$mine['id'];
        $my_links[] = ['text' => '我的主页', 'url' => route_url('user', ['id' => $uid])];
        $my_links[] = ['text' => '我的主题', 'url' => route_url('user', ['id' => $uid, 'tab' => 'topics'])];
        $my_links[] = ['text' => '我的回帖', 'url' => route_url('user', ['id' => $uid, 'tab' => 'replies'])];
        $my_links[] = ['text' => '我的收藏', 'url' => route_url('user', ['id' => $uid, 'tab' => 'favorites'])];
        $my_links[] = ['text' => '我的通知', 'url' => route_url('user', ['id' => $uid, 'tab' => 'notifications'])];
        $my_links[] = ['text' => '个人设置', 'url' => route_url('profile')];
        if (can_access_admin()) $my_links[] = ['text' => '后台面板', 'url' => route_url('admin')];
    } else {
        $my_links[] = ['text' => '登录', 'url' => route_url('login')];
        if (setting('allow_register', '1') === '1') $my_links[] = ['text' => '注册', 'url' => route_url('register')];
    }
    $quick_links = [];
    $raw_links = hook('sidebar.feature_links', [], ['is_mobile_menu' => true]);
    if (is_array($raw_links)) {
        foreach ($raw_links as $key => $link) {
            if (is_array($link)) {
                $text = trim((string)($link['text'] ?? $link['title'] ?? $link['label'] ?? ''));
                $url = trim((string)($link['url'] ?? $link['href'] ?? ''));
            } elseif (is_string($key) && is_string($link)) {
                $text = trim($key);
                $url = trim($link);
            } else {
                continue;
            }
            if ($text !== '' && $url !== '') $quick_links[] = ['text' => $text, 'url' => $url];
        }
    }
    return '<div class="mobile-menu-backdrop" id="mobile-menu" hidden><aside class="mobile-menu-drawer" id="mobile-menu-drawer" aria-label="移动端菜单"><div class="mobile-menu-head"><strong>菜单</strong><button type="button" class="mobile-menu-close" data-mobile-menu-close aria-label="关闭菜单">×</button></div><div class="mobile-menu-body">' . mobile_menu_section_html('版块列表', $forum_links) . mobile_menu_section_html('我的菜单', $my_links) . mobile_menu_section_html('快捷功能', $quick_links) . '</div></aside></div>';
}
function shell_html(string $main, string $sidebar, string $class = ''): string
{
    return '<div class="home-shell' . ($class !== '' ? ' ' . h($class) : '') . '"><div class="forum-layout"><div class="forum-main"><div class="main-panel">' . $main . '</div></div>' . $sidebar . '</div></div>';
}
function tab_bar_html(array $items, string $active, string $class = ''): string
{
    $html = '<div class="tab-bar' . ($class !== '' ? ' ' . $class : '') . '">';
    foreach ($items as $key => $item) {
        $label = is_array($item) ? (string)($item['label'] ?? '') : (string)$item;
        $href = is_array($item) ? (string)($item['href'] ?? '#') : '#';
        $extra = is_array($item) ? (string)($item['class'] ?? '') : '';
        $html .= '<a class="tab' . ($active === $key ? ' active' : '') . ($extra !== '' ? ' ' . $extra : '') . '" href="' . h($href) . '">' . $label . '</a>';
    }
    return $html . '</div>';
}
function auth_tabs_html(string $active): string
{
    return tab_bar_html([
        'login' => ['label' => '登录', 'href' => route_url('login')],
        'register' => ['label' => '注册', 'href' => route_url('register')],
    ], $active, 'auth-tabs');
}
function sidebar_stack_html(array $parts, array $ctx = []): string
{
    $filtered = hook('sidebar.stack', $parts, $ctx);
    if (is_array($filtered)) $parts = $filtered;
    $entries = array_values(array_filter([sidebar_feature_links_html($ctx)], fn($part) => $part !== ''));
    if ($entries) array_splice($parts, 1, 0, $entries);
    $html = '<aside class="sidebar">';
    foreach ($parts as $part) if ($part !== '') $html .= $part;
    return $html . '</aside>';
}
function sidebar_user_card_html(?array $m = null, bool $reply_button = false, int $fid = 0): string
{
    $m = $m ?: me();
    if (!$m) return '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big visitor-avatar">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div><div class="side-auth' . (setting('allow_register', '1') === '1' ? '' : ' single') . '"><a href="' . h(route_url('login')) . '">登录</a>' . (setting('allow_register', '1') === '1' ? '<a href="' . h(route_url('register')) . '">注册</a>' : '') . '</div></div></div>';
    $is_self = uid() && (int)$m['id'] === uid();
    $prefix = $is_self ? '我的' : 'TA的';
    $unread = $is_self ? (int)($m['unread_notifications'] ?? 0) : 0;
    $links = '<a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'topics'])) . '">' . svg_icon('topic') . $prefix . '主题</a><a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'replies'])) . '">' . svg_icon('reply') . $prefix . '回帖</a><a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'favorites'])) . '">' . svg_icon('favorite') . $prefix . '收藏</a>';
    if ($is_self) $links .= '<a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'notifications'])) . '">' . svg_icon('notify') . $prefix . '通知' . notification_badge_html($unread) . '</a><a href="' . h(route_url('profile')) . '">' . svg_icon('settings') . '个人设置</a>' . (can_access_admin() ? '<a href="' . h(route_url('admin')) . '">' . svg_icon('admin') . '后台面板</a>' : '');
    else $links .= '<a href="' . h(route_url('notify', ['id' => (int)$m['id']])) . '" onclick="openNotify(this.href);return false">' . svg_icon('notify') . '私信TA</a>';
    $user_url = route_url('user', ['id' => (int)$m['id']]);
    $rank = h($m['group_name'] ?? '用户') . ' · 积分 ' . (int)($m['points'] ?? 0);
    $html = '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><a class="user-avatar-big" href="' . $user_url . '">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</a><div><a class="user-name" href="' . $user_url . '">' . h($m['username']) . '</a><div class="user-rank">' . $rank . '</div></div></div></div><div class="user-links">' . $links . '</div></div>';
    if (can_speak()) $html .= '<a class="btn-post' . ($is_self ? '' : ' notify-link') . '" href="' . h($reply_button ? '#reply' : ($is_self ? route_url('topic_edit', ['fid' => $fid ?: null]) : route_url('notify', ['id' => (int)$m['id']]))) . '"' . ($is_self || $reply_button ? '' : ' onclick="openNotify(this.href);return false"') . '>' . ($reply_button ? '回帖' : ($is_self ? '+ 发帖' : '私信TA')) . '</a>';
    return $html . '</div>';
}
function sidebar_stats_card_html(): string
{
    $stats = stats_cache();
    $html = '<div class="card sidebar-card stats-card"><div class="stats-wrap"><div class="stats-title">站点统计</div><div class="stats-sub">主题 ' . (int)$stats['topics'] . ' · 回复 ' . (int)$stats['replies'] . ' · 用户 ' . (int)$stats['users'] . '</div><div class="new-users-title">最新用户</div><div class="new-users">';
    foreach (($stats['latest_users'] ?? []) as $u) $html .= '<a class="nu-item" href="' . h(route_url('user', ['id' => (int)$u['id']])) . '"><div class="nu-avatar-circle">' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), '', (string)($u['avatar_seed'] ?? '')) . '</div><span class="nu-name">' . h($u['username']) . '</span></a>';
    return $html . '</div></div></div>';
}
function sidebar_bio_card_html(?array $user): string
{
    if (!$user || trim((string)($user['bio'] ?? '')) === '') return '';
    return '<div class="card sidebar-card bio-card"><div class="quick-wrap"><div class="quick-title">个人简介</div><div class="sidebar-bio">' . h($user['bio']) . '</div></div></div>';
}
function topic_user_group_html(array $row): string
{
    $gid = (int)($row['group_id'] ?? 0);
    $default_gid = (int)setting('default_group_id', '2');
    if ($gid <= 0 || $gid === $default_gid) return '';
    $g = group_by_id($gid);
    return $g ? '<span class="post-user-group">' . h($g['name']) . '</span>' : '';
}
function form_shell(string $body, ?array $m = null): string
{
    return shell_html($body, sidebar_stack_html([sidebar_user_card_html($m)]));
}
function stats_cache(bool $refresh = false): array
{
    return load_array_cache(STATS_CACHE_FILE, $refresh, fn(): array => [
        'topics' => (int)val("SELECT COUNT(*) FROM topics"),
        'replies' => (int)val("SELECT COUNT(*) FROM replies"),
        'users' => (int)val("SELECT COUNT(*) FROM users"),
        'latest_users' => q("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
    ]);
}
function now(): int
{
    return time();
}
function uid(): int
{
    return (int)($_SESSION['uid'] ?? 0);
}
function is_super_user(): bool
{
    return uid() === 1;
}
function me(): ?array
{
    if (!uid()) return null;
    if (isset($GLOBALS['__me_cache']) && is_array($GLOBALS['__me_cache'])) return $GLOBALS['__me_cache'];
    $u = one("SELECT * FROM users WHERE id=?", [uid()]);
    if (!$u) return null;
    $g = group_by_id((int)$u['group_id']) ?: err('用户组不存在');
    return $GLOBALS['__me_cache'] = $u + ['group_name' => $g['name'], 'group_id' => (int)($u['group_id'] ?? 0), 'is_banned' => (int)($u['is_banned'] ?? 0), 'is_muted' => (int)($u['is_muted'] ?? 0), 'allow_manage' => (int)($g['allow_manage'] ?? 0), 'allow_admin' => (int)($g['allow_admin'] ?? 0)];
}
function can_manage(): bool
{
    if (is_super_user()) return true;
    $u = me();
    return $u && (int)($u['allow_manage'] ?? 0) === 1;
}
function can_access_admin(): bool
{
    if (is_super_user()) return true;
    $u = me();
    return $u && (int)($u['allow_admin'] ?? 0) === 1;
}
function is_muted(): bool
{
    if (is_super_user()) return false;
    $u = me();
    return $u && !can_access_admin() && (int)$u['is_muted'] === 1;
}
function can_speak(): bool
{
    if (!uid() || is_muted()) return false;
    return hook('user.can_speak', true, ['user' => me()]) === true;
}
function set_auth_return_url(string $url): void
{
    if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) $_SESSION['auth_return_url'] = $url;
}
function consume_auth_return_url(): string
{
    $url = (string)($_SESSION['auth_return_url'] ?? '');
    unset($_SESSION['auth_return_url']);
    return $url !== '' && !preg_match('/^https?:\/\//i', $url) ? $url : route_url('home');
}
function complete_login(int $user_id): never
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $user_id;
    go(consume_auth_return_url());
}
function need_login(): void
{
    if (!uid()) go(route_url('login'));
}
function need_speak(): void
{
    need_login();
    $allowed = hook('user.can_speak', true, ['user' => me()]);
    if ($allowed !== true) ajax_request() ? ajax_error(is_string($allowed) ? $allowed : '禁止发言') : err(is_string($allowed) ? $allowed : '禁止发言');
    if (is_muted()) ajax_request() ? ajax_error('禁止发言') : err('禁止发言');
}
function need_admin(): void
{
    need_login();
    if (!can_access_admin()) err('无权限');
}
function need_manage(): void
{
    need_login();
    if (!can_manage()) err('无权限');
}
function need_site_access(): void
{
    if (!db_schema_ready()) simple_error_page('请先运行 install.php 进行安装');
    if (!is_super_user() && me() && !can_access_admin() && (int)me()['is_banned'] === 1 && ($_GET['a'] ?? '') !== 'logout') err('当前用户禁止访问');
    $a = $_GET['a'] ?? 'home';
    if (setting('site_closed') === '1' && !can_access_admin() && !in_array($a, ['login', 'logout', 'forgot_password', 'reset_password', 'two_factor', 'form_error', 'plugin_share_receive', 'plugin_market_feed', 'robots.txt', 'sitemap.xml', 'favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'], true)) err('网站已关闭');
}
function check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['a'] ?? '') === 'plugin_share_receive') return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        ajax_request() ? ajax_error('请求已过期') : err('请求已过期');
    }
}
function ajax_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}
function set_flash(string $message): void
{
    setcookie('__flash', $message, [
        'expires' => time() + 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function form_error_redirect(string $message): never
{
    $_SESSION['form_error'] = [
        'message' => $message,
        'created_at' => time(),
    ];
    go(route_url('form_error'));
}
function ajax_error(string $m, bool $log = true): never
{
    if ($log) debug_log_write($m);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => 0, 'message' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}
function simple_error_page(string $m): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>错误</title><style>body{margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fb;color:#222;font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}.box{max-width:420px;padding:28px 24px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.06)}</style></head><body><div class="box">' . h($m) . '</div></body></html>';
    exit;
}
function go(string $u): never
{
    if (ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'redirect' => $u], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: $u");
    exit;
}
function error_page(string $title, string $message, int $status = 200): never
{
    if ($status > 0) http_response_code($status);
    $message = trim($message);
    $body = '<div class="form-panel form-error-panel"><h2>' . h($title) . '</h2><p>' . h($message !== '' ? $message : $title) . '</p></div>';
    page($title, shell_html($body, sidebar_stack_html([sidebar_user_card_html()])));
    exit;
}
function database_error(Throwable $e): bool
{
    do {
        if ($e instanceof PDOException) return true;
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'sqlite') || str_contains($message, 'sqlstate') || str_contains($message, 'database')) return true;
        $e = $e->getPrevious();
    } while ($e);
    return false;
}
function err(string $m): never
{
    debug_log_write($m);
    if (ajax_request()) ajax_error($m, false);
    if (!is_file(INSTALL_LOCK_FILE)) simple_error_page($m);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') form_error_redirect($m);
    error_page('错误', $m);
}
function not_found(string $m): never
{
    if (ajax_request()) ajax_error($m, false);
    if (!is_file(INSTALL_LOCK_FILE)) simple_error_page($m);
    error_page('404', $m, 404);
}
function cut(string $v, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
function human_time(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 172800) return '昨天';
    if ($diff < 604800) return floor($diff / 86400) . '天前';
    return date('Y-m-d', $ts);
}
function paginate(int $total, int $page, int $size, string $url): string
{
    $pages = max(1, (int)ceil($total / $size));
    if ($pages <= 1) return '';
    $page = max(1, min($page, $pages));
    $page_url = fn(int $n): string => append_url_query($url, ['p' => $n]);
    $h = '<div class="pagination"><ul>';
    if ($page > 1) $h .= '<li><a href="' . h($page_url($page - 1)) . '">上一页</a></li>';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $h .= '<li><a href="' . h($page_url(1)) . '">1</a></li>';
        if ($start > 2) $h .= '<li><span class="ellipsis">...</span></li>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $h .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . h($page_url($i)) . '">' . $i . '</a></li>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $h .= '<li><span class="ellipsis">...</span></li>';
        $h .= '<li><a href="' . h($page_url($pages)) . '">' . $pages . '</a></li>';
    }
    if ($page < $pages) $h .= '<li><a href="' . h($page_url($page + 1)) . '">下一页</a></li>';
    $h .= '</ul></div>';
    return $h;
}
function simple_paginate(bool $has_prev, bool $has_next, int $page, string $url): string
{
    if (!$has_prev && !$has_next) return '';
    $page_url = fn(int $n): string => append_url_query($url, ['p' => $n]);
    $h = '<div class="pagination"><ul>';
    if ($has_prev) $h .= '<li><a href="' . h($page_url(max(1, $page - 1))) . '">上一页</a></li>';
    $h .= '<li class="active"><a href="' . h($page_url($page)) . '">' . $page . '</a></li>';
    if ($has_next) $h .= '<li><a href="' . h($page_url($page + 1)) . '">下一页</a></li>';
    return $h . '</ul></div>';
}
function user_reply_topics_page(int $user_id, int $page, int $size): array
{
    $need = $page * $size + 1;
    $scan_limit = min(max($need * 3, $size + 1), 1000);
    $offset = 0;
    $topics = [];
    do {
        $rows = q("SELECT topic_id,created_at,id FROM replies WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", [$user_id, $scan_limit, $offset])->fetchAll();
        foreach ($rows as $row) {
            $tid = (int)$row['topic_id'];
            if (isset($topics[$tid])) continue;
            $topics[$tid] = ['id' => $tid, 'my_reply_at' => (int)$row['created_at'], 'my_reply_id' => (int)$row['id']];
            if (count($topics) >= $need) break 2;
        }
        $offset += $scan_limit;
    } while (count($rows) === $scan_limit);
    $slice = array_slice($topics, ($page - 1) * $size, $size, true);
    return [$slice, count($topics) > $page * $size];
}
function topic_page_links(int $topic_id, int $reply_count): string
{
    $size = max(1, (int)setting('replies_per_page', '50'));
    $pages = (int)ceil($reply_count / $size);
    if ($pages <= 1) return '';
    $nums = [];
    foreach ([2, 3, $pages - 2, $pages - 1, $pages] as $n) if ($n >= 2 && $n <= $pages) $nums[$n] = true;
    $nums = array_keys($nums);
    sort($nums);
    $h = '<span class="topic-pages">' . svg_icon('pages');
    $prev = 1;
    foreach ($nums as $i) {
        if ($i - $prev > 1) $h .= '<span class="topic-pages-sep">…</span>';
        $h .= '<a href="' . h(route_url('topic', ['id' => $topic_id, 'p' => $i])) . '">' . $i . '</a>';
        $prev = $i;
    }
    return $h . '</span>';
}
function post(string $k, int $max = 0): string
{
    $v = trim((string)($_POST[$k] ?? ''));
    return $max ? cut($v, $max) : $v;
}
function id(string $k = 'id'): int
{
    return max(0, (int)($_GET[$k] ?? $_POST[$k] ?? 0));
}
function form_token(): string
{
    return '<input type="hidden" name="_csrf" value="' . h($_SESSION['csrf'] ??= bin2hex(random_bytes(16))) . '">';
}
function hidden_inputs(array $fields): string
{
    $html = '';
    foreach ($fields as $name => $value) {
        if ($value === null) continue;
        $html .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    return $html;
}
function post_action_form(string $action, string $label, array $fields = [], string $class = '', string $confirm = ''): string
{
    $confirm_attr = $confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '';
    return '<form class="post-action-form" method="post" action="' . h($action) . '"' . $confirm_attr . '>' . form_token() . hidden_inputs($fields) . '<button type="submit"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . h($label) . '</button></form>';
}
function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('请求方式错误');
}
function svg_icon(string $name): string
{
    $icons = [
        'user' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 21c1.8-4 4.5-6 8-6s6.2 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'reply' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
        'notify' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 18.5a2.5 2.5 0 0 0 2.4-1.8H9.6a2.5 2.5 0 0 0 2.4 1.8Zm7-4.5-1.6-1.9V10a5.4 5.4 0 0 0-4.4-5.3V4a1 1 0 1 0-2 0v.7A5.4 5.4 0 0 0 6.6 10v2.1L5 14v1h14z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
        'forum' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5h16v14H4z" stroke="currentColor" stroke-width="2"/><path d="M8 9h8M8 13h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'topic' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4h14v16H5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'view' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>',
        'favorite' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
        'favorite_fill' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/></svg>',
        'settings' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm8.5 3.5-.9-.5c-.3-.2-.4-.6-.3-.9l.8-1.4-1.8-1.8-1.4.8c-.3.2-.7.1-.9-.3l-.5-.9h-2l-.5.9c-.2.4-.6.5-.9.3l-1.4-.8-1.8 1.8.8 1.4c.2.3.1.7-.3.9l-.9.5v2l.9.5c.3.2.4.6.3.9l-.8 1.4 1.8 1.8 1.4-.8c.3-.2.7-.1.9.3l.5.9h2l.5-.9c.2-.4.6-.5.9-.3l1.4.8 1.8-1.8-.8-1.4c-.2-.3-.1-.7.3-.9l.9-.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
        'admin' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 7.8 8 9 4.6-1.2 8-4 8-9V6l-8-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 12l2 2 4-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'pages' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 4h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9.5 9h6M9.5 12.5h6M9.5 16h3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    ];
    return $icons[$name] ?? '';
}
function avatar_styles(): array
{
    return [
        'dylan' => 'Dylan',
        'big-ears' => 'Big Ears',
        'big-ears-neutral' => 'Big Ears Neutral',
        'big-smile' => 'Big Smile',
        'disco' => 'Disco',
        'lorelei' => 'Lorelei',
        'lorelei-neutral' => 'Lorelei Neutral',
        'pixel-art' => 'Pixel Art',
        'pixel-art-neutral' => 'Pixel Art Neutral',
        'adventurer' => 'Adventurer',
        'adventurer-neutral' => 'Adventurer Neutral',
        'avataaars' => 'Avataaars',
        'avataaars-neutral' => 'Avataaars Neutral',
        'bottts' => 'Bottts',
        'bottts-neutral' => 'Bottts Neutral',
        'croodles' => 'Croodles',
        'croodles-neutral' => 'Croodles Neutral',
        'fun-emoji' => 'Fun Emoji',
        'glass' => 'Glass',
        'glyphs' => 'Glyphs',
        'icons' => 'Icons',
        'identicon' => 'Identicon',
        'initial-face' => 'Initial Face',
        'initials' => 'Initials',
        'micah' => 'Micah',
        'miniavs' => 'Miniavs',
        'notionists' => 'Notionists',
        'notionists-neutral' => 'Notionists Neutral',
        'open-peeps' => 'Open Peeps',
        'personas' => 'Personas',
        'rings' => 'Rings',
        'shape-grid' => 'Shape Grid',
        'shapes' => 'Shapes',
        'stripes' => 'Stripes',
        'thumbs' => 'Thumbs',
        'toon-head' => 'Toon Head',
        'triangles' => 'Triangles',
    ];
}
function avatar_style(string $style): string
{
    if ($style === '') return '';
    $styles = avatar_styles();
    return isset($styles[$style]) ? $style : 'dylan';
}
function avatar_seed_count(string $style): int
{
    return 48;
}
function decimal_mod(string $number, int $divisor): int
{
    $mod = 0;
    foreach (str_split($number) as $digit) $mod = ($mod * 10 + (int)$digit) % $divisor;
    return $mod;
}
function avatar_seed(string $style, string $seed, int $uid = 0): string
{
    $count = max(1, avatar_seed_count($style));
    $seed = trim($seed);
    if ($seed === '') $seed = (string)$uid;
    if (ctype_digit($seed)) {
        $mod = decimal_mod($seed, $count);
        return (string)($mod === 0 ? $count : $mod);
    }
    $hash = (string)sprintf('%u', crc32($seed));
    $mod = decimal_mod($hash, $count);
    return (string)($mod === 0 ? $count : $mod);
}
function avatar_remote_url(string $style, string $seed): string
{
    return 'https://api.dicebear.com/10.x/' . rawurlencode($style) . '/svg?seed=' . rawurlencode($seed);
}
function avatar_file_name(string $style, string $seed): string
{
    $seed = preg_replace('/[^A-Za-z0-9._-]/', '_', $seed) ?? '';
    return $style . '_' . ($seed === '' ? '0' : $seed) . '.svg';
}
function avatar_mirror_styles(?string $text = null): array
{
    static $cache = null;
    if ($text === null && $cache !== null) return $cache;
    $styles = [];
    foreach (preg_split('/[\s,，]+/u', (string)($text ?? setting('avatar_mirror_styles', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $style) {
        $style = avatar_style(trim($style));
        if ($style !== '') $styles[$style] = true;
    }
    $styles = array_values(array_filter(array_keys(avatar_styles()), fn($style) => !empty($styles[$style])));
    return $text === null ? $cache = $styles : $styles;
}
function avatar_mirror_styles_text(?string $text = null, ?string $add_style = null): string
{
    $styles = array_fill_keys(avatar_mirror_styles($text), true);
    if ($add_style !== null) {
        $style = avatar_style($add_style);
        if ($style !== '') $styles[$style] = true;
    }
    return implode(',', array_values(array_filter(array_keys(avatar_styles()), fn($style) => !empty($styles[$style]))));
}
function avatar_style_mirrored(string $style): bool
{
    return in_array($style, avatar_mirror_styles(), true);
}
function local_avatar_url(string $style, string $seed, string $remote): string
{
    return avatar_style_mirrored($style) ? asset_url('avatars/' . avatar_file_name($style, $seed)) : $remote;
}
function cache_avatar_url(string $style, string $seed): string
{
    $style = avatar_style($style) ?: 'dylan';
    $seed = avatar_seed($style, $seed);
    $remote = avatar_remote_url($style, $seed);
    if (!is_dir(AVATAR_DIR) && !mkdir(AVATAR_DIR, 0755, true)) return $remote;
    $file = AVATAR_DIR . '/' . avatar_file_name($style, $seed);
    if (is_file($file)) return asset_url('avatars/' . basename($file));
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    $response = remote_http_request($remote, 5, ['Accept: image/svg+xml,image/*;q=0.9,*/*;q=0.1']);
    if (!$response['ok']) return $remote;
    $svg = (string)$response['body'];
    if (!is_string($svg) || $svg === '' || stripos($svg, '<svg') === false) return $remote;
    if (@file_put_contents($tmp, $svg, LOCK_EX) === false) return $remote;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return $remote;
    }
    return asset_url('avatars/' . basename($file));
}
function avatar_mirror_page(): void
{
    need_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 0, 'message' => '只允许POST'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $style = avatar_style((string)($_REQUEST['style'] ?? '')) ?: 'dylan';
    $seed = avatar_seed($style, (string)($_REQUEST['seed'] ?? ''));
    if (($_POST['complete'] ?? '') === '1') {
        $text = avatar_mirror_styles_text(setting('avatar_mirror_styles', ''), $style);
        save_settings_values(['avatar_mirror_styles' => $text]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'style' => $style, 'styles' => setting('avatar_mirror_styles', '')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $url = cache_avatar_url($style, $seed);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => str_starts_with($url, asset_url('avatars/')) ? 1 : 0, 'url' => $url, 'style' => $style, 'seed' => $seed], JSON_UNESCAPED_UNICODE);
    exit;
}
function avatar_tag(int $uid, string $name, string $style = '', string $class = '', string $seed = ''): string
{
    $classes = trim('avatar-img ' . $class);
    $style = avatar_style($style) ?: 'dylan';
    $seed = avatar_seed($style, $seed, $uid);
    $remote = avatar_remote_url($style, $seed);
    $src = local_avatar_url($style, $seed, $remote);
    return '<img class="' . h($classes) . '" src="' . h($src) . '" alt="' . h($name) . '" loading="lazy">';
}
function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    $base = ($dir === '' || $dir === '.') ? '' : $dir;
    if ($path === '') return $base === '' ? '/' : $base . '/';
    return $base . '/' . $path;
}
function append_url_query(string $url, array $params): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') continue;
        $query[$key] = (string)$value;
    }
    if (!$query) return $url;
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function index_url(array $params = []): string
{
    return append_url_query(app_url('index.php'), $params);
}
function admin_url(array $params = []): string
{
    return route_url('admin', $params);
}
function route_url(string $a = 'home', array $params = []): string
{
    if (setting('pretty_url', '0') !== '1') return $a === 'home' ? index_url($params) : index_url(['a' => $a] + $params);
    if ($a === 'home') return $params ? index_url($params) : app_url();
    $params = $a === 'home' ? $params : ['a' => $a] + $params;
    $segments = [];
    if (isset($params['a']) && $params['a'] !== '') {
        $segments[] = rawurlencode((string)$params['a']);
        unset($params['a']);
    }
    if (isset($params['id']) && ctype_digit((string)$params['id'])) {
        $segments[] = rawurlencode((string)$params['id']);
        unset($params['id']);
    }
    return append_url_query(app_url(implode('/', $segments)), $params);
}
function asset_url(string $file): string
{
    return app_url($file);
}
function markdown_link_text(string $text): string
{
    return str_replace([']', '['], ['\]', '\['], $text);
}
function upload_image_ext(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}
function upload_detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            if (is_string($mime) && $mime !== '') return strtolower($mime);
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') return strtolower($mime);
    }
    return '';
}
function upload_image_mime(string $ext): string
{
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => '',
    };
}
function upload_image_valid(string $path, string $ext, string $mime): bool
{
    $expected = upload_image_mime($ext);
    if ($expected === '' || $mime !== $expected) return false;
    $info = @getimagesize($path);
    if (!is_array($info) || (string)($info['mime'] ?? '') !== $expected) return false;
    if (!function_exists('imagecreatefromstring')) return true;
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') return false;
    $image = @imagecreatefromstring($bytes);
    if (!$image) return false;
    return true;
}
function upload_allowed_ext(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp3', 'mp4', 'mov'], true);
}
function upload_hash_dir(string $hash): string
{
    return substr($hash, 0, 2);
}
function attachment_max_count(): int
{
    return max(0, (int)setting('attachment_max_count', '10'));
}
function attachment_max_mb(): int
{
    return max(0, (int)setting('attachment_max_mb', '20'));
}
function attachment_max_bytes(): int
{
    return attachment_max_mb() * 1024 * 1024;
}
function attachment_quota_bytes(?array $user = null): int
{
    $user = $user ?: me();
    if (!$user) return 0;
    $group = group_by_id((int)($user['group_id'] ?? 0));
    return max(0, (int)($group['upload_quota_mb'] ?? 0)) * 1024 * 1024;
}
function attachment_used_bytes(int $user_id): int
{
    return max(0, (int)(val("SELECT COALESCE(SUM(size),0) FROM attachments WHERE user_id=?", [$user_id]) ?: 0));
}
function attachment_require_quota(int $user_id, int $size): void
{
    $quota = attachment_quota_bytes(user_by_id($user_id));
    if ($quota <= 0) return;
    if (attachment_used_bytes($user_id) + $size > $quota) err('上传空间已达用户组上限');
}
function attachment_record(int $user_id, string $hash, string $file_name, string $original, string $ext, string $mime, int $size, bool $is_image): void
{
    q("INSERT INTO attachments(user_id,hash,file_name,original_name,ext,mime,size,is_image,created_at) VALUES(?,?,?,?,?,?,?,?,?)", [$user_id, $hash, $file_name, $original, $ext, $mime, $size, $is_image ? 1 : 0, now()]);
}
function format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
function attachment_row_html(array $file): string
{
    $name = trim((string)($file['original_name'] ?? '')) ?: (string)($file['file_name'] ?? '附件');
    $file_name = (string)($file['file_name'] ?? '');
    $url = route_url('attachment', ['f' => $file_name, 'name' => $name]);
    $kind = (int)($file['is_image'] ?? 0) ? '图片' : '文件';
    $ext = strtoupper((string)($file['ext'] ?? ''));
    $mime = (string)($file['mime'] ?? '');
    $meta = '<span>' . h($kind) . '</span><span>' . h(format_bytes((int)($file['size'] ?? 0))) . '</span>';
    if ($mime !== '') $meta .= '<span>' . h($mime) . '</span>';
    $meta .= '<span>' . h(human_time((int)($file['created_at'] ?? 0))) . '</span>';
    $action = (int)($file['is_image'] ?? 0) ? '查看' : '下载';
    return '<li class="profile-file-row"><div class="profile-file-icon">' . h($ext !== '' ? $ext : 'FILE') . '</div><div class="profile-file-main"><a class="profile-file-name" href="' . h($url) . '">' . h($name) . '</a><div class="profile-file-meta">' . $meta . '</div></div><a class="profile-file-download" href="' . h($url) . '">' . $action . '</a></li>';
}
function attachment_summary_html(int $total, int $used_bytes, int $quota_bytes): string
{
    $percent = $quota_bytes > 0 ? min(100, max(0, (int)round($used_bytes * 100 / $quota_bytes))) : 0;
    $quota_text = $quota_bytes > 0 ? format_bytes($quota_bytes) : '不限';
    return '<div class="profile-file-summary"><span class="profile-file-usage"><span class="profile-file-usage-head"><span>空间已用 ' . h(format_bytes($used_bytes)) . ' 总空间 ' . h($quota_text) . '</span>' . ($quota_bytes > 0 ? '<span>' . $percent . '%</span>' : '') . '</span><span class="profile-file-progress"><span style="width:' . $percent . '%"></span></span></span></div>';
}
function upload_attachment_markdown(array $file): string
{
    if (attachment_max_count() <= 0 || attachment_max_mb() <= 0) err('附件上传已关闭');
    $user_id = uid();
    if ($user_id <= 0) err('请先登录');
    $allowed = hook('attachment.before_upload', true, ['user_id' => $user_id]);
    if ($allowed !== true) err(is_string($allowed) ? $allowed : '禁止上传附件');
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) err('请选择附件');
    if ($error !== UPLOAD_ERR_OK) err('附件上传失败');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) err('附件不能为空');
    if ($size > attachment_max_bytes()) err('单个附件不能超过' . attachment_max_mb() . 'MB');
    attachment_require_quota($user_id, $size);
    $original = trim(preg_replace('/[\r\n]+/', ' ', basename((string)($file['name'] ?? ''))) ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '' || !upload_allowed_ext($ext)) err('附件格式不允许');
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) err('附件保存失败');
    $tmp = (string)$file['tmp_name'];
    $mime = upload_detect_mime($tmp);
    if ($mime === '') err('附件类型无法识别');
    $is_image = upload_image_ext($ext);
    if ($is_image && !upload_image_valid($tmp, $ext, $mime)) err('图片文件校验失败');
    if (!$is_image && str_starts_with($mime, 'image/')) err('附件格式与内容不一致');
    $hash = hash_file('sha256', (string)$file['tmp_name']);
    if (!is_string($hash) || $hash === '') err('附件保存失败');
    $hash_dir = upload_hash_dir($hash);
    $dir = UPLOAD_DIR . '/' . $hash_dir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) err('附件目录不可写');
    $name = $hash . ($is_image ? '.' . $ext : '.attach');
    $target = $dir . '/' . $name;
    if (!is_file($target) && !move_uploaded_file((string)$file['tmp_name'], $target)) err('附件保存失败');
    attachment_record($user_id, $hash, $name, $original, $ext, $mime, $size, $is_image);
    $label = markdown_link_text($original !== '' ? $original : $name);
    if ($is_image) return '![' . $label . '](' . base_url() . asset_url('upload/' . $hash_dir . '/' . $name) . ')';
    return '[' . $label . '](' . base_url() . route_url('attachment', ['f' => $name, 'name' => $original !== '' ? $original : '附件.' . $ext]) . ')';
}
function attachment_upload_page(): void
{
    require_post();
    need_speak();
    ob_start();
    try {
        $markdown = upload_attachment_markdown(is_array($_FILES['attachment'] ?? null) ? $_FILES['attachment'] : []);
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'markdown' => $markdown], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        debug_log_write('附件上传失败', $e);
        ajax_error(database_error($e) ? '数据库出了点小问题' : ($e->getMessage() ?: '附件上传失败'));
    }
}
function topic_upload_attachments_markdown(): string
{
    $files = $_FILES['attachments'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) return '';
    $items = [];
    $count = count((array)$files['name']);
    $max_count = attachment_max_count();
    if ($max_count <= 0) {
        foreach ((array)$files['error'] as $error) if ((int)$error !== UPLOAD_ERR_NO_FILE) err('附件上传已关闭');
        return '';
    }
    if ($count > $max_count) err('附件最多上传' . $max_count . '个');
    for ($i = 0; $i < $count; $i++) {
        if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $items[] = upload_attachment_markdown([
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ]);
    }
    return $items ? "\n\n附件：\n" . implode("\n", $items) : '';
}
function attachment_page(): void
{
    $file = (string)($_GET['f'] ?? '');
    if (preg_match('/^[a-f0-9]{64}\.(?:attach|jpe?g|png|gif|webp)$/', $file) !== 1) not_found('附件不存在');
    $hash = substr($file, 0, 64);
    $path = UPLOAD_DIR . '/' . upload_hash_dir($hash) . '/' . $file;
    if (!is_file($path)) not_found('附件不存在');
    $name = trim(preg_replace('/[\r\n"\\\\\/]+/', ' ', basename((string)($_GET['name'] ?? '附件'))) ?? '');
    if ($name === '') $name = '附件';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $is_image = upload_image_ext($ext);
    header('Content-Type: ' . ($is_image ? upload_image_mime($ext) : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($is_image ? 'inline' : 'attachment') . '; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
function parse_path_route(): void
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
    $path = trim($path, '/');
    if ($path === '' || $path === basename($script)) return;
    $segments = array_values(array_filter(explode('/', $path), 'strlen'));
    if (isset($segments[0]) && $segments[0] !== 'a' && !array_key_exists('a', $_GET)) $_GET['a'] = rawurldecode($segments[0]);
    if (isset($segments[1]) && ctype_digit($segments[1]) && !array_key_exists('id', $_GET)) $_GET['id'] = rawurldecode($segments[1]);
}
function markdown_inline(string $text): string
{
    $text = h($text);
    $codes = [];
    $clean_url = fn($url) => html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace_callback('/`([^`\n]+)`/u', function ($m) use (&$codes) {
        $key = "\x1A" . count($codes) . "\x1A";
        $codes[$key] = '<code>' . $m[1] . '</code>';
        return $key;
    }, $text) ?? $text;
    $text = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace_callback('/!\[([^\]\n]*)\]\((https?:\/\/[^\s)<]+)\)/u', function ($m) use (&$codes) {
        $key = "\x1A" . count($codes) . "\x1A";
        $url = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $codes[$key] = '<img src="' . h($url) . '" alt="' . $m[1] . '" loading="lazy">';
        return $key;
    }, $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)<]+)\)/u', function ($m) use ($clean_url) {
        return '<a href="' . h($clean_url($m[2])) . '" target="_blank" rel="nofollow noopener">' . $m[1] . '</a>';
    }, $text) ?? $text;
    $text = preg_replace_callback('/@([^\s@#<]{1,32})\s+#(t?)(\d+)/u', function ($m) {
        $is_topic = $m[2] === 't';
        $url = $is_topic ? route_url('topic', ['id' => (int)$m[3]]) : route_url('topic', ['replyid' => (int)$m[3]]);
        return '<a href="' . $url . '" target="_blank" rel="noopener">@' . $m[1] . ' #' . ($is_topic ? 't' : '') . (int)$m[3] . '</a>';
    }, $text) ?? $text;
    $text = preg_replace_callback('/(?<!["\'>=])(https?:\/\/[^\s<]+)/u', function ($m) {
        $raw_url = rtrim($m[1], '.,;:!?');
        $url = html_entity_decode($raw_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tail = substr($m[1], strlen($raw_url));
        return '<a href="' . h($url) . '" target="_blank" rel="nofollow noopener">' . h($url) . '</a>' . h($tail);
    }, $text) ?? $text;
    return strtr($text, $codes);
}
function markdown_table_cells(string $line): array
{
    $line = trim($line);
    if (str_starts_with($line, '|')) $line = substr($line, 1);
    if (str_ends_with($line, '|')) $line = substr($line, 0, -1);
    return array_map(fn($cell) => trim(str_replace('\\|', '|', $cell)), preg_split('/(?<!\\\\)\|/u', $line) ?: []);
}
function markdown_table_aligns(string $line): ?array
{
    $cells = markdown_table_cells($line);
    if (!$cells) return null;
    $aligns = [];
    foreach ($cells as $cell) {
        $cell = trim($cell);
        if (!preg_match('/^:?-{3,}:?$/', $cell)) return null;
        $left = str_starts_with($cell, ':');
        $right = str_ends_with($cell, ':');
        $aligns[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
    }
    return $aligns;
}
function markdown_table_html(array $lines): string
{
    if (count($lines) < 2) return '';
    $aligns = markdown_table_aligns($lines[1]);
    if ($aligns === null) return '';
    $headers = markdown_table_cells($lines[0]);
    if (!$headers || count($headers) !== count($aligns)) return '';
    $cell_attr = fn(string $align) => $align !== '' ? ' style="text-align:' . $align . '"' : '';
    $html = '<div class="markdown-table-wrap"><table><thead><tr>';
    foreach ($headers as $i => $cell) $html .= '<th' . $cell_attr((string)($aligns[$i] ?? '')) . '>' . markdown_inline($cell) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach (array_slice($lines, 2) as $line) {
        if (trim($line) === '') continue;
        $cells = markdown_table_cells($line);
        if (!$cells) continue;
        $html .= '<tr>';
        for ($i = 0, $count = count($headers); $i < $count; $i++) {
            $html .= '<td' . $cell_attr((string)($aligns[$i] ?? '')) . '>' . markdown_inline((string)($cells[$i] ?? '')) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}
function markdown_html(string $text): string
{
    $text = (string)hook('markdown.before', $text);
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return '';
    $html = [];
    $paragraph = [];
    $code = [];
    $code_lang = '';
    $in_code = false;
    $code_block_html = function (array $lines, string $lang = ''): string {
        $lang = strtolower(trim($lang));
        $class = preg_match('/^[a-z0-9_-]{1,32}$/', $lang) ? ' class="language-' . h($lang) . '"' : '';
        return '<pre><code' . $class . '>' . h(rtrim(implode("\n", $lines), "\n")) . '</code></pre>';
    };
    $flush = function () use (&$html, &$paragraph) {
        $block = trim(implode("\n", $paragraph));
        $paragraph = [];
        if ($block === '') return;
        $lines = explode("\n", $block);
        $render_plain = function (array $lines): string {
            $block = trim(implode("\n", $lines));
            if ($block === '') return '';
            if (count($lines) === 1 && preg_match('/^(#{1,6})\s+(.+)$/u', $lines[0], $m)) {
                $level = strlen($m[1]);
                return '<h' . $level . '>' . markdown_inline($m[2]) . '</h' . $level . '>';
            }
            $table = markdown_table_html($lines);
            if ($table !== '') return $table;
            if (count($lines) > 1 && preg_match('/^\s*[-*]\s+/', $lines[0])) {
                $items = '';
                foreach ($lines as $line) if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $m)) $items .= '<li>' . markdown_inline($m[1]) . '</li>';
                if ($items !== '') return '<ul>' . $items . '</ul>';
            }
            return '<p>' . str_replace("\n", '<br>', markdown_inline($block)) . '</p>';
        };
        $has_quote = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*>\s?/u', $line)) {
                $has_quote = true;
                break;
            }
        }
        if (!$has_quote) {
            $html[] = $render_plain($lines);
            return;
        }
        $chunk = [];
        $quote = null;
        $append_chunk = function () use (&$html, &$chunk, &$quote, $render_plain) {
            if (!$chunk) return;
            if ($quote) {
                $inner = trim(implode("\n", array_map(fn($line) => preg_replace('/^\s*>\s?/u', '', $line) ?? $line, $chunk)));
                $html[] = '<blockquote>' . ($inner === '' ? '' : markdown_html($inner)) . '</blockquote>';
            } else {
                $html[] = $render_plain($chunk);
            }
            $chunk = [];
        };
        foreach ($lines as $line) {
            $is_quote = preg_match('/^\s*>\s?/u', $line) === 1;
            if ($quote !== null && $is_quote !== $quote) $append_chunk();
            $quote = $is_quote;
            $chunk[] = $line;
        }
        $append_chunk();
    };
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*```\s*([\w-]*)\s*$/u', $line, $m)) {
            if ($in_code) {
                $html[] = $code_block_html($code, $code_lang);
                $code = [];
                $code_lang = '';
                $in_code = false;
            } else {
                $flush();
                $code_lang = (string)($m[1] ?? '');
                $in_code = true;
            }
            continue;
        }
        if ($in_code) {
            $code[] = $line;
            continue;
        }
        if (trim($line) === '') {
            $flush();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $m)) {
            $flush();
            $level = strlen($m[1]);
            $html[] = '<h' . $level . '>' . markdown_inline($m[2]) . '</h' . $level . '>';
            continue;
        }
        $paragraph[] = $line;
    }
    if ($in_code) $html[] = $code_block_html($code, $code_lang);
    else $flush();
    return (string)hook('markdown.after', implode('', $html), ['text' => $text]);
}
function avatar_picker_html(array $u): string
{
    $uid = (int)$u['id'];
    $style = avatar_style((string)($u['avatar_style'] ?? ''));
    $seed = (string)($u['avatar_seed'] ?? '');
    if ($seed !== '') $seed = avatar_seed($style ?: 'dylan', $seed, $uid);
    $name = (string)($u['username'] ?? '');
    $mirror_styles = avatar_mirror_styles();
    $local_only = !empty($mirror_styles);
    $styles = avatar_styles();
    if ($local_only) {
        $styles = array_filter($styles, fn($v, $k) => in_array($k, $mirror_styles, true), ARRAY_FILTER_USE_BOTH);
        if (!isset($styles[$style])) $style = (string)array_key_first($styles);
        if ($seed === '') $seed = avatar_seed($style ?: 'dylan', (string)$uid, $uid);
    }
    $seeds = array_map('strval', range(1, avatar_seed_count($style ?: 'dylan')));
    $html = '<div class="grid avatar-field"><span>头像设置</span><div class="avatar-picker" data-seed="' . $uid . '" data-avatar-base="' . h(asset_url('avatars/')) . '" data-avatar-mirror-styles="' . h(setting('avatar_mirror_styles', '')) . '" data-avatar-local-only="' . ($local_only ? '1' : '0') . '"><div class="avatar-picker-head"><div class="avatar-picker-preview">' . avatar_tag($uid, $name, $style, '', $seed) . '</div><select name="avatar_style">';
    if (!$local_only) $html .= '<option value=""' . ($style === '' ? ' selected' : '') . '>默认 Dylan</option>';
    foreach ($styles as $k => $v) $html .= '<option value="' . h($k) . '"' . ($k === $style ? ' selected' : '') . '>' . h($v) . '</option>';
    $html .= '</select></div><input type="hidden" name="avatar_seed" value="' . h($seed) . '"><div class="avatar-options">';
    if (!$local_only) $html .= '<button class="avatar-option' . ($seed === '' ? ' active' : '') . '" type="button" data-seed="">' . avatar_tag($uid, $name, $style, '', '') . '</button>';
    foreach ($seeds as $s) $html .= '<button class="avatar-option' . ($s === $seed ? ' active' : '') . '" type="button" data-seed="' . h($s) . '">' . avatar_tag($uid, $name, $style, '', $s) . '</button>';
    return $html . '</div></div></div>';
}
function topic_post_row(array $row, string $body, int $time, string $ops = '', string $title = '', string $stats = '', bool $highlight = false, array $ctx = []): string
{
    $is_reply = isset($row['topic_id']);
    $filtered = hook($is_reply ? 'reply.before_render' : 'topic.before_render', ['row' => $row, 'body' => $body], ['time' => $time]);
    if (is_array($filtered)) {
        if (isset($filtered['row']) && is_array($filtered['row'])) $row = $filtered['row'];
        if (isset($filtered['body'])) $body = (string)$filtered['body'];
    }
    $has_title = $title !== '';
    $title_html = $has_title ? '<div class="post-topic-title"><h1 class="post-content-title">' . h($title) . '</h1>' . $stats . '</div>' : '';
    $avatar = avatar_tag((int)$row['user_id'], (string)$row['username'], (string)($row['avatar_style'] ?? ''), '', (string)($row['avatar_seed'] ?? ''));
    $html = '<li class="post-item post-entry' . ($has_title ? ' has-title' : '') . ($highlight ? ' post-highlight' : '') . '" id="post-' . (int)($row['id'] ?? 0) . '">' . $title_html . '<div class="post-avatar">' . $avatar . '</div><div class="post-body"><div class="post-head"><a class="post-title post-author" href="' . h(route_url('user', ['id' => (int)$row['user_id']])) . '">' . h($row['username']) . '</a>' . topic_user_group_html($row) . user_state_tag_html($row) . $ops . '</div><div class="post-meta"><span>' . human_time($time) . '</span></div></div><div class="post-content">' . markdown_html($body) . '</div></li>';
    return (string)hook($is_reply ? 'reply.after_render' : 'topic.after_render', $html, ['row' => $row, 'body' => $body] + $ctx);
}
function quote_reply_action(array $row): string
{
    $type = isset($row['topic_id']) ? 'reply' : 'topic';
    return '<a class="icon-action icon-quote quote-reply" href="#reply" data-username="' . h((string)$row['username']) . '" data-type="' . $type . '" data-id="' . (int)($row['id'] ?? 0) . '" title="引用回复"><span>引用回复</span></a>';
}
function topic_list_select_columns(string $table = 'topics'): string
{
    $p = $table . '.';
    return $p . 'id,' . $p . 'title,' . $p . 'highlight_style,' . $p . 'created_at,' . $p . 'updated_at,' . $p . 'reply_count,' . $p . 'last_reply_at,' . $p . 'last_reply_user_id,' . $p . 'forum_id,' . $p . 'user_id';
}
function topic_fts_query(string $query, string $field = ''): string
{
    $query = trim($query);
    if ($query === '') $query = '__nomatch__';
    $quoted = '"' . str_replace('"', '""', $query) . '"';
    $field = in_array($field, ['title', 'body'], true) ? $field : '';
    return $field !== '' ? $field . ':' . $quoted : $quoted;
}
function topic_fts_create(): void
{
    q("CREATE VIRTUAL TABLE IF NOT EXISTS topics_fts USING fts5(title, body, tokenize='trigram')");
}
function search_char_len(string $query): int
{
    preg_match_all('/./us', trim($query), $m);
    return count($m[0] ?? []);
}
function search_min_chars_message(): string
{
    return '请至少输入' . SEARCH_MIN_CHARS . '个字符再搜索';
}
function require_search_min_chars(string $query): void
{
    if (trim($query) !== '' && search_char_len($query) < SEARCH_MIN_CHARS) err(search_min_chars_message());
}
function topic_search_condition(string $query, string $field = '', string $prefix = ''): array
{
    $column_prefix = $prefix !== '' ? $prefix . '.' : '';
    $field = in_array($field, ['title', 'body'], true) ? $field : '';
    return [
        $column_prefix . "id IN (SELECT rowid FROM topics_fts WHERE topics_fts MATCH ?)",
        [topic_fts_query($query, $field)],
    ];
}
function topic_fts_sync(int $id, string $title, string $body): void
{
    q("DELETE FROM topics_fts WHERE rowid=?", [$id]);
    q("INSERT INTO topics_fts(rowid,title,body) VALUES(?,?,?)", [$id, $title, $body]);
}
function topic_fts_delete(int $id): void
{
    q("DELETE FROM topics_fts WHERE rowid=?", [$id]);
}
function topic_fts_rebuild_from(int $start_id): int
{
    $start_id = max(1, $start_id);
    $db = db();
    $db->beginTransaction();
    try {
        if ($start_id === 1) {
            q("DROP TABLE IF EXISTS topics_fts");
            topic_fts_create();
        } else {
            topic_fts_create();
        }
        q("DELETE FROM topics_fts WHERE rowid>=?", [$start_id]);
        $rows = q("SELECT id,title,body FROM topics WHERE id>=? ORDER BY id", [$start_id])->fetchAll();
        $stmt = $db->prepare("INSERT INTO topics_fts(rowid,title,body) VALUES(?,?,?)");
        foreach ($rows as $row) $stmt->execute([(int)$row['id'], (string)$row['title'], (string)$row['body']]);
        $db->commit();
        return count($rows);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
function topic_list_row(array $t, string $sort): string
{
    $filtered = hook('topic.before_render', ['row' => $t], ['list' => true, 'sort' => $sort]);
    if (is_array($filtered) && isset($filtered['row']) && is_array($filtered['row'])) $t = $filtered['row'];
    $time = (int)($t['time'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
    $forum = $t['forum'] ?? ['id' => (int)$t['forum_id'], 'name' => ''];
    $user_link = '<a href="' . h(route_url('user', ['id' => (int)$t['user_id']])) . '">' . svg_icon('user') . h($t['username']) . '</a>';
    $forum_link = '<a href="' . h(route_url('forum', ['id' => (int)$forum['id']])) . '">' . h($forum['name']) . '</a>';
    $last_reply_username = (string)($t['last_reply_username'] ?? '');
    $last_reply_user = $last_reply_username !== '' ? '<span>' . svg_icon('user') . h($last_reply_username) . '</span>' : '';
    $time_meta = '<span>' . human_time($time) . '</span>';
    $meta = '<span>' . $user_link . '</span>' . ($sort === 'post' ? $time_meta : '') . '<span class="post-forum-meta">' . svg_icon('forum') . $forum_link . '</span><span>' . svg_icon('reply') . (int)$t['reply_count'] . '</span>' . $last_reply_user . ($sort === 'post' ? '' : $time_meta);
    $pages = topic_page_links((int)$t['id'], (int)$t['reply_count']);
    $reply_id = (int)($t['my_reply_id'] ?? 0);
    $topic_url = route_url('topic', ['id' => (int)$t['id'], 'replyid' => $reply_id > 0 ? $reply_id : null]);
    $badges = ((int)($t['is_pinned'] ?? 0) ? '<span class="topic-badge pinned">置顶</span>' : '');
    $style = (string)($t['highlight_style'] ?? '') !== '' ? ' style="' . h((string)$t['highlight_style']) . '"' : '';
    $title_suffix = (string)hook('topic.title_suffix', '', ['row' => $t, 'list' => true, 'sort' => $sort]);
    $html = '<li class="post-item' . ((int)($t['is_pinned'] ?? 0) ? ' topic-pinned' : '') . '"><div class="post-avatar">' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), '', (string)($t['avatar_seed'] ?? '')) . '</div><div class="post-body"><div class="post-title-row">' . $badges . '<a class="post-title" href="' . h($topic_url) . '"' . $style . '>' . h($t['title']) . '</a>' . $title_suffix . $pages . '</div><div class="post-meta">' . $meta . '</div></div><a class="post-tag post-forum-badge" href="' . h(route_url('forum', ['id' => (int)$forum['id']])) . '">' . h($forum['name']) . '</a></li>';
    return (string)hook('topic.after_render', $html, ['row' => $t, 'list' => true, 'sort' => $sort]);
}
function topic_stats_html(int $view_count, int $reply_count): string
{
    $stats = '';
    if ($view_count > 0) $stats .= '<span>' . svg_icon('view') . $view_count . '</span>';
    if ($reply_count > 0) $stats .= '<span>' . svg_icon('reply') . $reply_count . '</span>';
    return $stats ? '<div class="post-content-stats">' . $stats . '</div>' : '';
}
function page_head_html(string $page_title, string $meta, string $head_extra = ''): string
{
    return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $meta . '<title>' . h($page_title) . '</title><link rel="icon" type="image/svg+xml" href="' . h(asset_url('logo.svg')) . '"><link rel="stylesheet" href="/index.css?v=' . h(APP_VERSION) . '">' . $head_extra . '</head><body>';
}
function page_nav_html(string $site_name): string
{
    $q = trim((string)($_GET['q'] ?? ''));
    $active_forum = ($_GET['a'] ?? '') === 'forum' ? id() : 0;
    $mine = me();
    $mine_unread = $mine ? (int)($mine['unread_notifications'] ?? 0) : 0;
    $mine_link = $mine ? route_url('user', ['id' => (int)$mine['id'], 'tab' => $mine_unread > 0 ? 'notifications' : null]) : route_url('login');
    $mine_label = $mine ? '我的' . notification_badge_html($mine_unread) : '登录';
    $html = '<div class="top"><div class="bar"><button class="mobile-menu-button" type="button" data-mobile-menu-open aria-label="打开菜单" aria-controls="mobile-menu-drawer" aria-expanded="false"><svg width="19" height="19" viewBox="0 0 19 19" fill="none" aria-hidden="true"><path d="M3.5 5.5H15.5M3.5 9.5H15.5M3.5 13.5H15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button><a class="brand" href="' . h(route_url('home')) . '">' . h($site_name) . '</a><nav class="forum-nav">';
    foreach (array_slice(array_values(array_filter(forums_cache(), fn($f) => forum_group_allowed($f, 'allow_view_groups'))), 0, 7) as $f) {
        $html .= '<a class="forum-link' . ((int)$f['id'] === $active_forum ? ' active' : '') . '" href="' . h(route_url('forum', ['id' => (int)$f['id']])) . '">' . h($f['name']) . '</a>';
    }
    return $html . '</nav><form class="search-form" method="post" action="' . h(route_url('search')) . '" data-no-ajax="1">' . form_token() . '<input class="search-input" type="search" name="q" placeholder="搜索主题" value="' . h($q) . '" minlength="' . SEARCH_MIN_CHARS . '"><button class="search-btn" type="submit" aria-label="搜索"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9.5L13 13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></button></form><a class="nav-mine" href="' . h($mine_link) . '">' . $mine_label . '</a></div></div>' . mobile_menu_html($mine);
}
function page_footer_html(array $settings, string $title, string $flash): string
{
    $footer_html = (string)($settings['footer_html'] ?? '') . (string)hook('page.footer', '', ['title' => $title]);
    return '<footer class="footer">' . $footer_html . 'Powered by <a href="https://bbs1.org" target="_blank">bbs1org</a> ' . h(APP_VERSION) . '</footer><div class="modal-backdrop" id="notify-modal" hidden><div class="modal-panel"><div class="modal-head"><strong id="notify-modal-title">提示</strong><button type="button" class="modal-close" data-modal-close aria-label="关闭">×</button></div><div class="modal-body" id="notify-modal-body"></div></div></div><div class="toast" id="toast" hidden></div><script>window.__pageFlash=' . json_encode($flash, JSON_UNESCAPED_UNICODE) . ';</script><script src="/index.js?v=' . h(APP_VERSION) . '" defer></script></body></html>';
}
function page(string $title, string $body, array $seo = []): void
{
    $settings = settings_cache();
    $body = (string)hook('page.before_render', $body, ['title' => $title]);
    $site_name = trim((string)$settings['site_name']) ?: 'FORUM';
    $page_title = $title === '' || $title === $site_name ? $site_name : $title . ' - ' . $site_name;
    $meta = '';
    $description = trim((string)($seo['description'] ?? ($settings['site_description'] ?? '')));
    $is_home = ($_GET['a'] ?? 'home') === 'home' && trim((string)($_GET['q'] ?? '')) === '';
    if ($is_home && ($settings['site_keywords'] ?? '') !== '') $meta .= '<meta name="keywords" content="' . h($settings['site_keywords'] ?? '') . '">';
    if ($description !== '') $meta .= '<meta name="description" content="' . h($description) . '">';
    if (!empty($seo['canonical'])) $meta .= '<link rel="canonical" href="' . h((string)$seo['canonical']) . '">';
    $head_extra = (string)hook('page.head', '', ['title' => $title, 'page_title' => $page_title, 'seo' => $seo]);
    $flash = trim((string)($_COOKIE['__flash'] ?? ''));
    if ($flash !== '' && !headers_sent()) setcookie('__flash', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $header_html = (string)($settings['header_html'] ?? '') . (string)hook('page.header', '', ['title' => $title]);
    echo page_head_html($page_title, $meta, $head_extra) . page_nav_html($site_name) . $header_html . '<main class="wrap">' . $body . '</main>' . page_footer_html($settings, $title, $flash);
}
function form_field_caption(string $label, string $help = ''): string
{
    return '<span>' . h($label) . ($help !== '' ? '<small>' . h($help) . '</small>' : '') . '</span>';
}
function input(string $label, string $name, $value = '', string $type = 'text', bool $required = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '') . '></label>';
}
function textarea(string $label, string $name, $value = '', bool $required = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<textarea name="' . h($name) . '"' . ($required ? ' required' : '') . '>' . h($value) . '</textarea></label>';
}
function checkbox(string $label, string $name, bool $checked = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '></label>';
}
function number_input(string $label, string $name, $value = '', int|float|null $min = null, int|float|null $max = null, bool $required = true, string $help = '', string $class = ''): string
{
    $limits = ($min !== null ? ' min="' . h($min) . '"' : '') . ($max !== null ? ' max="' . h($max) . '"' : '');
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input name="' . h($name) . '" type="number" value="' . h($value) . '"' . $limits . ($required ? ' required' : '') . '></label>';
}
function select_input(string $label, string $name, $value, array $options, string $help = '', string $class = ''): string
{
    $html = '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<select name="' . h($name) . '">';
    foreach ($options as $option_value => $option_label) $html .= '<option value="' . h($option_value) . '"' . ((string)$option_value === (string)$value ? ' selected' : '') . '>' . h($option_label) . '</option>';
    return $html . '</select></label>';
}
function render_form_fields(array $fields, array $values = []): string
{
    $html = '';
    foreach ($fields as $name => $field) {
        if (isset($field['html'])) {
            $html .= (string)$field['html'];
            continue;
        }
        $type = (string)($field['type'] ?? 'text');
        $label = (string)($field['label'] ?? $name);
        $value = array_key_exists('value', $field) ? $field['value'] : ($values[$name] ?? '');
        $help = (string)($field['help'] ?? '');
        $class = (string)($field['class'] ?? '');
        if ($help !== '' && !str_contains(' ' . $class . ' ', ' settings-help-field ')) $class = trim($class . ' settings-help-field');
        if ($type === 'checkbox') $html .= checkbox($label, (string)$name, (bool)(int)$value, $help, $class);
        elseif ($type === 'number') $html .= number_input($label, (string)$name, $value, $field['min'] ?? null, $field['max'] ?? null, (bool)($field['required'] ?? true), $help, $class);
        elseif ($type === 'select') $html .= select_input($label, (string)$name, $value, (array)($field['options'] ?? []), $help, $class);
        elseif ($type === 'textarea') $html .= textarea($label, (string)$name, $value, !empty($field['required']), $help, $class);
        else $html .= input($label, (string)$name, $value, $type, !empty($field['required']), $help, $class);
    }
    return $html;
}
function attachment_uploader_html(): string
{
    $count = attachment_max_count();
    $mb = attachment_max_mb();
    if ($count <= 0 || $mb <= 0) return '';
    return '<label class="grid attachment-field"><span>附件</span><div class="attachment-uploader" data-upload-url="' . h(route_url('attachment_upload')) . '" data-upload-max-count="' . $count . '" data-upload-max-mb="' . $mb . '"><input class="attachment-input" type="file" multiple data-attachment-input><div class="attachment-drop"><strong>选择附件</strong><span>最多' . $count . '个，单个不超过' . $mb . 'MB。</span></div><div class="attachment-list" data-attachment-list></div></div></label>';
}
function select_group(int $gid): string
{
    return select_input('用户组', 'group_id', $gid, array_column(groups_cache(), 'name', 'id'));
}
function select_forum(int $fid): string
{
    $options = [];
    foreach (forums_cache() as $f) if (forum_group_allowed($f, 'allow_post_groups')) $options[(int)$f['id']] = (string)$f['name'];
    return select_input('版块', 'forum_id', $fid, $options);
}
function can_manage_topic(array $t): bool
{
    $allowed = can_manage() || (uid() && (int)$t['user_id'] === uid());
    return hook('topic.can_manage', $allowed, ['topic' => $t]) === true;
}
function can_manage_reply(array $r): bool
{
    return can_manage() || (uid() && (int)$r['user_id'] === uid());
}
function can_admin_delete(string $type, int $id): bool
{
    if ($type === 'users') return can_manage() && $id !== uid();
    if (in_array($type, ['groups', 'forums'], true)) return can_manage() && is_super_user();
    $row = deletable_post_row($type, $id);
    if ($type === 'topics') return $row && can_manage_topic($row);
    if ($type === 'replies') return $row && can_manage_reply($row);
    return false;
}
function trash_rows_copy(string $table, array $row): void
{
    q("INSERT INTO trash(table_name,row_id,row_data,deleted_by,created_at) VALUES(?,?,?,?,?)", [$table, (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE), uid(), now()]);
}
function trash_restore_row(int $id): string
{
    $trash = one('SELECT * FROM trash WHERE id=?', [$id]) ?: err('记录不存在');
    $table = (string)$trash['table_name'];
    if (!in_array($table, ['users', 'topics', 'replies'], true)) err('参数错误');
    $row = json_decode((string)$trash['row_data'], true);
    if (!is_array($row)) err('数据错误');
    $cols = q('PRAGMA table_info(' . $table . ')')->fetchAll();
    $fields = [];
    $values = [];
    foreach ($cols as $col) {
        $name = (string)($col['name'] ?? '');
        $fields[] = $name;
        $values[] = $row[$name] ?? null;
    }
    q('INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES(' . implode(',', array_fill(0, count($fields), '?')) . ')', $values);
    if ($table === 'topics') topic_fts_sync((int)$row['id'], (string)($row['title'] ?? ''), (string)($row['body'] ?? ''));
    if ($table === 'replies') refresh_topic_stats((int)($row['topic_id'] ?? 0));
    q('DELETE FROM trash WHERE id=?', [$id]);
    return $table;
}
function admin_trash_count(string $table = ''): int
{
    return $table !== '' ? (int)val('SELECT COUNT(*) FROM trash WHERE table_name=?', [$table]) : (int)val('SELECT COUNT(*) FROM trash');
}
function admin_trash_list(string $table = '', int $size = 50, int $offset = 0): array
{
    $rows = $table !== '' ? q('SELECT * FROM trash WHERE table_name=? ORDER BY id DESC LIMIT ? OFFSET ?', [$table, $size, $offset])->fetchAll() : q('SELECT * FROM trash ORDER BY id DESC LIMIT ? OFFSET ?', [$size, $offset])->fetchAll();
    $users = rows_by_ids('users', array_column($rows, 'deleted_by'), 'id,username');
    foreach ($rows as &$row) $row['deleted_username'] = (string)($users[(int)($row['deleted_by'] ?? 0)]['username'] ?? '用户删除');
    unset($row);
    return $rows;
}
function admin_trash_search_form(string $table): string
{
    $html = '<form class="admin-table-search" method="get" action="' . h(index_url()) . '"><input type="hidden" name="a" value="admin"><input type="hidden" name="tab" value="trash"><select class="admin-search-select" name="table">';
    $html .= '<option value="">全部</option>';
    foreach (['users' => '用户', 'topics' => '主题', 'replies' => '回帖'] as $k => $v) $html .= '<option value="' . $k . '"' . ($table === $k ? ' selected' : '') . '>' . $v . '</option>';
    return $html . '</select><button class="admin-search-link" type="submit">筛选</button></form>';
}
function admin_trash_row(array $row): string
{
    $data = json_decode((string)$row['row_data'], true);
    $title = (['users' => '用户', 'topics' => '主题', 'replies' => '回帖'][(string)$row['table_name']] ?? (string)$row['table_name']) . ' #' . (int)$row['row_id'];
    $summary = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string)$row['row_data'];
    return '<li class="admin-list-item admin-object-row admin-trash-row"><input class="admin-row-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '" form="admin-bulk-form"><div class="admin-row-main"><div class="admin-topic-user"><span class="admin-group-pill">' . h($title) . '</span><span class="admin-dot">·</span>删除人：' . h((string)$row['deleted_username']) . '<span class="admin-dot">·</span>删除时间：' . date('Y-m-d H:i', (int)$row['created_at']) . '</div><pre class="admin-trash-data">' . h($summary) . '</pre></div><div class="admin-inline-ops">' . post_action_form(admin_url(['do' => 'restore']), '恢复', ['id' => (int)$row['id']], '', '确定恢复？') . '</div></li>';
}
function refresh_topic_stats(int $tid): void
{
    q("UPDATE topics SET reply_count=(SELECT COUNT(*) FROM replies WHERE topic_id=?),last_reply_at=COALESCE((SELECT created_at FROM replies WHERE topic_id=? ORDER BY created_at DESC,id DESC LIMIT 1),created_at),last_reply_user_id=COALESCE((SELECT user_id FROM replies WHERE topic_id=? ORDER BY created_at DESC,id DESC LIMIT 1),0) WHERE id=?", [$tid, $tid, $tid, $tid]);
}
function refresh_forum_last_topic(int $fid): void
{
    $t = one("SELECT id,title FROM topics WHERE forum_id=? ORDER BY updated_at DESC,id DESC LIMIT 1", [$fid]);
    q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [(int)($t['id'] ?? 0), (string)($t['title'] ?? ''), $fid]);
}
function save_user(bool $admin = false): void
{
    $ip = ip_addr();
    if (!$admin && !id() && !rate_allow_bucket($ip, 'register')) err('同一IP 1小时内注册次数已达上限');
    $username = post('username', 40);
    $email = post('email', 120);
    $bio = post('bio', 1000);
    $user_id = id();
    $avatar_style = avatar_style(post('avatar_style', 40));
    $avatar_seed = post('avatar_seed', 80);
    if ($avatar_seed !== '') $avatar_seed = avatar_seed($avatar_style ?: 'dylan', $avatar_seed);
    $avatar_mirror_styles = avatar_mirror_styles();
    if ($avatar_mirror_styles && (isset($_POST['avatar_style']) || isset($_POST['avatar_seed']))) {
        if ($avatar_style === '') $avatar_style = (string)$avatar_mirror_styles[0];
        if (!in_array($avatar_style, $avatar_mirror_styles, true)) err('头像目录不在本地镜像设置中');
        if ($avatar_seed === '') $avatar_seed = avatar_seed($avatar_style, (string)$user_id);
    }
    if ($username === '') err('用户名不能为空');
    $old_user = $user_id ? one("SELECT username,group_id,points,is_banned,is_muted FROM users WHERE id=?", [$user_id]) : null;
    if ($user_id && !$old_user) err('用户不存在');
    if (!$admin && (!$old_user || (string)$old_user['username'] !== $username) && in_array(function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username), array_map(fn($v) => function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v), preg_split('/[\s,，]+/u', setting('reserved_usernames'), -1, PREG_SPLIT_NO_EMPTY) ?: []), true)) err('用户名已保留');
    $gid = $admin ? max(1, (int)$_POST['group_id']) : ($old_user ? (int)$old_user['group_id'] : (int)setting('default_group_id', '2'));
    if (!group_by_id($gid)) err('用户组不存在');
    $points = $admin ? (int)($_POST['points'] ?? 0) : (int)($old_user['points'] ?? 0);
    $is_banned = $admin ? (isset($_POST['is_banned']) ? 1 : 0) : (int)($old_user['is_banned'] ?? 0);
    $is_muted = $admin ? (isset($_POST['is_muted']) ? 1 : 0) : (int)($old_user['is_muted'] ?? 0);
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if ($pwd !== '' && $pwd !== $pwd2) err('两次密码不一致');
    $filtered = hook('user.before_save', [
        'username' => $username,
        'email' => $email,
        'bio' => $bio,
        'avatar_style' => $avatar_style,
        'avatar_seed' => $avatar_seed,
        'group_id' => $gid,
        'points' => $points,
        'is_banned' => $is_banned,
        'is_muted' => $is_muted,
    ], ['id' => $user_id, 'admin' => $admin, 'creating' => !$user_id]);
    if (is_array($filtered)) {
        $username = cut((string)($filtered['username'] ?? $username), 40);
        $email = cut((string)($filtered['email'] ?? $email), 120);
        $bio = cut((string)($filtered['bio'] ?? $bio), 1000);
        $avatar_style = avatar_style(cut((string)($filtered['avatar_style'] ?? $avatar_style), 40));
        $avatar_seed = cut((string)($filtered['avatar_seed'] ?? $avatar_seed), 80);
        $gid = max(1, (int)($filtered['group_id'] ?? $gid));
        if (!group_by_id($gid)) err('用户组不存在');
        $points = (int)($filtered['points'] ?? $points);
        $is_banned = (int)($filtered['is_banned'] ?? $is_banned) ? 1 : 0;
        $is_muted = (int)($filtered['is_muted'] ?? $is_muted) ? 1 : 0;
    }
    if ($username === '') err('用户名不能为空');
    $exists = $user_id ? one("SELECT id FROM users WHERE username=? AND id<>?", [$username, $user_id]) : one("SELECT id FROM users WHERE username=?", [$username]);
    if ($exists) err('用户名已存在');
    if ($user_id) {
        $p = [$username, $email, $bio, $avatar_style, $avatar_seed, $gid, $is_banned, $is_muted, $user_id];
        $sql = "UPDATE users SET username=?,email=?,bio=?,avatar_style=?,avatar_seed=?,group_id=?,is_banned=?,is_muted=? WHERE id=?";
        if ($pwd !== '') {
            $sql = "UPDATE users SET username=?,email=?,bio=?,avatar_style=?,avatar_seed=?,group_id=?,is_banned=?,is_muted=?,password=? WHERE id=?";
            $p = [$username, $email, $bio, $avatar_style, $avatar_seed, $gid, $is_banned, $is_muted, password_hash($pwd, PASSWORD_DEFAULT), $user_id];
        }
        q($sql, $p);
        if ($admin) user_points_set($user_id, $points, '管理员调整');
        fire('user.after_save', ['id' => $user_id, 'username' => $username, 'email' => $email, 'admin' => $admin, 'creating' => false]);
    } else {
        if ($pwd === '') err('密码不能为空');
        q("INSERT INTO users(username,password,email,bio,avatar_style,avatar_seed,group_id,points,is_banned,is_muted,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)", [$username, password_hash($pwd, PASSWORD_DEFAULT), $email, $bio, $avatar_style, $avatar_seed, $gid, $points, $is_banned, $is_muted, now()]);
        $new_user_id = (int)db()->lastInsertId();
        $GLOBALS['__last_saved_user_id'] = $new_user_id;
        if (!$admin && !id()) rate_hit_bucket($ip, 'register');
        fire('user.after_save', ['id' => $new_user_id, 'username' => $username, 'email' => $email, 'admin' => $admin, 'creating' => true]);
    }
    stats_cache(true);
}
function puppet_username_from_body(string $body): string
{
    if (!can_manage()) return '';
    return preg_match('/@@([A-Za-z0-9_.\-\x{4e00}-\x{9fff}]{1,40})/u', $body, $m) ? (string)$m[1] : '';
}
function strip_puppet_commands(string $body): string
{
    return trim(preg_replace('/@@[A-Za-z0-9_.\-\x{4e00}-\x{9fff}]{1,40}/u', '', $body) ?? $body);
}
function puppet_user_id(string $username): int
{
    $u = one("SELECT id FROM users WHERE username=?", [$username]);
    if ($u) return (int)$u['id'];
    $pwd = bin2hex(random_bytes(16));
    q("INSERT INTO users(username,password,email,bio,avatar_style,avatar_seed,group_id,is_banned,is_muted,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)", [$username, password_hash($pwd, PASSWORD_DEFAULT), $username . '@local', '', '', '', (int)setting('default_group_id', '2'), 0, 0, now()]);
    stats_cache(true);
    return (int)db()->lastInsertId();
}
function apply_puppet_author(string $body): array
{
    $username = puppet_username_from_body($body);
    if ($username === '') return ['user_id' => uid(), 'body' => $body];
    return ['user_id' => puppet_user_id($username), 'body' => strip_puppet_commands($body)];
}
function user_notify_page(): void
{
    need_login();
    $target = one("SELECT id,username,avatar_style,avatar_seed,group_id FROM users WHERE id=?", [id()]) ?: err('用户不存在');
    if ((int)$target['id'] === uid()) err('不能通知自己');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $quote = notification_excerpt((string)($_POST['quote'] ?? ''), 100);
        $body = post('content', 500);
        $content = trim(($quote !== '' ? '> ' . $quote . "\n\n" : '') . $body);
        if ($content === '') {
            ajax_request() ? ajax_error('通知内容不能为空') : err('通知内容不能为空');
        }
        create_notification((int)$target['id'], uid(), 'direct', $content);
        if (ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'message' => '已发送'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        go(route_url('user', ['id' => (int)$target['id'], 'tab' => 'notifications']));
    }
    $target['group_name'] = (group_by_id((int)$target['group_id']) ?: ['name' => '用户'])['name'];
    $quote = notification_excerpt((string)($_GET['quote'] ?? ''), 100);
    $quote_html = $quote !== '' ? '<blockquote class="notify-quote-card"><p>' . h($quote) . '</p></blockquote><input type="hidden" name="quote" value="' . h($quote) . '">' : '';
    $html = '<div class="notify-pop"><div class="notify-target"><div class="notify-target-avatar">' . avatar_tag((int)$target['id'], (string)$target['username'], (string)$target['avatar_style'], '', (string)$target['avatar_seed']) . '</div><div class="notify-target-info"><strong>' . h($target['username']) . '</strong><span>' . h($target['group_name']) . '</span></div></div><form class="notify-form" method="post" action="' . h(route_url('notify', ['id' => (int)$target['id']])) . '">' . form_token() . $quote_html . '<textarea name="content" placeholder="输入私信内容" required></textarea><div class="notify-actions"><span class="notify-status"></span><button type="submit">发送</button></div></form></div>';
    if (ajax_request()) {
        echo $html;
        exit;
    }
    page('通知TA', form_shell('<div class="form-panel"><h2>通知TA</h2>' . $html . '</div>', me()));
}
function base_url(): string
{
    $configured = clean_site_base_url(setting('site_base_url', ''));
    if ($configured !== '') return $configured;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}
function absolute_url(string $url): string
{
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    return rtrim(base_url(), '/') . '/' . ltrim($url, '/');
}
function seo_text(string $text, int $max = 160): string
{
    $text = strip_tags(markdown_html($text));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    return cut($text, $max);
}
function page_seo(string $route, array $params = [], string $description = ''): array
{
    $seo = ['canonical' => absolute_url(route_url($route, $params))];
    $description = seo_text($description);
    if ($description !== '') $seo['description'] = $description;
    return $seo;
}
function send_mail_text(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $site = trim(setting('site_name')) ?: 'FORUM';
    $from = trim(setting('mail_from'));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'no-reply@' . preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $encoded_site = '=?UTF-8?B?' . base64_encode($site) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $encoded_site . ' <' . $from . '>',
    ];
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}
function mail_virtual_enabled(): bool
{
    return setting('mail_virtual', '0') === '1';
}
function virtual_mail_page(string $title, string $to, string $subject, string $body): void
{
    $html = '<div class="form-panel auth-panel"><h2>' . h($title) . '</h2><div class="note warn">已启用虚拟发送，邮件未实际发出。</div><div class="mail-preview"><div><span>收件人</span><strong>' . h($to) . '</strong></div><div><span>主题</span><strong>' . h($subject) . '</strong></div><pre>' . h($body) . '</pre></div></div>';
    page($title, shell_html($html, password_reset_notice_sidebar('reset')));
}
function create_password_reset(array $user): string
{
    q("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at=0", [now(), (int)$user['id']]);
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO password_resets(user_id,token_hash,expires_at,created_at) VALUES(?,?,?,?)", [(int)$user['id'], hash('sha256', $token), now() + 3600, now()]);
    return $token;
}
function password_reset_notice_sidebar(string $mode): string
{
    $items = $mode === 'reset'
        ? ['重置链接有效期为 1 小时。', '请设置一个新的安全密码。', '重置成功后旧链接会立即失效。']
        : ['邮箱保密，仅忘记密码时可用。', '需要用户名和邮箱同时匹配。', '重置邮件可能会进入垃圾邮件箱。'];
    return sidebar_stack_html([sidebar_notice_card_html($mode === 'reset' ? '重置密码说明' : '找回密码说明', $items)]);
}
function forgot_password_page(): void
{
    if (uid()) go(route_url('home'));
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = ip_addr();
        if (!rate_allow_bucket($ip, 'reset_fail')) err('同一IP 1小时内错误次数已达上限');
        hook('forgot_password.before_submit', true, []);
        $username = post('username', 40);
        $email = post('email', 120);
        $u = one("SELECT id,username,email FROM users WHERE username=? AND email=?", [$username, $email]);
        if (!$u || !filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
            rate_hit_bucket($ip, 'reset_fail');
            err('用户名和邮箱不匹配');
        }
        $token = create_password_reset($u);
        $link = base_url() . route_url('reset_password', ['token' => $token]);
        $subject = '重置密码 - ' . (trim(setting('site_name')) ?: 'FORUM');
        $body = "你好，" . $u['username'] . "\n\n请打开以下链接重置密码：\n" . $link . "\n\n链接有效期为 1 小时。如果不是你本人操作，请忽略本邮件。";
        if (mail_virtual_enabled()) {
            virtual_mail_page('重置密码', (string)$u['email'], $subject, $body);
            return;
        }
        if (!send_mail_text((string)$u['email'], $subject, $body)) err('邮件发送失败，请稍后再试');
        if (ajax_request()) go(route_url('login'));
        $sent = true;
    }
    $body = '<div class="form-panel auth-panel"><h2>忘记密码</h2>';
    if ($sent) {
        $body .= '<p class="muted">重置密码邮件已经发送，请查收邮箱。</p><p class="auth-extra"><a href="' . h(route_url('login')) . '">返回登录</a></p>';
    } else {
        $form_extra = (string)hook('forgot_password.form_extra', '', []);
        $body .= '<form method="post" data-no-ajax="1">' . form_token() . input('用户名', 'username', '', 'text', true) . input('邮箱', 'email', '', 'email', true) . $form_extra . '<button>发送重置邮件</button></form><p class="auth-extra"><a href="' . h(route_url('login')) . '">返回登录</a></p>';
    }
    page('忘记密码', shell_html(auth_tabs_html('login') . $body . '</div>', password_reset_notice_sidebar('forgot')));
}
function reset_password_page(): void
{
    if (uid()) go(route_url('home'));
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') err('重置链接无效');
    $row = one("SELECT * FROM password_resets WHERE token_hash=? AND used_at=0 AND expires_at>=?", [hash('sha256', $token), now()]);
    if (!$row) err('重置链接无效或已过期');
    $reset_user = user_by_id((int)$row['user_id']) ?: err('用户不存在');
    $row['username'] = $reset_user['username'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pwd = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password2'] ?? '');
        if ($pwd === '') err('密码不能为空');
        if ($pwd !== $pwd2) err('两次密码不一致');
        q("UPDATE users SET password=? WHERE id=?", [password_hash($pwd, PASSWORD_DEFAULT), (int)$row['user_id']]);
        q("UPDATE password_resets SET used_at=? WHERE id=?", [now(), (int)$row['id']]);
        if (ajax_request()) go(route_url('login'));
        page('密码已重置', shell_html(auth_tabs_html('login') . '<div class="form-panel auth-panel"><h2>密码已重置</h2><p class="muted">请使用新密码登录。</p><p class="auth-extra"><a href="' . h(route_url('login')) . '">去登录</a></p></div>', password_reset_notice_sidebar('reset')));
        return;
    }
    $form = '<div class="form-panel auth-panel"><h2>重置密码</h2><form method="post">' . form_token() . '<input type="hidden" name="token" value="' . h($token) . '">' . input('新密码', 'password', '', 'password', true) . input('确认密码', 'password2', '', 'password', true) . '<button>保存新密码</button></form></div>';
    page('重置密码', shell_html(auth_tabs_html('login') . $form, password_reset_notice_sidebar('reset')));
}
function save_forum(): void
{
    $name = post('name', 80);
    if ($name === '') err('版块名不能为空');
    $description = post('description', 300);
    $sort = (int)$_POST['sort'];
    $allow_view_groups = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST['allow_view_groups'] ?? []))))));
    $allow_post_groups = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST['allow_post_groups'] ?? []))))));
    $allow_reply_groups = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST['allow_reply_groups'] ?? []))))));
    id()
        ? q("UPDATE forums SET name=?,description=?,sort=?,allow_view_groups=?,allow_post_groups=?,allow_reply_groups=? WHERE id=?", [$name, $description, $sort, $allow_view_groups, $allow_post_groups, $allow_reply_groups, id()])
        : q("INSERT INTO forums(name,description,sort,allow_view_groups,allow_post_groups,allow_reply_groups) VALUES(?,?,?,?,?,?)", [$name, $description, $sort, $allow_view_groups, $allow_post_groups, $allow_reply_groups]);
    forums_cache(true);
}
function save_group(): void
{
    $name = post('name', 60);
    if ($name === '') err('组名不能为空');
    $allow_manage = isset($_POST['allow_manage']) ? 1 : 0;
    $allow_admin = isset($_POST['allow_admin']) ? 1 : 0;
    $upload_quota_mb = max(0, (int)($_POST['upload_quota_mb'] ?? 0));
    id() ? q("UPDATE groups SET name=?,allow_manage=?,allow_admin=?,upload_quota_mb=? WHERE id=?", [$name, $allow_manage, $allow_admin, $upload_quota_mb, id()]) : q("INSERT INTO groups(name,allow_manage,allow_admin,upload_quota_mb) VALUES(?,?,?,?)", [$name, $allow_manage, $allow_admin, $upload_quota_mb]);
    groups_cache(true);
}
function save_topic(): int
{
    need_speak();
    if (!id()) {
        check_post_interval();
    }
    $action = (string)($_POST['topic_action'] ?? '');
    $fid = max(1, (int)$_POST['forum_id']);
    $forum = forum_by_id($fid) ?: err('版块不存在');
    $title = post('title', 120);
    $body = post('body', 20000);
    if (!id()) $body .= topic_upload_attachments_markdown();
    $filtered = hook('topic.before_save', ['title' => $title, 'body' => $body, 'forum_id' => $fid], ['id' => id(), 'action' => $action]);
    if (is_array($filtered)) {
        $title = cut((string)($filtered['title'] ?? $title), 120);
        $body = cut((string)($filtered['body'] ?? $body), 20000);
        $next_fid = max(1, (int)($filtered['forum_id'] ?? $fid));
        if ($next_fid !== $fid) {
            $fid = $next_fid;
            $forum = forum_by_id($fid) ?: err('版块不存在');
        }
    }
    if (id()) {
        $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
        if ($action !== '' && !can_manage()) err('无权限');
        if ($action === 'delete') {
            del('topics', (int)$t['id']);
            go(route_url('home'));
        }
        if ($action === 'pin') {
            set_pinned_topic((int)$t['id'], true);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === 'unpin') {
            set_pinned_topic((int)$t['id'], false);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === 'highlight') {
            $raw_color = trim((string)($_POST['highlight_style'] ?? ''));
            $style = $raw_color === '' ? '' : 'color:' . (preg_match('/^#[0-9a-fA-F]{6}$/', $raw_color, $m) ? $m[0] : '#d94b4b');
            q("UPDATE topics SET highlight_style=? WHERE id=?", [$style, (int)$t['id']]);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === 'mute_author') {
            if ((int)$t['user_id'] === 1) err('不能操作超级管理员');
            q("UPDATE users SET is_muted=1 WHERE id=?", [(int)$t['user_id']]);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === '') {
            if (!forum_group_allowed($forum, 'allow_post_groups')) err('无权限');
            if ($title === '' || $body === '') err('标题和内容不能为空');
        } else {
            $title = (string)($t['title'] ?? '');
            $body = (string)($t['body'] ?? '');
        }
        $topic_id = id();
        tx(function () use ($topic_id, $fid, $title, $body, $t) {
            q("UPDATE topics SET forum_id=?,title=?,body=?,updated_at=? WHERE id=?", [$fid, $title, $body, now(), $topic_id]);
            topic_fts_sync($topic_id, $title, $body);
            if ((int)$t['forum_id'] !== $fid) q("UPDATE forums SET last_topic_id=0,last_topic_title='' WHERE id=?", [(int)$t['forum_id']]);
            q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [$topic_id, $title, $fid]);
        });
        forums_cache(true);
        fire('topic.after_save', ['id' => $topic_id, 'forum_id' => $fid, 'title' => $title, 'body' => $body, 'editing' => true]);
        return $topic_id;
    }
    if (!forum_group_allowed($forum, 'allow_post_groups')) err('无权限');
    if ($title === '' || $body === '') err('标题和内容不能为空');
    $author = apply_puppet_author($body);
    $body = (string)$author['body'];
    if ($body === '') err('内容不能为空');
    $ts = now();
    $tid = tx(function () use ($fid, $author, $title, $body, $ts) {
        q("INSERT INTO topics(forum_id,user_id,title,body,created_at,updated_at,last_reply_at) VALUES(?,?,?,?,?,?,?)", [$fid, (int)$author['user_id'], $title, $body, $ts, $ts, $ts]);
        $tid = (int)db()->lastInsertId();
        topic_fts_sync($tid, $title, $body);
        q("UPDATE users SET last_post_at=? WHERE id=?", [$ts, (int)$author['user_id']]);
        q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [$tid, $title, $fid]);
        return $tid;
    });
    forums_cache(true);
    stats_cache(true);
    fire('topic.after_save', ['id' => $tid, 'forum_id' => $fid, 'title' => $title, 'body' => $body, 'user_id' => (int)$author['user_id'], 'editing' => false]);
    return $tid;
}
function save_reply(): array
{
    need_speak();
    if (!id()) {
        check_post_interval();
    }
    $ajax = ajax_request();
    $tid = max(1, (int)$_POST['topic_id']);
    $topic = one("SELECT id,forum_id FROM topics WHERE id=?", [$tid]) ?: ($ajax ? ajax_error('主题不存在') : err('主题不存在'));
    $forum = forum_by_id((int)$topic['forum_id']) ?: err('版块不存在');
    if (!forum_group_allowed($forum, 'allow_reply_groups')) $ajax ? ajax_error('无权限') : err('无权限');
    $body = post('body', 10000);
    $filtered = hook('reply.before_save', ['body' => $body, 'topic_id' => $tid], ['id' => id()]);
    if (is_array($filtered)) $body = cut((string)($filtered['body'] ?? $body), 10000);
    if ($body === '') $ajax ? ajax_error('回复不能为空') : err('回复不能为空');
    if (id()) {
        $r = one("SELECT * FROM replies WHERE id=?", [id()]) ?: err('回复不存在');
        if (!can_manage_reply($r)) $ajax ? ajax_error('无权限') : err('无权限');
        q("UPDATE replies SET body=?,updated_at=? WHERE id=?", [$body, now(), id()]);
        fire('reply.after_save', ['id' => (int)$r['id'], 'topic_id' => (int)$r['topic_id'], 'body' => $body, 'editing' => true]);
        return ['topic_id' => (int)$r['topic_id'], 'reply_id' => (int)$r['id']];
    }
    $author = apply_puppet_author($body);
    $body = (string)$author['body'];
    if ($body === '') $ajax ? ajax_error('回复不能为空') : err('回复不能为空');
    $ts = now();
    $rid = tx(function () use ($tid, $author, $body, $ts) {
        q("INSERT INTO replies(topic_id,user_id,body,created_at,updated_at) VALUES(?,?,?,?,?)", [$tid, (int)$author['user_id'], $body, $ts, $ts]);
        $rid = (int)db()->lastInsertId();
        q("UPDATE users SET last_post_at=? WHERE id=?", [$ts, (int)$author['user_id']]);
        q("UPDATE topics SET updated_at=?,reply_count=reply_count+1,last_reply_at=?,last_reply_user_id=? WHERE id=?", [$ts, $ts, (int)$author['user_id'], $tid]);
        create_reply_notifications($tid, $rid, $body, (int)$author['user_id']);
        return $rid;
    });
    stats_cache(true);
    fire('reply.after_save', ['id' => $rid, 'topic_id' => $tid, 'body' => $body, 'user_id' => (int)$author['user_id'], 'editing' => false]);
    return ['topic_id' => $tid, 'reply_id' => $rid];
}
function del(string $table, int $id): void
{
    $allow = ['users', 'groups', 'forums', 'topics', 'replies'];
    if (!in_array($table, $allow, true)) err('参数错误');
    if (in_array($table, ['users', 'groups', 'forums'], true) && !can_manage()) err('无权限');
    if ($table === 'users' && $id === uid()) err('不能删除自己');
    if ($table === 'groups' && $id <= 2) err('内置用户组不能删除');
    if ($table === 'groups' && $id === (int)setting('default_group_id', '2')) err('默认用户组不能删除');
    if ($table === 'forums' && count(forums_cache()) <= 1) err('至少保留一个版块');
    if ($table === 'replies') {
        $r = one("SELECT * FROM replies WHERE id=?", [$id]);
        if (!$r) err('记录不存在');
        tx(function () use ($id, $r) {
            trash_rows_copy('replies', $r);
            q("DELETE FROM replies WHERE id=?", [$id]);
            refresh_topic_stats((int)$r['topic_id']);
        });
        stats_cache(true);
        return;
    }
    if ($table === 'users') {
        $r = one("SELECT * FROM users WHERE id=?", [$id]);
        if (!$r) err('记录不存在');
        $tids = q("SELECT DISTINCT topic_id FROM replies WHERE user_id=?", [$id])->fetchAll();
        tx(function () use ($id, $r, $tids) {
            trash_rows_copy('users', $r);
            q("DELETE FROM users WHERE id=?", [$id]);
            foreach ($tids as $row) refresh_topic_stats((int)$row['topic_id']);
        });
        stats_cache(true);
        return;
    }
    if ($table === 'topics') {
        $r = one("SELECT * FROM topics WHERE id=?", [$id]);
        if (!$r) err('记录不存在');
        tx(function () use ($id, $r) {
            fire('topic.before_delete', ['id' => $id, 'row' => $r]);
            trash_rows_copy('topics', $r);
            topic_fts_delete($id);
            q("DELETE FROM topics WHERE id=?", [$id]);
            refresh_forum_last_topic((int)$r['forum_id']);
        });
        stats_cache(true);
        return;
    }
    tx(fn() => q("DELETE FROM $table WHERE id=?", [$id]));
    if ($table === 'forums') {
        forums_cache(true);
        stats_cache(true);
    }
    if ($table === 'groups') groups_cache(true);
    if ($table === 'topics') stats_cache(true);
}
function login_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = ip_addr();
        if (!rate_allow_bucket($ip, 'login_fail')) err('同一IP 1小时内错误次数已达上限');
        hook('login.before_submit', true, []);
        $u = one("SELECT id,password FROM users WHERE username=?", [post('username', 40)]);
        if ($u && password_verify((string)$_POST['password'], $u['password'])) {
            $auth = hook('auth.password_verified', ['continue' => true, 'user_id' => (int)$u['id']], ['user' => $u]);
            if (!is_array($auth) || !empty($auth['continue'])) complete_login((int)$u['id']);
            return;
        }
        rate_hit_bucket($ip, 'login_fail');
        err('用户名或密码错误');
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('登录注意事项', ['请使用用户名登录。', '密码区分大小写。', '公共设备登录后请及时退出。']),
    ]);
    $form_extra = (string)hook('login.form_extra', '', []);
    page('登录', shell_html(auth_tabs_html('login') . '<div class="form-panel auth-panel"><h2>登录</h2><form method="post">' . form_token() . input('用户名', 'username', '', 'text', true) . input('密码', 'password', '', 'password', true) . $form_extra . '<button>登录</button></form><p class="auth-extra"><a href="' . h(route_url('forgot_password')) . '">忘记密码？</a></p></div>', $sidebar));
}
function register_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if (setting('allow_register', '1') !== '1') err('注册已关闭');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        save_user(false);
        $_SESSION['uid'] = (int)($GLOBALS['__last_saved_user_id'] ?? db()->lastInsertId());
        go(consume_auth_return_url());
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('注册注意事项', ['用户名注册后可在个人资料中调整。', '邮箱保密，仅忘记密码时可用。', '请不要使用保留用户名或冒充他人。']),
    ]);
    $form_extra = (string)hook('register.form_extra', '', []);
    page('注册', shell_html(auth_tabs_html('register') . '<div class="form-panel auth-panel"><h2>注册</h2><form method="post">' . form_token() . input('用户名', 'username', '', 'text', true) . input('邮箱', 'email', '', 'email') . input('密码', 'password', '', 'password', true) . input('确认密码', 'password2', '', 'password', true) . $form_extra . '<button>注册</button></form></div>', $sidebar));
}
function profile_page(): void
{
    need_login();
    $u = me();
    $current_ip = '<label class="grid readonly-grid"><span>当前IP</span><input class="readonly-input" type="text" value="' . h(ip_addr()) . '" disabled readonly></label>';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST['id'] = uid();
        save_user(false);
        go(route_url('profile'));
    }
    $profile_extra = (string)hook('profile.after_form', '', ['user' => $u]);
    page('个人资料', form_shell('<div class="form-panel"><h2>个人资料</h2><form method="post">' . form_token() . input('用户名', 'username', $u['username'], 'text', true) . input('邮箱', 'email', $u['email'], 'email') . $current_ip . input('新密码', 'password', '', 'password') . input('确认密码', 'password2', '', 'password') . avatar_picker_html($u) . textarea('简介', 'bio', $u['bio']) . '<button>保存</button></form>' . $profile_extra . '<div class="profile-exit">' . post_action_form(route_url('logout'), '安全退出', [], 'profile-exit-button') . '</div></div>', $u));
}
function user_page(): void
{
    $user = one("SELECT id,username,bio,avatar_style,avatar_seed,group_id,points FROM users WHERE id=?", [id()]) ?: not_found('你访问的页面不存在');
    $g = group_by_id((int)$user['group_id']) ?: ['name' => '用户'];
    $user['group_name'] = $g['name'];
    $tab = $_GET['tab'] ?? 'topics';
    if ($tab === 'notify') user_notify_page();
    else topic_index_page(null, $user);
}
function favorite_page(): void
{
    need_login();
    check();
    $tid = id('topic_id') ?: id();
    if (!$tid) err('参数错误');
    one("SELECT id FROM topics WHERE id=?", [$tid]) ?: err('主题不存在');
    if (one("SELECT 1 FROM favorites WHERE user_id=? AND topic_id=?", [uid(), $tid])) q("DELETE FROM favorites WHERE user_id=? AND topic_id=?", [uid(), $tid]);
    else q("INSERT INTO favorites(user_id,topic_id,created_at) VALUES(?,?,?)", [uid(), $tid, now()]);
    go(route_url('topic', ['id' => $tid]));
}
function topic_index_page(?array $filter_forum = null, ?array $filter_user = null): void
{
    $fid = (int)($filter_forum['id'] ?? 0);
    $profile_uid = (int)($filter_user['id'] ?? 0);
    $own_profile = $profile_uid && uid() === $profile_uid;
    $url = function (string $query) use ($profile_uid, $fid): string {
        parse_str($query, $params);
        if ($profile_uid) return route_url('user', ['id' => $profile_uid] + $params);
        if ($fid) return route_url('forum', ['id' => $fid] + $params);
        return route_url('home', $params);
    };
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = max(1, (int)setting('topics_per_page', '30'));
    $off = ($p - 1) * $size;
    $profile_tab = $_GET['tab'] ?? 'topics';
    if (!in_array($profile_tab, ['topics', 'replies', 'favorites', 'files', 'notifications'], true)) $profile_tab = 'topics';
    if ($profile_uid && !$own_profile && $profile_tab === 'notifications') $profile_tab = 'topics';
    $sort = $profile_uid ? 'post' : (($_GET['sort'] ?? 'comment') === 'post' ? 'post' : 'comment');
    $order = $sort === 'post' ? 't.created_at DESC,t.id DESC' : 't.last_reply_at DESC,t.id DESC';
    $q = trim((string)($_GET['q'] ?? ''));
    require_search_min_chars($q);
    $file_used_bytes = 0;
    $file_quota_bytes = 0;
    $pinned_ids = (!$profile_uid && !$fid && $q === '') ? pinned_topic_ids() : [];
    $where_parts = [];
    $params = [];
    if ($fid) {
        $where_parts[] = 't.forum_id=?';
        $params[] = $fid;
    }
    if ($q !== '') {
        $forum_ids = [];
        foreach (forums_cache() as $f) if (stripos((string)$f['name'], $q) !== false) $forum_ids[] = (int)$f['id'];
        [$condition, $search_params] = topic_search_condition($q, '', 't');
        $where_parts[] = '(' . $condition . ($forum_ids ? ' OR t.forum_id IN (' . implode(',', array_fill(0, count($forum_ids), '?')) . ')' : '') . ')';
        $params = array_merge($params, $search_params);
        if ($forum_ids) $params = array_merge($params, $forum_ids);
    }
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    $stats = stats_cache();
    if ($profile_uid && $profile_tab === 'notifications') {
        $total = notifications_total($profile_uid);
        $unread_total = notifications_unread_total($profile_uid);
        $rows = notifications_list($profile_uid, $size, $off);
    } elseif ($profile_uid && $profile_tab === 'replies') {
        [$topic_meta, $has_next_reply_page] = user_reply_topics_page($profile_uid, $p, $size);
        $total = $p * $size + ($has_next_reply_page ? 1 : 0);
        $topic_ids = array_keys($topic_meta);
        $rows = array_values(rows_by_ids('topics', $topic_ids, topic_list_select_columns('topics')));
        foreach ($rows as &$row) {
            $meta = $topic_meta[(int)$row['id']] ?? ['my_reply_at' => 0, 'my_reply_id' => 0];
            $row['my_reply_at'] = $meta['my_reply_at'];
            $row['my_reply_id'] = $meta['my_reply_id'];
        }
        unset($row);
        usort($rows, fn($a, $b) => ((int)$b['my_reply_at'] <=> (int)$a['my_reply_at']) ?: ((int)$b['my_reply_id'] <=> (int)$a['my_reply_id']));
        $rows = attach_topic_list_users($rows);
    } elseif ($profile_uid && $profile_tab === 'favorites') {
        $fav_rows = q("SELECT topic_id,created_at favorite_at FROM favorites WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?", [$profile_uid, $size, $off])->fetchAll();
        $total = (int)val("SELECT COUNT(*) FROM favorites WHERE user_id=?", [$profile_uid]);
        $fav_map = [];
        foreach ($fav_rows as $fr) $fav_map[(int)$fr['topic_id']] = (int)$fr['favorite_at'];
        $topic_ids = array_keys($fav_map);
        $rows = array_values(rows_by_ids('topics', $topic_ids, topic_list_select_columns('topics')));
        foreach ($rows as &$row) $row['favorite_at'] = $fav_map[(int)$row['id']] ?? 0;
        unset($row);
        usort($rows, fn($a, $b) => (int)$b['favorite_at'] <=> (int)$a['favorite_at']);
        $rows = attach_topic_list_users($rows);
    } elseif ($profile_uid && $profile_tab === 'files') {
        $total = (int)val("SELECT COUNT(*) FROM attachments WHERE user_id=?", [$profile_uid]);
        $file_used_bytes = attachment_used_bytes($profile_uid);
        $file_quota_bytes = attachment_quota_bytes($filter_user);
        $rows = q("SELECT * FROM attachments WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", [$profile_uid, $size, $off])->fetchAll();
    } else {
        if ($profile_uid) {
            $where = $where ? $where . ' AND t.user_id=?' : 'WHERE t.user_id=?';
            $params[] = $profile_uid;
        }
        if (!$profile_uid) {
            $visible_forums = [];
            foreach (forums_cache() as $f) if (forum_group_allowed($f, 'allow_view_groups')) $visible_forums[] = (int)$f['id'];
            if ($visible_forums) {
                $where = $where ? $where . ' AND t.forum_id IN (' . implode(',', array_fill(0, count($visible_forums), '?')) . ')' : 'WHERE t.forum_id IN (' . implode(',', array_fill(0, count($visible_forums), '?')) . ')';
                $params = array_merge($params, $visible_forums);
            } else {
                $where = $where ? $where . ' AND 1=0' : 'WHERE 1=0';
            }
        }
        $total = ($q === '' && !$fid && !$profile_uid) ? (int)$stats['topics'] : (int)q("SELECT COUNT(*) FROM topics t $where", $params)->fetchColumn();
        $rows = q("SELECT " . topic_list_select_columns('t') . " FROM topics t $where ORDER BY $order LIMIT ? OFFSET ?", array_merge($params, [$size, $off]))->fetchAll();
        $rows = attach_topic_list_users($rows);
        if ($pinned_ids && $p === 1) {
            $pinned_rows = attach_topic_list_users(array_values(rows_by_ids('topics', $pinned_ids, topic_list_select_columns('topics'))));
            $by_id = [];
            foreach ($pinned_rows as $r) $by_id[(int)$r['id']] = $r + ['is_pinned' => 1];
            $ordered = [];
            foreach ($pinned_ids as $pid) if (isset($by_id[$pid])) $ordered[] = $by_id[$pid];
            $rows = array_merge($ordered, array_values(array_filter($rows, fn($r) => !in_array((int)$r['id'], $pinned_ids, true))));
        }
    }
    $main = '';
    if ($profile_uid) {
        $tab_items = [
            'topics' => ['label' => '主题', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=topics')],
            'replies' => ['label' => '回帖', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=replies')],
            'favorites' => ['label' => '收藏', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=favorites')],
            'files' => ['label' => '文件', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=files')],
        ];
        if ($own_profile) $tab_items['notifications'] = ['label' => '通知', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=notifications')];
        $main .= '<div class="profile-toolbar">' . tab_bar_html($tab_items, $profile_tab) . ($own_profile ? '<span class="tab-actions"><a href="' . h(route_url('profile')) . '">设置</a>' . (can_access_admin() ? '<a href="' . h(route_url('admin')) . '">后台</a>' : '') . '</span>' : '<span class="tab-actions"><a class="notify-link" href="' . h(route_url('notify', ['id' => $profile_uid])) . '" onclick="openNotify(this.href);return false">私信TA</a></span>') . '</div>';
        if ($profile_tab === 'files') $main .= attachment_summary_html((int)$total, $file_used_bytes, $file_quota_bytes);
    } else {
        if (!$profile_uid && $q === '') {
            $forum_links = '<div class="mobile-forum-strip"><a class="mobile-forum-link' . ($fid ? '' : ' active') . '" href="' . h(route_url('home')) . '">全部</a>';
            foreach (forums_cache() as $f) {
                if (!forum_group_allowed($f, 'allow_view_groups')) continue;
                $forum_links .= '<a class="mobile-forum-link' . ((int)$f['id'] === $fid ? ' active' : '') . '" href="' . h(route_url('forum', ['id' => (int)$f['id']])) . '">' . h($f['name']) . '</a>';
            }
            $main .= $forum_links . '</div>';
        }
        $tab_items = [
            'comment' => ['label' => '新评论', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=comment')],
            'post' => ['label' => '新帖子', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=post')],
        ];
        $hook_tabs = hook('topic.index_tabs', $tab_items, ['forum_id' => $fid, 'query' => $q, 'sort' => $sort]);
        if (is_array($hook_tabs)) $tab_items = $hook_tabs;
        $toolbar_actions = (string)hook('topic.toolbar_actions', '', ['forum_id' => $fid, 'query' => $q, 'sort' => $sort]);
        $main .= '<div class="topic-toolbar">' . tab_bar_html($tab_items, $sort) . $toolbar_actions . (can_speak() ? '<a class="tab-post" href="' . h(route_url('topic_edit', ['fid' => $fid ?: null])) . '">+ 发帖</a>' : '') . '</div>';
    }
    $main .= '<ul class="post-list">';
    if ($profile_uid && $profile_tab === 'notifications') {
        mark_notifications_read($profile_uid);
        if (!$rows) $main .= '<li class="empty-state">暂无通知</li>';
        else foreach ($rows as $i => $n) {
            if (($unread_total ?? 0) > 0 && $off + $i === ($unread_total ?? 0)) $main .= '<li class="notification-read-divider">下面的通知已读</li>';
            $main .= notification_row_html($n);
        }
    } elseif ($profile_uid && $profile_tab === 'files') {
        if (!$rows) $main .= '<li class="empty-state">暂无文件</li>';
        else foreach ($rows as $file) $main .= attachment_row_html($file);
    } elseif (!$rows) {
        $empty = $profile_uid ? ($profile_tab === 'replies' ? '暂无回帖' : ($profile_tab === 'favorites' ? '暂无收藏' : '暂无主题')) : '暂无主题';
        $main .= '<li class="empty-state">' . ($q !== '' ? '没有找到匹配的主题' : $empty) . '</li>';
    } else {
        foreach ($rows as $t) {
            $time = (int)($t['my_reply_at'] ?? $t['favorite_at'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
            $t['time'] = $time;
            $t['forum'] = forum_by_id((int)$t['forum_id']) ?: ['id' => 0, 'name' => ''];
            $main .= topic_list_row($t, $sort);
        }
    }
    $page_query = ($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . ($profile_uid ? 'tab=' . $profile_tab : 'sort=' . $sort);
    $pagination = ($profile_uid && $profile_tab === 'replies') ? simple_paginate($p > 1, (bool)($has_next_reply_page ?? false), $p, $url($page_query)) : paginate($total, $p, $size, $url($page_query));
    $main .= '</ul>' . ($pagination !== '' ? '<div class="pagination-bar">' . $pagination . '</div>' : '');
    $sidebar_user = $profile_uid ? $filter_user : null;
    $is_home_first_page = !$profile_uid && !$filter_forum && $q === '' && $p === 1;
    $sidebar = sidebar_stack_html([sidebar_user_card_html($sidebar_user, false, $fid), sidebar_bio_card_html($filter_user), (!$profile_uid ? quick_forums_html() . ($is_home_first_page ? sidebar_stats_card_html() : '') : '')], ['is_home_first_page' => $is_home_first_page]);
    $title = $profile_uid ? $filter_user['username'] : ($filter_forum ? $filter_forum['name'] : '首页');
    $seo = [];
    if ($profile_uid) $seo = page_seo('user', ['id' => $profile_uid], (string)($filter_user['bio'] ?? $filter_user['username']));
    elseif ($filter_forum) $seo = page_seo('forum', ['id' => $fid], (string)($filter_forum['description'] ?? $filter_forum['name']));
    page($title, shell_html($main, $sidebar, $is_home_first_page ? 'home-mobile-sidebar' : ''), $seo);
}
function home_page(): void
{
    topic_index_page();
}
function search_page(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') go(route_url('home'));
    $q = post('q', 120);
    if ($q === '') go(route_url('home'));
    require_search_min_chars($q);
    $seconds = post_interval_seconds();
    $wait = $seconds - (time() - (int)($_SESSION['last_search_at'] ?? 0));
    if ($seconds > 0 && $wait > 0) err('搜索太频繁，请 ' . $wait . ' 秒后再试');
    $_SESSION['last_search_at'] = time();
    go(route_url('home', ['q' => $q]));
}
function forum_page(): void
{
    $fid = id();
    $f = forum_by_id($fid) ?: not_found('你访问的页面不存在');
    if (!forum_group_allowed($f, 'allow_view_groups')) err('无权限');
    remember_forum($fid);
    topic_index_page($f);
}
function topic_page(): void
{
    if (!id() && id('replyid')) {
        $reply = one("SELECT topic_id FROM replies WHERE id=?", [id('replyid')]) ?: not_found('你访问的帖子可能已经删除');
        go(route_url('topic', ['id' => (int)$reply['topic_id'], 'replyid' => id('replyid')]));
    }
    $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: not_found('你访问的帖子可能已经删除');
    $t = attach_users([$t])[0];
    $forum = forum_by_id((int)$t['forum_id']) ?: not_found('你访问的页面不存在');
    if (!forum_group_allowed($forum, 'allow_view_groups')) err('无权限');
    remember_forum((int)$t['forum_id']);
    if (mark_viewed((int)$t['id'])) {
        q("UPDATE topics SET view_count=view_count+1 WHERE id=?", [(int)$t['id']]);
        $t['view_count'] = (int)$t['view_count'] + 1;
    }
    $size = max(1, (int)setting('replies_per_page', '50'));
    $replyid = id('replyid');
    if ($replyid > 0) {
        $reply = one("SELECT id,created_at FROM replies WHERE id=? AND topic_id=?", [$replyid, (int)$t['id']]);
        if ($reply) {
            $before = (int)q("SELECT COUNT(*) FROM replies WHERE topic_id=? AND (created_at<? OR (created_at=? AND id<=?))", [(int)$t['id'], (int)$reply['created_at'], (int)$reply['created_at'], $replyid])->fetchColumn();
            $_GET['p'] = (string)max(1, (int)ceil($before / $size));
        } else {
            not_found('你访问的帖子可能已经删除');
        }
    }
    $p = max(1, (int)($_GET['p'] ?? 1));
    $off = ($p - 1) * $size;
    $replies = attach_users(q("SELECT * FROM replies WHERE topic_id=? ORDER BY created_at,id LIMIT ? OFFSET ?", [(int)$t['id'], $size, $off])->fetchAll());
    fire('topic.after_view', ['topic' => $t, 'replies' => $replies, 'page' => $p, 'page_size' => $size, 'reply_count' => (int)$t['reply_count']]);
    $fav = uid() ? one("SELECT 1 FROM favorites WHERE user_id=? AND topic_id=?", [uid(), (int)$t['id']]) : null;
    $topic_ops = '';
    if (uid()) $topic_ops .= quote_reply_action($t);
    if (uid()) $topic_ops .= '<a class="fav-btn' . ($fav ? ' active' : '') . '" href="' . h(route_url('favorite', ['id' => (int)$t['id']])) . '" title="' . ($fav ? '已收藏' : '收藏') . '" aria-label="' . ($fav ? '已收藏' : '收藏') . '">' . svg_icon($fav ? 'favorite_fill' : 'favorite') . '<span>' . ($fav ? '已收藏' : '收藏') . '</span></a>';
    if (can_manage_topic($t)) $topic_ops .= '<a class="icon-action icon-edit" href="' . h(route_url('topic_edit', ['id' => (int)$t['id']])) . '" title="编辑"><span>编辑</span></a>';
    $breadcrumb = '<div class="breadcrumb"><a href="' . h(route_url('home')) . '">首页</a><span>/</span><a href="' . h(route_url('forum', ['id' => (int)$forum['id']])) . '">' . h($forum['name']) . '</a></div>';
    $main = $breadcrumb . '<div class="post-topic-title"><h1 class="post-content-title">' . h($t['title']) . '</h1>' . topic_stats_html((int)$t['view_count'], (int)$t['reply_count']) . '</div><ul class="post-list topic-post-list">';
    if ($p === 1) $main .= topic_post_row($t, $t['body'], (int)$t['created_at'], $topic_ops ? '<div class="post-ops">' . $topic_ops . '</div>' : '');
    foreach ($replies as $i => $r) {
        $reply_ops = uid() ? quote_reply_action($r) : '';
        if (can_manage_reply($r)) $reply_ops .= '<a class="icon-action icon-edit" href="' . h(route_url('reply_edit', ['id' => (int)$r['id']])) . '" title="编辑"><span>编辑</span></a>';
        $reply_ops = $reply_ops !== '' ? '<div class="post-ops">' . $reply_ops . '</div>' : '';
        $main .= topic_post_row($r, $r['body'], (int)$r['created_at'], $reply_ops, '', '', (int)$r['id'] === $replyid, ['reply_position' => $off + $i + 1]);
    }
    if (!$replies && (int)$t['reply_count'] === 0) $main .= '<li class="empty-state">暂无回复</li>';
    $pagination = paginate((int)$t['reply_count'], $p, $size, route_url('topic', ['id' => (int)$t['id']]));
    if ($pagination !== '') $main .= '</ul><div class="pagination-bar">' . $pagination . '</div>';
    else $main .= '</ul>';
    $can_reply_forum = forum_group_allowed($forum, 'allow_reply_groups');
    $reply_status = uid() ? (can_speak() ? ($can_reply_forum ? '说两句' : '无回帖权限') : '禁止发言') : '登录后回复';
    $help = '<span class="reply-status">' . $reply_status . '</span>';
    $main .= '<div class="reply-panel" id="reply"><div class="reply-panel-head"><h3>发表回复</h3>' . $help . '</div>';
    if (can_speak() && $can_reply_forum) {
        $reply_form_extra = (string)hook('reply.form_extra', '', ['topic' => $t, 'editing' => false]);
        $main .= '<form class="ajax-reply-form" method="post" action="' . h(route_url('reply_edit')) . '">' . form_token() . '<input type="hidden" name="topic_id" value="' . (int)$t['id'] . '">' . textarea('内容', 'body', '', true) . $reply_form_extra . '<button>回复</button></form>';
    } elseif (!uid()) {
        $main .= '<div class="reply-login-box"><a href="' . h(route_url('login')) . '">登录后回复</a></div>';
    } elseif (!$can_reply_forum) {
        $main .= '<div class="reply-login-box disabled">当前用户组无回帖权限</div>';
    } else {
        $main .= '<div class="reply-login-box disabled">当前用户禁止发言</div>';
    }
    $main .= '</div>';
    page($t['title'] . ' - ' . $forum['name'], shell_html($main, sidebar_stack_html([sidebar_user_card_html(null, true), quick_forums_html()])), page_seo('topic', ['id' => (int)$t['id']], (string)$t['body']));
}
function topic_edit_page(): void
{
    need_speak();
    $t = ['id' => 0, 'forum_id' => id('fid') ?: 1, 'title' => '', 'body' => '', 'user_id' => uid()];
    if (id()) {
        $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') go(route_url('topic', ['id' => save_topic()]));
    $title = id() ? '编辑主题' : '发表主题';
    $topic_ops = '';
    if (id() && can_manage()) {
        $style = preg_match('/#[0-9a-fA-F]{6}/', (string)($t['highlight_style'] ?? ''), $m) ? $m[0] : '';
        $colors = ['#d94b4b', '#d97706', '#16a34a', '#2563eb', '#7c3aed'];
        $swatches = '<div class="topic-color-swatches">';
        foreach ($colors as $color) $swatches .= '<button class="topic-color-swatch' . ($style === $color ? ' active' : '') . '" type="button" data-topic-color="' . h($color) . '" style="background:' . h($color) . '" aria-label="' . h($color) . '"></button>';
        $swatches .= '<button class="topic-color-swatch topic-color-clear' . ($style === '' ? ' active' : '') . '" type="button" data-topic-color="" aria-label="取消高亮"></button>';
        $swatches .= '</div>';
        $topic_ops = '<label class="grid topic-action-field"><span>操作</span><select name="topic_action" data-topic-action><option value="">不操作</option><option value="delete">删除</option><option value="pin">置顶</option><option value="unpin">取消置顶</option><option value="highlight">高亮</option><option value="mute_author">禁言作者</option></select></label><label class="grid topic-highlight-field is-hidden" data-topic-highlight-wrap><span>颜色</span><input type="hidden" name="highlight_style" value="' . h($style) . '" data-topic-highlight-value>' . $swatches . '</label>';
    }
    $attachments = attachment_uploader_html();
    $form_extra = (string)hook('topic.form_extra', '', ['topic' => $t, 'editing' => id() > 0]);
    page($title, shell_html('<div class="form-panel topic-form-panel"><h2>' . $title . '</h2><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '">' . select_forum((int)$t['forum_id']) . input('标题', 'title', $t['title'], 'text', true) . textarea('内容', 'body', $t['body'], true) . $attachments . $form_extra . $topic_ops . '<button>保存</button></form></div>', sidebar_stack_html([sidebar_user_card_html(), sidebar_notice_card_html('Markdown 说明', ['**粗体**，*斜体*', '`代码`', '- 列表项', '| 表头 | 表头 | + | --- | --- |', '[链接文字](https://example.com)', '![图片描述](https://example.com/a.jpg)'])])));
}
function reply_edit_page(): void
{
    need_speak();
    $r = ['id' => 0, 'topic_id' => id('topic_id'), 'body' => '', 'user_id' => uid()];
    if (id()) {
        $r = one("SELECT * FROM replies WHERE id=?", [id()]) ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'mute_author') {
            if (!can_manage()) err('无权限');
            if ((int)$r['user_id'] === 1) err('不能操作超级管理员');
            q("UPDATE users SET is_muted=1 WHERE id=?", [(int)$r['user_id']]);
            go(route_url('topic', ['id' => (int)$r['topic_id'], 'replyid' => (int)$r['id']]));
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $editing = id() > 0;
        $saved = save_reply();
        if (!empty($saved['redirect'])) go($saved['redirect']);
        if (ajax_request() && $editing) go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
        if (ajax_request()) {
            $row = one("SELECT * FROM replies WHERE id=?", [$saved['reply_id']]) ?: err('回复不存在');
            $row = attach_users([$row])[0];
            $ops = quote_reply_action($row);
            if (can_manage_reply($row)) $ops .= '<a class="icon-action icon-edit" href="' . h(route_url('reply_edit', ['id' => (int)$row['id']])) . '" title="编辑"><span>编辑</span></a>';
            $ops = '<div class="post-ops">' . $ops . '</div>';
            $topic = one("SELECT view_count,reply_count FROM topics WHERE id=?", [$saved['topic_id']]) ?: ['view_count' => 0, 'reply_count' => 0];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'html' => topic_post_row($row, $row['body'], (int)$row['created_at'], $ops), 'stats_html' => topic_stats_html((int)$topic['view_count'], (int)$topic['reply_count'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
    }
    $ops = (int)$r['id'] > 0 ? '<span class="reply-edit-ops">' . (can_manage() ? post_action_form(route_url('reply_edit'), '禁言作者', ['id' => (int)$r['id'], 'do' => 'mute_author'], 'reply-mute-link', '确定禁言作者？') : '') . post_action_form(route_url('delete'), '删除', ['type' => 'replies', 'id' => (int)$r['id'], 'back' => 'topic', 'tid' => (int)$r['topic_id']], 'reply-delete-link', '确定删除？') . '</span>' : '';
    $reply_form_extra = (string)hook('reply.form_extra', '', ['reply' => $r, 'editing' => (int)$r['id'] > 0]);
    page('编辑回复', form_shell('<div class="form-panel reply-edit-panel"><div class="reply-edit-head"><h2>编辑回复</h2>' . $ops . '</div><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="topic_id" value="' . (int)$r['topic_id'] . '">' . textarea('内容', 'body', $r['body'], true) . attachment_uploader_html() . $reply_form_extra . '<button>保存</button></form></div>'));
}

function admin_nav(string $tab): string
{
    return sidebar_stack_html([sidebar_user_card_html()], ['is_admin' => true, 'admin_tab' => $tab]);
}
function admin_tabs(string $tab): string
{
    $items = ['settings' => '设置', 'forums' => '版块', 'groups' => '用户组', 'topics' => '主题', 'replies' => '回帖', 'users' => '用户', 'plugins' => '插件'];
    $h = '<div class="tab-bar admin-tabs">';
    foreach ($items as $k => $v) $h .= '<a class="tab' . ($tab === $k ? ' active' : '') . '" href="' . h(admin_url(['tab' => $k])) . '">' . $v . '</a>';
    return $h . '</div>';
}
function admin_layout(string $tab, string $body): string
{
    return shell_html(admin_tabs($tab) . $body, admin_nav($tab));
}
function admin_plugin_action_form(string $id, string $action, string $label, string $class = '', string $confirm = ''): string
{
    return post_action_form(admin_url(['tab' => 'plugins']), $label, ['plugin_id' => $id, 'plugin_action' => $action], $class, $confirm);
}
function admin_plugin_uninstall_form(string $id): string
{
    return '<form class="post-action-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" data-plugin-uninstall="1" data-confirm="确定卸载插件？">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'uninstall', 'keep_plugin_data' => '1']) . '<button type="submit" class="danger">卸载</button></form>';
}
function admin_plugin_share_form(string $id): string
{
    return '<form class="post-action-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" target="_blank" rel="noopener" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'share']) . '<button type="submit">分享</button></form>';
}
function admin_plugin_entry_toggle_form(array $plugin, string $entry, string $label): string
{
    if (!plugin_uses_entry($plugin, $entry)) return '';
    $id = (string)$plugin['id'];
    $checked = plugin_entry_enabled($plugin, $entry);
    return '<form class="post-action-form plugin-entry-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'entry_toggle', 'entry' => $entry, 'entry_enabled' => '0']) . '<label class="plugin-entry-check"><input type="checkbox" name="entry_enabled" value="1" data-auto-submit' . ($checked ? ' checked' : '') . '><span>' . h($label) . '</span></label></form>';
}
function admin_plugins_page_html(): string
{
    $plugins = plugins();
    uasort($plugins, function (array $a, array $b): int {
        $a_time = is_file((string)($a['file'] ?? '')) ? (int)filemtime((string)$a['file']) : 0;
        $b_time = is_file((string)($b['file'] ?? '')) ? (int)filemtime((string)$b['file']) : 0;
        return ($b_time <=> $a_time) ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $enabled_count = 0;
    foreach ($plugins as $plugin) if (is_array($plugin) && plugin_enabled($plugin)) $enabled_count++;
    $head_left = '<div class="admin-plugin-summary"><strong>插件</strong><span>已发现 ' . count($plugins) . ' 个，已启用 ' . $enabled_count . ' 个</span></div>';
    $html = admin_plugins_tabs_html('local') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head_left, '') . '<ul class="admin-manage-list plugin-list">';
    foreach ($plugins as $plugin) {
        if (!is_array($plugin)) continue;
        $id = (string)$plugin['id'];
        $enabled = plugin_enabled($plugin);
        $manage_url = '';
        if ($enabled && !empty($plugin['admin_tabs']) && is_array($plugin['admin_tabs'])) {
            foreach ($plugin['admin_tabs'] as $key => $fn) {
                if (is_string($key) && is_string($fn)) {
                    $manage_url = admin_url(['tab' => $key]);
                    break;
                }
            }
        }
        $ops = $manage_url !== '' ? '<a class="plugin-manage-link" href="' . h($manage_url) . '">管理</a>' : '';
        $ops .= $enabled
            ? admin_plugin_action_form($id, 'disable', '停用', 'danger', '确定停用插件？')
            : admin_plugin_action_form($id, 'enable', '启用', 'plugin-enable');
        $entry_ops = admin_plugin_entry_toggle_form($plugin, 'feature_links', '快捷功能');
        $entry_ops .= admin_plugin_entry_toggle_form($plugin, 'sidebar_cards', '边栏卡片');
        $ops .= admin_plugin_share_form($id);
        $ops .= admin_plugin_uninstall_form($id);
        $meta = [];
        if ((string)($plugin['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$plugin['version'];
        if ((string)($plugin['author'] ?? '') !== '') $meta[] = (string)$plugin['author'];
        $features = [];
        if (!empty($plugin['hooks'])) $features[] = count($plugin['hooks']) . ' 个钩子';
        if (!empty($plugin['routes'])) $features[] = count($plugin['routes']) . ' 个路由';
        if (!empty($plugin['admin_tabs'])) $features[] = count($plugin['admin_tabs']) . ' 个后台页';
        $file = str_replace(__DIR__ . '/', '', (string)($plugin['file'] ?? ''));
        $entry_line = $entry_ops !== '' ? '<div class="plugin-entry-line"><span class="plugin-entry-label">展示位置</span><div class="plugin-entry-options">' . $entry_ops . '</div></div>' : '';
        $html .= '<li class="admin-list-item admin-object-row plugin-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h((string)$plugin['name']) . '</strong><span class="admin-flag' . ($enabled ? ' on' : '') . '">' . h($enabled ? '已启用' : '已停用') . '</span></div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ($meta ? '<span>' . h(implode(' / ', $meta)) . '</span>' : '') . ($features ? '<span>' . h(implode(' / ', $features)) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($plugin['description'] ?? '')) . '</div><div class="plugin-file">' . h($file) . '</div></div>' . $entry_line . '<div class="admin-inline-ops plugin-ops">' . $ops . '</div></li>';
    }
    if (!$plugins) $html .= '<li class="empty-state">暂无插件，放入 plugins/*/plugin.php 后重新打开本页即可显示。</li>';
    return $html . '</ul></div>';
}
function admin_plugins_tabs_html(string $active): string
{
    return tab_bar_html([
        'local' => ['label' => '本地插件', 'href' => admin_url(['tab' => 'plugins'])],
        'market' => ['label' => '插件市场', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market'])],
    ], $active, 'plugin-tabs');
}
function plugin_market_search_form(string $query): string
{
    $base = '<input type="hidden" name="a" value="admin"><input type="hidden" name="tab" value="plugins"><input type="hidden" name="view" value="market">';
    $clear = $query !== '' ? '<a class="admin-search-clear" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'market'])) . '">清空</a>' : '';
    return '<form class="admin-table-search" method="get" action="' . h(index_url()) . '">' . $base . '<div class="admin-search-field"><input name="q" value="' . h($query) . '" placeholder="搜索标题 / 插件ID / 制作者" minlength="' . SEARCH_MIN_CHARS . '"><button class="admin-search-submit" type="submit">搜索</button></div>' . $clear . '</form>';
}
function plugin_market_item_matches_query(array $item, string $query): bool
{
    if ($query === '') return true;
    foreach (['title', 'name', 'id', 'creator', 'description'] as $key) {
        if (stripos((string)($item[$key] ?? ''), $query) !== false) return true;
    }
    return false;
}
function admin_plugins_market_page_html(): string
{
    $market = plugin_market_fetch();
    $items = is_array($market['plugins'] ?? null) ? $market['plugins'] : [];
    $local = plugins();
    $update_ids = [];
    foreach ($items as $id => $item) {
        if (isset($local[$id]) && is_array($item) && plugin_market_update_info($local[$id], $item) !== null) $update_ids[$id] = true;
    }
    uksort($items, fn(string $a, string $b): int => (int)isset($update_ids[$b]) <=> (int)isset($update_ids[$a]));
    $query = trim((string)($_GET['q'] ?? ''));
    $head_left = '<div class="admin-plugin-summary"><strong>插件市场</strong><span>仅展示官方审核通过的插件，安装后默认仍需手动启用。</span></div>';
    $head_right = '<div class="plugin-head-actions">' . plugin_market_search_form($query) . '<a class="admin-search-clear" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'market'])) . '">刷新</a></div>';
    $html = admin_plugins_tabs_html('market') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head_left, $head_right) . '<ul class="admin-manage-list plugin-list">';
    if (!(int)($market['ok'] ?? 0)) {
        $html .= '<li class="empty-state">' . h((string)($market['message'] ?? '插件市场暂不可用')) . '</li>';
        return $html . '</ul></div>';
    }
    $shown = 0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        if (!plugin_id_valid($id)) continue;
        if (!plugin_market_item_matches_query($item, $query)) continue;
        $shown++;
        $installed = isset($local[$id]);
        $remote_sha = (string)($item['sha256'] ?? '');
        $needs_update = isset($update_ids[$id]);
        $label = $installed ? ($needs_update ? '更新' : '重新安装') : '安装';
        $button_class = $installed && !$needs_update ? '' : 'plugin-enable';
        $ops = '<form class="post-action-form" method="post" action="' . h(admin_url(['tab' => 'plugins', 'view' => 'market'])) . '" data-confirm="确定' . h($label) . '该插件？插件代码将写入本地 plugins 目录。">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'market_install']) . '<button type="submit"' . ($button_class !== '' ? ' class="' . h($button_class) . '"' : '') . '>' . h($label) . '</button></form>';
        $meta = [];
        if ((string)($item['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$item['version'];
        $creator = trim((string)($item['creator'] ?? ''));
        $meta[] = '插件制作者 ' . ($creator !== '' ? $creator : '未声明');
        if ((int)($item['updated_at'] ?? 0) > 0) $meta[] = date('Y-m-d H:i', (int)$item['updated_at']);
        $topic_url = (string)($item['url'] ?? '');
        $title = h((string)($item['name'] ?? $id));
        if ($topic_url !== '') $title = '<a class="admin-content-title" href="' . h($topic_url) . '" target="_blank" rel="noopener">' . $title . '</a>';
        else $title = '<strong class="admin-content-title">' . $title . '</strong>';
        $flag = $installed ? '<span class="admin-flag ' . ($needs_update ? 'update' : 'on') . '">' . h($needs_update ? '可更新' : '已安装') . '</span>' : '<span class="admin-flag">未安装</span>';
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . ($needs_update ? ' plugin-update-item' : '') . '"><div class="admin-row-main"><div class="plugin-title-line">' . $title . $flag . '</div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ($meta ? '<span>' . h(implode(' / ', $meta)) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($item['description'] ?? '')) . '</div><div class="plugin-file">' . h(substr($remote_sha, 0, 16)) . '</div></div><div class="admin-inline-ops plugin-ops">' . $ops . '</div></li>';
    }
    if ($shown === 0) $html .= '<li class="empty-state">' . h($query !== '' ? '没有匹配的插件。' : '暂无已审核通过的插件。') . '</li>';
    return $html . '</ul></div>';
}
function admin_plugin_tab_html(string $tab): ?string
{
    foreach (plugins() as $plugin) {
        if (!is_array($plugin) || !plugin_enabled($plugin)) continue;
        $fn = $plugin['admin_tabs'][$tab] ?? null;
        if (is_string($fn)) {
            plugin_load($plugin);
            if (function_exists($fn)) return (string)$fn($plugin);
        }
    }
    return null;
}
function admin_page(): void
{
    need_admin();
    $tab = $_GET['tab'] ?? 'settings';
    $q = trim((string)($_GET['q'] ?? ''));
    require_search_min_chars($q);
    $topic_field = admin_topic_field((string)($_GET['field'] ?? 'title'));
    $reply_field = admin_reply_field((string)($_GET['reply_field'] ?? 'body'));
    $topic_forum_id = max(0, (int)($_GET['forum_id'] ?? 0));
    $user_group_id = max(0, (int)($_GET['group_id'] ?? 0));
    $user_banned_filter = isset($_GET['is_banned']) && $_GET['is_banned'] !== '' ? (int)$_GET['is_banned'] : -1;
    $user_muted_filter = isset($_GET['is_muted']) && $_GET['is_muted'] !== '' ? (int)$_GET['is_muted'] : -1;
    $manageable = can_manage();
    $admin_size = 50;
    $admin_page = max(1, (int)($_GET['p'] ?? 1));
    $admin_offset = ($admin_page - 1) * $admin_size;
    if ($tab === 'settings' && (string)($_GET['debug_log'] ?? '') === 'view') {
        header('Content-Type: text/plain; charset=utf-8');
        echo is_file(DEBUG_LOG_FILE) ? (string)file_get_contents(DEBUG_LOG_FILE) : '';
        exit;
    }
    if ($tab === 'plugins' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $plugin_action = (string)($_POST['plugin_action'] ?? '');
        $plugin_id = (string)($_POST['plugin_id'] ?? '');
        if ($plugin_action === 'enable') {
            plugin_set_enabled($plugin_id, true);
            set_flash('插件已启用');
        } elseif ($plugin_action === 'disable') {
            plugin_set_enabled($plugin_id, false);
            set_flash('插件已停用');
        } elseif ($plugin_action === 'uninstall') {
            $keep_data = (string)($_POST['keep_plugin_data'] ?? '1') === '1';
            plugin_uninstall($plugin_id, $keep_data);
            set_flash($keep_data ? '插件已卸载，数据已保留' : '插件已卸载，数据已清理');
        } elseif ($plugin_action === 'share') {
            plugin_share_post_page($plugin_id);
            return;
        } elseif ($plugin_action === 'entry_toggle') {
            plugin_set_entry_enabled($plugin_id, (string)($_POST['entry'] ?? ''), (string)($_POST['entry_enabled'] ?? '0') === '1');
            if (ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => 1, 'message' => '插件入口显示已更新'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            set_flash('插件入口显示已更新');
        } elseif ($plugin_action === 'market_install') {
            plugin_market_install($plugin_id);
            set_flash('插件已安装或更新，请确认后手动启用。');
        } else err('参数错误');
        go(admin_url(['tab' => 'plugins', 'view' => (string)($_GET['view'] ?? '') === 'market' ? 'market' : null]));
    }
    if ($tab === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((string)($_POST['debug_log_action'] ?? '') === 'clear') {
            if (!is_dir(dirname(DEBUG_LOG_FILE))) mkdir(dirname(DEBUG_LOG_FILE), 0755, true);
            file_put_contents(DEBUG_LOG_FILE, '', LOCK_EX);
            set_flash('Debug日志已清空');
            go(admin_url(['tab' => 'settings']));
        }
        if (isset($_POST['clear_opcache'])) {
            clear_opcache_cache();
            set_flash('OPcache已清理');
            go(admin_url(['tab' => 'settings']));
        }
        save_settings();
        go(admin_url(['tab' => 'settings']));
    }
    $html = '';
    if ($tab === 'settings') {
        $s = settings_cache();
        $avatar_mirror_field = '<label class="grid avatar-mirror-field"><span>头像目录设置<small>记录已完成本地镜像的 style 目录，多个用逗号隔开。</small></span><div class="avatar-mirror-box"><textarea name="avatar_mirror_styles" data-avatar-mirror-styles-input>' . h($s['avatar_mirror_styles'] ?? '') . '</textarea><div class="row avatar-mirror-actions"><button type="button" class="btn alt" data-avatar-mirror-button data-url="' . h(route_url('avatar_mirror')) . '" data-styles="' . h(implode(',', array_keys(avatar_styles()))) . '" data-seed-count="' . avatar_seed_count('dylan') . '">镜像远程目录</button><span class="avatar-mirror-status" data-avatar-mirror-status></span></div></div></label>';
        $fields = [
            'site_name' => ['label' => '网站名', 'required' => true],
            'site_base_url' => ['label' => '网站固定地址', 'type' => 'url'],
            'site_keywords' => ['label' => '关键字'],
            'site_description' => ['label' => '网站介绍', 'type' => 'textarea'],
            'mail_from' => ['label' => '系统发件邮箱', 'type' => 'email'],
            'pinned_topic_ids' => ['label' => '置顶主题ID'],
            'header_html' => ['label' => '页头HTML代码', 'type' => 'textarea'],
            'footer_html' => ['label' => '页脚HTML代码', 'type' => 'textarea'],
            'topics_per_page' => ['label' => '列表单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'replies_per_page' => ['label' => '回帖单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'mail_virtual' => ['label' => '是否虚拟发送邮件', 'type' => 'checkbox'],
            'pretty_url' => ['label' => '是否开启rewrite', 'type' => 'checkbox'],
            'avatar_mirror' => ['html' => $avatar_mirror_field],
            'site_closed' => ['label' => '是否关闭', 'type' => 'checkbox'],
            'debug_mode' => ['label' => 'Debug模式', 'type' => 'checkbox'],
            'allow_register' => ['label' => '是否允许注册', 'type' => 'checkbox'],
            'default_group_id' => ['label' => '新用户默认用户组', 'type' => 'select', 'options' => array_column(groups_cache(), 'name', 'id')],
            'reserved_usernames' => ['label' => '保留用户名', 'type' => 'textarea'],
            'register_per_hour' => ['label' => '1小时内注册限制', 'type' => 'number', 'min' => 1, 'max' => 100],
            'login_fail_per_hour' => ['label' => '1小时内登录错误限制', 'type' => 'number', 'min' => 1, 'max' => 100],
            'reset_fail_per_hour' => ['label' => '1小时内操作错误限制', 'type' => 'number', 'min' => 1, 'max' => 100],
            'post_interval_seconds' => ['label' => '发帖/回复间隔（秒）', 'type' => 'number', 'min' => 0, 'max' => 3600, 'help' => '发帖/回复间隔设置为 0 可关闭限制，默认 5 秒一次。'],
            'attachment_max_count' => ['label' => '附件数量限制', 'type' => 'number', 'min' => 0, 'help' => '设置为 0 可关闭附件上传。'],
            'attachment_max_mb' => ['label' => '单个附件大小（MB）', 'type' => 'number', 'min' => 0, 'help' => '设置为 0 可关闭附件上传，实际上限受服务器配置影响。'],
        ];
        $debug_cards = '<div class="settings-tool-card"><div><strong>清理OPcache</strong><span>刷新已编译脚本缓存，适合代码更新后手动触发。</span></div>' . post_action_form(admin_url(['tab' => 'settings']), '清理', ['clear_opcache' => '1'], 'settings-tool-action') . '</div>';
        if ((string)($s['debug_mode'] ?? '0') === '1') {
            $debug_cards .= '<div class="settings-tool-card"><div><strong>Debug日志</strong><span>' . h(DEBUG_LOG_FILE) . '</span></div><div class="settings-tool-actions">' . post_action_form(admin_url(['tab' => 'settings']), '清空', ['debug_log_action' => 'clear'], 'settings-tool-action', '确定清空Debug日志？') . '<a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'debug_log' => 'view'])) . '" target="_blank">查看</a></div></div>';
        }
        $html .= '<div class="form-panel settings-form"><form method="post">' . form_token() . render_form_fields($fields, $s) . '<div class="row settings-actions"><button type="submit">保存</button></div></form><div class="settings-tool-grid">' . $debug_cards . '</div></div>';
    } elseif ($tab === 'users') {
        $html .= admin_object_list_html('users', $q, $manageable,
            fn(): int => admin_count('users', $q, 'title', $user_group_id, $user_banned_filter, $user_muted_filter),
            fn(): array => admin_users_list($q, $admin_size, $admin_offset, $user_group_id, $user_banned_filter, $user_muted_filter),
            fn(int $total): string => admin_pagination('users', $q, $total, $admin_page, $admin_size, '', $user_group_id, $user_banned_filter, $user_muted_filter)
        );
    } elseif ($tab === 'groups') {
        $html .= '<table class="list admin-bulk-list"><tr><th>名称</th><th>上传空间</th><th>用户和内容管理</th><th>后台管理</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => 0])) . '">添加</a></th></tr>';
        foreach (groups_cache() as $g) {
            $quota = (int)($g['upload_quota_mb'] ?? 0);
            $html .= '<tr><td><strong class="admin-name">' . h($g['name']) . '</strong></td><td>' . h($quota > 0 ? $quota . ' MB' : '不限') . '</td><td>' . admin_flag((int)($g['allow_manage'] ?? 0)) . '</td><td>' . admin_flag((int)($g['allow_admin'] ?? 0)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => (int)$g['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'groups', 'id' => (int)$g['id'], 'tab' => 'groups'], 'danger', '确定删除？') . '</td></tr>';
        }
        $html .= '</table>';
    } elseif ($tab === 'forums') {
        $html .= '<table class="list admin-bulk-list"><tr><th>名称</th><th>排序</th><th>权限</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => 0])) . '">添加</a></th></tr>';
        foreach (forums_cache() as $f) {
            $perm = [];
            $perm[] = '浏览:' . (forum_group_ids($f, 'allow_view_groups') ? count(forum_group_ids($f, 'allow_view_groups')) . '组' : '不限');
            $perm[] = '发帖:' . (forum_group_ids($f, 'allow_post_groups') ? count(forum_group_ids($f, 'allow_post_groups')) . '组' : '不限');
            $perm[] = '回帖:' . (forum_group_ids($f, 'allow_reply_groups') ? count(forum_group_ids($f, 'allow_reply_groups')) . '组' : '不限');
            $html .= '<tr><td><strong class="admin-name">' . h($f['name']) . '</strong></td><td><span class="admin-group-pill">' . (int)$f['sort'] . '</span></td><td>' . h(implode(' / ', $perm)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => (int)$f['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'forums', 'id' => (int)$f['id'], 'tab' => 'forums'], 'danger', '确定删除？') . '</td></tr>';
        }
        $html .= '</table>';
    } elseif ($tab === 'topics') {
        $html .= admin_object_list_html('topics', $q, $manageable,
            fn(): int => admin_count('topics', $q, $topic_field, 0, -1, -1, $topic_forum_id),
            fn(): array => admin_topics_list($q, $admin_size, $admin_offset, $topic_field, $topic_forum_id),
            fn(int $total): string => admin_pagination('topics', $q, $total, $admin_page, $admin_size, $topic_field),
            $manageable ? admin_topics_tools_html() : '<a class="admin-search-link" href="' . h(admin_url(['tab' => 'trash'])) . '">回收站</a>'
        );
    } elseif ($tab === 'replies') {
        $html .= admin_object_list_html('replies', $q, $manageable,
            fn(): int => admin_count('replies', $q, $reply_field),
            fn(): array => admin_replies_list($q, $admin_size, $admin_offset, $reply_field),
            fn(int $total): string => admin_pagination('replies', $q, $total, $admin_page, $admin_size, $reply_field)
        );
    } elseif ($tab === 'trash') {
        $trash_table = in_array((string)($_GET['table'] ?? ''), ['users', 'topics', 'replies'], true) ? (string)$_GET['table'] : '';
        $total = admin_trash_count($trash_table);
        $url = admin_url(['tab' => 'trash', 'table' => $trash_table]);
        if ($manageable) $html .= admin_bulk_delete_form_open('trash', '');
        $html .= '<div class="admin-list-panel">' . admin_list_head(admin_trash_search_form($trash_table), '') . '<ul class="admin-manage-list">';
        foreach (admin_trash_list($trash_table, $admin_size, $admin_offset) as $row) $html .= admin_trash_row($row);
        if ($total === 0) $html .= '<li class="empty-state">暂无数据</li>';
        $html .= '</ul></div>';
        if ($manageable) $html .= admin_bulk_delete_bar('trash');
        $phtml = paginate($total, $admin_page, $admin_size, $url);
        $html .= $phtml === '' ? '' : '<div class="pagination-bar">' . $phtml . '</div>';
    } elseif ($tab === 'plugins') {
        $html .= (string)($_GET['view'] ?? '') === 'market' ? admin_plugins_market_page_html() : admin_plugins_page_html();
    } else {
        $plugin_html = admin_plugin_tab_html((string)$tab);
        if ($plugin_html === null) not_found('你访问的页面不存在');
        $html .= $plugin_html;
    }
    page('后台', admin_layout($tab, $html));
}
function admin_edit_page(): void
{
    need_admin();
    $type = $_GET['type'] ?? $_POST['type'] ?? '';
    if ($type === 'user') need_manage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($type === 'user') save_user(true);
        elseif ($type === 'group') save_group();
        elseif ($type === 'forum') save_forum();
        else err('参数错误');
        go(admin_url(['tab' => $type === 'user' ? 'users' : $type . 's']));
    }
    if ($type === 'user') {
        $u = admin_user_form_data(id());
        $tab = 'users';
        $is_new = id() === 0;
        $body = input('用户名', 'username', $u['username'], 'text', true) . input('邮箱', 'email', $u['email'], 'email') . input($is_new ? '密码' : '新密码', 'password', '', 'password', $is_new) . input('确认密码', 'password2', '', 'password', $is_new) . avatar_picker_html($u) . select_group((int)$u['group_id']) . number_input('积分', 'points', (int)($u['points'] ?? 0)) . checkbox('禁止访问', 'is_banned', (bool)(int)($u['is_banned'] ?? 0)) . checkbox('禁止发言', 'is_muted', (bool)(int)($u['is_muted'] ?? 0)) . textarea('简介', 'bio', $u['bio']);
    } elseif ($type === 'group') {
        $g = id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0, 'upload_quota_mb' => 0];
        $tab = 'groups';
        $body = input('名称', 'name', $g['name'], 'text', true) . number_input('上传空间（MB）', 'upload_quota_mb', (int)($g['upload_quota_mb'] ?? 0), 0, null, true, '0 表示不限制。') . checkbox('允许用户和内容管理', 'allow_manage', (bool)(int)($g['allow_manage'] ?? 0)) . checkbox('允许后台管理', 'allow_admin', (bool)(int)($g['allow_admin'] ?? 0));
    } elseif ($type === 'forum') {
        $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0, 'allow_view_groups' => '', 'allow_post_groups' => '', 'allow_reply_groups' => ''];
        if (!$f) err('版块不存在');
        $tab = 'forums';
        $body = input('名称', 'name', $f['name'], 'text', true) . number_input('排序', 'sort', $f['sort']) . textarea('描述', 'description', $f['description']) . forum_group_select_options($f, 'allow_view_groups', '允许浏览用户组') . forum_group_select_options($f, 'allow_post_groups', '允许发帖用户组') . forum_group_select_options($f, 'allow_reply_groups', '允许回帖用户组');
    } else err('参数错误');
    page('编辑', admin_layout($tab, '<div class="form-panel"><h2>编辑</h2><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>保存</button></form></div>'));
}
function robots_page(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow:\nSitemap: " . absolute_url(app_url('sitemap.xml')) . "\n";
    exit;
}
function sitemap_page(): void
{
    $urls = [
        ['loc' => absolute_url(route_url('home')), 'lastmod' => time()],
    ];
    foreach (forums_cache() as $f) {
        if ((string)($f['allow_view_groups'] ?? '') !== '') continue;
        $urls[] = ['loc' => absolute_url(route_url('forum', ['id' => (int)$f['id']])), 'lastmod' => time()];
    }
    foreach (q("SELECT t.id,t.created_at,t.updated_at FROM topics t JOIN forums f ON f.id=t.forum_id WHERE f.allow_view_groups='' ORDER BY t.id DESC LIMIT 5000")->fetchAll() as $t) {
        $urls[] = ['loc' => absolute_url(route_url('topic', ['id' => (int)$t['id']])), 'lastmod' => max((int)$t['created_at'], (int)$t['updated_at'])];
    }
    foreach (q("SELECT id FROM users ORDER BY id DESC LIMIT 5000")->fetchAll() as $u) {
        $urls[] = ['loc' => absolute_url(route_url('user', ['id' => (int)$u['id']])), 'lastmod' => time()];
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $url) {
        echo "  <url><loc>" . h($url['loc']) . "</loc><lastmod>" . date('c', (int)$url['lastmod']) . "</lastmod></url>\n";
    }
    echo "</urlset>\n";
    exit;
}
function favicon_page(): void
{
    header('Location: ' . asset_url('logo.svg'), true, 302);
    exit;
}

secure_session_start();
parse_path_route();
if (!db_schema_ready()) simple_error_page('请先安装');
check();
need_site_access();
if ((string)($_GET['a'] ?? '') === 'admin' && (string)($_GET['tab'] ?? 'settings') === 'plugins' && can_access_admin()) plugins(true);
fire('app.boot');
try {
    if (($_GET['__route_not_found'] ?? '') === '1') {
        not_found(($_GET['__route_not_found_kind'] ?? '') === 'topic' ? '你访问的帖子可能已经删除' : '你访问的页面不存在');
    }
    $a = $_GET['a'] ?? 'home';
    $do = $_GET['do'] ?? '';
    $static_routes = [
        'home' => 'home_page',
        'robots.txt' => 'robots_page',
        'sitemap.xml' => 'sitemap_page',
        'search' => 'search_page',
        'attachment' => 'attachment_page',
        'attachment_upload' => 'attachment_upload_page',
        'avatar_mirror' => 'avatar_mirror_page',
        'login' => 'login_page',
        'register' => 'register_page',
        'forgot_password' => 'forgot_password_page',
        'reset_password' => 'reset_password_page',
        'profile' => 'profile_page',
        'user' => 'user_page',
        'notify' => 'user_notify_page',
        'favorite' => 'favorite_page',
        'forum' => 'forum_page',
        'topic' => 'topic_page',
        'topic_edit' => 'topic_edit_page',
        'reply_edit' => 'reply_edit_page',
    ];
    if (isset($static_routes[$a])) {
        $static_routes[$a]();
    }
    elseif (in_array($a, ['favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'], true)) favicon_page();
    elseif ($a === 'form_error') {
        $data = is_array($_SESSION['form_error'] ?? null) ? $_SESSION['form_error'] : [];
        unset($_SESSION['form_error']);
        error_page('操作失败', trim((string)($data['message'] ?? '操作失败')));
    }
    elseif ($a === 'logout') {
        require_post();
        session_destroy();
        go(route_url('home'));
    }
    elseif ($a === 'delete') {
        require_post();
        need_login();
        $type = (string)($_POST['type'] ?? '');
        $row = deletable_post_row($type, id());
        if (!$row || !in_array($type, ['topics', 'replies'], true)) err('参数错误');
        if (($type === 'topics' && !can_manage_topic($row)) || ($type === 'replies' && !can_manage_reply($row))) err('无权限');
        del($type, id());
        $back = (string)($_POST['back'] ?? '');
        if ($back === 'topic') go(route_url('topic', ['id' => (int)($_POST['tid'] ?? 0)]));
        go(route_url('home'));
    } elseif ($a === 'admin') {
        if ($do === 'edit') admin_edit_page();
        elseif ($do === 'delete') {
            require_post();
            need_admin();
            $type = ['user' => 'users', 'group' => 'groups', 'forum' => 'forums'][$_POST['type'] ?? ''] ?? ($_POST['type'] ?? '');
            if (!in_array($type, ['users', 'groups', 'forums', 'topics', 'replies'], true)) err('参数错误');
            if (!can_admin_delete($type, id())) err('无权限');
            del($type, id());
            go(admin_url(['tab' => $_POST['tab'] ?? 'settings']));
        } elseif ($do === 'restore') {
            require_post();
            need_admin();
            need_manage();
            $type = trash_restore_row(id());
            if (in_array($type, ['users', 'topics', 'replies'], true)) stats_cache(true);
            go(admin_url(['tab' => 'trash']));
        } elseif ($do === 'rebuild_fts') {
            require_post();
            need_admin();
            need_manage();
            $start_id = max(1, (int)($_POST['start_id'] ?? 1));
            $count = topic_fts_rebuild_from($start_id);
            set_flash('已重建主题索引：' . $count . ' 条');
            go(admin_url(['tab' => 'topics']));
        } elseif ($do === 'batch_action') {
            require_post();
            need_admin();
            need_manage();
            $tab = $_POST['tab'] ?? '';
            $action = (string)($_POST['batch_action'] ?? 'delete');
            if (!in_array($tab, ['users', 'topics', 'replies', 'trash'], true)) err('参数错误');
            $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
            if ($tab === 'trash' && $action === 'restore') {
                foreach ($ids as $trash_id) {
                    $type = trash_restore_row($trash_id);
                    if (in_array($type, ['users', 'topics', 'replies'], true)) stats_cache(true);
                }
            } elseif ($tab === 'users' && in_array($action, ['mute', 'unmute', 'ban', 'unban'], true)) {
                $field = in_array($action, ['ban', 'unban'], true) ? 'is_banned' : 'is_muted';
                $value = in_array($action, ['ban', 'mute'], true) ? 1 : 0;
                foreach ($ids as $uid) if ($uid !== 1 && $uid !== uid()) q("UPDATE users SET $field=? WHERE id=?", [$value, $uid]);
            } elseif ($tab === 'topics' && $action === 'move') {
                $forum_id = max(1, (int)($_POST['forum_id'] ?? 0));
                if (!forum_by_id($forum_id)) err('版块不存在');
                foreach ($ids as $tid) {
                    $row = one("SELECT forum_id FROM topics WHERE id=?", [$tid]);
                    if (!$row) continue;
                    q("UPDATE topics SET forum_id=?,updated_at=? WHERE id=?", [$forum_id, now(), $tid]);
                    refresh_forum_last_topic((int)$row['forum_id']);
                    refresh_forum_last_topic($forum_id);
                }
                forums_cache(true);
            } elseif ($action === 'delete') {
                foreach ($ids as $rid) if (can_admin_delete($tab, $rid)) del($tab, $rid);
            }
            go(admin_url(['tab' => $tab]));
        } else admin_page();
    } elseif (plugin_route((string)$a)) {
    } else not_found('你访问的页面不存在');
} catch (Throwable $e) {
    debug_log_write('未捕获异常', $e);
    err(uid() === 1 ? exception_detail($e) : (database_error($e) ? '数据库出了点小问题' : '操作失败'));
}
