<?php

declare(strict_types=1);
require __DIR__ . '/function.php';

if (!is_file(INSTALL_LOCK_FILE)) {
    err('请先执行安装操作');
}
need_site_access();
check();
$a = $_GET['a'] ?? 'home';
try {
    if ($a === 'login') login_page();
    elseif ($a === 'register') register_page();
    elseif ($a === 'forgot_password') forgot_password_page();
    elseif ($a === 'reset_password') reset_password_page();
    elseif ($a === 'logout') {
        session_destroy();
        go('index.php');
    } elseif ($a === 'profile') profile_page();
    elseif ($a === 'user') user_page();
    elseif ($a === 'notify') user_notify_page();
    elseif ($a === 'favorite') favorite_page();
    elseif ($a === 'forum') forum_page();
    elseif ($a === 'topic') topic_page();
    elseif ($a === 'topic_edit') topic_edit_page();
    elseif ($a === 'reply_edit') reply_edit_page();
    elseif ($a === 'delete') {
        need_login();
        $type = $_GET['type'] ?? '';
        $row = deletable_post_row($type, id());
        if (in_array($type, ['users', 'groups', 'forums'], true)) err('无权限');
        elseif ($type === 'topics' && (!$row || !can_manage_topic($row))) err('无权限');
        elseif ($type === 'replies' && (!$row || !can_manage_reply($row))) err('无权限');
        else if (!$row && !in_array($type, ['users', 'groups', 'forums'], true)) err('参数错误');
        del($type, id());
        $back = $_GET['back'] ?? '';
        if ($back === 'topic') go('index.php?a=topic&id=' . (int)($_GET['tid'] ?? 0));
        go('index.php');
    } else home_page();
} catch (Throwable $e) {
    err('操作失败');
}
