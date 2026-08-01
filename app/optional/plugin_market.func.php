<?php
if (!defined('APP_ROOT')) exit;

const PLUGIN_MARKET_ENDPOINT = 'https://bbs1.org/index.php';
const PLUGIN_MARKET_SHARE_MAX = 200000;

function plugin_market_url(string $action): string
{
    return append_url_query(PLUGIN_MARKET_ENDPOINT, ['a' => $action]);
}

function plugin_market_fetch(): array
{
    $response = remote_http_request(plugin_market_url('plugin_market_feed'), 8, ['Accept: application/json']);
    if (!$response['ok']) return ['ok' => 0, 'message' => '无法连接插件市场' . ((string)$response['error'] !== '' ? '：' . (string)$response['error'] : ''), 'plugins' => []];
    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) return ['ok' => 0, 'message' => '插件市场返回格式错误', 'plugins' => []];
    $plugins = [];
    foreach ((array)($data['plugins'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        $code = (string)($item['code'] ?? '');
        if (!plugin_id_valid($id) || $id === 'plugin_market' || trim($code) === '') continue;
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

function plugin_market_install(string $id): void
{
    if (!plugin_id_valid($id)) err('插件不存在');
    if ($id === 'plugin_market') err('该插件 ID 为系统保留');
    require_writable_dir(PLUGIN_DIR, '插件目录不可写，请检查 app/plugins/ 目录权限');
    $market = plugin_market_fetch();
    $item = $market['plugins'][$id] ?? null;
    if (!is_array($item)) err((string)($market['message'] ?? '') ?: '插件市场没有返回该插件');
    $code = (string)($item['code'] ?? '');
    if (!str_starts_with(ltrim($code), '<?php')) err('插件代码格式错误');
    if ((string)$item['sha256'] !== '' && !hash_equals((string)$item['sha256'], hash('sha256', $code))) err('插件代码校验失败');
    if (preg_match('/[\'"]id[\'"]\s*=>\s*([\'"])(.*?)\1/s', $code, $match) !== 1 || (string)$match[2] !== $id) err('插件代码 ID 与市场 ID 不一致');
    $dir = PLUGIN_DIR . '/' . $id;
    $file = $dir . '/plugin.php';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) err('插件目录创建失败');
    require_writable_dir($dir, '插件目录不可写，请检查 app/plugins/ 目录权限');
    if (is_file($file)) {
        $backup_dir = CACHE_DIR . '/plugin-backups';
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
    plugin_update_row($id, ['enabled' => 0, 'status' => 'disabled', 'disabled_reason' => '']);
    q("UPDATE app_cron_tasks SET enabled=0 WHERE plugin_id=?", [$id]);
    save_settings_values([
        'plugin_' . $id . '_market_sha256' => (string)$item['sha256'],
        'plugin_' . $id . '_market_topic_id' => (string)(int)$item['topic_id'],
        'plugin_sync_pending' => '1',
    ]);
    plugin_assets_mark_dirty();
}

function plugin_market_install_page(): void
{
    need_admin();
    require_post();
    plugin_market_install((string)($_POST['plugin_id'] ?? ''));
    $message = '插件已安装或更新，已自动停用，请启用后使用。';
    if (ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => 1, 'message' => $message, 'refresh' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_flash($message);
    go(admin_url(['tab' => 'plugins', 'view' => 'market']));
}

function plugin_market_share_page(): void
{
    need_admin();
    require_post();
    $id = (string)($_POST['plugin_id'] ?? '');
    $target = plugin_id_valid($id) ? (plugins()[$id] ?? null) : null;
    if (!is_array($target)) err('插件不存在');
    $file = (string)($target['file'] ?? '');
    $code = $file !== '' && is_file($file) ? file_get_contents($file) : false;
    if (!is_string($code) || trim($code) === '') err('插件文件不存在或为空');
    if (preg_match('/^\s*```\s*[\w-]*\s*$/m', $code)) err('插件代码包含独立的 Markdown 代码块标记，无法安全分享');
    $body = "```php\n" . rtrim($code) . "\n```";
    if (strlen($body) > PLUGIN_MARKET_SHARE_MAX) err('插件代码超过分享长度限制');
    $name = trim((string)($target['name'] ?? '')) ?: $id;
    $share_form = '<form class="post-action-form" method="post" action="' . h(plugin_market_url('plugin_share_receive')) . '" data-no-ajax="1" data-plugin-share-auto="1"><input type="hidden" name="title" value="' . h('[' . $id . ']' . $name) . '"><textarea name="body" hidden>' . h($body) . '</textarea><button type="submit" class="plugin-enable">立即继续</button></form>';
    $author = trim((string)($target['author'] ?? ''));
    $head = '<div class="admin-plugin-summary"><strong>正在前往插件市场</strong><span>插件代码已准备好，请在官方站点确认后发布。</span></div>';
    $row = '<li class="admin-list-item admin-object-row plugin-item"><div class="admin-row-main"><div class="plugin-title-line"><strong class="admin-content-title">' . h($name) . '</strong><span class="admin-flag on">分享</span></div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span>' . ((string)($target['version'] ?? '') !== '' ? '<span>版本 ' . h((string)$target['version']) . '</span>' : '') . ($author !== '' ? '<span>插件作者 ' . h($author) . '</span>' : '') . '</div><div class="admin-content-text plugin-desc">' . h((string)($target['description'] ?? '')) . '</div><div class="plugin-file">' . h($file) . '</div></div><div class="admin-inline-ops plugin-ops">' . $share_form . '</div></li>';
    page('分享插件', shell_html('<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, '') . '<ul class="admin-manage-list plugin-list">' . $row . '</ul></div>', sidebar_stack_html([sidebar_user_card_html()])));
}

function plugin_market_update_available(array $plugin, array $item): bool
{
    $remote = trim((string)($item['version'] ?? ''));
    $local = trim((string)($plugin['version'] ?? ''));
    return $remote !== '' && $local !== '' && version_compare($remote, $local, '>');
}

function plugin_market_search_form(string $query): string
{
    $hidden = hidden_inputs(['a' => 'admin', 'tab' => 'plugins', 'view' => 'market']);
    $url = admin_url(['tab' => 'plugins', 'view' => 'market']);
    $clear = $query !== '' ? '<a class="admin-search-clear" href="' . h($url) . '">清空</a>' : '';
    return '<form class="admin-table-search" method="get" action="' . h(index_url()) . '">' . $hidden . '<div class="admin-search-field"><input name="q" value="' . h($query) . '" placeholder="搜索标题 / 插件ID / 制作者" minlength="' . search_min_chars() . '"><button class="admin-search-submit" type="submit">搜索</button></div>' . $clear . '</form>';
}

function plugin_market_matches(array $item, string $query): bool
{
    if ($query === '') return true;
    foreach (['title', 'name', 'id', 'creator', 'description'] as $key) if (stripos((string)($item[$key] ?? ''), $query) !== false) return true;
    return false;
}

function plugin_market_page_html(bool $with_tabs = true): string
{
    $market = plugin_market_fetch();
    $items = is_array($market['plugins'] ?? null) ? $market['plugins'] : [];
    $local = plugins();
    $updates = [];
    foreach ($items as $id => $item) if (isset($local[$id]) && is_array($item) && plugin_market_update_available($local[$id], $item)) $updates[$id] = true;
    uksort($items, fn(string $a, string $b): int => (int)isset($updates[$b]) <=> (int)isset($updates[$a]));
    $query = trim((string)($_GET['q'] ?? ''));
    $url = admin_url(['tab' => 'plugins', 'view' => 'market']);
    $head = '<div class="admin-plugin-summary"><strong>插件市场</strong><span>仅展示官方审核通过的插件，安装后默认仍需手动启用。</span></div>';
    $actions = '<div class="plugin-head-actions">' . plugin_market_search_form($query) . '<a class="admin-search-clear" href="' . h($url) . '">刷新</a></div>';
    $html = ($with_tabs ? admin_plugins_tabs_html('market') : '') . '<div class="admin-list-panel plugin-list-panel">' . admin_list_head($head, $actions) . '<ul class="admin-manage-list plugin-list">';
    if (!(int)($market['ok'] ?? 0)) return $html . '<li class="empty-state">' . h((string)($market['message'] ?? '插件市场暂不可用')) . '</li></ul></div>';
    $shown = 0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (string)($item['id'] ?? '');
        if (!plugin_id_valid($id) || !plugin_market_matches($item, $query)) continue;
        $shown++;
        $installed = isset($local[$id]);
        $needs_update = isset($updates[$id]);
        $label = $installed ? ($needs_update ? '更新' : '重新安装') : '安装';
        $button_class = $installed && !$needs_update ? '' : 'plugin-enable';
        $ops = '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_install')) . '" data-replace-target=".plugin-list-panel" data-confirm="确定' . h($label) . '该插件？插件代码将写入本地 plugins 目录，完成后插件会自动停用。">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit"' . ($button_class !== '' ? ' class="' . h($button_class) . '"' : '') . '>' . h($label) . '</button></form>';
        $meta = [];
        if ((string)($item['version'] ?? '') !== '') $meta[] = '版本 ' . (string)$item['version'];
        $creator = trim((string)($item['creator'] ?? ''));
        $meta[] = '插件制作者 ' . ($creator !== '' ? $creator : '未声明');
        if ((int)($item['updated_at'] ?? 0) > 0) $meta[] = date('Y-m-d H:i', (int)$item['updated_at']);
        $topic_url = (string)($item['url'] ?? '');
        $title = h((string)($item['name'] ?? $id));
        $title = $topic_url !== '' ? '<a class="admin-content-title" href="' . h($topic_url) . '" target="_blank" rel="noopener">' . $title . '</a>' : '<strong class="admin-content-title">' . $title . '</strong>';
        $flag = $installed ? '<span class="admin-flag ' . ($needs_update ? 'update' : 'on') . '">' . h($needs_update ? '可更新' : '已安装') . '</span>' : '<span class="admin-flag">未安装</span>';
        $class = $needs_update ? ' plugin-market-update plugin-update-item' : ($installed ? ' plugin-market-installed' : ' plugin-market-available');
        $html .= '<li class="admin-list-item admin-object-row plugin-item' . $class . '"><div class="admin-row-main"><div class="plugin-title-line">' . $title . $flag . '</div><div class="admin-row-meta"><span class="plugin-id">ID ' . h($id) . '</span><span>' . h(implode(' / ', $meta)) . '</span></div><div class="admin-content-text plugin-desc">' . h((string)($item['description'] ?? '')) . '</div><div class="plugin-file">' . h(substr((string)($item['sha256'] ?? ''), 0, 16)) . '</div></div><div class="admin-inline-ops plugin-ops">' . $ops . '</div></li>';
    }
    if ($shown === 0) $html .= '<li class="empty-state">' . h($query !== '' ? '没有匹配的插件。' : '暂无已审核通过的插件。') . '</li>';
    return $html . '</ul></div>';
}

function plugin_market_admin_actions(array $plugin): string
{
    $id = (string)($plugin['id'] ?? '');
    if (!plugin_id_valid($id)) return '';
    return '<form class="post-action-form" method="post" action="' . h(route_url('plugin_market_share')) . '" target="_blank" rel="noopener" data-no-ajax="1">' . form_token() . hidden_inputs(['plugin_id' => $id]) . '<button type="submit">分享</button></form>';
}

function plugin_market_route(string $action): bool
{
    $handler = [
        'plugin_market_install' => 'plugin_market_install_page',
        'plugin_market_share' => 'plugin_market_share_page',
    ][$action] ?? null;
    if ($handler === null) return false;
    $handler();
    return true;
}
