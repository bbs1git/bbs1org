<?php

declare(strict_types=1);

namespace app\optional;

use RuntimeException;
use ReflectionFunction;
use Throwable;
use WeakMap;
use InvalidArgumentException;

if (!defined('APP_ROOT')) {
    exit;
}

const PLUGIN_MARKET_ENDPOINT = 'https://bbs1.org/index.php';
const PLUGIN_MARKET_CACHE_TTL = 900;
const PLUGIN_MARKET_CACHE_FILE = 'plugin-market-list-cache.json';
const PLUGIN_MARKET_PAGE_SIZE = 40;
const PLUGIN_UPLOAD_FILE = 'plugin_upload.data';
const PLUGIN_UPLOAD_MAX = 20971520;

final class Plugin
{
private static array $registry_cache = [];
private static array $asset_required = [];
private static ?array $asset_manifest_cache = null;
private static ?array $plugins_cache = null;
private static bool $asset_missing_retry = false;
private static ?array $asset_ondemand_cache = null;

private static function plugin_runtime_cache_rows_valid(array $rows): bool
{
    $fields = array_fill_keys(['id', 'file', 'config', 'entries', 'hooks', 'routes', 'admin_tabs', 'assets', 'assets_mode', 'cron'], true);
    foreach ($rows as $row) {
        if (!is_array($row) || array_diff_key($row, $fields) || array_diff_key($fields, $row)) return false;
        $id = (string)$row['id'];
        $file = str_replace('\\', '/', ltrim((string)$row['file'], '/'));
        if (!self::plugin_id_valid($id) || $id === 'plugin_market' || $file !== 'app/plugins/' . $id . '/plugin.php') return false;
        foreach (['config', 'entries', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron'] as $field) {
            if (!is_array($row[$field])) return false;
        }
    }
    return true;
}

public static function plugin_runtime_cache_rows(bool $refresh = false): array
{
    $rows = $refresh ? null : json_decode(setting('cache_plugins'), true);
    if (is_array($rows) && self::plugin_runtime_cache_rows_valid($rows)) return $rows;
    $rows = [];
    foreach (q("SELECT id,file,manifest_json,config_json,entries_json FROM app_plugins WHERE enabled=1 ORDER BY id")->fetchAll() as $row) {
        $id = (string)($row['id'] ?? '');
        $file = str_replace('\\', '/', ltrim((string)($row['file'] ?? ''), '/'));
        $manifest = plugin_json_decode($row['manifest_json'] ?? '', null);
        if (!self::plugin_id_valid($id) || $id === 'plugin_market' || $file !== 'app/plugins/' . $id . '/plugin.php' || $manifest === null) continue;
        $rows[] = [
            'id' => $id,
            'file' => $file,
            'config' => plugin_json_decode($row['config_json'] ?? '') ?? [],
            'entries' => plugin_json_decode($row['entries_json'] ?? '') ?? [],
            'hooks' => is_array($manifest['hooks'] ?? null) ? $manifest['hooks'] : [],
            'routes' => is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [],
            'admin_tabs' => is_array($manifest['admin_tabs'] ?? null) ? $manifest['admin_tabs'] : [],
            'assets' => is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [],
            'assets_mode' => (string)($manifest['assets_mode'] ?? ''),
            'cron' => is_array($manifest['cron'] ?? null) ? $manifest['cron'] : [],
        ];
    }
    save_settings_values(['cache_plugins' => plugin_json_encode($rows)]);
    return $rows;
}

public static function plugin_update_row(string $id, array $values, bool $touch_updated_at = true): void
{
    if (!self::plugin_id_valid($id)) throw new InvalidArgumentException('插件 ID 无效');
    $allowed = array_flip(['name', 'version', 'file', 'code_hash', 'manifest_json', 'config_json', 'entries_json', 'enabled', 'status', 'disabled_reason', 'installed_at']);
    if (array_diff_key($values, $allowed)) throw new InvalidArgumentException('插件字段无效');
    foreach (['manifest_json', 'config_json', 'entries_json'] as $field) {
        if (array_key_exists($field, $values)) $values[$field] = plugin_json_encode(plugin_json_decode($values[$field]) ?? []);
    }
    if (!$values) return;
    if ($touch_updated_at) $values['updated_at'] = now();
    $fields = array_keys($values);
    q('UPDATE app_plugins SET ' . implode('=?,', $fields) . '=? WHERE id=?', array_merge(array_values($values), [$id]));
}

private static function plugin_registry_row(array $row): ?array
{
    $id = (string)($row['id'] ?? '');
    $file = str_replace('\\', '/', ltrim((string)($row['file'] ?? ''), '/'));
    $manifest = plugin_json_decode($row['manifest_json'] ?? '', null);
    if (!self::plugin_id_valid($id) || $file !== 'app/plugins/' . $id . '/plugin.php' || $manifest === null) return null;
    return array_merge($manifest, [
        'id' => $id,
        'name' => (string)$row['name'],
        'version' => (string)$row['version'],
        'enabled' => (int)$row['enabled'] === 1,
        'disabled_reason' => (string)($row['disabled_reason'] ?? ''),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'file' => APP_ROOT . '/' . $file,
        'config' => plugin_json_decode($row['config_json'] ?? '') ?? [],
        'entries' => plugin_json_decode($row['entries_json'] ?? '') ?? [],
    ]);
}

public static function plugin_id_valid(string $id): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) === 1;
}

public static function plugin_load(array $plugin): void
{
    $file = (string)($plugin['file'] ?? '');
    if ($file === '' || !is_file($file)) return;
    if (array_key_exists($file, $GLOBALS['__plugin_raw'] ?? [])) return;
    $GLOBALS['__plugin_raw'][$file] = include $file;
}

public static function plugin_callback_exists(mixed $callback): bool
{
    return is_string($callback) && $callback !== '' && function_exists($callback);
}

public static function plugin_call(array $plugin, callable $callback): mixed
{
    try {
        self::plugin_load($plugin);
        return $callback();
    } catch (Throwable $e) {
        self::plugin_disable_after_exception((string)($plugin['id'] ?? ''), $e);
        throw $e;
    }
}

public static function plugins(bool $refresh = false): array
{
    if (!$refresh && self::$plugins_cache !== null) return self::$plugins_cache;
    $plugins = [];
    foreach (self::plugin_runtime_cache_rows($refresh) as $plugin) {
        $id = (string)$plugin['id'];
        $plugin['file'] = APP_ROOT . '/' . (string)$plugin['file'];
        $plugin['enabled'] = true;
        $plugins[$id] = $plugin;
    }
    plugin_runtime_cache_reset();
    return self::$plugins_cache = $plugins;
}

public static function plugin_enabled(array $plugin): bool
{
    return !empty($plugin['enabled']);
}

public static function plugin_entry_definitions(): array
{
    return [
        'feature_links' => ['hook' => 'sidebar.feature_links', 'label' => '快捷功能'],
        'sidebar_cards' => ['hook' => 'sidebar.stack', 'label' => '边栏卡片'],
        'home_tabs' => ['hook' => 'topic.index_tabs', 'label' => '首页Tab'],
        'profile_tabs' => ['hook' => 'user.profile_tabs', 'label' => '个人主页Tab'],
        'profile_settings_tabs' => ['hook' => 'profile.settings_tabs', 'label' => '个人设置Tab'],
        'profile_card' => ['hook' => 'user.menu_links', 'label' => '个人卡片'],
        'topic_actions' => ['hook' => 'topic.actions', 'label' => '主题操作'],
        'admin_tabs' => ['hook' => 'admin.tabs', 'label' => '后台Tab'],
        'top_menu' => ['hook' => 'top.menu_links', 'label' => '顶部菜单'],
        'top_actions' => ['hook' => 'top.bar.actions', 'label' => '顶部栏入口'],
    ];
}

public static function plugin_entry_hook_name(string $entry): string
{
    return (string)(self::plugin_entry_definitions()[$entry]['hook'] ?? '');
}

public static function plugin_uses_entry(array $plugin, string $entry): bool
{
    $hook = self::plugin_entry_hook_name($entry);
    return $hook !== '' && isset($plugin['hooks'][$hook]);
}

public static function plugin_entry_enabled(array $plugin, string $entry): bool
{
    if (!self::plugin_uses_entry($plugin, $entry)) return false;
    return !array_key_exists($entry, (array)($plugin['entries'] ?? [])) || !empty($plugin['entries'][$entry]);
}

public static function plugin_registry(?string $id = null, bool $refresh = false): array
{
    if ($id !== null && !self::plugin_id_valid($id)) return [];
    if ($refresh) self::$registry_cache = [];
    $key = $id === null ? '*' : $id;
    if (isset(self::$registry_cache[$key])) return self::$registry_cache[$key];
    if ($id !== null && isset(self::$registry_cache['*'])) {
        return isset(self::$registry_cache['*'][$id]) ? [$id => self::$registry_cache['*'][$id]] : [];
    }
    $plugins = [];
    $sql = "SELECT id,name,version,file,manifest_json,config_json,entries_json,enabled,disabled_reason,updated_at FROM app_plugins";
    $rows = q($sql . ($id === null ? ' ORDER BY id' : ' WHERE id=?'), $id === null ? [] : [$id])->fetchAll();
    foreach ($rows as $row) {
        if ((string)$row['id'] === 'plugin_market') continue;
        $plugin = self::plugin_registry_row($row);
        if ($plugin) $plugins[(string)$plugin['id']] = $plugin;
    }
    self::$registry_cache[$key] = $plugins;
    return $plugins;
}

public static function plugin_registry_flush(): void
{
    self::$registry_cache = [];
}

public static function plugin_market_url(string $action): string
{
    return append_url_query(PLUGIN_MARKET_ENDPOINT, ['a' => $action]);
}

public static function remote_http_request(string $url, int $timeout = 8, array $headers = [], ?array $post_fields = null, array $options = []): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '服务器未启用 cURL'];
    $timeout = max(1, $timeout);
    $connect_timeout = min($timeout, max(1, (int)($options['connect_timeout'] ?? min(4, $timeout))));
    $max_bytes = max(0, (int)($options['max_bytes'] ?? 0));
    $user_agent = trim((string)($options['user_agent'] ?? '')) ?: 'bbs1org/' . APP_VERSION;
    $ch = curl_init($url);
    if (!$ch) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '无法初始化请求'];
    $body = '';
    $too_large = false;
    $options = [
        CURLOPT_RETURNTRANSFER => $max_bytes === 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $connect_timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $user_agent,
    ];
    if ($max_bytes > 0) {
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$too_large, $max_bytes): int {
            if (strlen($body) + strlen($chunk) > $max_bytes) {
                $too_large = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
    }
    if (setting('ignore_ssl_errors') === '1') {
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    if ($headers) $options[CURLOPT_HTTPHEADER] = $headers;
    if ($post_fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post_fields);
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    curl_setopt_array($ch, $options);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($too_large) return ['ok' => false, 'status' => $status, 'body' => '', 'error' => '响应内容过大'];
    if ($result === false) return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error !== '' ? $error : '请求失败'];
    if ($max_bytes === 0) $body = (string)$result;
    if ($status < 200 || $status >= 300) return ['ok' => false, 'status' => $status, 'body' => (string)$body, 'error' => 'HTTP ' . $status];
    return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'error' => ''];
}

