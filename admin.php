<?php

declare(strict_types=1);
require __DIR__ . '/function.php';

function admin_nav(string $tab): string
{
    $items = ['settings' => '站点设置', 'users' => '用户', 'groups' => '用户组', 'forums' => '版块', 'topics' => '主题', 'replies' => '回帖'];
    $h = '<aside class="sidebar"><div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">后台功能模块</div><ul class="quick-links">';
    foreach ($items as $k => $v) $h .= '<li><a class="' . ($tab === $k ? 'active' : '') . '" href="admin.php?tab=' . $k . '">' . $v . '</a></li>';
    return $h . '</ul></div></div></aside>';
}
function admin_layout(string $tab, string $body): string
{
    return '<div class="home-shell"><div class="forum-layout"><div class="forum-main"><div class="main-panel">' . $body . '</div></div>' . admin_nav($tab) . '</div></div>';
}
function admin_page(): void
{
    need_admin();
    $tab = $_GET['tab'] ?? 'settings';
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
        $html .= '<div class="box form-panel settings-form"><h2>站点设置</h2><form method="post">' . form_token() . input('网站名', 'site_name', $s['site_name']) . input('关键字', 'site_keywords', $s['site_keywords']) . textarea('网站介绍', 'site_description', $s['site_description']) . textarea('页头HTML代码', 'header_html', $s['header_html']) . textarea('页脚HTML代码', 'footer_html', $s['footer_html']) . '<label class="grid"><span>是否关闭</span><input type="checkbox" name="site_closed" value="1"' . ((int)$s['site_closed'] ? ' checked' : '') . '></label><label class="grid"><span>是否允许注册</span><input type="checkbox" name="allow_register" value="1"' . ((int)$s['allow_register'] ? ' checked' : '') . '></label>' . textarea('保留用户名', 'reserved_usernames', $s['reserved_usernames']) . $group_select . '<button>保存</button></form></div>';
    } elseif ($tab === 'users') {
        $html .= '<div class="row"><h2 class="grow">用户</h2><a class="btn" href="admin.php?a=edit&type=user">添加</a></div><table class="list"><tr><th>ID</th><th>用户名</th><th>组</th><th>邮箱</th><th>操作</th></tr>';
        foreach (q("SELECT * FROM users ORDER BY id DESC LIMIT 200") as $u) {
            $g = group_by_id((int)$u['group_id']) ?: ['name' => ''];
            $html .= '<tr><td>' . (int)$u['id'] . '</td><td>' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), 'table-avatar', (string)($u['avatar_seed'] ?? '')) . h($u['username']) . '</td><td>' . h($g['name']) . '</td><td>' . h($u['email']) . '</td><td class="ops"><a href="admin.php?a=edit&type=user&id=' . (int)$u['id'] . '">编辑</a> <a href="admin.php?a=delete&type=users&id=' . (int)$u['id'] . '&tab=users" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        }
        $html .= '</table>';
    } elseif ($tab === 'groups') {
        $html .= '<div class="row"><h2 class="grow">用户组</h2><a class="btn" href="admin.php?a=edit&type=group">添加</a></div><table class="list"><tr><th>ID</th><th>名称</th><th>管理员</th><th>禁访</th><th>禁言</th><th>操作</th></tr>';
        foreach (groups_cache() as $g) $html .= '<tr><td>' . (int)$g['id'] . '</td><td>' . h($g['name']) . '</td><td>' . ((int)$g['is_admin'] ? '是' : '否') . '</td><td>' . ((int)$g['is_banned'] ? '是' : '否') . '</td><td>' . ((int)$g['is_muted'] ? '是' : '否') . '</td><td class="ops"><a href="admin.php?a=edit&type=group&id=' . (int)$g['id'] . '">编辑</a> <a href="admin.php?a=delete&type=groups&id=' . (int)$g['id'] . '&tab=groups" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } elseif ($tab === 'forums') {
        $html .= '<div class="row"><h2 class="grow">版块</h2><a class="btn" href="admin.php?a=edit&type=forum">添加</a></div><table class="list"><tr><th>ID</th><th>名称</th><th>排序</th><th>操作</th></tr>';
        foreach (forums_cache() as $f) $html .= '<tr><td>' . (int)$f['id'] . '</td><td>' . h($f['name']) . '</td><td>' . (int)$f['sort'] . '</td><td class="ops"><a href="admin.php?a=edit&type=forum&id=' . (int)$f['id'] . '">编辑</a> <a href="admin.php?a=delete&type=forums&id=' . (int)$f['id'] . '&tab=forums" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } elseif ($tab === 'topics') {
        $html .= '<div class="row"><h2 class="grow">主题</h2><a class="btn" href="index.php?a=topic_edit">添加</a></div><table class="list"><tr><th>ID</th><th>标题</th><th>用户</th><th>操作</th></tr>';
        foreach (q("SELECT t.id,t.title,t.user_id,u.username,u.avatar_style,u.avatar_seed FROM topics t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 200") as $t) $html .= '<tr><td>' . (int)$t['id'] . '</td><td>' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), 'table-avatar', (string)($t['avatar_seed'] ?? '')) . h($t['title']) . '</td><td>' . h($t['username']) . '</td><td class="ops"><a href="index.php?a=topic&id=' . (int)$t['id'] . '">查看</a> <a href="index.php?a=topic_edit&id=' . (int)$t['id'] . '">编辑</a> <a href="admin.php?a=delete&type=topics&id=' . (int)$t['id'] . '&tab=topics" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } elseif ($tab === 'replies') {
        $html .= '<h2>回帖</h2><table class="list"><tr><th>ID</th><th>内容</th><th>用户</th><th>操作</th></tr>';
        foreach (q("SELECT r.id,r.body,r.topic_id,r.user_id,u.username,u.avatar_style,u.avatar_seed FROM replies r JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 200") as $r) $html .= '<tr><td>' . (int)$r['id'] . '</td><td>' . avatar_tag((int)$r['user_id'], (string)$r['username'], (string)($r['avatar_style'] ?? ''), 'table-avatar', (string)($r['avatar_seed'] ?? '')) . h(cut($r['body'], 80)) . '</td><td>' . h($r['username']) . '</td><td class="ops"><a href="index.php?a=topic&id=' . (int)$r['topic_id'] . '">查看</a> <a href="index.php?a=reply_edit&id=' . (int)$r['id'] . '">编辑</a> <a href="admin.php?a=delete&type=replies&id=' . (int)$r['id'] . '&tab=replies" onclick="return confirm(\'确定删除？\')">删除</a></td></tr>';
        $html .= '</table>';
    } else err('参数错误');
    page('后台', admin_layout($tab, $html));
}
function admin_edit_page(): void
{
    need_admin();
    $type = $_GET['type'] ?? $_POST['type'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($type === 'user') save_user(true);
        elseif ($type === 'group') save_group();
        elseif ($type === 'forum') save_forum();
        else err('参数错误');
        go('admin.php?tab=' . ($type === 'user' ? 'users' : $type . 's'));
    }
    if ($type === 'user') {
        $u = id() ? one("SELECT * FROM users WHERE id=?", [id()]) : ['id' => 0, 'username' => '', 'email' => '', 'bio' => '', 'avatar_style' => '', 'avatar_seed' => '', 'group_id' => (int)setting('default_group_id', '2')];
        if (!$u) err('用户不存在');
        $tab = 'users';
        $body = input('用户名', 'username', $u['username']) . input('邮箱', 'email', $u['email'], 'email') . input(id() ? '新密码' : '密码', 'password', '', 'password') . input('确认密码', 'password2', '', 'password') . avatar_picker_html($u) . select_group((int)$u['group_id']) . textarea('简介', 'bio', $u['bio']);
    } elseif ($type === 'group') {
        $g = id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'is_admin' => 0, 'is_banned' => 0, 'is_muted' => 0];
        $tab = 'groups';
        $body = input('名称', 'name', $g['name']) . '<label class="grid"><span>管理员</span><input type="checkbox" name="is_admin" value="1"' . ((int)$g['is_admin'] ? ' checked' : '') . '></label><label class="grid"><span>禁止访问</span><input type="checkbox" name="is_banned" value="1"' . ((int)$g['is_banned'] ? ' checked' : '') . '></label><label class="grid"><span>禁止发言</span><input type="checkbox" name="is_muted" value="1"' . ((int)$g['is_muted'] ? ' checked' : '') . '></label>';
    } elseif ($type === 'forum') {
        $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0];
        if (!$f) err('版块不存在');
        $tab = 'forums';
        $body = input('名称', 'name', $f['name']) . input('排序', 'sort', $f['sort'], 'number') . textarea('描述', 'description', $f['description']);
    } else err('参数错误');
    page('编辑', admin_layout($tab, '<div class="box form-panel"><h2>编辑</h2><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>保存</button></form></div>'));
}

check();
try {
    $a = $_GET['a'] ?? '';
    if ($a === 'edit') admin_edit_page();
    elseif ($a === 'delete') {
        need_admin();
        $type = $_GET['type'] ?? '';
        if (!in_array($type, ['users', 'groups', 'forums', 'topics', 'replies'], true)) err('参数错误');
        del($type, id());
        $tab = in_array($_GET['tab'] ?? '', ['settings', 'users', 'groups', 'forums', 'topics', 'replies'], true) ? $_GET['tab'] : 'settings';
        go('admin.php?tab=' . $tab);
    } else admin_page();
} catch (Throwable $e) {
    err('操作失败');
}
