<?php

declare(strict_types=1);
require __DIR__ . '/function.php';

function admin_nav(string $tab): string
{
    return '<aside class="sidebar">' . user_card_html() . '</aside>';
}
function admin_tabs(string $tab): string
{
    $items = ['settings' => '设置', 'users' => '用户', 'groups' => '用户组', 'forums' => '版块', 'topics' => '主题', 'replies' => '回帖'];
    $h = '<div class="tab-bar admin-tabs">';
    foreach ($items as $k => $v) $h .= '<a class="tab' . ($tab === $k ? ' active' : '') . '" href="admin.php?tab=' . $k . '">' . $v . '</a>';
    return $h . '</div>';
}
function admin_layout(string $tab, string $body): string
{
    return shell_html(admin_tabs($tab) . $body, admin_nav($tab));
}
function admin_page(): void
{
    need_admin();
    $tab = $_GET['tab'] ?? 'settings';
    $q = trim((string)($_GET['q'] ?? ''));
    $manageable = can_manage();
    if ($tab === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        save_settings();
        go('admin.php?tab=settings');
    }
    $html = '';
    if ($tab === 'settings') {
        $s = settings_cache();
        $group_select = '<label class="grid"><span>新用户默认用户组</span><select name="default_group_id">';
        foreach (groups_cache() as $g) $group_select .= '<option value="' . (int)$g['id'] . '"' . ((int)$g['id'] === (int)$s['default_group_id'] ? ' selected' : '') . '>' . h($g['name']) . '</option>';
        $group_select .= '</select></label>';
        $html .= '<div class="form-panel settings-form"><h2>站点设置</h2><form method="post">' . form_token() . input('网站名', 'site_name', $s['site_name'], 'text', true) . input('关键字', 'site_keywords', $s['site_keywords']) . textarea('网站介绍', 'site_description', $s['site_description']) . input('系统发件邮箱', 'mail_from', $s['mail_from'], 'email') . textarea('页头HTML代码', 'header_html', $s['header_html']) . textarea('页脚HTML代码', 'footer_html', $s['footer_html']) . input('列表单页数量', 'topics_per_page', $s['topics_per_page'], 'number', true) . input('回帖单页数量', 'replies_per_page', $s['replies_per_page'], 'number', true) . '<label class="grid"><span>是否关闭</span><input type="checkbox" name="site_closed" value="1"' . ((int)$s['site_closed'] ? ' checked' : '') . '></label><label class="grid"><span>是否允许注册</span><input type="checkbox" name="allow_register" value="1"' . ((int)$s['allow_register'] ? ' checked' : '') . '></label>' . textarea('保留用户名', 'reserved_usernames', $s['reserved_usernames']) . $group_select . '<button>保存</button></form></div>';
    } elseif ($tab === 'users') {
        $html .= '<div class="row"><h2 class="grow">用户</h2>' . ($manageable ? '<a class="btn" href="admin.php?a=edit&type=user">添加</a>' : '') . '</div>' . admin_search_form('users', $q);
        if ($manageable) $html .= admin_bulk_delete_form_open('users', $q);
        $html .= '<table class="list"><tr>' . ($manageable ? '<th class="check-col"></th>' : '') . '<th>ID</th><th>用户名</th><th>组</th><th>邮箱</th>' . ($manageable ? '<th>操作</th>' : '') . '</tr>';
        foreach (admin_users_list($q) as $u) $html .= admin_user_row($u, $manageable);
        $html .= '</table>';
        if ($manageable) $html .= admin_bulk_delete_bar() . '</form>';
    } elseif ($tab === 'groups') {
        $html .= '<div class="row"><h2 class="grow">用户组</h2><a class="btn" href="admin.php?a=edit&type=group">添加</a></div><table class="list"><tr><th>ID</th><th>名称</th><th>用户和内容管理</th><th>后台管理</th><th>禁访</th><th>禁言</th><th>操作</th></tr>';
        foreach (groups_cache() as $g) $html .= '<tr><td>' . (int)$g['id'] . '</td><td>' . h($g['name']) . '</td><td>' . ((int)($g['allow_manage'] ?? 0) ? '是' : '否') . '</td><td>' . ((int)($g['allow_admin'] ?? 0) ? '是' : '否') . '</td><td>' . ((int)$g['is_banned'] ? '是' : '否') . '</td><td>' . ((int)$g['is_muted'] ? '是' : '否') . '</td><td class="ops"><a href="admin.php?a=edit&type=group&id=' . (int)$g['id'] . '">编辑</a> <a href="admin.php?a=delete&type=groups&id=' . (int)$g['id'] . '&tab=groups" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } elseif ($tab === 'forums') {
        $html .= '<div class="row"><h2 class="grow">版块</h2><a class="btn" href="admin.php?a=edit&type=forum">添加</a></div><table class="list"><tr><th>ID</th><th>名称</th><th>排序</th><th>操作</th></tr>';
        foreach (forums_cache() as $f) $html .= '<tr><td>' . (int)$f['id'] . '</td><td>' . h($f['name']) . '</td><td>' . (int)$f['sort'] . '</td><td class="ops"><a href="admin.php?a=edit&type=forum&id=' . (int)$f['id'] . '">编辑</a> <a href="admin.php?a=delete&type=forums&id=' . (int)$f['id'] . '&tab=forums" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } elseif ($tab === 'topics') {
        $html .= '<div class="row"><h2 class="grow">主题</h2>' . ($manageable ? '<a class="btn" href="index.php?a=topic_edit">添加</a>' : '') . '</div>' . admin_search_form('topics', $q);
        if ($manageable) $html .= admin_bulk_delete_form_open('topics', $q);
        $html .= '<table class="list"><tr>' . ($manageable ? '<th class="check-col"></th>' : '') . '<th>ID</th><th>标题</th><th>用户</th><th>操作</th></tr>';
        foreach (admin_topics_list($q) as $t) $html .= admin_topic_row($t, $manageable);
        $html .= '</table>';
        if ($manageable) $html .= admin_bulk_delete_bar() . '</form>';
    } elseif ($tab === 'replies') {
        $html .= '<h2>回帖</h2>' . admin_search_form('replies', $q);
        if ($manageable) $html .= admin_bulk_delete_form_open('replies', $q);
        $html .= '<table class="list"><tr>' . ($manageable ? '<th class="check-col"></th>' : '') . '<th>ID</th><th>内容</th><th>用户</th><th>操作</th></tr>';
        foreach (admin_replies_list($q) as $r) $html .= admin_reply_row($r, $manageable);
        $html .= '</table>';
        if ($manageable) $html .= admin_bulk_delete_bar() . '</form>';
    } else err('参数错误');
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
        go('admin.php?tab=' . ($type === 'user' ? 'users' : $type . 's'));
    }
    if ($type === 'user') {
        $u = admin_user_form_data(id());
        $tab = 'users';
        $is_new = id() === 0;
        $body = input('用户名', 'username', $u['username'], 'text', true) . input('邮箱', 'email', $u['email'], 'email') . input($is_new ? '密码' : '新密码', 'password', '', 'password', $is_new) . input('确认密码', 'password2', '', 'password', $is_new) . avatar_picker_html($u) . select_group((int)$u['group_id']) . textarea('简介', 'bio', $u['bio']);
    } elseif ($type === 'group') {
        $g = id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0, 'is_banned' => 0, 'is_muted' => 0];
        $tab = 'groups';
        $body = input('名称', 'name', $g['name'], 'text', true) . '<label class="grid"><span>允许用户和内容管理</span><input type="checkbox" name="allow_manage" value="1"' . ((int)($g['allow_manage'] ?? 0) ? ' checked' : '') . '></label><label class="grid"><span>允许后台管理</span><input type="checkbox" name="allow_admin" value="1"' . ((int)($g['allow_admin'] ?? 0) ? ' checked' : '') . '></label><label class="grid"><span>禁止访问</span><input type="checkbox" name="is_banned" value="1"' . ((int)$g['is_banned'] ? ' checked' : '') . '></label><label class="grid"><span>禁止发言</span><input type="checkbox" name="is_muted" value="1"' . ((int)$g['is_muted'] ? ' checked' : '') . '></label>';
    } elseif ($type === 'forum') {
        $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0];
        if (!$f) err('版块不存在');
        $tab = 'forums';
        $body = input('名称', 'name', $f['name'], 'text', true) . input('排序', 'sort', $f['sort'], 'number', true) . textarea('描述', 'description', $f['description']);
    } else err('参数错误');
    page('编辑', admin_layout($tab, '<div class="form-panel"><h2>编辑</h2><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>保存</button></form></div>'));
}

check();
try {
    $a = $_GET['a'] ?? '';
    if ($a === 'edit') admin_edit_page();
    elseif ($a === 'delete') {
        need_admin();
        $type = $_GET['type'] ?? '';
        if (!in_array($type, ['users', 'groups', 'forums', 'topics', 'replies'], true)) err('参数错误');
        if (in_array($type, ['users', 'topics', 'replies'], true)) need_manage();
        del($type, id());
        $tab = in_array($_GET['tab'] ?? '', ['settings', 'users', 'groups', 'forums', 'topics', 'replies'], true) ? $_GET['tab'] : 'settings';
        go('admin.php?tab=' . $tab);
    } elseif ($a === 'batch_delete') {
        need_admin();
        need_manage();
        $tab = (string)($_POST['tab'] ?? '');
        if (!in_array($tab, ['users', 'topics', 'replies'], true)) err('参数错误');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0)));
        foreach ($ids as $id) del($tab, $id);
        $query = trim((string)($_POST['q'] ?? ''));
        go('admin.php?tab=' . $tab . ($query !== '' ? '&q=' . urlencode($query) : ''));
    } else admin_page();
} catch (Throwable $e) {
    err('操作失败');
}