private static function plugin_market_view(string $view): string
{
    return $view === 'beta' ? 'beta' : 'certified';
}

private static function plugin_market_view_param(string $market_view): ?string
{
    return $market_view !== 'certified' ? $market_view : null;
}

private static function plugin_market_topic_url(string $plugin_id, int $topic_id, string $market_view): string
{
    $market = self::plugin_market_cache_read(false, self::plugin_market_view($market_view))
        ?? self::plugin_market_cache_read(false, self::plugin_market_view($market_view) === 'beta' ? 'certified' : 'beta');
    $item = is_array($market) ? ($market['plugins'][$plugin_id] ?? null) : null;
    if (!is_array($item)) return '';
    if ($topic_id > 0 && (int)($item['topic_id'] ?? 0) > 0 && (int)$item['topic_id'] !== $topic_id) return '';
    $url = (string)($item['url'] ?? '');
    if ($url === '') return '';
    $site = parse_url(PLUGIN_MARKET_ENDPOINT);
    $target = parse_url($url);
    if (!is_array($site) || !is_array($target)) return '';
    $host = strtolower((string)($site['host'] ?? ''));
    $target_host = strtolower((string)($target['host'] ?? ''));
    if ($host === '' || ($target_host !== $host && !str_ends_with($target_host, '.' . $host))) return '';
    return $url;
}

private static function plugin_market_cache_file(string $market_view = 'certified'): string
{
    return DATA_DIR . '/' . PLUGIN_MARKET_CACHE_FILE . ($market_view === 'beta' ? '-beta' : '');
}

private static function plugin_market_cache_read(bool $fresh, string $market_view = 'certified'): ?array
{
    $file = self::plugin_market_cache_file($market_view);
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['plugins'] ?? null) || !$data['plugins']) return null;
    $fetched_at = (int)($data['fetched_at'] ?? 0);
    if ($fetched_at < 1) return null;
    if ($fresh && now() - $fetched_at >= PLUGIN_MARKET_CACHE_TTL) return null;
    return $data;
}

private static function plugin_market_cache_write(array $data, string $market_view = 'certified'): void
{
    $data['fetched_at'] = now();
    @file_put_contents(self::plugin_market_cache_file($market_view), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
}

public static function plugin_market_fetch(bool $need_code = false, bool $force = false, string $plugin_id = '', int $topic_id = 0, string $market_view = 'certified', string $authorization_code = ''): array
{
    $market_view = self::plugin_market_view($market_view);
    if (!$need_code && !$force) {
        $cached = self::plugin_market_cache_read(true, $market_view);
        if ($cached !== null) return $cached;
    }
    $feed_url = append_url_query(self::plugin_market_url('plugin_market_feed'), ['mode' => $need_code ? 'install' : 'list', 'market_view' => $market_view === 'beta' ? 'beta' : null]);
    if ($need_code) {
        if (!self::plugin_id_valid($plugin_id) || $topic_id < 1) return ['ok' => 0, 'message' => '插件市场参数无效', 'plugins' => []];
        $feed_url = append_url_query($feed_url, ['id' => $plugin_id, 'topic_id' => $topic_id, 'authorization_code' => trim($authorization_code) !== '' ? trim($authorization_code) : null]);
    }
    $response = self::remote_http_request($feed_url, $need_code ? 8 : 5, ['Accept: application/json']);
    if (!$response['ok']) {
        if (!$need_code) {
            $stale = self::plugin_market_cache_read(false, $market_view);
            if ($stale !== null) return $stale;
        }
        return ['ok' => 0, 'message' => '无法连接插件市场' . ((string)$response['error'] !== '' ? '：' . (string)$response['error'] : ''), 'plugins' => []];
    }
    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) return ['ok' => 0, 'message' => '插件市场返回格式错误', 'plugins' => []];
    $plugins = [];
    foreach ((array)($data['plugins'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        $code = (string)($item['code'] ?? '');
        $price_points = max(0, (int)($item['price_points'] ?? 0));
        $online_install = !array_key_exists('online_install', $item) ? $price_points === 0 : !empty($item['online_install']);
        $item_market_view = self::plugin_market_view((string)($item['market_status'] ?? 'certified'));
        if (!self::plugin_id_valid($id) || $id === 'plugin_market' || ($need_code && $online_install && trim($code) === '')) continue;
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
            'price_points' => $price_points,
            'online_install' => $online_install,
            'market_status' => $item_market_view,
            'required' => $item_market_view === 'certified' && !empty($item['required']),
            'updated_at' => (int)($item['updated_at'] ?? 0),
            'sha256' => (string)($item['sha256'] ?? hash('sha256', $code)),
            'url' => clean_site_base_url((string)($item['url'] ?? '')),
        ];
        if ($need_code && $online_install) $plugins[$id]['code'] = $code;
    }
    $result = ['ok' => (int)($data['ok'] ?? 1), 'message' => (string)($data['message'] ?? ''), 'plugins' => $plugins];
    if (!$need_code && (int)$result['ok'] === 1 && $plugins) {
        $result['fetched_at'] = now();
        self::plugin_market_cache_write($result, $market_view);
        return $result;
    }
    if (!$need_code) {
        $stale = self::plugin_market_cache_read(false, $market_view);
        if ($stale !== null) return $stale;
    }
    return $result;
}

private static function plugin_manifest_code_id(string $code): string
{
    $tokens = token_get_all($code);
    $return_arrays = [];
    $brace_depth = 0;
    $count = count($tokens);
    $next_code_token = static function (int $offset) use ($tokens, $count): int {
        for ($i = $offset; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $i;
        }
        return -1;
    };
    $literal_value = static function ($token): ?string {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || strlen($token[1]) < 2) return null;
        $quote = $token[1][0];
        if ($quote !== "'" && $quote !== '"') return null;
        $value = substr($token[1], 1, -1);
        return $quote === "'" ? (string)preg_replace('/\\\\([\\\\\'])/', '$1', $value) : stripcslashes($value);
    };
    $constants = [];
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $brace_depth++;
            continue;
        }
        if ($token === '}') {
            $brace_depth = max(0, $brace_depth - 1);
            continue;
        }
        if ($brace_depth === 0 && is_array($token) && $token[0] === T_CONST) {
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
                $equal = $next_code_token($j + 1);
                $value = $equal >= 0 && $tokens[$equal] === '=' ? $next_code_token($equal + 1) : -1;
                if ($value >= 0 && ($decoded = $literal_value($tokens[$value])) !== null) $constants[$tokens[$j][1]] = $decoded;
            }
            continue;
        }
        if ($brace_depth === 0 && is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], 'define') === 0) {
            $open = $next_code_token($i + 1);
            $name_at = $open >= 0 && $tokens[$open] === '(' ? $next_code_token($open + 1) : -1;
            $comma = $name_at >= 0 ? $next_code_token($name_at + 1) : -1;
            $value = $comma >= 0 && $tokens[$comma] === ',' ? $next_code_token($comma + 1) : -1;
            $name = $name_at >= 0 ? $literal_value($tokens[$name_at]) : null;
            $decoded = $value >= 0 ? $literal_value($tokens[$value]) : null;
            if ($name !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1 && $decoded !== null) $constants[$name] = $decoded;
        }
        if ($brace_depth !== 0 || !is_array($token) || $token[0] !== T_RETURN) continue;
        $start = $next_code_token($i + 1);
        if ($start < 0) continue;
        if ($tokens[$start] === '[') {
            $return_arrays[] = $start;
            continue;
        }
        if (is_array($tokens[$start]) && $tokens[$start][0] === T_ARRAY) {
            $open = $next_code_token($start + 1);
            if ($open >= 0 && $tokens[$open] === '(') $return_arrays[] = $open;
        }
    }
    foreach (array_reverse($return_arrays) as $start) {
        $depth = 1;
        for ($i = $start + 1; $i < $count && $depth > 0; $i++) {
            $token = $tokens[$i];
            if (in_array($token, ['[', '(', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if (in_array($token, [']', ')', '}'], true)) {
                $depth--;
                continue;
            }
            if ($depth !== 1 || $literal_value($token) !== 'id') continue;
            $arrow = $next_code_token($i + 1);
            $value = $arrow >= 0 && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW ? $next_code_token($arrow + 1) : -1;
            if ($value < 0) return '';
            $id = $literal_value($tokens[$value]);
            if ($id === null && is_array($tokens[$value]) && $tokens[$value][0] === T_STRING) $id = $constants[$tokens[$value][1]] ?? null;
            return is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id) === 1 ? $id : '';
        }
    }
    return '';
}

public static function plugin_market_install(string $id, int $topic_id, bool $auto_enable = false, string $market_view = 'certified', string $authorization_code = ''): void
{
    if (!self::plugin_id_valid($id)) err('插件不存在');
    if ($id === 'plugin_market') err('该插件 ID 为系统保留');
    require_writable_dir(PLUGIN_DIR, '插件目录不可写，请检查 app/plugins/ 目录权限');
    $market_view = self::plugin_market_view($market_view);
    $market = self::plugin_market_fetch(true, true, $id, $topic_id, $market_view, $authorization_code);
    $item = $market['plugins'][$id] ?? null;
    if (!is_array($item)) err((string)($market['message'] ?? '') ?: '插件市场没有返回该插件');
    if (empty($item['online_install'])) err((int)($item['price_points'] ?? 0) > 0 ? '授权码无效、已使用或已过期，请在插件页面重新获取' : '该插件暂不支持在线安装');
    $code = (string)($item['code'] ?? '');
    if (!str_starts_with(ltrim($code), '<?php')) err('插件代码格式错误');
    if ((string)$item['sha256'] !== '' && !hash_equals((string)$item['sha256'], hash('sha256', $code))) err('插件代码校验失败');
    if (self::plugin_manifest_code_id($code) !== $id) err('插件代码 ID 与市场 ID 不一致');
    $dir = PLUGIN_DIR . '/' . $id;
    $file = $dir . '/plugin.php';
    $plugin_file_loaded = array_key_exists($file, $GLOBALS['__plugin_raw'] ?? []);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) err('插件目录创建失败');
    require_writable_dir($dir, '插件目录不可写，请检查 app/plugins/ 目录权限');
    if (is_file($file)) {
        $backup_dir = DATA_DIR . '/plugin-backups';
        if (!is_dir($backup_dir) && !mkdir($backup_dir, 0755, true)) err('插件备份目录创建失败');
        if (!copy($file, $backup_dir . '/' . $id . '-' . date('YmdHis') . '.php')) err('现有插件备份失败');
    }
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $code, LOCK_EX) === false) err('插件写入失败');
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        err('插件安装失败');
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    $target_enabled = $auto_enable ? 1 : 0;
    self::plugin_update_row($id, ['enabled' => $target_enabled, 'status' => $target_enabled ? 'enabled' : 'disabled', 'disabled_reason' => ''], true);
    q("UPDATE app_cron_tasks SET enabled=? WHERE plugin_id=?", [$target_enabled, $id]);
    save_settings_values([
        'plugin_' . $id . '_market_sha256' => (string)$item['sha256'],
        'plugin_' . $id . '_market_topic_id' => (string)(int)$item['topic_id'],
        'plugin_sync_pending' => self::plugin_sync_pending_mark($id),
        'plugin_force_sync' => self::plugin_force_sync_mark($id),
    ]);
    self::plugin_registry_flush();
    if (!$auto_enable) return;
    if ($plugin_file_loaded) return;
    self::plugin_registry_sync([$id], true);
    self::plugin_force_sync_clear($id);
    self::plugin_set_enabled($id, true, true);
    self::plugin_assets_rebuild([$id]);
    save_settings_values(['plugin_sync_pending' => '0']);
}

