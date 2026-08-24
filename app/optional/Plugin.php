<?php
namespace app\optional;

use RuntimeException;
use Throwable;
use WeakMap;

if (!defined('APP_ROOT')) {
    exit;
}

const PLUGIN_MARKET_ENDPOINT = 'https://bbs1.org/index.php';
const PLUGIN_MARKET_SHARE_MAX = 200000;
const PLUGIN_MARKET_CACHE_TTL = 900;
const PLUGIN_MARKET_CACHE_FILE = 'plugin-market-list-cache.json';
const PLUGIN_MARKET_PAGE_SIZE = 40;
const PLUGIN_UPLOAD_FILE = 'plugin_upload.data';
const PLUGIN_UPLOAD_MAX = 2097152;

final class Plugin
{
private static function plugin_runtime_cache_rows_valid(array $rows): bool
{
    $fields = array_fill_keys(['id', 'file', 'config', 'entries', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron'], true);
    foreach ($rows as $row) {
        if (!is_array($row) || array_diff_key($row, $fields) || array_diff_key($fields, $row)) return false;
        $id = (string)$row['id'];
        $file = str_replace('\\', '/', ltrim((string)$row['file'], '/'));
        if (!plugin_id_valid($id) || $id === 'plugin_market' || $file !== 'app/plugins/' . $id . '/plugin.php') return false;
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
        if (!plugin_id_valid($id) || $id === 'plugin_market' || $file !== 'app/plugins/' . $id . '/plugin.php' || $manifest === null) continue;
        $rows[] = [
            'id' => $id,
            'file' => $file,
            'config' => plugin_json_decode($row['config_json'] ?? '') ?? [],
            'entries' => plugin_json_decode($row['entries_json'] ?? '') ?? [],
            'hooks' => is_array($manifest['hooks'] ?? null) ? $manifest['hooks'] : [],
            'routes' => is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [],
            'admin_tabs' => is_array($manifest['admin_tabs'] ?? null) ? $manifest['admin_tabs'] : [],
            'assets' => is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [],
            'cron' => is_array($manifest['cron'] ?? null) ? $manifest['cron'] : [],
        ];
    }
    save_settings_values(['cache_plugins' => plugin_json_encode($rows)]);
    return $rows;
}

public static function plugin_registry(?string $id = null): array
{
    if ($id !== null && !plugin_id_valid($id)) return [];
    $plugins = [];
    $sql = "SELECT id,name,version,file,manifest_json,config_json,entries_json,enabled,disabled_reason,updated_at FROM app_plugins";
    $rows = q($sql . ($id === null ? ' ORDER BY id' : ' WHERE id=?'), $id === null ? [] : [$id])->fetchAll();
    foreach ($rows as $row) {
        if ((string)$row['id'] === 'plugin_market') continue;
        $plugin = plugin_registry_row($row);
        if ($plugin) $plugins[(string)$plugin['id']] = $plugin;
    }
    return $plugins;
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

public static function plugin_market_fetch(bool $need_code = false, bool $force = false, string $plugin_id = '', int $topic_id = 0, string $market_view = 'certified'): array
{
    $market_view = self::plugin_market_view($market_view);
    if (!$need_code && !$force) {
        $cached = self::plugin_market_cache_read(true, $market_view);
        if ($cached !== null) return $cached;
    }
    $feed_url = append_url_query(self::plugin_market_url('plugin_market_feed'), ['mode' => $need_code ? 'install' : 'list', 'market_view' => $market_view === 'beta' ? 'beta' : null]);
    if ($need_code) {
        if (!plugin_id_valid($plugin_id) || $topic_id < 1) return ['ok' => 0, 'message' => '插件市场参数无效', 'plugins' => []];
        $feed_url = append_url_query($feed_url, ['id' => $plugin_id, 'topic_id' => $topic_id]);
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
        if (!plugin_id_valid($id) || $id === 'plugin_market' || ($need_code && $online_install && trim($code) === '')) continue;
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

public static function plugin_market_install(string $id, int $topic_id, bool $auto_enable = false, string $market_view = 'certified'): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    if ($id === 'plugin_market') err('该插件 ID 为系统保留');
    require_writable_dir(PLUGIN_DIR, '插件目录不可写，请检查 app/plugins/ 目录权限');
    $market_view = self::plugin_market_view($market_view);
    $market = self::plugin_market_fetch(true, true, $id, $topic_id, $market_view);
    $item = $market['plugins'][$id] ?? null;
    if (!is_array($item)) err((string)($market['message'] ?? '') ?: '插件市场没有返回该插件');
    if (empty($item['online_install']) || (int)($item['price_points'] ?? 0) > 0) err('付费插件不支持在线安装，请前往插件页面购买并下载');
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
    plugin_update_row($id, ['enabled' => $target_enabled, 'status' => $target_enabled ? 'enabled' : 'disabled', 'disabled_reason' => ''], true);
    q("UPDATE app_cron_tasks SET enabled=? WHERE plugin_id=?", [$target_enabled, $id]);
    save_settings_values([
        'plugin_' . $id . '_market_sha256' => (string)$item['sha256'],
        'plugin_' . $id . '_market_topic_id' => (string)(int)$item['topic_id'],
        'plugin_sync_pending' => '1',
    ]);
    self::plugin_assets_mark_dirty();
    if (!$auto_enable) return;
    self::plugin_registry_sync();
    self::plugin_set_enabled($id, true, true);
    self::plugin_assets_rebuild();
    if (!$plugin_file_loaded) save_settings_values(['plugin_sync_pending' => '0']);
}

public static function plugin_market_install_page(): void
{
    need_admin();
    require_post();
    $auto_enable = (string)($_POST['auto_enable'] ?? '') === '1';
    $market_view = self::plugin_market_view((string)($_POST['market_view'] ?? ''));
    self::plugin_market_install((string)($_POST['plugin_id'] ?? ''), max(0, (int)($_POST['topic_id'] ?? 0)), $auto_enable, $market_view);
    $message = $auto_enable ? '插件已安装或更新并启用。' : '插件已安装或更新，已停用。';
    if (ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'message' => $message, 'refresh' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_flash($message);
    go(admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : null]));
}

public static function plugin_market_share_page(): void
{
    need_admin();
    require_post();
    $id = (string)($_POST['plugin_id'] ?? '');
    $target = plugin_id_valid($id) ? (self::plugin_registry($id)[$id] ?? null) : null;
    if (!is_array($target)) err('插件不存在');
    $file = (string)($target['file'] ?? '');
    $code = $file !== '' && is_file($file) ? file_get_contents($file) : false;
    if (!is_string($code) || trim($code) === '') err('插件文件不存在或为空');
    if (preg_match('/^\s*```\s*[\w-]*\s*$/m', $code)) err('插件代码包含独立的 Markdown 代码块标记，无法安全分享');
    $body = "```php\n" . rtrim($code) . "\n```";
    if (strlen($body) > PLUGIN_MARKET_SHARE_MAX) err('插件代码超过分享长度限制');
    $name = trim((string)($target['name'] ?? '')) ?: $id;
    $share_form = '<form class="post-action-form" method="post" action="' . h(self::plugin_market_url('plugin_share_receive')) . '" data-no-ajax="1" data-plugin-share-auto="1"><input type="hidden" name="title" value="' . h('[' . $id . ']' . $name) . '"><textarea name="body" hidden>' . h($body) . '</textarea><button type="submit" class="plugin-enable">立即继续</button></form>';
    $author = trim((string)($target['author'] ?? ''));
    $head = '<div class="admin-plugin-summary"><strong>正在前往插件市场</strong><span>插件代码已准备好，请在官方站点确认后发布。</span></div>';
    $row = '<li class="admin-list-item admin-object-row plugin-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h($name) . '</strong><span class="admin-flag on">分享</span></div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ((string)($target['version'] ?? '') !== '' ? '<span>版本 ' . h((string)$target['version']) . '</span>' : '') . ($author !== '' ? '<span>插件作者 ' . h($author) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($target['description'] ?? '')) . '</div><div class="plugin-file">' . h($file) . '</div></div><div class="admin-inline-ops plugin-ops">' . $share_form . '</div></li>';
    page('分享插件', shell_html('<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, '') . '<ul class="admin-manage-list plugin-list">' . $row . '</ul></div>', sidebar_stack_html([sidebar_user_card_html()])));
}

public static function plugin_download_page(): void
{
    need_admin();
    require_post();
    $id = (string)($_POST['plugin_id'] ?? '');
    $plugin = plugin_id_valid($id) ? (self::plugin_registry($id)[$id] ?? null) : null;
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
    $hidden = hidden_inputs(['a' => 'admin', 'tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : '']);
    $url = admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : null]);
    $clear = $query !== '' ? '<a class="admin-search-clear" href="' . h($url) . '">清空</a>' : '';
    return '<form class="admin-table-search" method="get" action="' . h(index_url()) . '">' . $hidden . '<div class="admin-search-field"><input name="q" value="' . h($query) . '" placeholder="搜索标题 / 插件ID / 制作者" minlength="' . search_min_chars() . '"><button class="admin-search-submit" type="submit">搜索</button></div>' . $clear . '</form>';
}

public static function plugin_market_matches(array $item, string $query): bool
{
    if ($query === '') return true;
    foreach (['title', 'name', 'id', 'creator', 'description'] as $key) if (stripos((string)($item[$key] ?? ''), $query) !== false) return true;
    return false;
}

public static function plugin_market_page_html(bool $with_tabs = true): string
{
    $market_view = self::plugin_market_view((string)($_GET['market_view'] ?? ''));
    $force = (string)($_GET['refresh'] ?? '') === '1';
    $query = trim((string)($_GET['q'] ?? ''));
    $market = self::plugin_market_fetch(false, $force, '', 0, $market_view);
    $items = is_array($market['plugins'] ?? null) ? $market['plugins'] : [];
    if ($query !== '') {
        $other_market = self::plugin_market_fetch(false, $force, '', 0, $market_view === 'beta' ? 'certified' : 'beta');
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
    $items = array_filter($items, static function ($item) use ($query, $market_view): bool {
        if (!is_array($item)) return false;
        $id = (string)($item['id'] ?? '');
        return plugin_id_valid($id) && ($query !== '' || (string)($item['market_status'] ?? 'certified') === $market_view) && self::plugin_market_matches($item, $query);
    });
    uasort($items, static function (array $a, array $b) use ($updates): int {
        $a_id = (string)($a['id'] ?? '');
        $b_id = (string)($b['id'] ?? '');
        $update = (int)isset($updates[$b_id]) <=> (int)isset($updates[$a_id]);
        if ($update !== 0) return $update;
        $required = (int)!empty($b['required']) <=> (int)!empty($a['required']);
        if ($required !== 0) return $required;
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
    $url = admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : null]);
    $head = '<div class="admin-plugin-summary"><strong>插件市场</strong><span>免费插件支持在线安装，付费插件请前往插件页面购买并下载。' . ((int)($market['fetched_at'] ?? 0) > 0 ? '<br>列表更新于 ' . date('Y-m-d H:i', (int)$market['fetched_at']) . '。' : '') . '</span></div>';
    $actions = '<div class="plugin-head-actions">' . self::plugin_market_search_form($query, $market_view) . '<a class="admin-search-clear" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : null, 'q' => $query !== '' ? $query : null, 'refresh' => '1'])) . '">刷新</a></div>';
    $market_tabs = tab_bar_html([
        'certified' => ['label' => '站长认证', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market'])],
        'beta' => ['label' => '最新beta', 'href' => admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => 'beta'])],
    ], $market_view, 'plugin-admin-market-tabs');
    $html = ($with_tabs ? self::admin_plugins_tabs_html('market') : '') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, $actions) . $market_tabs . '<ul class="admin-manage-list plugin-list">';
    if (!(int)($market['ok'] ?? 0)) return $html . '<li class="empty-state">' . h((string)($market['message'] ?? '插件市场暂不可用')) . '</li></ul></div>';
    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');
        $installed = isset($local[$id]);
        $needs_update = isset($updates[$id]);
        $price_points = max(0, (int)($item['price_points'] ?? 0));
        $online_install = !array_key_exists('online_install', $item) ? $price_points === 0 : !empty($item['online_install']);
        $paid = $price_points > 0 || !$online_install;
        $item_market_view = self::plugin_market_view((string)($item['market_status'] ?? 'certified'));
        $label = $installed ? ($needs_update ? '更新' : '重新安装') : '安装';
        $button_class = $installed && !$needs_update ? '' : 'plugin-enable';
        $topic_url = (string)($item['url'] ?? '');
        if ($paid) {
            $ops = $topic_url !== '' ? '<a class="btn plugin-enable" href="' . h($topic_url) . '" target="_blank" rel="noopener">购买 / 下载 · ' . $price_points . ' 积分</a>' : '';
        } else {
            $ops = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_install')) . '" data-replace-target=".plugin-list-panel" data-plugin-market-install="1" data-plugin-market-action="' . h($label) . '" data-confirm="确定' . h($label) . '该插件？插件代码将写入本地 plugins 目录。">' . form_token() . hidden_inputs(['plugin_id' => $id, 'topic_id' => (int)($item['topic_id'] ?? 0), 'auto_enable' => '1', 'market_view' => $item_market_view]) . '<button type="submit" data-loading-text="安装中"' . ($button_class !== '' ? ' class="' . h($button_class) . '"' : '') . '>' . h($label) . '</button></form>';
        }
        if ($topic_url !== '') $ops .= '<a href="' . h($topic_url) . '" target="_blank" rel="noopener">质量报告</a>';
        $meta = [];
        if ((string)($item['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$item['version'];
        $creator = trim((string)($item['creator'] ?? ''));
        $meta[] = '插件制作者 ' . ($creator !== '' ? $creator : '未声明');
        $meta[] = $paid ? $price_points . ' 积分' : '免费';
        if ((int)($item['updated_at'] ?? 0) > 0) $meta[] = date('Y-m-d H:i', (int)$item['updated_at']);
        $title = h((string)($item['name'] ?? $id));
        $title = $topic_url !== '' ? '<a class="admin-content-title" href="' . h($topic_url) . '" target="_blank" rel="noopener">' . $title . '</a>' : '<strong class="admin-content-title">' . $title . '</strong>';
        $flag = $item_market_view === 'certified' ? '<span class="admin-flag on">站长认证</span>' : '';
        $flag .= (!empty($item['required']) ? '<span class="admin-flag danger">必装</span>' : '') . ($installed ? '<span class="admin-flag ' . ($needs_update ? 'update' : 'on') . '">' . h($needs_update ? '可更新' : '已安装') . '</span>' : '<span class="admin-flag">未安装</span>');
        $class = ' plugin-market-entry' . ($needs_update ? ' plugin-market-update plugin-update-item' : ($installed ? ' plugin-market-installed' : ' plugin-market-available'));
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . $class . '"><div class="admin-row-main"><div class="plugin-title-line">' . $title . $flag . '</div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span><span>' . h(implode(' / ', $meta)) . '</span></div><div class="admin-content-text plugin-desc">' . h((string)($item['description'] ?? '')) . '</div><div class="plugin-file">' . h(substr((string)($item['sha256'] ?? ''), 0, 16)) . '</div></div><div class="admin-inline-ops plugin-ops">' . $ops . '</div></li>';
    }
    if (!$items) $html .= '<li class="empty-state">' . h($query !== '' ? '没有匹配的插件。' : ($market_view === 'beta' ? '暂无待审核或忽略的插件。' : '暂无已审核通过的插件。')) . '</li>';
    $html .= '</ul></div>';
    $pagination = paginate($total, $page, PLUGIN_MARKET_PAGE_SIZE, admin_url(['tab' => 'plugins', 'view' => 'market', 'market_view' => $market_view === 'beta' ? 'beta' : null, 'q' => $query !== '' ? $query : null]));
    return $html . ($pagination !== '' ? '<div class="pagination-bar">' . $pagination . '</div>' : '');
}

public static function plugin_market_admin_actions(array $plugin): string
{
    $id = (string)($plugin['id'] ?? '');
    if (!plugin_id_valid($id)) return '';
    $share = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_share')) . '" target="_blank" rel="noopener" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit">分享</button></form>';
    $download = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_download')) . '" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit">下载</button></form>';
    return $share . $download;
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
    if (!plugin_uses_entry($plugin, $entry)) return '';
    $id = (string)$plugin['id'];
    $checked = plugin_entry_enabled($plugin, $entry);
    return '<form class="post-action-form plugin-entry-form" method="post" action="' . h(admin_url(['tab' => 'plugins'])) . '" data-replace-target=".plugin-list-panel">' . form_token() . hidden_inputs(['plugin_id' => $id, 'plugin_action' => 'entry_toggle', 'entry' => $entry, 'entry_enabled' => '0']) . '<label class="plugin-entry-check"><input type="checkbox" name="entry_enabled" value="1" data-auto-submit' . ($checked ? ' checked' : '') . '><span>' . h($label) . '</span></label></form>';
}
public static function admin_plugins_page_html(bool $with_tabs = true): string
{
    $plugins = self::plugin_registry();
    $table_conflicts = is_array($GLOBALS['__plugin_table_conflicts'] ?? null)
        ? $GLOBALS['__plugin_table_conflicts']
        : self::plugin_table_conflicts(self::plugin_files());
    uasort($plugins, function (array $a, array $b): int {
        $a_time = (int)($a['updated_at'] ?? 0);
        $b_time = (int)($b['updated_at'] ?? 0);
        return ($b_time <=> $a_time) ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $enabled_count = 0;
    foreach ($plugins as $plugin) if (plugin_enabled($plugin)) $enabled_count++;
    $head_left = '<div class="admin-plugin-summary"><strong>插件</strong><span>已发现 ' . count($plugins) . ' 个，已启用 ' . $enabled_count . ' 个</span></div>';
    $search = '<label class="admin-search-field plugin-local-search"><input type="search" placeholder="搜索本地插件" aria-label="搜索本地插件" data-plugin-local-search></label>';
    $head_right = '<div class="plugin-head-actions">' . $search . self::admin_plugin_upload_form() . self::admin_plugin_action_form('', 'sync', '同步插件', 'plugin-head-button') . '</div>';
    $html = ($with_tabs ? self::admin_plugins_tabs_html('local') : '') . '<div class="admin-list-panel plugin-list-panel plugin-local-list-panel">' . admin_list_head($head_left, $head_right) . '<ul class="admin-manage-list plugin-list" data-plugin-local-list>';
    foreach ($plugins as $plugin) {
        $id = (string)$plugin['id'];
        $enabled = plugin_enabled($plugin);
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
        foreach (plugin_entry_definitions() as $entry => $definition) {
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
        $plugin_file = (string)($plugin['file'] ?? '');
        $disabled_reason = !$enabled ? trim((string)($plugin['disabled_reason'] ?? '')) : '';
        $reason_line = $disabled_reason !== '' ? '<div class="plugin-disabled-reason"><strong>自动停用原因</strong><span>' . h($disabled_reason) . '</span></div>' : '';
        $table_conflict_line = isset($table_conflicts[$plugin_file]) ? '<div class="plugin-disabled-reason"><strong>数据表冲突</strong><span>' . h(implode('；', $table_conflicts[$plugin_file])) . '</span></div>' : '';
        $entry_line = $entry_ops !== '' ? '<div class="plugin-entry-line"><span class="plugin-entry-label">展示位置</span><div class="plugin-entry-options">' . $entry_ops . '</div></div>' : '';
        $local_class = $enabled ? ' plugin-local-enabled' : ' plugin-local-disabled';
        $title = $manage_url !== '' ? '<a class="admin-content-title" href="' . h($manage_url) . '">' . h((string)$plugin['name']) . '</a>' : '<strong class="admin-content-title">' . h((string)$plugin['name']) . '</strong>';
        $search_text = implode("\n", [(string)($plugin['name'] ?? ''), $id, (string)($plugin['author'] ?? ''), (string)($plugin['description'] ?? '')]);
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . $local_class . '" data-plugin-local-search-text="' . h($search_text) . '"><div class="admin-row-main"><div class="plugin-title-line">' . $title . '<span class="admin-flag' . ($enabled ? ' on' : '') . '">' . h($enabled ? '已启用' : '已停用') . '</span></div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ($meta ? '<span>' . h(implode(' / ', $meta)) . '</span>' : '') . ($features ? '<span>' . h(implode(' / ', $features)) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($plugin['description'] ?? '')) . '</div>' . $reason_line . $table_conflict_line . '<div class="plugin-file">' . h($file) . '</div></div>' . $entry_line . '<div class="admin-inline-ops plugin-ops">' . $ops . '</div></li>';
    }
    if ($plugins) $html .= '<li class="empty-state is-hidden" data-plugin-local-empty hidden>未找到匹配插件</li>';
    else $html .= '<li class="empty-state">暂无插件，放入 app/plugins/*/plugin.php 后点击“同步插件”。</li>';
    return $html . '</ul></div>';
}
public static function admin_plugin_manage_url(array $plugin): string
{
    if (!plugin_enabled($plugin) || !is_array($plugin['admin_tabs'] ?? null)) return '';
    foreach ($plugin['admin_tabs'] as $key => $fn) {
        if (is_string($key) && $key !== '' && is_string($fn) && $fn !== '') return admin_url(['tab' => $key]);
    }
    return '';
}
public static function admin_plugins_tabs_html(string $active): string
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
        foreach (self::plugin_registry() as $plugin) {
            $url = self::admin_plugin_manage_url($plugin);
            if ($url !== '') $entries[] = '<a href="' . h($url) . '">' . h((string)($plugin['name'] ?? $plugin['id'])) . '</a>';
        }
        if ($entries) $entry_html = '<div class="admin-list-head plugin-management-entries"><strong>插件管理配置</strong><div>' . implode('', $entries) . '</div></div>';
    }
    return tab_bar_html($items, $active, 'plugin-tabs') . $entry_html;
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
        $html .= '<li class="admin-list-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h($plugin_name) . '</strong><span class="admin-flag' . $class . '">' . h($labels[$status] ?? $status) . '</span></div><div class="admin-row-meta"><span class="plugin-id">' . h($plugin_id) . ' / ' . h((string)$row['task_name']) . '</span><span>' . date('Y-m-d H:i:s', $started_at) . '</span><span>' . h($duration) . '</span>' . ($message !== '' ? '<span title="' . h($message) . '">' . h(cut($message, 160)) . '</span>' : '') . '</div></div></li>';
    }
    if (!$rows) $html .= '<li class="empty-state">暂无计划任务运行记录</li>';
    $html .= '</ul></div>';
    $pagination = simple_paginate($page > 1, $has_next, $page, admin_url(['tab' => 'plugins', 'view' => 'cron']));
    return $html . ($pagination === '' ? '' : '<div class="pagination-bar">' . $pagination . '</div>');
}
public static function admin_plugin_tab_html(string $tab): ?string
{
    foreach (plugins() as $plugin) {
        if (!plugin_enabled($plugin)) continue;
        $fn = $plugin['admin_tabs'][$tab] ?? null;
        if ($fn !== null) {
            $html = plugin_call($plugin, fn(): ?string => plugin_callback_exists($fn) ? (string)$fn($plugin) : null);
            if ($html !== null) return $html;
        }
    }
    return null;
}

public static function plugin_disable_after_exception(string $id, Throwable $e): void
{
    if (!plugin_id_valid($id)) return;
    static $handled;
    $handled ??= new WeakMap();
    if (isset($handled[$e])) return;
    $handled[$e] = true;
    $message = trim((string)preg_replace('/\s+/', ' ', $e->getMessage()));
    $file = str_replace('\\', '/', $e->getFile());
    $root = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/';
    if (str_starts_with($file, $root)) $file = substr($file, strlen($root));
    $reason = date('Y-m-d H:i:s') . ' ' . get_class($e) . ($message !== '' ? ': ' . cut($message, 500) : '') . ($file !== '' ? ' (' . $file . ':' . $e->getLine() . ')' : '');
    try {
        plugin_update_row($id, ['enabled' => 0, 'status' => 'error', 'disabled_reason' => $reason]);
        q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
        plugins(true);
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
    if (!plugin_id_valid($id) || $id !== basename(dirname($file))) return null;
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
        $hook = plugin_entry_hook_name($entry);
        if ($hook !== '' && isset($base['hooks'][$hook])) $entries[$entry] = !empty($enabled);
    }
    $base['entries'] = $entries;
    $assets = [];
    foreach (['css', 'js'] as $type) {
        $fn = $base['assets'][$type] ?? null;
        if (is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $assets[$type] = $fn;
    }
    $base['assets'] = $assets;
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

public static function plugin_assets_mark_dirty(): void
{
    save_settings_values(['plugin_assets_dirty' => '1']);
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

public static function plugin_assets_rebuild(): array
{
    $chunks = ['css' => [], 'js' => []];
    foreach (plugins() as $plugin) {
        if (!plugin_enabled($plugin) || empty($plugin['assets'])) continue;
        foreach (['css', 'js'] as $type) {
            $fn = $plugin['assets'][$type] ?? null;
            if (!is_string($fn)) continue;
            $content = trim((string)plugin_call($plugin, function () use ($fn): string {
                return plugin_callback_exists($fn) ? (string)$fn() : '';
            }));
            if ($content === '') continue;
            $chunk = '/* ' . (string)$plugin['id'] . " */\n" . $content;
            $chunks[$type][] = $chunk;
        }
    }
    $files = ['css' => PLUGIN_CSS_FILE, 'js' => PLUGIN_JS_FILE];
    $manifest = [];
    foreach ($files as $key => $file) {
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
        'plugin_assets_dirty' => '0',
    ]);
    return $manifest;
}

private static function plugin_file_functions(string $file): array
{
    $code = @file_get_contents($file);
    if (!is_string($code) || $code === '') return [];
    $tokens = token_get_all($code);
    $functions = [];
    $namespace = '';
    $brace_depth = 0;
    $class_depths = [];
    $function_depths = [];
    $pending_class = false;
    $pending_function = false;
    $class_tokens = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) $class_tokens[] = T_ENUM;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token)) {
            if ($token[0] === T_NAMESPACE && !$class_depths && !$function_depths) {
                $name = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if ($part === ';' || $part === '{') break;
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) $name .= $part[1];
                }
                $namespace = trim($name, '\\');
                continue;
            }
            if (in_array($token[0], $class_tokens, true)) {
                $k = $i - 1;
                while ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $k--;
                $previous = $k >= 0 ? $tokens[$k] : null;
                if (!is_array($previous) || $previous[0] !== T_DOUBLE_COLON) $pending_class = true;
                continue;
            }
            if ($token[0] !== T_FUNCTION) continue;
            $nested = (bool)$class_depths || (bool)$function_depths;
            $pending_function = true;
            $j = $i + 1;
            while ($j < $count) {
                $part = $tokens[$j];
                if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                    continue;
                }
                if ($part === '&' || (is_array($part) && $part[1] === '&')) {
                    $j++;
                    continue;
                }
                break;
            }
            if (!$nested && $j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = ($namespace !== '' ? $namespace . '\\' : '') . (string)$tokens[$j][1];
                $key = strtolower($name);
                $functions[$key][] = ['name' => $name, 'line' => (int)$tokens[$j][2]];
            }
            continue;
        }
        if ($token === '{') {
            $brace_depth++;
            if ($pending_class) {
                $class_depths[] = $brace_depth;
                $pending_class = false;
            }
            if ($pending_function) {
                $function_depths[] = $brace_depth;
                $pending_function = false;
            }
            continue;
        }
        if ($token === ';') {
            $pending_class = false;
            $pending_function = false;
            continue;
        }
        if ($token !== '}') continue;
        if ($class_depths && end($class_depths) === $brace_depth) array_pop($class_depths);
        if ($function_depths && end($function_depths) === $brace_depth) array_pop($function_depths);
        $brace_depth = max(0, $brace_depth - 1);
    }
    return $functions;
}

private static function plugin_function_conflicts(array $files): array
{
    $definitions = [];
    $by_file = [];
    foreach ($files as $file) {
        $by_file[$file] = self::plugin_file_functions($file);
        foreach ($by_file[$file] as $name => $items) {
            foreach ($items as $item) $definitions[$name][] = ['file' => $file] + $item;
        }
    }
    $conflicts = [];
    foreach ($definitions as $name => $items) {
        $display = (string)($items[0]['name'] ?? $name);
        $files_for_name = array_values(array_unique(array_column($items, 'file')));
        $real_files_for_name = array_values(array_filter(array_map('realpath', $files_for_name), 'is_string'));
        foreach ($files_for_name as $file) {
            $same_file_count = count(array_filter($items, static fn(array $item): bool => (string)$item['file'] === $file));
            if ($same_file_count > 1) $conflicts[$file][] = '插件文件内重复定义函数 ' . $display;
        }
        if (count($files_for_name) > 1) {
            $ids = array_map(static fn(string $file): string => basename(dirname($file)), $files_for_name);
            foreach ($files_for_name as $file) {
                $others = array_values(array_filter($ids, static fn(string $id): bool => $id !== basename(dirname($file))));
                $conflicts[$file][] = '函数 ' . $display . ' 与插件 ' . implode('、', $others) . ' 重复定义';
            }
        }
        if (!function_exists($display)) continue;
        $defined_internal = false;
        try {
            $reflection = new \ReflectionFunction($display);
            $defined_internal = $reflection->isInternal();
            $defined_file = $reflection->getFileName();
        } catch (Throwable $e) {
            $defined_file = false;
        }
        $defined_real = is_string($defined_file) ? realpath($defined_file) : false;
        foreach ($files_for_name as $file) {
            if ($defined_real !== false && $defined_real === realpath($file)) continue;
            if ($defined_real !== false && in_array($defined_real, $real_files_for_name, true)) continue;
            if ($defined_internal) $source = 'PHP 内置函数';
            elseif ($defined_real !== false) $source = str_replace(rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/', '', str_replace('\\', '/', $defined_real));
            else $source = is_string($defined_file) && $defined_file !== '' ? $defined_file : '当前已加载代码';
            $conflicts[$file][] = '函数 ' . $display . ' 已由 ' . $source . ' 定义';
        }
    }
    foreach ($conflicts as $file => $reasons) $conflicts[$file] = array_values(array_unique($reasons));
    return $conflicts;
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

public static function plugin_registry_sync(): array
{
    $existing = [];
    foreach (q("SELECT * FROM app_plugins")->fetchAll() as $row) $existing[(string)$row['id']] = $row;
    $synced = [];
    $disable = function (string $id, string $file, string $reason) use (&$existing, &$synced): void {
        if (!plugin_id_valid($id) || $id === 'plugin_market') return;
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
            'disabled_reason' => cut($reason, 500),
            'installed_at' => (int)($old['installed_at'] ?? 0) ?: now(),
            'updated_at' => (string)($old['code_hash'] ?? '') === $code_hash ? ((int)($old['updated_at'] ?? 0) ?: now()) : now(),
        ], ['id']);
        q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
        $synced[$id] = true;
    };
    $files = self::plugin_files();
    $function_conflicts = self::plugin_function_conflicts($files);
    $GLOBALS['__plugin_table_conflicts'] = self::plugin_table_conflicts($files);
    foreach ($files as $file) {
        $id = basename(dirname($file));
        if ($id === 'plugin_market') continue;
        if (isset($function_conflicts[$file])) {
            $disable($id, $file, implode('；', $function_conflicts[$file]));
            continue;
        }
        if (array_key_exists($file, $GLOBALS['__plugin_raw'] ?? [])) {
            $raw = $GLOBALS['__plugin_raw'][$file];
        } else {
            try {
                if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
                $raw = include $file;
            } catch (Throwable $e) {
                $disable($id, $file, $e->getMessage());
                continue;
            }
            $GLOBALS['__plugin_raw'][$file] = $raw;
        }
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
        $code_hash = hash_file('sha256', $file) ?: '';
        $updated_at = isset($old['updated_at']) && (string)($old['code_hash'] ?? '') === $code_hash
            ? (int)$old['updated_at']
            : now();
        $config = plugin_json_decode($old['config_json'] ?? '') ?? [];
        $entries = isset($old['entries_json']) ? plugin_json_decode($old['entries_json']) ?? [] : [];
        foreach (plugin_entry_definitions() as $entry => $definition) {
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
            'manifest_json' => plugin_json_encode(array_intersect_key($plugin, array_flip(['description', 'author', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron', 'install', 'uninstall']))),
            'config_json' => plugin_json_encode($config),
            'entries_json' => plugin_json_encode($entries),
            'enabled' => $enabled,
            'status' => $enabled ? 'enabled' : 'disabled',
            'disabled_reason' => (string)($old['disabled_reason'] ?? ''),
            'installed_at' => $installed_at,
            'updated_at' => $updated_at,
        ], ['id']);
        $synced[$id] = true;
    }
    foreach (array_keys($existing) as $id) {
        if (isset($synced[$id])) continue;
        q("DELETE FROM app_cron_tasks WHERE plugin_id=?", [$id]);
        q("DELETE FROM app_plugins WHERE id=?", [$id]);
    }
    $plugins = self::plugin_registry();
    plugins(true);
    foreach ($plugins as $plugin) Cron::plugin_cron_sync($plugin);
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
    if (!plugin_id_valid($id) || !hash_equals($expected, $token)) err('插件启用参数无效');
    self::plugin_set_enabled($id, true);
    self::plugin_assets_rebuild();
    set_flash('插件 ' . $id . ' 已上传、同步并启用');
    go(admin_url(['tab' => 'plugins']));
}

private static function plugin_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) err($error === UPLOAD_ERR_NO_FILE ? '请选择插件脚本文件' : '插件上传失败');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if (!is_uploaded_file($tmp) || $size <= 0) err('插件文件无效');
    if ($size > PLUGIN_UPLOAD_MAX) err('插件文件不能超过2MB');
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
    if (!plugin_id_valid($id) || $id === 'plugin_market') {
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
    save_settings_values(['plugin_sync_pending' => '1', 'plugin_assets_dirty' => '1']);
    return ['id' => $id];
}

public static function plugin_set_entry_enabled(string $id, string $entry, bool $enabled): void
{
    if (!plugin_id_valid($id) || plugin_entry_hook_name($entry) === '') err('参数错误');
    $plugin = self::plugin_registry($id)[$id] ?? null;
    if (!$plugin || !plugin_uses_entry($plugin, $entry)) err('插件未使用该入口');
    $entries = (array)($plugin['entries'] ?? []);
    $entries[$entry] = $enabled;
    plugin_update_row($id, ['entries_json' => $entries], false);
    plugins(true);
}

public static function plugin_set_enabled(string $id, bool $enabled, bool $run_install = false): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    $plugin = self::plugin_registry($id)[$id] ?? null;
    if (!$plugin) err('插件不存在');
    if ($enabled) {
        $row = one("SELECT status,disabled_reason FROM app_plugins WHERE id=?", [$id]);
        $disabled_reason = trim((string)($row['disabled_reason'] ?? ''));
        if ((string)($row['status'] ?? '') === 'error' && str_contains($disabled_reason, '函数')) {
            err('插件函数冲突尚未重新同步：' . $disabled_reason);
        }
        $file = (string)($plugin['file'] ?? '');
        $conflicts = self::plugin_function_conflicts(self::plugin_files());
        if ($file !== '' && isset($conflicts[$file])) err('插件存在函数冲突：' . implode('；', $conflicts[$file]) . '。请修正后重新同步插件');
        plugin_call($plugin, function () use ($plugin, $run_install): void {
            if (($run_install || !plugin_enabled($plugin)) && plugin_callback_exists($plugin['install'] ?? null)) {
                call_user_func((string)$plugin['install'], $plugin);
            }
        });
    }
    plugin_update_row($id, ['enabled' => $enabled ? 1 : 0, 'status' => $enabled ? 'enabled' : 'disabled', 'disabled_reason' => ''], false);
    q("UPDATE app_cron_tasks SET enabled=? WHERE plugin_id=?", [$enabled ? 1 : 0, $id]);
    $runtime_plugins = plugins(true);
    if ($enabled && isset($runtime_plugins[$id])) Cron::plugin_cron_sync($runtime_plugins[$id]);
    self::plugin_assets_mark_dirty();
}

public static function plugin_uninstall(string $id, bool $keep_data = true): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
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
    if (!$keep_data) {
        plugin_call($plugin, function () use ($plugin, $id): void {
            $fn = (string)($plugin['uninstall'] ?? '');
            if (!plugin_callback_exists($fn)) $fn = str_replace('-', '_', $id) . '_uninstall';
            if (plugin_callback_exists($fn)) call_user_func($fn, $plugin);
        });
    }
    self::plugin_remove_directory($dir);
    q("DELETE FROM app_cron_tasks WHERE plugin_id=?", [$id]);
    q("DELETE FROM app_plugins WHERE id=?", [$id]);
    plugins(true);
    plugin_runtime_cache_reset();
    self::plugin_assets_mark_dirty();
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
