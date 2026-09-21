<?php
declare(strict_types=1);

namespace app\optional;

if (!defined('APP_ROOT')) exit;

final class Admin
{
    public static function flag(int $yes, bool $danger = false): string
    {
        return '<span class="admin-flag' . ($yes ? ($danger ? ' danger' : ' on') : '') . '">' . ($yes ? '是' : '否') . '</span>';
    }

    public static function clear_opcache_cache(): bool
    {
        if (!function_exists('opcache_reset')) return false;
        try { return (bool)opcache_reset(); } catch (\Throwable) { return false; }
    }

    public static function opcache_refresh_route(): void
    {
        $lock_file = DATA_DIR . '/opcache-refresh.lock';
        if (!is_file($lock_file) || !self::clear_opcache_cache()) { http_response_code(403); exit('failed'); }
        @unlink($lock_file);
        exit('ok');
    }

    public static function save_settings(): void
    {
        $site_name = post('site_name', 80);
        if ($site_name === '') err('网站名不能为空');
        $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
        if (!group_by_id($gid)) err('默认用户组不存在');
        $values = ['site_name' => $site_name, 'site_base_url' => clean_site_base_url((string)($_POST['site_base_url'] ?? '')), 'pinned_topic_ids' => preg_replace('/[^\d,]/', '', (string)($_POST['pinned_topic_ids'] ?? '')) ?: '', 'default_group_id' => (string)$gid];
        foreach (['site_name_title' => 80, 'site_keywords' => 200, 'site_description' => 500] as $key => $max) $values[$key] = post($key, $max);
        foreach (['site_closed', 'debug_mode', 'ignore_ssl_errors', 'pretty_url', 'allow_register'] as $key) $values[$key] = isset($_POST[$key]) ? '1' : '0';
        foreach (['pc_nav_forum_count' => [0, 20, 6], 'topics_per_page' => [1, 200, 30], 'replies_per_page' => [1, 200, 50], 'max_pagination_pages' => [1, 1000, 50], 'search_min_chars' => [1, 20, 2], 'post_interval_seconds' => [0, 3600, 5]] as $key => [$min, $max, $default]) $values[$key] = (string)min($max, max($min, (int)($_POST[$key] ?? $default)));
        save_settings_values($values);
    }