public static function plugin_market_install_page(): void
{
    need_admin();
    require_post();
    $auto_enable = (string)($_POST['auto_enable'] ?? '') === '1';
    $raw_view = (string)($_POST['market_view'] ?? '');
    $market_view = self::plugin_market_view($raw_view);
    $return_view = $raw_view === 'required' ? 'required' : ($market_view === 'beta' ? 'beta' : null);
    $plugin_id = (string)($_POST['plugin_id'] ?? '');
    $topic_id = max(0, (int)($_POST['topic_id'] ?? 0));
    $authorization_code = trim((string)($_POST['authorization_code'] ?? ''));
    if ($authorization_code === '') {
        $requires_authorization = (string)($_POST['authorization_required'] ?? '') === '1';
        if (!$requires_authorization) {
            $market = self::plugin_market_fetch(false, true, '', 0, $market_view);
            $item = is_array($market['plugins'][$plugin_id] ?? null) ? $market['plugins'][$plugin_id] : null;
            $requires_authorization = is_array($item) && (int)($item['price_points'] ?? 0) > 0;
        }
        if ($requires_authorization) {
            $topic_url = self::plugin_market_topic_url($plugin_id, $topic_id, $market_view);
            $link = $topic_url !== ''
                ? '<a class="plugin-market-authorization-link" href="' . h($topic_url) . '" target="_blank" rel="noopener">获取授权码</a>'
                : '';
            $tip = '<p class="plugin-market-authorization-tip">' . ($link !== ''
                ? '在新的页面里购买并点击“授权码”，复制后关闭该页面，回到这里粘贴即可。'
                : '请在插件页面购买后点击“授权码”获取，复制后回到这里粘贴即可。') . '</p>';
            $form = '<form class="plugin-market-authorization-install-form" method="post" action="' . h(route_url('plugin_market_install')) . '" data-replace-target=".plugin-list-panel">' . form_token() . hidden_inputs(['plugin_id' => $plugin_id, 'topic_id' => $topic_id, 'auto_enable' => $auto_enable ? '1' : '0', 'market_view' => (string)$return_view]) . '<label class="grid"><span>授权码</span><input type="text" name="authorization_code" maxlength="40" autocomplete="off" spellcheck="false" placeholder="粘贴一次性授权码" required autofocus></label>' . $tip . '<div class="confirm-actions">' . $link . '<button type="button" class="btn alt" data-modal-close>取消</button><button type="submit" class="btn plugin-enable" data-loading-text="安装中">在线' . ($auto_enable ? '安装/更新' : '安装') . '</button></div></form>';
            json_response(['ok' => 1, 'modal' => ['title' => '填写授权码', 'html' => $form]]);
        }
    }
    self::plugin_market_install($plugin_id, $topic_id, $auto_enable, $market_view, $authorization_code);
    $message = $auto_enable ? '插件已安装或更新并启用。' : '插件已安装或更新，已停用。';
    if (ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'message' => $message, 'refresh' => 1, 'redirect' => admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $return_view])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_flash($message);
    go(admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $return_view]));
}

public static function plugin_market_share_page(): void
{
    need_admin();
    require_post();
    $id = (string)($_POST['plugin_id'] ?? '');
    $target = self::plugin_id_valid($id) ? (self::plugin_registry($id)[$id] ?? null) : null;
    if (!is_array($target)) err('插件不存在');
    $file = (string)($target['file'] ?? '');
    $code = $file !== '' && is_file($file) ? file_get_contents($file) : false;
    if (!is_string($code) || trim($code) === '') err('插件文件不存在或为空');
    if (preg_match('/^\s*```\s*[\w-]*\s*$/m', $code)) err('插件代码包含独立的 Markdown 代码块标记，无法安全分享');
    $body = "```php\n" . rtrim($code) . "\n```";
    $name = trim((string)($target['name'] ?? '')) ?: $id;
    $share_form = '<form class="post-action-form" method="post" action="' . h(self::plugin_market_url('plugin_share_receive')) . '" data-no-ajax="1" data-plugin-share-auto="1"><input type="hidden" name="title" value="' . h('[' . $id . ']' . $name) . '"><textarea name="body" hidden>' . h($body) . '</textarea><button type="submit" class="plugin-enable">立即继续</button></form>';
    $author = trim((string)($target['author'] ?? ''));
    $head = '<div class="admin-plugin-summary"><strong>正在前往插件市场</strong><span>插件代码已准备好，请在官方站点确认后发布。</span></div>';
    $row = '<li class="admin-list-item admin-object-row plugin-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h($name) . '</strong></div><div class="admin-inline-ops plugin-ops">' . $share_form . '</div><div class="plugin-row-detail"><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span><span>分享</span>' . ((string)($target['version'] ?? '') !== '' ? '<span>版本 ' . h((string)$target['version']) . '</span>' : '') . ($author !== '' ? '<span>插件作者 ' . h($author) . '</span>' : '') . '</div>' . self::plugin_desc_html((string)($target['description'] ?? '')) . '<div class="plugin-file">' . h($file) . '</div></div></div></li>';
    page('分享插件', shell_html('<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, '') . '<ul class="admin-manage-list plugin-list">' . $row . '</ul></div>', sidebar_stack_html([sidebar_user_card_html()])));
}

public static function plugin_download_page(): void
{
    need_admin();
    require_post();
    $id = (string)($_POST['plugin_id'] ?? '');
    $plugin = self::plugin_id_valid($id) ? (self::plugin_registry($id)[$id] ?? null) : null;
    if (!is_array($plugin)) err('插件不存在');
    $file = (string)($plugin['file'] ?? '');
    $expected = rtrim(str_replace('\\', '/', PLUGIN_DIR), '/') . '/' . $id . '/plugin.php';
    if (str_replace('\\', '/', $file) !== $expected || is_link($file) || !is_file($file) || !is_readable($file)) err('插件文件不存在或不可读');
    $code = file_get_contents($file);
    if (!is_string($code) || $code === '') err('插件文件不存在或为空');
    $version = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($plugin['version'] ?? '')), '._-');
    if ($version === '') $version = 'unknown';
    $filename = $id . '_' . $version . '.php1';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($code));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $code;
    exit;
}

public static function plugin_market_update_available(array $plugin, array $item): bool
{
    $remote = trim((string)($item['version'] ?? ''));
    $local = trim((string)($plugin['version'] ?? ''));
    return $remote !== '' && $local !== '' && version_compare($remote, $local, '>');
}

public static function plugin_market_search_form(string $query, string $market_view): string
{
    $view_param = self::plugin_market_view_param($market_view);
    $hidden = hidden_inputs(['a' => 'admin', 'tab' => 'plugins', 'view' => 'market', 'market_view' => (string)$view_param]);
    $url = admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $view_param]);
    $clear = $query !== '' ? '<a class="admin-search-clear" href="' . h($url) . '">清空</a>' : '';
    return '<form class="admin-table-search" method="get" action="' . h(index_url()) . '">' . $hidden . '<div class="admin-search-field"><input name="q" value="' . h($query) . '" placeholder="搜索标题 / 插件ID / 制作者"' . length_attributes('q') . '><button class="admin-search-submit" type="submit">搜索</button></div>' . $clear . '</form>';
}

public static function plugin_market_matches(array $item, string $query): bool
{
    if ($query === '') return true;
    foreach (['title', 'name', 'id', 'creator', 'description'] as $key) if (stripos((string)($item[$key] ?? ''), $query) !== false) return true;
    return false;
}

