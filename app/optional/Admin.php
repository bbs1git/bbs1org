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
        try {
            return (bool)opcache_reset();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function opcache_refresh_route(): void
    {
        $lock_file = DATA_DIR . '/opcache-refresh.lock';
        if (!is_file($lock_file) || !self::clear_opcache_cache()) {
            http_response_code(403);
            exit('failed');
        }
        @unlink($lock_file);
        exit('ok');
    }

    public static function save_settings(): void
    {
        $site_name = post('site_name', DB_STRING_MAX_LENGTH);
        if ($site_name === '') err('网站名不能为空');
        $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
        if (!group_by_id($gid)) err('默认用户组不存在');
        $values = ['site_name' => $site_name, 'site_base_url' => clean_site_base_url((string)($_POST['site_base_url'] ?? '')), 'pinned_topic_ids' => preg_replace('/[^\d,]/', '', (string)($_POST['pinned_topic_ids'] ?? '')) ?: '', 'default_group_id' => (string)$gid];
        foreach (['site_name_title', 'site_keywords'] as $key) $values[$key] = post($key, DB_STRING_MAX_LENGTH);
        $values['site_description'] = post('site_description', DB_TEXT_MAX_LENGTH);
        foreach (['site_closed', 'debug_mode', 'ignore_ssl_errors', 'pretty_url', 'allow_register'] as $key) $values[$key] = isset($_POST[$key]) ? '1' : '0';
        foreach (['pc_nav_forum_count' => [0, 20, 6], 'topics_per_page' => [1, 200, 30], 'replies_per_page' => [1, 200, 50], 'max_pagination_pages' => [1, 1000, 50], 'post_interval_seconds' => [0, 3600, 5]] as $key => [$min, $max, $default]) $values[$key] = (string)min($max, max($min, (int)($_POST[$key] ?? $default)));
        $length_fields = [
            'username' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'email' => [0, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'bio' => [0, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
            'search' => [2, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'title' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'topic_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
            'reply_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
        ];
        foreach ($length_fields as $key => [$default_min, $default_max, $hard_max]) {
            $min = min($hard_max, max(0, (int)($_POST[$key . '_min_length'] ?? $default_min)));
            $max = min($hard_max, max($min, (int)($_POST[$key . '_max_length'] ?? $default_max)));
            $values[$key . '_min_length'] = (string)$min;
            $values[$key . '_max_length'] = (string)$max;
        }
        $values['excerpt_length'] = (string)min(DB_TEXT_MAX_LENGTH, max(0, (int)($_POST['excerpt_length'] ?? 200)));
        $values['ai_api_url'] = cut(trim((string)($_POST['ai_api_url'] ?? '')), DB_STRING_MAX_LENGTH);
        $values['ai_api_key'] = cut(trim((string)($_POST['ai_api_key'] ?? '')), DB_STRING_MAX_LENGTH);
        $values['ai_model'] = cut(trim((string)($_POST['ai_model'] ?? '')), DB_STRING_MAX_LENGTH);
        foreach (['ai_temperature' => [0, 2, '0.7'], 'ai_max_tokens' => [1, 200000, '16384'], 'ai_timeout_seconds' => [1, 300, '30']] as $key => [$min, $max, $default]) {
            $raw = trim((string)($_POST[$key] ?? ''));
            $values[$key] = $raw === '' ? $default : (string)(min($max, max($min, (float)$raw)));
        }
        save_settings_values($values);
    }

    public static function save_forum(): void
    {
        $name = post('name', DB_STRING_MAX_LENGTH);
        if ($name === '') err('版块名不能为空');
        $description = post('description', DB_TEXT_MAX_LENGTH);
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
        $name = post('name', DB_STRING_MAX_LENGTH);
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
        if ((string)($_POST['ai_log_action'] ?? '') === 'clear') {
            q("DELETE FROM app_ai_logs");
            set_flash('AI 调用日志已清空');
            go(admin_url(['tab' => 'settings'] + ((string)($_POST['ai_log_return'] ?? '') === 'view' ? ['ai_log' => 'view'] : [])));
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
            'site_keywords' => ['label' => '关键字'],
            'site_description' => ['label' => '网站介绍', 'type' => 'textarea'],
            'pinned_topic_ids' => ['label' => '置顶主题ID'],
            'pc_nav_forum_count' => ['label' => 'PC顶部版块数量', 'type' => 'number', 'min' => 0, 'max' => 20, 'help' => 'PC端顶部默认展示的版块数量，默认6个；设为0仅显示“全部版块”。'],
            'topics_per_page' => ['label' => '列表单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'replies_per_page' => ['label' => '回帖单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'max_pagination_pages' => ['label' => '最大分页数', 'type' => 'number', 'min' => 1, 'max' => 1000, 'help' => '限制除主题回帖外的所有分页，默认50。'],
            'username_min_length' => ['label' => '用户名最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'username_max_length' => ['label' => '用户名最大长度', 'type' => 'number', 'min' => 1, 'max' => DB_STRING_MAX_LENGTH],
            'email_min_length' => ['label' => '邮箱最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'email_max_length' => ['label' => '邮箱最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'bio_min_length' => ['label' => '个人简介最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'bio_max_length' => ['label' => '个人简介最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'search_min_length' => ['label' => '搜索关键词最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'search_max_length' => ['label' => '搜索关键词最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'title_min_length' => ['label' => '标题最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'title_max_length' => ['label' => '标题最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'topic_body_min_length' => ['label' => '主题内容最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'topic_body_max_length' => ['label' => '主题内容最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'reply_body_min_length' => ['label' => '回帖内容最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'reply_body_max_length' => ['label' => '回帖内容最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'excerpt_length' => ['label' => '摘要长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'pretty_url' => ['label' => '是否开启rewrite', 'type' => 'checkbox'],
            'site_closed' => ['label' => '是否关闭站点进行维护', 'type' => 'checkbox'],
            'debug_mode' => ['label' => 'Debug模式', 'type' => 'checkbox'],
            'ignore_ssl_errors' => ['label' => '忽略 SSL 证书错误', 'type' => 'checkbox', 'help' => '警示篡改风险'],
            'allow_register' => ['label' => '是否允许注册', 'type' => 'checkbox'],
            'default_group_id' => ['label' => '新用户默认用户组', 'type' => 'select', 'options' => array_column(groups_cache(), 'name', 'id')],
            'post_interval_seconds' => ['label' => '发帖/回复间隔（秒）', 'type' => 'number', 'min' => 0, 'max' => 3600, 'help' => '发帖/回复间隔设置为 0 可关闭限制，默认 5 秒一次。'],
            'ai_api_url' => ['label' => 'AI 接口地址', 'help' => 'OpenAI 兼容接口基础地址，如 https://api.deepseek.com/v1。配置后内核统一向插件提供 AI 能力（ai_chat）。'],
            'ai_api_key' => ['label' => 'AI API Key', 'type' => 'password', 'help' => '地址、Key、模型三项都填写后内核 AI 服务可用。'],
            'ai_model' => ['label' => 'AI 模型', 'help' => '如 gpt-4o-mini、deepseek-chat、qwen-plus。'],
            'ai_temperature' => ['label' => 'AI 温度', 'type' => 'number', 'min' => 0, 'max' => 2, 'step' => 'any', 'required' => false, 'help' => '默认 0.7。控制回答的随机性：越低越稳定精准，越高越发散有创意。'],
            'ai_max_tokens' => ['label' => 'AI 单次最大输出', 'type' => 'number', 'min' => 1, 'max' => 200000, 'required' => false, 'help' => '默认 16384，只限制上限不增加费用；超过所用模型上限时部分接口会报错，可按模型调整。'],
            'ai_timeout_seconds' => ['label' => 'AI 请求超时（秒）', 'type' => 'number', 'min' => 1, 'max' => 300, 'required' => false, 'help' => '默认 30 秒。'],
        ];
        $tools = '<div class="settings-tool-card"><div><strong>清理OPcache</strong><span>刷新已编译脚本缓存，适合代码更新后手动触发。</span></div>' . post_action_form(admin_url(['tab' => 'settings']), '清理', ['clear_opcache' => '1'], 'settings-tool-action') . '</div>';
        if ((string)($settings['debug_mode'] ?? '0') === '1') $tools .= '<div class="settings-tool-card"><div><strong>Debug日志</strong><span>' . h(DEBUG_LOG_FILE) . '</span></div><div class="settings-tool-actions">' . post_action_form(admin_url(['tab' => 'settings']), '清空', ['debug_log_action' => 'clear'], 'settings-tool-action', '确定清空Debug日志？') . '<a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'debug_log' => 'view'])) . '" target="_blank">查看</a></div></div>';
        $update_state = is_file(UPDATE_STATE_FILE) ? json_decode((string)file_get_contents(UPDATE_STATE_FILE), true) : [];
        $update_sha = is_array($update_state) ? (string)($update_state['sha'] ?? '') : '';
        $update_time = is_array($update_state) ? (string)($update_state['updated_at'] ?? '') : '';
        $update_meta = $update_sha !== '' ? '当前版本 ' . $update_sha . ($update_time !== '' ? ' / ' . $update_time : '') : '尚无在线升级记录';
        $update_action = is_file(APP_DIR . '/optional/Setup.php') ? '<a class="settings-tool-action" href="' . h(route_url('update')) . '">升级</a>' : '<button class="settings-tool-action" type="button" disabled>升级</button>';
        $update_dot = preg_match('/^[a-f0-9]{64}$/', $notice_sha) === 1 ? '<i class="settings-update-dot" title="发现新版本" aria-label="发现新版本"></i>' : '';
        $tools .= '<div class="settings-tool-card"><div><strong class="settings-tool-title" data-update-tool-title>系统升级' . $update_dot . '</strong><span>' . h($update_meta) . '</span></div>' . $update_action . '</div>';
        $tools .= self::ai_log_card();
        $tools .= self::ai_debug_card();
        return '<span hidden data-settings-update-check-url="' . h(route_url('update', ['notice_check' => 1])) . '"></span><div class="form-panel settings-form"><form method="post">' . form_token() . render_form_fields($fields, $settings) . '<div class="row settings-actions"><button type="submit">保存</button></div></form><div class="settings-tool-grid">' . $tools . '</div></div>';
    }

    public static function ai_log_card(): string
    {
        $row = one("SELECT COUNT(*) AS total, SUM(CASE WHEN status<>'ok' THEN 1 ELSE 0 END) AS fails FROM app_ai_logs");
        $total = (int)($row['total'] ?? 0);
        $fails = (int)($row['fails'] ?? 0);
        $desc = $total > 0 ? '累计 ' . $total . ' 次调用，失败 ' . $fails . ' 次。' : '内核 AI 服务暂无调用记录。';
        $actions = post_action_form(admin_url(['tab' => 'settings']), '清空', ['ai_log_action' => 'clear'], 'settings-tool-action', '确定清空 AI 调用日志？');
        if ($total > 0) $actions .= '<a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'ai_log' => 'view'])) . '">查看</a>';
        return '<div class="settings-tool-card"><div><strong>AI 调用日志</strong><span>' . h($desc) . '</span></div><div class="settings-tool-actions">' . $actions . '</div></div>';
    }

    public static function ai_log_page(): void
    {
        need_admin();
        $plugin = cut(trim((string)($_GET['ai_plugin'] ?? '')), 255);
        $status = trim((string)($_GET['ai_status'] ?? ''));
        if (!in_array($status, ['ok', 'error'], true)) $status = '';
        $where = [];
        $args = [];
        if ($plugin === '__core__') {
            $where[] = "plugin_id=''";
        } elseif ($plugin !== '') {
            $where[] = 'plugin_id=?';
            $args[] = $plugin;
        }
        if ($status === 'ok') {
            $where[] = "status='ok'";
        } elseif ($status === 'error') {
            $where[] = "status<>'ok'";
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)val("SELECT COUNT(*) FROM app_ai_logs" . $where_sql, $args);
        $per_page = 30;
        $pages = max(1, (int)ceil($total / $per_page));
        $page = min($pages, max(1, (int)($_GET['p'] ?? 1)));
        $rows = q("SELECT * FROM app_ai_logs" . $where_sql . " ORDER BY id DESC LIMIT " . $per_page . " OFFSET " . (($page - 1) * $per_page), $args)->fetchAll();
        $user_ids = array_values(array_filter(array_unique(array_map(static fn(array $r): int => (int)$r['user_id'], $rows))));
        $names = [];
        if ($user_ids) {
            foreach (q("SELECT id,username FROM app_users WHERE id IN (" . sql_marks(count($user_ids)) . ")", $user_ids)->fetchAll() as $u) $names[(int)$u['id']] = (string)$u['username'];
        }
        $plugins = q("SELECT DISTINCT plugin_id FROM app_ai_logs ORDER BY plugin_id")->fetchAll(\PDO::FETCH_COLUMN);
        $hidden = hidden_inputs(['a' => 'admin', 'tab' => 'settings', 'ai_log' => 'view']);
        $plugin_sel = '<select name="ai_plugin"><option value="">全部插件</option>';
        foreach ($plugins as $pid) {
            $pid = (string)$pid;
            $value = $pid === '' ? '__core__' : $pid;
            $plugin_sel .= '<option value="' . h($value) . '"' . ($plugin === $value ? ' selected' : '') . '>' . h($pid !== '' ? $pid : '内核') . '</option>';
        }
        $plugin_sel .= '</select>';
        $status_sel = '<select name="ai_status"><option value="">全部状态</option><option value="ok"' . ($status === 'ok' ? ' selected' : '') . '>成功</option><option value="error"' . ($status === 'error' ? ' selected' : '') . '>失败</option></select>';
        $filters = '<form class="ai-log-filters" method="get" action="' . h(index_url()) . '">' . $hidden . $plugin_sel . $status_sel . '<button type="submit" class="ai-log-filter-apply">确定</button>' . ($plugin !== '' || $status !== '' ? '<a class="ai-log-filter-clear" href="' . h(admin_url(['tab' => 'settings', 'ai_log' => 'view'])) . '">重置</a>' : '') . '</form>';
        $clear = post_action_form(admin_url(['tab' => 'settings']), '清空日志', ['ai_log_action' => 'clear', 'ai_log_return' => 'view'], 'settings-tool-action', '确定清空全部 AI 调用日志？');
        $summary = '共 ' . $total . ' 条记录' . ($plugin !== '' ? ' · 插件 ' . h($plugin === '__core__' ? '内核' : $plugin) : '') . ($status === 'error' ? ' · 仅失败' : ($status === 'ok' ? ' · 仅成功' : ''));
        $body_rows = '';
        foreach ($rows as $r) {
            $ok = (string)$r['status'] === 'ok';
            $detail = $ok ? trim((string)$r['response_excerpt']) : trim((string)$r['error']);
            $tokens = ((int)$r['prompt_tokens'] > 0 || (int)$r['completion_tokens'] > 0) ? (int)$r['prompt_tokens'] . ' + ' . (int)$r['completion_tokens'] : '-';
            $user = $names[(int)$r['user_id']] ?? ((int)$r['user_id'] > 0 ? 'UID ' . (int)$r['user_id'] : '系统');
            $collapsible = mb_strlen($detail, 'UTF-8') > 140 || substr_count($detail, "\n") > 2;
            $detail_html = $collapsible
                ? '<details class="ai-log-detail"><summary><span class="ai-log-detail-preview">' . h($detail) . '</span><span class="ai-log-detail-toggle"></span></summary></details>'
                : '<div class="ai-log-detail">' . h($detail) . '</div>';
            $body_rows .= '<div class="ai-log-row' . ($ok ? ' is-ok' : ' is-error') . '">'
                . '<div class="ai-log-meta">'
                . '<span class="ai-log-time" data-label="时间">' . h(date('m-d H:i', (int)$r['created_at'])) . '</span>'
                . '<span class="ai-log-plugin" data-label="插件">' . h((string)$r['plugin_id'] !== '' ? (string)$r['plugin_id'] : '内核') . '</span>'
                . '<span class="ai-log-user" data-label="用户">' . h($user) . '</span>'
                . '<span class="ai-log-model" data-label="模型">' . h((string)$r['model']) . '</span>'
                . '<span class="ai-log-tokens" data-label="Token">' . h($tokens) . '</span>'
                . '<span class="ai-log-duration" data-label="耗时">' . (int)$r['duration_ms'] . 'ms</span>'
                . '<span class="ai-log-status" data-label="状态">' . ($ok ? '成功' : '失败') . '</span>'
                . '</div>'
                . $detail_html
                . '</div>';
        }
        $list = '<div class="ai-log-list">'
            . ($body_rows !== '' ? $body_rows : '<div class="ai-log-empty">没有匹配的日志记录。</div>')
            . '</div>';
        $pagination = paginate($total, $page, $per_page, admin_url(array_filter(['tab' => 'settings', 'ai_log' => 'view', 'ai_plugin' => $plugin !== '' ? $plugin : null, 'ai_status' => $status !== '' ? $status : null])));
        $html = '<div class="ai-log"><div class="ai-log-head"><div><strong>AI 调用日志</strong><span>' . $summary . '</span></div>' . $clear . '</div>' . $filters . $list . ($pagination !== '' ? '<div class="pagination-bar">' . $pagination . '</div>' : '') . '</div>';
        page('AI 调用日志', shell_html($html, sidebar_stack_html([sidebar_user_card_html()], ['is_admin' => true, 'admin_tab' => 'settings'])));
    }

    public static function ai_debug_card(): string
    {
        $desc = Ai::ready()
            ? '模型 ' . (string)setting('ai_model', '') . ' · 温度 ' . (float)setting('ai_temperature', '0.7') . ' · 最大输出 ' . (int)setting('ai_max_tokens', '16384') . '。发送一条测试消息，验证接口与参数。'
            : 'AI 服务未配置，填写接口地址、Key 和模型后即可测试。';
        return '<div class="settings-tool-card"><div><strong>AI 调用调试</strong><span>' . h($desc) . '</span></div><a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'ai_debug' => 'view'])) . '">调试</a></div>';
    }

    public static function ai_debug_page(): void
    {
        need_admin();
        $default_prompt = '你好，请用一句话介绍你自己。';
        $values = ['ai_debug_prompt' => $default_prompt, 'ai_debug_system' => '', 'ai_debug_temperature' => '', 'ai_debug_max_tokens' => '', 'ai_debug_json' => '0'];
        $result = null;
        if (is_post_request()) {
            require_post();
            $values['ai_debug_prompt'] = trim(post('ai_debug_prompt', DB_TEXT_MAX_LENGTH));
            if ($values['ai_debug_prompt'] === '') $values['ai_debug_prompt'] = $default_prompt;
            $values['ai_debug_system'] = trim(post('ai_debug_system', DB_TEXT_MAX_LENGTH));
            $values['ai_debug_temperature'] = trim((string)($_POST['ai_debug_temperature'] ?? ''));
            $values['ai_debug_max_tokens'] = trim((string)($_POST['ai_debug_max_tokens'] ?? ''));
            $values['ai_debug_json'] = isset($_POST['ai_debug_json']) ? '1' : '0';
            $options = [];
            if ($values['ai_debug_temperature'] !== '') $options['temperature'] = min(2, max(0, (float)$values['ai_debug_temperature']));
            if ($values['ai_debug_max_tokens'] !== '') $options['max_tokens'] = min(200000, max(1, (int)$values['ai_debug_max_tokens']));
            if ($values['ai_debug_json'] === '1') $options['json'] = true;
            try {
                $result = Ai::chat_text($values['ai_debug_system'], $values['ai_debug_prompt'], $options);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'content' => '', 'error' => $e->getMessage(), 'model' => (string)setting('ai_model', ''), 'duration_ms' => 0, 'log_id' => 0, 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]];
            }
        }
        $result_html = '';
        if (is_array($result)) {
            $ok = !empty($result['ok']);
            $usage = (array)($result['usage'] ?? []);
            $result_html = '<div class="ai-debug-result' . ($ok ? ' is-ok' : ' is-error') . '"><div class="ai-log-meta">'
                . '<span class="ai-log-status" data-label="结果">' . ($ok ? '成功' : '失败') . '</span>'
                . '<span class="ai-log-model" data-label="模型">' . h((string)($result['model'] ?? '')) . '</span>'
                . '<span class="ai-log-duration" data-label="耗时">' . (int)($result['duration_ms'] ?? 0) . 'ms</span>'
                . '<span class="ai-log-tokens" data-label="Token">' . (int)($usage['prompt_tokens'] ?? 0) . ' + ' . (int)($usage['completion_tokens'] ?? 0) . '</span>'
                . ((int)($result['log_id'] ?? 0) > 0 ? '<a class="ai-debug-log-link" href="' . h(admin_url(['tab' => 'settings', 'ai_log' => 'view'])) . '">已写入调用日志</a>' : '')
                . '</div>'
                . ($ok
                    ? '<pre class="ai-debug-output">' . h((string)$result['content']) . '</pre>'
                    : '<div class="ai-debug-error">' . h((string)($result['error'] ?? '未知错误')) . '</div>')
                . '</div>';
        }
        $settings = settings_cache();
        $fields = [
            'ai_debug_prompt' => ['label' => '提示词', 'type' => 'textarea', 'required' => true, 'help' => '作为用户消息发送给 AI。'],
            'ai_debug_system' => ['label' => '系统提示词', 'type' => 'textarea', 'required' => false, 'help' => '可选，留空则只发送用户消息。'],
            'ai_debug_temperature' => ['label' => '温度', 'type' => 'number', 'min' => 0, 'max' => 2, 'step' => 'any', 'required' => false, 'help' => '留空使用后台设置的值（' . (float)($settings['ai_temperature'] ?? '0.7') . '）。'],
            'ai_debug_max_tokens' => ['label' => '最大输出', 'type' => 'number', 'min' => 1, 'max' => 200000, 'required' => false, 'help' => '留空使用后台设置的值（' . (int)($settings['ai_max_tokens'] ?? '16384') . '）。'],
            'ai_debug_json' => ['label' => 'JSON 模式', 'type' => 'checkbox', 'help' => '要求接口返回 JSON 对象。'],
        ];
        $summary = Ai::ready()
            ? '当前模型 ' . (string)($settings['ai_model'] ?? '') . '，接口 ' . (string)($settings['ai_api_url'] ?? '') . '。每次测试都会真实调用一次并写入调用日志。'
            : 'AI 服务未配置，请先在后台设置中填写接口地址、Key 和模型。';
        $html = '<div class="ai-debug"><div class="ai-log-head"><div><strong>AI 调用调试</strong><span>' . h($summary) . '</span></div></div>'
            . ($result_html !== '' ? $result_html : '')
            . '<div class="form-panel settings-form"><form method="post">' . form_token() . render_form_fields($fields, $values) . '<div class="row settings-actions"><button type="submit">发送测试</button><a class="btn alt" href="' . h(admin_url(['tab' => 'settings'])) . '">返回设置</a></div></form></div>'
            . '</div>';
        page('AI 调用调试', shell_html($html, sidebar_stack_html([sidebar_user_card_html()], ['is_admin' => true, 'admin_tab' => 'settings'])));
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
            foreach (['allow_view_groups' => '浏览', 'allow_post_groups' => '发帖', 'allow_reply_groups' => '回帖'] as $field => $label) {
                $count = count(forum_group_ids($forum, $field));
                $permissions[] = $label . ':' . ($count ? $count . '组' : '不限');
            }
            $html .= '<tr><td><strong class="admin-name">' . h($forum['name']) . '</strong></td><td><span class="admin-group-pill">' . (int)$forum['sort'] . '</span></td><td>' . h(implode(' / ', $permissions)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => (int)$forum['id']])) . '">编辑</a>' . post_action_form(admin_url(['do' => 'delete']), '删除', ['type' => 'forums', 'id' => (int)$forum['id'], 'tab' => 'forums'], 'danger', '确定删除？') . '</td></tr>';
        }
        return $html . '</table>';
    }

    public static function page(): void
    {
        need_admin();
        $tab = (string)($_GET['tab'] ?? 'settings');
        if ($tab === 'settings' && (string)($_GET['debug_log'] ?? '') === 'view') {
            header('Content-Type: text/plain; charset=utf-8');
            echo is_file(DEBUG_LOG_FILE) ? (string)file_get_contents(DEBUG_LOG_FILE) : '';
            exit;
        }
        if ($tab === 'plugins' && is_post_request()) Plugin::admin_plugins_handle_post();
        if ($tab === 'plugins' && (string)($_GET['plugin_action'] ?? '') === 'enable_uploaded') Plugin::plugin_enable_uploaded_page();
        if ($tab === 'settings' && (string)($_GET['ai_log'] ?? '') === 'view') {
            self::ai_log_page();
            return;
        }
        if ($tab === 'settings' && (string)($_GET['ai_debug'] ?? '') === 'view') {
            self::ai_debug_page();
            return;
        }
        if ($tab === 'settings' && is_post_request()) self::settings_handle_post();
        $html = match ($tab) {
            'settings' => self::settings_html(),
            'groups' => self::groups_html(),
            'forums' => self::forums_html(),
            'plugins' => admin_plugins_html(),
            default => Plugin::admin_plugin_tab_html($tab)
        };
        if ($html === null) err('你访问的页面不存在', 404);
        page('后台', self::layout($tab, $html));
    }

    public static function edit_page(): void
    {
        need_admin();
        $type = $_GET['type'] ?? $_POST['type'] ?? '';
        if (is_post_request()) {
            if ($type === 'group') self::save_group();
            elseif ($type === 'forum') self::save_forum();
            else err('参数错误');
            go(admin_url(['tab' => $type . 's']));
        }
        if ($type === 'group') {
            $g = id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0];
            $tab = 'groups';
            $body = input('名称', 'name', $g['name'], 'text', true) . checkbox('允许用户和内容管理', 'allow_manage', (bool)(int)($g['allow_manage'] ?? 0)) . checkbox('允许后台管理', 'allow_admin', (bool)(int)($g['allow_admin'] ?? 0));
        } elseif ($type === 'forum') {
            $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0, 'allow_view_groups' => '', 'allow_post_groups' => '', 'allow_reply_groups' => ''];
            if (!$f) err('版块不存在');
            $tab = 'forums';
            $body = input('名称', 'name', $f['name'], 'text', true) . number_input('排序', 'sort', $f['sort']) . textarea('描述', 'description', $f['description']) . self::forum_group_select_options($f, 'allow_view_groups', '允许浏览用户组') . self::forum_group_select_options($f, 'allow_post_groups', '允许发帖用户组') . self::forum_group_select_options($f, 'allow_reply_groups', '允许回帖用户组');
        } else err('参数错误');
        $is_new = id() === 0;
        $label = $type === 'group' ? '用户组' : '版块';
        $title = ($is_new ? '添加' : '编辑') . $label;
        $summary = '<div class="admin-plugin-summary"><strong>' . h($title) . '</strong><span>' . h($is_new
            ? '填写后保存，返回列表即可看到新的' . $label . '。'
            : ($type === 'group' ? '修改名称与管理权限，保存后立即生效。' : '修改名称、排序与访问权限，保存后立即生效。')) . '</span></div>';
        page($title, self::layout($tab, '<div class="form-panel admin-form-panel"><div class="admin-form-head">' . $summary . '</div><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>保存</button></form></div>'));
    }

    public static function route(): void
    {
        $do = (string)($_GET['do'] ?? '');
        if ($do === 'edit') {
            self::edit_page();
            return;
        }
        if ($do === '') {
            self::page();
            return;
        }
        if ($do !== 'delete') err('你访问的页面不存在', 404);
        require_post();
        need_admin();
        $type = ['group' => 'groups', 'groups' => 'groups', 'forum' => 'forums', 'forums' => 'forums'][$_POST['type'] ?? ''] ?? '';
        if (!in_array($type, ['groups', 'forums'], true)) err('参数错误');
        if (!self::can_delete($type, id())) err('无权限');
        del($type, id());
        go(admin_url(['tab' => $type]));
    }
}