    public static function save_forum(): void
    {
        $name = post('name', 80);
        if ($name === '') err('版块名不能为空');
        $description = post('description', 300);
        $sort = (int)$_POST['sort'];
        $permissions = [];
        foreach (['allow_view_groups', 'allow_post_groups', 'allow_reply_groups'] as $field) $permissions[$field] = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$field] ?? []))))));
        id() ? q("UPDATE app_forums SET name=?,description=?,sort=?,allow_view_groups=?,allow_post_groups=?,allow_reply_groups=? WHERE id=?", [$name, $description, $sort, ...array_values($permissions), id()]) : q("INSERT INTO app_forums(name,description,sort,allow_view_groups,allow_post_groups,allow_reply_groups) VALUES(?,?,?,?,?,?)", [$name, $description, $sort, ...array_values($permissions)]);
        forums_cache(true);
    }

    public static function forum_group_select_options(?array $forum = null, string $field = '', string $label = ''): string
    {
        $selected = [];
        if ($forum && $field !== '') $selected = forum_group_ids($forum, $field);
        $html = '<div class="grid"><span>' . h($label) . '</span><div class="forum-group-checks">';
        foreach (groups_cache() as $group) {
            $gid = (int)$group['id'];
            $html .= '<label class="check"><input type="checkbox" name="' . h($field) . '[]" value="' . $gid . '"' . (in_array($gid, $selected, true) ? ' checked' : '') . '><span>' . h($group['name']) . '</span></label>';
        }
        return $html . '</div></div>';
    }

    public static function save_group(): void
    {
        $name = post('name', 60);
        if ($name === '') err('组名不能为空');
        $allow_manage = isset($_POST['allow_manage']) ? 1 : 0;
        $allow_admin = isset($_POST['allow_admin']) ? 1 : 0;
        id() ? q("UPDATE app_groups SET name=?,allow_manage=?,allow_admin=? WHERE id=?", [$name, $allow_manage, $allow_admin, id()]) : q("INSERT INTO app_groups(name,allow_manage,allow_admin) VALUES(?,?,?)", [$name, $allow_manage, $allow_admin]);
        groups_cache(true);
    }

    public static function deletable_post_row(string $type, int $id): ?array
    {
        if ($type === 'topics') return row('app_topics', 'id', $id);
        if ($type === 'replies') return row('app_replies', 'id', $id);
        return null;
    }

    public static function can_delete(string $type, int $id): bool
    {
        if ($type === 'users') return can_manage() && $id !== uid() && ($id !== 1 || is_super_user());
        if (in_array($type, ['groups', 'forums'], true)) return can_manage() && is_super_user();
        $row = self::deletable_post_row($type, $id);
        if ($type === 'topics') return $row && can_manage_topic($row);
        if ($type === 'replies') return $row && can_manage_reply($row);
        return false;
    }

    public static function layout(string $tab, string $body): string
    {
        return shell_html(admin_tabs($tab) . $body, sidebar_stack_html([sidebar_user_card_html()], ['is_admin' => true, 'admin_tab' => $tab]));
    }

    public static function settings_handle_post(): never
    {
        if ((string)($_POST['debug_log_action'] ?? '') === 'clear') {
            if (!is_dir(dirname(DEBUG_LOG_FILE))) mkdir(dirname(DEBUG_LOG_FILE), 0755, true);
            file_put_contents(DEBUG_LOG_FILE, '', LOCK_EX);
            set_flash('Debug日志已清空');
            go(admin_url(['tab' => 'settings']));
        }
        if (isset($_POST['clear_opcache'])) {
            self::clear_opcache_cache();
            set_flash('OPcache已清理');
            go(admin_url(['tab' => 'settings']));
        }
        self::save_settings();
        go(admin_url(['tab' => 'settings']));
    }

    public static function settings_html(): string
    {
        $notice_state = Setup::update_state_data();
        $pending_notice = is_array($notice_state['update_notice'] ?? null) ? $notice_state['update_notice'] : [];
        $notice_sha = (string)($pending_notice['sha'] ?? ($notice_state['update_notice_sent_sha'] ?? ''));
        Setup::deliver_update_notice();
        $settings = settings_cache();
        $fields = [
            'site_name' => ['label' => '网站名', 'required' => true],
            'site_name_title' => ['label' => '网站名title', 'help' => '为空时使用网站名。'],
            'site_base_url' => ['label' => '网站固定地址', 'type' => 'url', 'help' => '填写以 https:// 开头的网站域名。'],
            'site_keywords' => ['label' => '关键字'], 'site_description' => ['label' => '网站介绍', 'type' => 'textarea'],
            'pinned_topic_ids' => ['label' => '置顶主题ID'], 'pc_nav_forum_count' => ['label' => 'PC顶部版块数量', 'type' => 'number', 'min' => 0, 'max' => 20, 'help' => 'PC端顶部默认展示的版块数量，默认6个；设为0仅显示“全部版块”。'],
            'topics_per_page' => ['label' => '列表单页数量', 'type' => 'number', 'min' => 1, 'max' => 200], 'replies_per_page' => ['label' => '回帖单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'max_pagination_pages' => ['label' => '最大分页数', 'type' => 'number', 'min' => 1, 'max' => 1000, 'help' => '限制除主题回帖外的所有分页，默认50。'], 'search_min_chars' => ['label' => '搜索最小字符数', 'type' => 'number', 'min' => 1, 'max' => 20, 'help' => '默认2；未启用全文搜索插件时使用基础 LIKE 搜索。'],
            'pretty_url' => ['label' => '是否开启rewrite', 'type' => 'checkbox'], 'site_closed' => ['label' => '是否关闭站点进行维护', 'type' => 'checkbox'], 'debug_mode' => ['label' => 'Debug模式', 'type' => 'checkbox'],
            'ignore_ssl_errors' => ['label' => '忽略 SSL 证书错误', 'type' => 'checkbox', 'help' => '警示篡改风险'], 'allow_register' => ['label' => '是否允许注册', 'type' => 'checkbox'], 'default_group_id' => ['label' => '新用户默认用户组', 'type' => 'select', 'options' => array_column(groups_cache(), 'name', 'id')], 'post_interval_seconds' => ['label' => '发帖/回复间隔（秒）', 'type' => 'number', 'min' => 0, 'max' => 3600, 'help' => '发帖/回复间隔设置为 0 可关闭限制，默认 5 秒一次。'],
        ];
        $tools = '<div class="settings-tool-card"><div><strong>清理OPcache</strong><span>刷新已编译脚本缓存，适合代码更新后手动触发。</span></div>' . post_action_form(admin_url(['tab' => 'settings']), '清理', ['clear_opcache' => '1'], 'settings-tool-action') . '</div>';
        if ((string)($settings['debug_mode'] ?? '0') === '1') $tools .= '<div class="settings-tool-card"><div><strong>Debug日志</strong><span>' . h(DEBUG_LOG_FILE) . '</span></div><div class="settings-tool-actions">' . post_action_form(admin_url(['tab' => 'settings']), '清空', ['debug_log_action' => 'clear'], 'settings-tool-action', '确定清空Debug日志？') . '<a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'debug_log' => 'view'])) . '" target="_blank">查看</a></div></div>';
        $update_state = is_file(UPDATE_STATE_FILE) ? json_decode((string)file_get_contents(UPDATE_STATE_FILE), true) : [];
        $update_sha = is_array($update_state) ? (string)($update_state['sha'] ?? '') : '';
        $update_time = is_array($update_state) ? (string)($update_state['updated_at'] ?? '') : '';
        $update_meta = $update_sha !== '' ? '当前版本 ' . substr($update_sha, 0, 12) . ($update_time !== '' ? ' / ' . $update_time : '') : '尚无在线升级记录';
        $update_action = is_file(APP_DIR . '/optional/Setup.php') ? '<a class="settings-tool-action" href="' . h(route_url('update')) . '">升级</a>' : '<button class="settings-tool-action" type="button" disabled>升级</button>';
        $update_dot = preg_match('/^[a-f0-9]{64}$/', $notice_sha) === 1 ? '<i class="settings-update-dot" title="发现新版本" aria-label="发现新版本"></i>' : '';
        $tools .= '<div class="settings-tool-card"><div><strong class="settings-tool-title" data-update-tool-title>系统升级' . $update_dot . '</strong><span>' . h($update_meta) . '</span></div>' . $update_action . '</div>';
        return '<span hidden data-settings-update-check-url="' . h(route_url('update', ['notice_check' => 1])) . '"></span><div class="form-panel settings-form"><form method="post">' . form_token() . render_form_fields($fields, $settings) . '<div class="row settings-actions"><button type="submit">保存</button></div></form><div class="settings-tool-grid">' . $tools . '</div></div>';
    }

    public static function groups_html(): string
    {
        $html = '<table class="list admin-bulk-list"><tr><th>名称</th><th>用户和内容管理</th><th>后台管理</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => 0])) . '">添加</a></th></tr>';
        foreach (groups_cache() as $group) $html .= '<tr><td><strong class="admin-name">' . h($group['name']) . '</strong></td><td>' . self::flag((int)($group['allow_manage'] ?? 0)) . '</td><td>' . self::flag((int)($group['allow_admin'] ?? 0)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => (int)$group['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'groups', 'id' => (int)$group['id'], 'tab' => 'groups'], 'danger', '确定删除？') . '</td></tr>';
        return $html . '</table>';
    }

    public static function forums_html(): string
    {
        $html = '<table class="list admin-bulk-list"><tr><th>名称</th><th>排序</th><th>权限</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => 0])) . '">添加</a></th></tr>';
        foreach (forums_cache() as $forum) {
            $permissions = [];
            foreach (['allow_view_groups' => '浏览', 'allow_post_groups' => '发帖', 'allow_reply_groups' => '回帖'] as $field => $label) { $count = count(forum_group_ids($forum, $field)); $permissions[] = $label . ':' . ($count ? $count . '组' : '不限'); }
            $html .= '<tr><td><strong class="admin-name">' . h($forum['name']) . '</strong></td><td><span class="admin-group-pill">' . (int)$forum['sort'] . '</span></td><td>' . h(implode(' / ', $permissions)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => (int)$forum['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'forums', 'id' => (int)$forum['id'], 'tab' => 'forums'], 'danger', '确定删除？') . '</td></tr>';
        }
        return $html . '</table>';
    }

    public static function page(): void
    {
        need_admin();
        $tab = (string)($_GET['tab'] ?? 'settings');
        if ($tab === 'settings' && (string)($_GET['debug_log'] ?? '') === 'view') { header('Content-Type: text/plain; charset=utf-8'); echo is_file(DEBUG_LOG_FILE) ? (string)file_get_contents(DEBUG_LOG_FILE) : ''; exit; }
        if ($tab === 'plugins' && is_post_request()) Plugin::admin_plugins_handle_post();
        if ($tab === 'plugins' && (string)($_GET['plugin_action'] ?? '') === 'enable_uploaded') Plugin::plugin_enable_uploaded_page();
        if ($tab === 'settings' && is_post_request()) self::settings_handle_post();
        $html = match ($tab) { 'settings' => self::settings_html(), 'groups' => self::groups_html(), 'forums' => self::forums_html(), 'plugins' => admin_plugins_html(), default => Plugin::admin_plugin_tab_html($tab) };
        if ($html === null) err('你访问的页面不存在', 404);
        page('后台', self::layout($tab, $html));
    }

    public static function edit_page(): void
    {
        need_admin();
        $type = $_GET['type'] ?? $_POST['type'] ?? '';
        if (is_post_request()) { if ($type === 'group') self::save_group(); elseif ($type === 'forum') self::save_forum(); else err('参数错误'); go(admin_url(['tab' => $type . 's'])); }
        if ($type === 'group') { $g = id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0]; $tab = 'groups'; $body = input('名称', 'name', $g['name'], 'text', true) . checkbox('允许用户和内容管理', 'allow_manage', (bool)(int)($g['allow_manage'] ?? 0)) . checkbox('允许后台管理', 'allow_admin', (bool)(int)($g['allow_admin'] ?? 0)); }
        elseif ($type === 'forum') { $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0, 'allow_view_groups' => '', 'allow_post_groups' => '', 'allow_reply_groups' => '']; if (!$f) err('版块不存在'); $tab = 'forums'; $body = input('名称', 'name', $f['name'], 'text', true) . number_input('排序', 'sort', $f['sort']) . textarea('描述', 'description', $f['description']) . self::forum_group_select_options($f, 'allow_view_groups', '允许浏览用户组') . self::forum_group_select_options($f, 'allow_post_groups', '允许发帖用户组') . self::forum_group_select_options($f, 'allow_reply_groups', '允许回帖用户组'); }
        else err('参数错误');
        page('编辑', self::layout($tab, '<div class="form-panel"><h2>编辑</h2><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>保存</button></form></div>'));
    }

    public static function route(): void
    {
        $do = (string)($_GET['do'] ?? '');
        if ($do === 'edit') { self::edit_page(); return; }
        if ($do === '') { self::page(); return; }
        if ($do !== 'delete') err('你访问的页面不存在', 404);
        require_post(); need_admin();
        $type = ['group' => 'groups', 'groups' => 'groups', 'forum' => 'forums', 'forums' => 'forums'][$_POST['type'] ?? ''] ?? '';
        if (!in_array($type, ['groups', 'forums'], true)) err('参数错误');
        if (!self::can_delete($type, id())) err('无权限');
        del($type, id());
        go(admin_url(['tab' => $type]));
    }
}