public static function plugin_market_page_html(bool $with_tabs = true): string
{
    $market_view = (string)($_GET['market_view'] ?? '');
    $market_view = $market_view === 'beta' ? 'beta' : ($market_view === 'required' ? 'required' : 'certified');
    $feed_view = $market_view === 'beta' ? 'beta' : 'certified';
    $force = (string)($_GET['refresh'] ?? '') === '1';
    $query = trim((string)($_GET['q'] ?? ''));
    $market = self::plugin_market_fetch(false, $force, '', 0, $feed_view);
    $items = is_array($market['plugins'] ?? null) ? $market['plugins'] : [];
    if ($query !== '') {
        $other_market = self::plugin_market_fetch(false, $force, '', 0, $feed_view === 'beta' ? 'certified' : 'beta');
        $merged = array_merge(array_values($items), array_values(is_array($other_market['plugins'] ?? null) ? $other_market['plugins'] : []));
        $items = [];
        foreach ($merged as $item) {
            if (!is_array($item)) continue;
            $key = (string)($item['id'] ?? '') . ':' . (int)($item['topic_id'] ?? 0) . ':' . (string)($item['market_status'] ?? 'certified');
            $items[$key] = $item;
        }
        if (empty($market['ok']) && !empty($other_market['ok'])) $market = $other_market;
    }
    $local = self::plugin_registry();
    $updates = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        if (isset($local[$id]) && self::plugin_market_update_available($local[$id], $item)) $updates[$id] = true;
    }
    $items = array_filter($items, static function ($item) use ($query, $market_view, $feed_view): bool {
        if (!is_array($item)) return false;
        $id = (string)($item['id'] ?? '');
        if (!self::plugin_id_valid($id)) return false;
        if ($market_view === 'required' && empty($item['required'])) return false;
        return ($query !== '' || (string)($item['market_status'] ?? 'certified') === $feed_view) && self::plugin_market_matches($item, $query);
    });
    uasort($items, static function (array $a, array $b) use ($updates, $local): int {
        $a_id = (string)($a['id'] ?? '');
        $b_id = (string)($b['id'] ?? '');
        $update = (int)isset($updates[$b_id]) <=> (int)isset($updates[$a_id]);
        if ($update !== 0) return $update;

        $required = static fn(array $item): int => !empty($item['required']) && !isset($local[(string)($item['id'] ?? '')]) ? 1 : 0;
        $required_sort = $required($b) <=> $required($a);
        if ($required_sort !== 0) return $required_sort;
        $updated = (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0);
        if ($updated !== 0) return $updated;
        $topic = (int)($b['topic_id'] ?? 0) <=> (int)($a['topic_id'] ?? 0);
        return $topic !== 0 ? $topic : strcmp($a_id, $b_id);
    });
    $total = count($items);
    $page = max(1, (int)($_GET['p'] ?? 1));
    $pages = min(max_pagination_pages(), max(1, (int)ceil($total / PLUGIN_MARKET_PAGE_SIZE)));
    $page = min($page, $pages);
    $items = array_slice($items, ($page - 1) * PLUGIN_MARKET_PAGE_SIZE, PLUGIN_MARKET_PAGE_SIZE, true);
    $view_param = self::plugin_market_view_param($market_view);
    $url = admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $view_param]);
    $head = '<div class="admin-plugin-summary"><strong>插件市场</strong><span>免费插件可直接在线安装；付费插件购买后，在插件页面获取一次性授权码即可在线安装或更新。' . ((int)($market['fetched_at'] ?? 0) > 0 ? '<br>列表更新于 ' . date('Y-m-d H:i', (int)$market['fetched_at']) . '。' : '') . '</span></div>';
    $actions = '<div class="plugin-head-actions">' . self::plugin_market_search_form($query, $market_view) . '<a class="admin-search-clear" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $view_param, 'q' => $query !== '' ? $query : null, 'refresh' => '1'])) . '">刷新</a></div>';
    $market_tabs = tab_bar_html([
        'certified' => ['label' => '站长认证', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market'])],
        'required' => ['label' => '必装', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => 'required'])],
        'beta' => ['label' => '最新beta', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => 'beta'])],
    ], $market_view, 'admin-tabs');
    $html = ($with_tabs ? self::admin_plugins_tabs_html('market') : '') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, $actions) . $market_tabs . '<ul class="admin-manage-list plugin-list plugin-market-list">';
    if (!(int)($market['ok'] ?? 0)) return $html . '<li class="empty-state">' . h((string)($market['message'] ?? '插件市场暂不可用')) . '</li></ul></div>';
    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');
        $installed = isset($local[$id]);
        $needs_update = isset($updates[$id]);
        $price_points = max(0, (int)($item['price_points'] ?? 0));
        $online_install = !array_key_exists('online_install', $item) ? $price_points === 0 : !empty($item['online_install']);
        $paid = $price_points > 0;
        $item_market_view = self::plugin_market_view((string)($item['market_status'] ?? 'certified'));
        $label = $installed ? ($needs_update ? '更新' : '重新安装') : '安装';
        $button_class = $installed && !$needs_update ? '' : 'plugin-enable';
        $topic_url = (string)($item['url'] ?? '');
        if ($paid) {
            $online_label = $installed ? ($needs_update ? '在线更新' : '在线重装') : '在线安装';
            $online_form = '<form class="post-action-form plugin-market-online-install-form" method="post" action="' . h(route_url('plugin_market_install')) . '">' . form_token() . hidden_inputs(['plugin_id' => $id, 'topic_id' => (int)($item['topic_id'] ?? 0), 'auto_enable' => '1', 'market_view' => ($market_view === 'required' ? 'required' : $item_market_view), 'authorization_required' => '1']) . '<button type="submit" class="plugin-enable" data-loading-text="准备中">' . h($online_label) . '</button></form>';
            $ops = $online_form;
        } elseif ($online_install) {
            $ops = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_install')) . '" data-replace-target=".plugin-list-panel" data-plugin-market-install="1" data-plugin-market-action="' . h($label) . '" data-confirm="确定' . h($label) . '该插件？插件代码将写入本地 plugins 目录。">' . form_token() . hidden_inputs(['plugin_id' => $id, 'topic_id' => (int)($item['topic_id'] ?? 0), 'auto_enable' => '1', 'market_view' => ($market_view === 'required' ? 'required' : $item_market_view)]) . '<button type="submit" data-loading-text="安装中"' . ($button_class !== '' ? ' class="' . h($button_class) . '"' : '') . '>' . h($label) . '</button></form>';
        } else {
            $ops = $topic_url !== '' ? '<a class="btn plugin-enable" href="' . h($topic_url) . '" target="_blank" rel="noopener">下载</a>' : '';
        }
        if ($topic_url !== '') $ops .= '<a href="' . h($topic_url) . '" target="_blank" rel="noopener">插件主题</a>';
        $meta = [];
        if ((string)($item['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$item['version'];
        $creator = trim((string)($item['creator'] ?? ''));
        $meta[] = '插件制作者 ' . ($creator !== '' ? $creator : '未声明');
        if (!$paid) $meta[] = '免费';
        if ((int)($item['updated_at'] ?? 0) > 0) $meta[] = date('Y-m-d H:i', (int)$item['updated_at']);
        $title = h((string)($item['name'] ?? $id));
        $title = $topic_url !== '' ? '<a class="admin-content-title" href="' . h($topic_url) . '" target="_blank" rel="noopener">' . $title . '</a>' : '<strong class="admin-content-title">' . $title . '</strong>';
        $status = [];
        if ($item_market_view === 'certified') $status[] = '站长认证';
        if (!empty($item['required'])) $status[] = '必装';
        $status[] = $installed ? ($needs_update ? '可更新' : '已安装') : '未安装';
        $price_tag = $paid ? '<span class="plugin-market-price">' . $price_points . ' 积分</span>' : '';
        $required_stamp = !empty($item['required']) ? '<span class="plugin-required-stamp" aria-hidden="true">必装</span>' : '';
        $class = ' plugin-market-entry' . ($needs_update ? ' plugin-market-update plugin-update-item' : ($installed ? ' plugin-market-installed' : ' plugin-market-available')) . ($required_stamp !== '' ? ' plugin-has-required' : '');
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . $class . '">' . $required_stamp . '<div class="admin-row-main"><div class="plugin-title-line">' . $title . $price_tag . '</div><div class="admin-inline-ops plugin-ops">' . $ops . '</div><div class="plugin-row-detail"><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span><span>' . h(implode(' / ', $status)) . '</span><span>' . h(implode(' / ', $meta)) . '</span></div>' . self::plugin_desc_html((string)($item['description'] ?? '')) . '<div class="plugin-file">' . h(substr((string)($item['sha256'] ?? ''), 0, 16)) . '</div></div></div></li>';
    }
    if (!$items) $html .= '<li class="empty-state">' . h($query !== '' ? '没有匹配的插件。' : ($market_view === 'beta' ? '暂无待审核或忽略的插件。' : ($market_view === 'required' ? '暂无必装插件。' : '暂无已审核通过的插件。'))) . '</li>';
    $html .= '</ul></div>';
    $pagination = paginate($total, $page, PLUGIN_MARKET_PAGE_SIZE, admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $view_param, 'q' => $query !== '' ? $query : null]));
    return $html . ($pagination !== '' ? '<div class="pagination-bar">' . $pagination . '</div>' : '');
}

public static function plugin_market_admin_actions(array $plugin): string
{
    $id = (string)($plugin['id'] ?? '');
    if (!self::plugin_id_valid($id)) return '';
    $share = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_share')) . '" target="_blank" rel="noopener" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit">分享</button></form>';
    $download = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_download')) . '" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit">下载</button></form>';
    return $share . $download;
}

public static function plugin_desc_html(string $desc): string
{
    $desc = trim($desc);
    if ($desc === '') return '<div class="admin-content-text plugin-desc"></div>';
    return '<div class="plugin-desc-row"><div class="admin-content-text plugin-desc">' . h($desc) . '</div><button type="button" class="plugin-desc-toggle" data-plugin-desc-toggle aria-expanded="false" hidden>展开</button></div>';
}

