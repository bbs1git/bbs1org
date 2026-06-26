<?php

declare(strict_types=1);
require __DIR__ . '/function.php';

need_site_access();
check();
$a = $_GET['a'] ?? 'home';
try {
    if ($a === 'login') login_page();
    elseif ($a === 'register') register_page();
    elseif ($a === 'logout') {
        session_destroy();
        go('?');
    } elseif ($a === 'profile') profile_page();
    elseif ($a === 'user') user_page();
    elseif ($a === 'favorite') favorite_page();
    elseif ($a === 'forum') forum_page();
    elseif ($a === 'topic') topic_page();
    elseif ($a === 'topic_edit') topic_edit_page();
    elseif ($a === 'reply_edit') reply_edit_page();
    elseif ($a === 'delete') {
        need_login();
        $type = $_GET['type'] ?? '';
        $row = in_array($type, ['topics', 'replies'], true) ? one("SELECT * FROM $type WHERE id=?", [id()]) : null;
        if (in_array($type, ['users', 'groups', 'forums'], true)) err('无权限');
        elseif ($type === 'topics' && (!$row || !can_topic($row))) err('无权限');
        elseif ($type === 'replies' && (!$row || !can_reply($row))) err('无权限');
        else if (!$row && !in_array($type, ['users', 'groups', 'forums'], true)) err('参数错误');
        del($type, id());
        $back = $_GET['back'] ?? '';
        if ($back === 'topic') go('?a=topic&id=' . (int)($_GET['tid'] ?? 0));
        go('?');
    } else home_page();
} catch (Throwable $e) {
    err('操作失败');
}