public static function admin_plugin_action_form(string $id, string $action, string $label, string $class = '', string $confirm = ''): string
{
    $confirm_attr = $confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '';
    $loading_attr = $action === 'enable' ? ' data-loading-text="正在启用"' : '';
    return '<form class="post-action-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" data-replace-target=".plugin-list-panel"' . $confirm_attr . '>' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => $action]) . '<button type="submit"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . $loading_attr . '>' . h($label) . '</button></form>';
}
public static function admin_plugin_upload_form(): string
{
    $form = '<form class="plugin-upload-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" enctype="multipart/form-data">' . form_token() . hidden_inputs(['plugin_action' => 'upload']) . '<input type="file" name="plugin_file" data-plugin-upload-file required><div class="plugin-upload-note">请选择插件脚本文件上传。⚠️ 同名插件将覆盖安装。</div><div class="confirm-actions"><button type="button" class="btn alt" data-modal-close>取消</button><button type="submit" class="plugin-enable" data-loading-text="上传中">上传</button></div></form>';
    return '<button type="button" class="plugin-head-button" data-plugin-upload-open>插件上传</button><template data-plugin-upload-template>' . $form . '</template>';
}
public static function admin_plugin_uninstall_form(string $id): string
{
    return '<form class="post-action-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" data-plugin-uninstall="1" data-replace-target=".plugin-list-panel" data-confirm="确定卸载插件？插件目录将被永久删除。">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'uninstall', 'keep_plugin_data' => '1']) . '<button type="submit" class="danger" data-loading-text="正在卸载">卸载</button></form>';
}
public static function admin_plugin_entry_toggle_form(array $plugin, string $entry, string $label): string
{
    if (!self::plugin_uses_entry($plugin, $entry)) return '';
    $id = (string)$plugin['id'];
    $checked = self::plugin_entry_enabled($plugin, $entry);
    return '<form class="post-action-form plugin-entry-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" data-replace-target=".plugin-list-panel">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'entry_toggle', 'entry' => $entry, 'entry_enabled' => '0']) . '<label class="plugin-entry-check"><input type="checkbox" name="entry_enabled" value="1" data-auto-submit' . ($checked ? ' checked' : '') . '><span>' . h($label) . '</span></label></form>';
}
public static function admin_plugins_page_html(bool $with_tabs = true): string
{
    $plugins = self::plugin_registry();
    uasort($plugins, function (array $a, array $b): int {
        $a_time = (int)($a['updated_at'] ?? 0);
        $b_time = (int)($b['updated_at'] ?? 0);
        return ($b_time <=> $a_time) ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $enabled_count = 0;
    foreach ($plugins as $plugin) if (self::plugin_enabled($plugin)) $enabled_count++;
    $head_left = '<div class="admin-plugin-summary"><strong>插件</strong><span>已发现 ' . count($plugins) . ' 个，已启用 ' . $enabled_count . ' 个</span></div>';
    $search = '<label class="admin-search-field plugin-local-search"><input type="search" placeholder="搜索本地插件" aria-label="搜索本地插件" data-plugin-local-search></label>';
    $head_right = '<div class="plugin-head-actions">' . $search . self::admin_plugin_upload_form() . self::admin_plugin_action_form('', 'sync', '同步插件', 'plugin-head-button') . '</div>';
    $html = ($with_tabs ? self::admin_plugins_tabs_html('local', $plugins) : '') . '<div class="admin-list-panel plugin-list-panel plugin-local-list-panel">' . admin_list_head($head_left, $head_right) . '<ul class="admin-manage-list plugin-list" data-plugin-local-list>';
    foreach ($plugins as $plugin) {
        $id = (string)$plugin['id'];
        $enabled = self::plugin_enabled($plugin);
        $manage_url = self::admin_plugin_manage_url($plugin);
        $ops = $manage_url !== '' ? '<a class="plugin-manage-link" href="' . h($manage_url) . '">管理</a>' : '';
        $market_topic_id = max(0, (int)setting('plugin_' . $id . '_market_topic_id'));
        $feedback = '';
        if ($market_topic_id > 0) {
            $feedback_url = append_url_query(self::plugin_market_url('topic'), ['id' => $market_topic_id]);
            $feedback = '<a href="' . h($feedback_url) . '" target="_blank" rel="noopener" title="查看插件帖子并反馈问题">反馈</a>';
        }
        $ops .= $enabled
            ? self::admin_plugin_action_form($id, 'disable', '停用', 'danger', '确定停用插件？')
            : self::admin_plugin_action_form($id, 'enable', '启用', 'plugin-enable');
        $entry_ops = '';
        foreach (self::plugin_entry_definitions() as $entry => $definition) {
            $entry_ops .= self::admin_plugin_entry_toggle_form($plugin, $entry, (string)$definition['label']);
        }
        $ops .= self::plugin_market_admin_actions($plugin);
        $ops .= (string)hook('admin.plugin.actions', '', ['plugin' => $plugin]);
        $ops .= self::admin_plugin_uninstall_form($id);
        $ops .= $feedback;
        $meta = [];
        if ((string)($plugin['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$plugin['version'];
        if ((string)($plugin['author'] ?? '') !== '') $meta[] = (string)$plugin['author'];
        $features = [];
        if (!empty($plugin['hooks'])) $features[] = count($plugin['hooks']) . ' 个钩子';
        if (!empty($plugin['routes'])) $features[] = count($plugin['routes']) . ' 个路由';
        if (!empty($plugin['admin_tabs'])) $features[] = count($plugin['admin_tabs']) . ' 个后台页';
        $file = str_replace(APP_ROOT . '/', '', (string)($plugin['file'] ?? ''));
        $disabled_reason = !$enabled ? trim((string)($plugin['disabled_reason'] ?? '')) : '';
        $reason_line = $disabled_reason !== '' ? '<div class="plugin-disabled-reason"><strong>自动停用原因</strong><span>' . h($disabled_reason) . '</span></div>' : '';
        $entry_line = $entry_ops !== '' ? '<div class="plugin-entry-line"><span class="plugin-entry-label">展示位置</span><div class="plugin-entry-options">' . $entry_ops . '</div></div>' : '';
        $local_class = $enabled ? ' plugin-local-enabled' : ' plugin-local-disabled';
        $title = $manage_url !== '' ? '<a class="admin-content-title" href="' . h($manage_url) . '">' . h((string)$plugin['name']) . '</a>' : '<strong class="admin-content-title">' . h((string)$plugin['name']) . '</strong>';
        $search_text = implode("\n", [(string)($plugin['name'] ?? ''), $id, (string)($plugin['author'] ?? ''), (string)($plugin['description'] ?? '')]);
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . $local_class . '" data-plugin-id="' . h($id) . '" data-plugin-local-search-text="' . h($search_text) . '"><div class="admin-row-main"><div class="plugin-title-line">' . $title . '</div><div class="admin-inline-ops plugin-ops" data-slot="admin.plugin.actions">' . $ops . '</div><div class="plugin-row-detail"><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span><span>' . h($enabled ? '已启用' : '已停用') . '</span>' . ($meta ? '<span>' . h(implode(' / ', $meta)) . '</span>' : '') . ($features ? '<span>' . h(implode(' / ', $features)) . '</span>' : '') . '</div>' . self::plugin_desc_html((string)($plugin['description'] ?? '')) . '<div class="plugin-file">' . h($file) . '</div></div>' . $reason_line . '</div>' . $entry_line . '</li>';
    }
    if ($plugins) $html .= '<li class="empty-state is-hidden" data-plugin-local-empty hidden>未找到匹配插件</li>';
    else $html .= '<li class="empty-state">暂无插件，放入 app/plugins/*/plugin.php 后点击“同步插件”。</li>';
    return $html . '</ul></div>';
}
public static function admin_plugin_manage_url(array $plugin): string
{
    if (!self::plugin_enabled($plugin) || !is_array($plugin['admin_tabs'] ?? null)) return '';
    foreach ($plugin['admin_tabs'] as $key => $fn) {
        if (is_string($key) && $key !== '' && is_string($fn) && $fn !== '') return admin_url(['tab' => $key]);
    }
    return '';
}
public static function admin_plugins_tabs_html(string $active, ?array $plugins = null): string
{
    $items = [
        'local' => ['label' => '本地插件', 'href' => admin_url(['tab' => 'plugins'])],
        'market' => ['label' => '插件市场', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market'])],
    ];
    $hook_items = hook('admin.plugins.tabs', $items, ['active' => $active]);
    if (is_array($hook_items)) $items = $hook_items;
    $items['cron'] = ['label' => '计划任务日志', 'href' => admin_url(['tab' => 'plugins', 'view' => 'cron'])];
    $entry_html = '';
    if ($active === 'local') {
        $entries = [];
        foreach (($plugins ?? self::plugin_registry()) as $plugin) {
            $url = self::admin_plugin_manage_url($plugin);
            if ($url !== '') $entries[] = '<a href="' . h($url) . '">' . h((string)($plugin['name'] ?? $plugin['id'])) . '</a>';
        }
        if ($entries) $entry_html = '<div class="admin-list-head plugin-management-entries"><strong>插件管理配置</strong><div>' . implode('', $entries) . '</div></div>';
    }
    return tab_bar_html($items, $active, 'plugin-tabs', 'admin.plugins.tabs') . $entry_html;
}
public static function admin_plugins_cron_logs_page_html(): string
{
    $size = 50;
    $page = max(1, (int)($_GET['p'] ?? 1));
    $offset = ($page - 1) * $size;
    $names = [];
    foreach (self::plugin_registry() as $plugin) {
        $names[(string)$plugin['id']] = (string)($plugin['name'] ?? $plugin['id']);
    }
    $rows = q("SELECT plugin_id,task_name,status,message,started_at,finished_at FROM app_cron_logs ORDER BY started_at DESC,id DESC LIMIT ? OFFSET ?", [$size + 1, $offset])->fetchAll();
    $has_next = count($rows) > $size;
    if ($has_next) array_pop($rows);
    $labels = ['success' => '成功', 'failed' => '失败', 'running' => '运行中'];
    $html = self::admin_plugins_tabs_html('cron') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head('<div class="admin-plugin-summary"><strong>计划任务日志</strong><span>最新运行记录</span></div>', '') . '<ul class="admin-manage-list">';
    foreach ($rows as $row) {
        $status = (string)$row['status'];
        $class = $status === 'success' ? ' on' : ($status === 'failed' ? ' danger' : '');
        $started_at = (int)$row['started_at'];
        $finished_at = (int)$row['finished_at'];
        $duration = $finished_at > 0 ? max(0, $finished_at - $started_at) . ' 秒' : '进行中';
        $message = trim((string)$row['message']);
        $plugin_id = (string)$row['plugin_id'];
        $plugin_name = $names[$plugin_id] ?? $plugin_id;
        $html .= '<li class="admin-list-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h($plugin_name) . '</strong><span class="admin-flag' . $class . '">' . h($labels[$status] ?? $status) . '</span></div><div class="admin-row-meta"><span class="plugin-id">' . h($plugin_id) . ' / ' . h((string)$row['task_name']) . '</span><span>' . date('Y-m-d H:i:s', $started_at) . '</span><span>' . h($duration) . '</span>' . ($message !== '' ? '<span title="' . h($message) . '">' . h($message) . '</span>' : '') . '</div></div></li>';
    }
    if (!$rows) $html .= '<li class="empty-state">暂无计划任务运行记录</li>';
    $html .= '</ul></div>';
    $pagination = simple_paginate($page > 1, $has_next, $page, admin_url(['tab' => 'plugins', 'view' => 'cron']));
    return $html . ($pagination === '' ? '' : '<div class="pagination-bar">' . $pagination . '</div>');
}
public static function admin_plugin_tab_html(string $tab): ?string
{
    foreach (self::plugins() as $plugin) {
        if (!self::plugin_enabled($plugin)) continue;
        $fn = $plugin['admin_tabs'][$tab] ?? null;
        if ($fn !== null) {
            $html = self::plugin_call($plugin, fn(): ?string => self::plugin_callback_exists($fn) ? (string)$fn($plugin) : null);
            if ($html !== null) return self::admin_plugin_page_html($plugin, $html);
        }
    }
    return null;
}

private static function admin_plugin_page_html(array $plugin, string $html): string
{
    $id = (string)($plugin['id'] ?? 'plugin');


    $page_tabs = '';
    if (preg_match('/^\s*<div class="tab-bar[^"]*\bplugin-tabs\b[^"]*"[^>]*>.*?<\/div>\s*/s', $html, $match)) {
        $page_tabs = trim($match[0]);
        $html = substr($html, strlen($match[0]));
    }
    if (!str_contains($html, 'admin-list-panel')) {
        $registered = $id !== '' ? (self::plugin_registry($id)[$id] ?? []) : [];
        $name = trim((string)($registered['name'] ?? $plugin['name'] ?? '')) ?: $id;
        $description = trim((string)($registered['description'] ?? $plugin['description'] ?? ''));
        $head = '<div class="admin-plugin-summary"><strong>' . h($name) . '</strong>'
            . ($description !== '' ? '<span>' . h($description) . '</span>' : '') . '</div>';
        $html = '<section class="admin-list-panel plugin-manage-panel plugin-admin-legacy-panel">'
            . admin_list_head($head, '') . '<div class="plugin-panel-body plugin-admin-legacy-body">' . $html . '</div></section>';
    }
    return $page_tabs . '<div class="plugin-admin-page plugin-admin-page-' . h($id) . '" data-plugin-admin-page="' . h($id) . '">' . $html . '</div>';
}

public static function plugin_disable_after_exception(string $id, Throwable $e): void
{
    if (!self::plugin_id_valid($id)) return;
    static $handled;
    $handled ??= new WeakMap();
    if (isset($handled[$e])) return;
    $handled[$e] = true;
    $message = trim((string)preg_replace('/\s+/', ' ', $e->getMessage()));
    $file = str_replace('\\', '/', $e->getFile());
    $root = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/';
    if (str_starts_with($file, $root)) $file = substr($file, strlen($root));
    $reason = date('Y-m-d H:i:s') . ' ' . get_class($e) . ($message !== '' ? ': ' . $message : '') . ($file !== '' ? ' (' . $file . ':' . $e->getLine() . ')' : '');
    try {
        self::plugin_update_row($id, ['enabled' => 0, 'status' => 'error', 'disabled_reason' => $reason]);
        q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
        self::plugins(true);
        self::plugin_registry_flush();
        self::plugin_assets_mark_dirty();
    } catch (Throwable $disable_error) {
        debug_log_write('插件 ' . $id . ' 自动停用失败', $disable_error);
        debug_log_write('插件 ' . $id . ' 运行异常', $e);
        return;
    }
    debug_log_write('插件 ' . $id . ' 运行异常，已自动停用', $e);
}

public static function plugin_manifest_validate(array $plugin, string $file): ?array
{
    $id = (string)($plugin['id'] ?? '');
    if (!self::plugin_id_valid($id) || $id !== basename(dirname($file))) return null;
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
        'assets' => is_array($plugin['assets'] ?? null) ? $plugin['assets'] : [],
        'assets_mode' => (string)($plugin['assets_mode'] ?? ''),
        'cron' => is_array($plugin['cron'] ?? null) ? $plugin['cron'] : [],
        'entries' => is_array($plugin['entries'] ?? null) ? $plugin['entries'] : [],
        'install' => (string)($plugin['install'] ?? ''),
        'uninstall' => (string)($plugin['uninstall'] ?? ''),
        'file' => $file,
    ];
    foreach (['hooks', 'routes', 'admin_tabs'] as $map) {
        $items = [];
        foreach ($base[$map] as $name => $fn) if (is_string($name) && is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $items[$name] = $fn;
        $base[$map] = $items;
    }
    $entries = [];
    foreach ($base['entries'] as $entry => $enabled) {
        $entry = (string)$entry;
        $hook = self::plugin_entry_hook_name($entry);
        if ($hook !== '' && isset($base['hooks'][$hook])) $entries[$entry] = !empty($enabled);
    }
    $base['entries'] = $entries;
    $assets = [];
    foreach (['css', 'js'] as $type) {
        $fn = $base['assets'][$type] ?? null;
        if (is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $assets[$type] = $fn;
    }
    $base['assets'] = $assets;
    $base['assets_mode'] = $base['assets_mode'] === 'ondemand' ? 'ondemand' : '';
    $cron = [];
    foreach ($base['cron'] as $name => $task) {
        if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) !== 1 || !is_array($task)) continue;
        $callback = (string)($task['callback'] ?? '');
        $interval = $task['interval'] ?? 0;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback) !== 1) continue;
        if (is_string($interval) && !is_numeric($interval)) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $interval) !== 1) continue;
        } else {
            $interval = (int)$interval;
            if ($interval < 60) continue;
            $interval = min(31536000, $interval);
        }
        $cron[$name] = ['callback' => $callback, 'interval' => $interval];
    }
    $base['cron'] = $cron;
    foreach (['install', 'uninstall'] as $key) if ($base[$key] !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base[$key]) !== 1) $base[$key] = '';
    return $base;
}

public static function plugin_files(): array
{
    $files = glob(PLUGIN_DIR . '/*/plugin.php') ?: [];
    sort($files);
    return array_values(array_filter($files, 'is_file'));
}

public static function plugin_assets_manifest(): array
{
    if (is_array(self::$asset_manifest_cache)) return self::$asset_manifest_cache;
    if (setting('plugin_assets_dirty', '1') === '1' || !is_file(PLUGIN_CSS_FILE) || !is_file(PLUGIN_JS_FILE)) {
        try { return self::$asset_manifest_cache = self::plugin_assets_rebuild(); }
        catch (Throwable $e) { debug_log_write('插件资源生成失败', $e); return self::$asset_manifest_cache = []; }
    }
    return self::$asset_manifest_cache = [
        'css' => setting('plugin_assets_css_hash'),
        'css_size' => (int)setting('plugin_assets_css_size'),
        'js' => setting('plugin_assets_js_hash'),
        'js_size' => (int)setting('plugin_assets_js_size'),
    ];
}

public static function plugin_assets_mark_dirty(): void
{
    self::$asset_manifest_cache = null;
    save_settings_values(['plugin_assets_dirty' => '1']);
}

private static function plugin_assets_refresh(string $id): void
{
    try {
        self::plugin_assets_rebuild([$id]);
    } catch (Throwable $e) {
        self::plugin_assets_mark_dirty();
        debug_log_write('插件资源更新失败', $e);
    }
}

public static function plugin_asset_write(string $file, string $content): void
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (is_writable(dirname($file)) && file_put_contents($tmp, $content, LOCK_EX) !== false) {
        if (@rename($tmp, $file)) return;
        @unlink($tmp);
    }
    if (file_put_contents($file, $content, LOCK_EX) === false) throw new RuntimeException('插件资源文件不可写：' . basename($file));
}

public static function plugin_assets_rebuild(?array $only_ids = null): array
{
    if ($only_ids !== null) {
        $only_ids = array_values(array_unique(array_filter(array_map('strval', $only_ids), 'plugin_id_valid')));
        if ($only_ids === []) $only_ids = null;
    }
    $plugins = [];
    foreach (self::plugins() as $plugin) {
        if (self::plugin_enabled($plugin)) $plugins[(string)$plugin['id']] = $plugin;
    }
    $previous = plugin_json_decode(setting('plugin_assets_ondemand'));
    $previous = is_array($previous) ? $previous : [];
    $ondemand = $only_ids === null ? [] : $previous;
    $bundle_dirty = $only_ids === null || (string)setting('plugin_assets_dirty', '0') === '1';
    $targets = $only_ids === null ? array_keys($plugins) : $only_ids;
    foreach ($targets as $id) {
        $plugin = $plugins[$id] ?? null;
        $was_ondemand = isset($previous[$id]);
        if ($plugin && !empty($plugin['assets']) && (string)($plugin['assets_mode'] ?? '') === 'ondemand') {
            $ondemand[$id] = self::plugin_asset_ondemand_write($plugin);
            if (!$was_ondemand) $bundle_dirty = true;
            continue;
        }
        if (!$plugin || !empty($plugin['assets']) || $was_ondemand) $bundle_dirty = true;
        if ($was_ondemand) {
            self::plugin_asset_remove((string)$id);
            unset($ondemand[$id]);
        }
    }
    if ($only_ids === null) {
        foreach (array_keys($previous) as $id) {
            if (isset($ondemand[$id])) continue;
            self::plugin_asset_remove((string)$id);
        }
    }
    $manifest = null;
    if ($bundle_dirty) {
        $chunks = ['css' => [], 'js' => []];
        foreach ($plugins as $plugin) {
            if (empty($plugin['assets']) || (string)($plugin['assets_mode'] ?? '') === 'ondemand') continue;
            foreach (['css', 'js'] as $type) {
                $chunk = self::plugin_asset_chunk($plugin, $type);
                if ($chunk !== '') $chunks[$type][] = $chunk;
            }
        }
        $manifest = [];
        foreach (['css' => PLUGIN_CSS_FILE, 'js' => PLUGIN_JS_FILE] as $key => $file) {
            $content = implode("\n", $chunks[$key]) . ($chunks[$key] ? "\n" : '');
            self::plugin_asset_write($file, $content);
            $manifest[$key] = hash('sha256', $content);
            $manifest[$key . '_size'] = strlen($content);
        }
        save_settings_values([
            'plugin_assets_css_hash' => (string)($manifest['css'] ?? ''),
            'plugin_assets_css_size' => (string)(int)($manifest['css_size'] ?? 0),
            'plugin_assets_js_hash' => (string)($manifest['js'] ?? ''),
            'plugin_assets_js_size' => (string)(int)($manifest['js_size'] ?? 0),
        ]);
    } else {
        $manifest = [
            'css' => (string)setting('plugin_assets_css_hash'),
            'css_size' => (int)setting('plugin_assets_css_size'),
            'js' => (string)setting('plugin_assets_js_hash'),
            'js_size' => (int)setting('plugin_assets_js_size'),
        ];
    }
    save_settings_values([
        'plugin_assets_ondemand' => plugin_json_encode($ondemand),
        'plugin_assets_dirty' => '0',
    ]);
    return self::$asset_manifest_cache = $manifest;
}

private static function plugin_asset_chunk(array $plugin, string $type): string
{
    $fn = $plugin['assets'][$type] ?? null;
    if (!is_string($fn)) return '';
    $content = trim((string)self::plugin_call($plugin, function () use ($fn): string {
        return self::plugin_callback_exists($fn) ? (string)$fn() : '';
    }));
    if ($content === '') return '';
    $id = (string)($plugin['id'] ?? '');
    if ($type === 'js') return '/* ' . $id . " */\n(function(){try{\n" . $content . "\n}catch(_bbs1Err){console.error('[" . $id . "]',_bbs1Err)}})();";
    return '/* ' . $id . " */\n" . $content;
}

private static function plugin_asset_ondemand_write(array $plugin): array
{
    $id = (string)($plugin['id'] ?? '');
    if (!is_dir(PLUGIN_ASSET_DIR) && !@mkdir(PLUGIN_ASSET_DIR, 0755, true) && !is_dir(PLUGIN_ASSET_DIR)) {
        throw new RuntimeException('插件资源目录不可写');
    }
    $entry = [];
    foreach (['css', 'js'] as $type) {
        $content = self::plugin_asset_chunk($plugin, $type);
        $file = PLUGIN_ASSET_DIR . '/' . $id . '.' . $type;
        if ($content === '') {
            if (is_file($file)) @unlink($file);
            $entry[$type] = ['hash' => '', 'size' => 0];
            continue;
        }
        self::plugin_asset_write($file, $content);
        $entry[$type] = ['hash' => hash('sha256', $content), 'size' => strlen($content)];
    }
    return $entry;
}

private static function plugin_asset_remove(string $id): void
{
    if (!self::plugin_id_valid($id)) return;
    foreach (['css', 'js'] as $type) {
        $file = PLUGIN_ASSET_DIR . '/' . $id . '.' . $type;
        if (is_file($file)) @unlink($file);
    }
}

public static function plugin_asset_require(string $id): void
{
    if (!self::plugin_id_valid($id)) return;
    $plugin = self::plugins()[$id] ?? null;
    if (!$plugin || !self::plugin_enabled($plugin) || empty($plugin['assets'])) return;
    if ((string)($plugin['assets_mode'] ?? '') !== 'ondemand') return;
    self::$asset_required[$id] = true;
}

private static function plugin_assets_ondemand_manifest(bool $refresh = false): array
{
    if ($refresh) self::$asset_ondemand_cache = null;
    if (is_array(self::$asset_ondemand_cache)) return self::$asset_ondemand_cache;
    $decoded = plugin_json_decode(setting('plugin_assets_ondemand'));
    return self::$asset_ondemand_cache = is_array($decoded) ? $decoded : [];
}

public static function plugin_assets_html(string $type): string
{
    if (!in_array($type, ['css', 'js'], true)) return '';
    $required = array_keys(self::$asset_required);
    if (!$required) return plugin_asset_tag($type);
    $html = plugin_asset_tag($type);
    $manifest = self::plugin_assets_ondemand_manifest(true);
    $missing = [];
    foreach ($required as $id) {
        $entry = $manifest[$id][$type] ?? null;
        if (!is_array($entry)) {
            $missing[] = $id;
            continue;
        }
        if ((int)($entry['size'] ?? 0) > 0 && !is_file(PLUGIN_ASSET_DIR . '/' . $id . '.' . $type)) $missing[] = $id;
    }
    if ($missing && !self::$asset_missing_retry) {
        self::$asset_missing_retry = true;
        try {
            self::plugin_assets_rebuild($missing);
            $manifest = self::plugin_assets_ondemand_manifest(true);
        } catch (Throwable $e) {
            debug_log_write('插件资源生成失败', $e);
        }
    }
    $seen = [];
    foreach ($required as $id) {
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $entry = $manifest[$id][$type] ?? null;
        if (!is_array($entry) || (int)($entry['size'] ?? 0) < 1) continue;
        $file = 'app/assets/plugins/' . $id . '.' . $type;
        $version = (string)($entry['hash'] ?? '');
        $html .= $type === 'css'
            ? '<link rel="stylesheet" href="' . h(asset_url($file)) . '?v=' . h($version) . '">'
            : '<script src="' . h(asset_url($file)) . '?v=' . h($version) . '" defer></script>';
    }
    return $html;
}

private static function plugin_file_created_tables(string $file): array
{
    $code = @file_get_contents($file);
    if (!is_string($code) || $code === '') return [];
    $tokens = token_get_all($code);
    $tables = [];
    $count = count($tokens);
    $call_operators = [T_OBJECT_OPERATOR, T_DOUBLE_COLON];
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) $call_operators[] = T_NULLSAFE_OBJECT_OPERATOR;
    $next_meaningful = static function (array $tokens, int $index, int $count): int {
        while (++$index < $count) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) return $index;
        }
        return $count;
    };
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || strtolower((string)$token[1]) !== 'app_db_create_table') continue;
        $previous = $i - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $previous--;
        if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], $call_operators, true)) continue;
        $j = $next_meaningful($tokens, $i, $count);
        if ($j >= $count || $tokens[$j] !== '(') continue;
        $j = $next_meaningful($tokens, $j, $count);
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $literal = (string)$tokens[$j][1];
        $quote = $literal[0] ?? '';
        if (($quote !== "'" && $quote !== '"') || !str_ends_with($literal, $quote)) continue;
        $table = substr($literal, 1, -1);
        $table = $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $table) : stripcslashes($table);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) continue;
        $tables[strtolower($table)][] = ['table' => $table, 'line' => (int)$tokens[$j][2]];
    }
    return $tables;
}

private static function plugin_table_conflicts(array $files): array
{
    $definitions = [];
    foreach ($files as $file) {
        foreach (self::plugin_file_created_tables($file) as $table => $items) {
            foreach ($items as $item) $definitions[$table][] = ['file' => $file] + $item;
        }
    }
    $conflicts = [];
    foreach ($definitions as $items) {
        $files_for_table = array_values(array_unique(array_column($items, 'file')));
        if (count($files_for_table) < 2) continue;
        foreach ($files_for_table as $file) {
            $own_lines = array_column(array_filter($items, static fn(array $item): bool => (string)$item['file'] === $file), 'line');
            $others = [];
            foreach ($items as $item) {
                if ((string)$item['file'] === $file) continue;
                $other_id = basename(dirname((string)$item['file']));
                $others[$other_id][] = (int)$item['line'];
            }
            $other_parts = [];
            foreach ($others as $other_id => $lines) $other_parts[] = $other_id . ' 第 ' . implode('、', array_unique($lines)) . ' 行';
            $conflicts[$file][] = '数据表 ' . (string)$items[0]['table'] . ' 重复建表：本插件第 ' . implode('、', array_unique($own_lines)) . ' 行；' . implode('；', $other_parts);
        }
    }
    return $conflicts;
}

public static function plugin_registry_sync(?array $only_ids = null, bool $force = false): array
{
    if ($only_ids !== null) {
        $only_ids = array_values(array_unique(array_filter(array_map('strval', $only_ids), 'plugin_id_valid')));
        if ($only_ids === []) $only_ids = null;
    }
    $only_lookup = $only_ids === null ? null : array_flip($only_ids);
    $existing = [];
    foreach (q("SELECT * FROM app_plugins")->fetchAll() as $row) $existing[(string)$row['id']] = $row;
    $synced = [];
    $changed = [];
    $disable = function (string $id, string $file, string $reason) use (&$existing, &$synced, &$changed): void {
        if (!self::plugin_id_valid($id) || $id === 'plugin_market') return;
        $old = $existing[$id] ?? [];
        $code_hash = is_file($file) ? (hash_file('sha256', $file) ?: '') : '';
        app_db_upsert('app_plugins', [
            'id' => $id,
            'name' => (string)($old['name'] ?? $id),
            'version' => (string)($old['version'] ?? ''),
            'file' => ltrim(str_replace(APP_ROOT, '', $file), '/'),
            'code_hash' => $code_hash,
            'manifest_json' => (string)($old['manifest_json'] ?? '{}'),
            'config_json' => (string)($old['config_json'] ?? '{}'),
            'entries_json' => (string)($old['entries_json'] ?? '{}'),
            'enabled' => 0,
            'status' => 'error',
            'disabled_reason' => $reason,
            'installed_at' => (int)($old['installed_at'] ?? 0) ?: now(),
            'updated_at' => (string)($old['code_hash'] ?? '') === $code_hash ? ((int)($old['updated_at'] ?? 0) ?: now()) : now(),
        ], ['id']);
        q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
        $synced[$id] = true;
        $changed[$id] = true;
    };
    $files = self::plugin_files();
    foreach ($files as $file) {
        $id = basename(dirname($file));
        if ($id === 'plugin_market') continue;
        if ($only_lookup !== null && !isset($only_lookup[$id])) {
            $synced[$id] = true;
            continue;
        }
        $old = $existing[$id] ?? [];
        $code_hash = hash_file('sha256', $file) ?: '';

        if (!$force && $code_hash !== '' && (string)($old['code_hash'] ?? '') === $code_hash && self::plugin_registry_row($old)) {
            $synced[$id] = true;
            continue;
        }
        if (array_key_exists($file, $GLOBALS['__plugin_raw'] ?? [])) {
            $synced[$id] = true;
            continue;
        }
        try {
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            $raw = include $file;
        } catch (Throwable $e) {
            $disable($id, $file, $e->getMessage());
            continue;
        }
        $GLOBALS['__plugin_raw'][$file] = $raw;
        if (!is_array($raw)) {
            $disable($id, $file, '插件定义格式无效');
            continue;
        }
        $plugin = self::plugin_manifest_validate($raw, $file);
        if (!$plugin) {
            $disable($id, $file, '插件定义校验失败');
            continue;
        }
        $id = (string)$plugin['id'];
        $old = $existing[$id] ?? [];
        $installed_at = (int)($old['installed_at'] ?? 0) ?: now();
        $updated_at = isset($old['updated_at']) && (string)($old['code_hash'] ?? '') === $code_hash
            ? (int)$old['updated_at']
            : now();
        $config = plugin_json_decode($old['config_json'] ?? '') ?? [];
        $entries = isset($old['entries_json']) ? plugin_json_decode($old['entries_json']) ?? [] : [];
        foreach (self::plugin_entry_definitions() as $entry => $definition) {
            if (!isset($plugin['hooks'][$definition['hook']]) || array_key_exists($entry, $entries)) continue;
            $entries[$entry] = !array_key_exists($entry, (array)$plugin['entries']) || !empty($plugin['entries'][$entry]);
        }
        $enabled = isset($old['enabled']) ? (int)$old['enabled'] : 0;
        app_db_upsert('app_plugins', [
            'id' => $id,
            'name' => (string)$plugin['name'],
            'version' => (string)$plugin['version'],
            'file' => ltrim(str_replace(APP_ROOT, '', $file), '/'),
            'code_hash' => $code_hash,
            'manifest_json' => plugin_json_encode(array_intersect_key($plugin, array_flip(['description', 'author', 'hooks', 'routes', 'admin_tabs', 'assets', 'assets_mode', 'cron', 'install', 'uninstall']))),
            'config_json' => plugin_json_encode($config),
            'entries_json' => plugin_json_encode($entries),
            'enabled' => $enabled,
            'status' => $enabled ? 'enabled' : 'disabled',
            'disabled_reason' => (string)($old['disabled_reason'] ?? ''),
            'installed_at' => $installed_at,
            'updated_at' => $updated_at,
        ], ['id']);
        $synced[$id] = true;
        $changed[$id] = true;
    }
    if ($only_ids === null) {
        foreach (array_keys($existing) as $id) {
            if (isset($synced[$id])) continue;
            q("DELETE FROM app_cron_tasks WHERE plugin_id=?", [$id]);
            q("DELETE FROM app_plugins WHERE id=?", [$id]);
        }
    }
    $plugins = self::plugin_registry(null, true);
    self::plugins(true);
    foreach ($plugins as $plugin) {
        $pid = (string)($plugin['id'] ?? '');
        if ($only_ids !== null) {

            if (!isset($changed[$pid])) continue;
        } elseif (!isset($changed[$pid]) && !self::plugin_enabled($plugin)) {
            continue;
        }
        Cron::plugin_cron_sync($plugin);
    }
    return $plugins;
}

public static function admin_plugins_handle_post(): void
{
    $plugin_action = (string)($_POST['plugin_action'] ?? '');
    $plugin_id = (string)($_POST['plugin_id'] ?? '');
    $message = '';
    $refresh_after_response = false;
    $redirect_after_response = false;
    $redirect_url = admin_url(['tab' => 'plugins']);
    if ($plugin_action === 'sync') {
        save_settings_values(['plugin_sync_pending' => '1']);
        $message = '插件已同步';
        $refresh_after_response = true;
    } elseif ($plugin_action === 'upload') {
        $plugin = self::plugin_upload((array)($_FILES['plugin_file'] ?? []));
        $uploaded_id = (string)$plugin['id'];
        $enable_token = hash_hmac('sha256', 'plugin-upload-enable|' . $uploaded_id, csrf_token());
        $redirect_url = admin_url([
            'tab' => 'plugins',
            'plugin_action' => 'enable_uploaded',
            'plugin_id' => $uploaded_id,
            'enable_token' => $enable_token,
        ]);
        $message = '插件 ' . $uploaded_id . ' 已上传，正在同步并启用';
        $redirect_after_response = true;
    } elseif ($plugin_action === 'enable') {
        self::plugin_set_enabled($plugin_id, true);
        $message = '插件已启用';
    } elseif ($plugin_action === 'disable') {
        self::plugin_set_enabled($plugin_id, false);
        $message = '插件已停用';
    } elseif ($plugin_action === 'uninstall') {
        $keep_data = (string)($_POST['keep_plugin_data'] ?? '1') === '1';
        self::plugin_uninstall($plugin_id, $keep_data);
        $message = $keep_data ? '插件已卸载，PHP脚本已备份，数据已保留' : '插件已卸载，PHP脚本已备份，目录和数据已删除';
    } elseif ($plugin_action === 'entry_toggle') {
        self::plugin_set_entry_enabled($plugin_id, (string)($_POST['entry'] ?? ''), (string)($_POST['entry_enabled'] ?? '0') === '1');
        $message = '插件入口显示已更新';
    } else err('参数错误');
    $view = (string)($_GET['view'] ?? '');
    if (ajax_request()) {
        if ($redirect_after_response) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'message' => $message, 'redirect' => $redirect_url], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($refresh_after_response) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'message' => $message, 'refresh' => 1], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $html = self::admin_plugins_page_html(false);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'message' => $message, 'html' => $html], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_flash($message);
    go($redirect_after_response ? $redirect_url : admin_url(['tab' => 'plugins', 'view' => $view === 'cron' ? $view : null]));
}

public static function plugin_enable_uploaded_page(): void
{
    $id = (string)($_GET['plugin_id'] ?? '');
    $token = (string)($_GET['enable_token'] ?? '');
    $expected = hash_hmac('sha256', 'plugin-upload-enable|' . $id, csrf_token());
    if (!self::plugin_id_valid($id) || !hash_equals($expected, $token)) err('插件启用参数无效');
    self::plugin_set_enabled($id, true);
    set_flash('插件 ' . $id . ' 已上传、同步并启用');
    go(admin_url(['tab' => 'plugins']));
}


private static function plugin_sync_pending_mark(string $id): string
{
    $pending = (string)setting('plugin_sync_pending', '0');
    if ($pending === '1') return '1';
    $ids = [];
    if (str_starts_with($pending, 'p:')) {
        foreach (explode(',', substr($pending, 2)) as $value) {
            if (self::plugin_id_valid($value)) $ids[] = $value;
        }
    }
    $ids[] = $id;
    return 'p:' . implode(',', array_slice(array_unique($ids), -100));
}

private static function plugin_force_sync_mark(string $id): string
{
    $ids = [];
    foreach (explode(',', (string)setting('plugin_force_sync', '')) as $value) {
        if (self::plugin_id_valid($value)) $ids[] = $value;
    }
    $ids[] = $id;
    return implode(',', array_slice(array_unique($ids), -100));
}

private static function plugin_force_sync_clear(string $id): void
{
    $ids = [];
    foreach (explode(',', (string)setting('plugin_force_sync', '')) as $value) {
        if (self::plugin_id_valid($value) && $value !== $id) $ids[] = $value;
    }
    save_settings_values(['plugin_force_sync' => implode(',', $ids)]);
}

private static function plugin_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) err($error === UPLOAD_ERR_NO_FILE ? '请选择插件脚本文件' : '插件上传失败');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if (!is_uploaded_file($tmp) || $size <= 0) err('插件文件无效');
    if ($size > PLUGIN_UPLOAD_MAX) err('插件文件不能超过20MB');
    require_writable_dir(DATA_DIR, 'app/data 目录不可写');
    $upload_file = DATA_DIR . '/' . PLUGIN_UPLOAD_FILE;
    if (!move_uploaded_file($tmp, $upload_file)) err('插件上传失败');
    $code = file_get_contents($upload_file);
    if (!is_string($code)) {
        @unlink($upload_file);
        err('插件文件读取失败');
    }
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (Throwable) {
        @unlink($upload_file);
        err('插件 PHP 语法无效');
    }
    if (!str_starts_with(ltrim($code), '<?php') || preg_match('/!\s*defined\s*\(\s*[\'\"]APP_ROOT[\'\"]\s*\)/', $code) !== 1) {
        @unlink($upload_file);
        err('不是标准的插件脚本');
    }
    preg_match_all('/\breturn\s*\[\s*([\'\"])id\1\s*=>\s*([\'\"])([a-z0-9][a-z0-9_-]{0,63})\2/s', $code, $matches, PREG_OFFSET_CAPTURE);
    $match_index = count($matches[0] ?? []) - 1;
    $id = $match_index >= 0 ? (string)$matches[3][$match_index][0] : '';
    $manifest_offset = $match_index >= 0 ? (int)$matches[0][$match_index][1] : -1;
    $manifest = $manifest_offset >= 0 ? substr($code, $manifest_offset) : '';
    foreach (['name', 'version', 'description', 'author'] as $key) {
        if ($manifest === '' || preg_match('/[\'\"]' . preg_quote($key, '/') . '[\'\"]\s*=>/', $manifest) !== 1) {
            @unlink($upload_file);
            err('插件 manifest 不完整');
        }
    }
    if (!self::plugin_id_valid($id) || $id === 'plugin_market') {
        @unlink($upload_file);
        err('插件 ID 无效');
    }
    $dir = rtrim(str_replace('\\', '/', PLUGIN_DIR), '/') . '/' . $id;
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        @unlink($upload_file);
        err('插件目录无效');
    }
    if (is_dir($dir)) {
        $source = $dir . '/plugin.php';
        if (is_link($source) || !is_file($source)) {
            @unlink($upload_file);
            err('同名插件目录缺少可备份的 plugin.php');
        }
        if (!is_writable($dir)) {
            @unlink($upload_file);
            err('插件目录不可写');
        }
        self::plugin_backup_php_files($id, $dir);
    } else {
        require_writable_dir(PLUGIN_DIR, 'app/plugins 目录不可写');
        if (!mkdir($dir, 0755)) {
            @unlink($upload_file);
            err('插件目录创建失败');
        }
    }
    $target = $dir . '/plugin.php';
    $target_tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($target_tmp, $code, LOCK_EX) === false || !rename($target_tmp, $target)) {
        @unlink($target_tmp);
        @unlink($upload_file);
        err('插件文件写入失败');
    }
    @chmod($target, 0644);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
    @unlink($upload_file);
    q("UPDATE app_plugins SET enabled=0,status='disabled',disabled_reason='' WHERE id=?", [$id]);
    q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
    save_settings_values(['plugin_sync_pending' => self::plugin_sync_pending_mark($id), 'plugin_force_sync' => self::plugin_force_sync_mark($id)]);
    self::plugin_registry_flush();
    return ['id' => $id];
}

public static function plugin_set_entry_enabled(string $id, string $entry, bool $enabled): void
{
    if (!self::plugin_id_valid($id) || self::plugin_entry_hook_name($entry) === '') err('参数错误');
    $plugin = self::plugin_registry($id)[$id] ?? null;
    if (!$plugin || !self::plugin_uses_entry($plugin, $entry)) err('插件未使用该入口');
    $entries = (array)($plugin['entries'] ?? []);
    $entries[$entry] = $enabled;
    self::plugin_update_row($id, ['entries_json' => $entries], false);
    self::plugins(true);
    self::plugin_registry_flush();
}

public static function plugin_set_enabled(string $id, bool $enabled, bool $run_install = false): void
{
    if (!self::plugin_id_valid($id)) err('插件不存在');
    $plugin = self::plugin_registry($id)[$id] ?? null;
    if (!$plugin) err('插件不存在');
    if ($enabled) {
        self::plugin_call($plugin, function () use ($plugin, $run_install): void {
            if (($run_install || !self::plugin_enabled($plugin)) && self::plugin_callback_exists($plugin['install'] ?? null)) {
                call_user_func((string)$plugin['install'], $plugin);
            }
        });
    }
    self::plugin_update_row($id, ['enabled' => $enabled ? 1 : 0, 'status' => $enabled ? 'enabled' : 'disabled', 'disabled_reason' => ''], false);
    q("UPDATE app_cron_tasks SET enabled=? WHERE plugin_id=?", [$enabled ? 1 : 0, $id]);
    $runtime_plugins = self::plugins(true);
    if ($enabled && isset($runtime_plugins[$id])) Cron::plugin_cron_sync($runtime_plugins[$id]);
    self::plugin_assets_refresh($id);
    self::plugin_registry_flush();
}

public static function plugin_uninstall(string $id, bool $keep_data = true): void
{
    if (!self::plugin_id_valid($id)) err('插件不存在');
    $plugin = self::plugin_registry($id)[$id] ?? null;
    if (!$plugin) err('插件不存在');
    $dir = rtrim(str_replace('\\', '/', PLUGIN_DIR), '/') . '/' . $id;
    if (str_replace('\\', '/', (string)($plugin['file'] ?? '')) !== $dir . '/plugin.php') err('插件目录无效');
    if (!self::plugin_directory_removable($dir)) err('插件目录不可删除，请检查目录权限');
    if (!$keep_data) {
        $table_conflicts = self::plugin_table_conflicts(self::plugin_files());
        $file = (string)($plugin['file'] ?? '');
        if (isset($table_conflicts[$file])) err('检测到数据表重复建表，为避免删除其他插件数据，不能执行删表卸载：' . implode('；', $table_conflicts[$file]));
    }
    self::plugin_backup_php_files($id, $dir);
    self::plugin_call($plugin, function () use ($plugin, $id, $keep_data): void {
        $fn = (string)($plugin['uninstall'] ?? '');
        if (!self::plugin_callback_exists($fn)) $fn = str_replace('-', '_', $id) . '_uninstall';
        if (!self::plugin_callback_exists($fn)) return;
        $accepts_keep_data = (new ReflectionFunction($fn))->getNumberOfParameters() >= 2;
        if ($keep_data && !$accepts_keep_data) return;
        if ($accepts_keep_data) call_user_func($fn, $plugin, $keep_data);
        else call_user_func($fn, $plugin);
    });
    self::plugin_remove_directory($dir);
    q("DELETE FROM app_cron_tasks WHERE plugin_id=?", [$id]);
    q("DELETE FROM app_plugins WHERE id=?", [$id]);
    self::plugins(true);
    plugin_runtime_cache_reset();
    self::plugin_assets_refresh($id);
    self::plugin_registry_flush();
}

private static function plugin_backup_php_files(string $id, string $dir): void
{
    if (is_link($dir)) throw new RuntimeException('插件目录不可备份');
    $backup_root = DATA_DIR . '/plugin-backups';
    require_writable_dir($backup_root, '插件备份目录不可写，请检查 app/data/plugin-backups 目录权限');
    $source = $dir . '/plugin.php';
    if (is_link($source) || !is_file($source)) throw new RuntimeException('插件入口脚本不可备份');
    $backup_file = $backup_root . '/' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.php';
    if (!copy($source, $backup_file) || !hash_equals((string)hash_file('sha256', $source), (string)hash_file('sha256', $backup_file))) {
        @unlink($backup_file);
        throw new RuntimeException('插件 PHP 脚本备份失败');
    }
}

private static function plugin_directory_removable(string $dir): bool
{
    if (!file_exists($dir) && !is_link($dir)) return true;
    if (is_link($dir)) return is_writable(dirname($dir));
    if (!is_dir($dir)) return false;
    if (!is_readable($dir) || !is_writable($dir)) return false;
    $items = scandir($dir);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path) && !self::plugin_directory_removable($path)) return false;
    }
    return is_writable(dirname($dir));
}

private static function plugin_remove_directory(string $dir): void
{
    if (is_link($dir)) {
        if (!unlink($dir)) throw new RuntimeException('无法删除插件目录');
        return;
    }
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) throw new RuntimeException('无法读取插件目录');
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            self::plugin_remove_directory($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException('无法删除插件文件：' . $item);
        }
    }
    if (!rmdir($dir)) throw new RuntimeException('无法删除插件目录');
}
}
