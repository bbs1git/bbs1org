<?php

declare(strict_types=1);
session_start();
define('DB_FILE', __DIR__ . '/data/forum.sqlite');
define('INSTALL_LOCK_FILE', __DIR__ . '/data/install.lock');
define('CACHE_DIR', __DIR__ . '/cache');
define('FORUM_CACHE_FILE', CACHE_DIR . '/forums.php');
define('GROUP_CACHE_FILE', CACHE_DIR . '/groups.php');
define('STATS_CACHE_FILE', CACHE_DIR . '/stats.php');
define('SETTING_CACHE_FILE', CACHE_DIR . '/settings.php');
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
            'PRAGMA foreign_keys=ON',
            'PRAGMA cache_size=-16000',
            'PRAGMA mmap_size=134217728',
            'PRAGMA wal_autocheckpoint=400',
        ] as $sql
    ) $db->exec($sql);
    $cols = [];
    foreach ($db->query("PRAGMA table_info(users)") as $col) $cols[(string)$col['name']] = true;
    if (!isset($cols['unread_notifications'])) $db->exec("ALTER TABLE users ADD COLUMN unread_notifications INTEGER NOT NULL DEFAULT 0");
    $group_cols = [];
    foreach ($db->query("PRAGMA table_info(groups)") as $col) $group_cols[(string)$col['name']] = true;
    if (!isset($group_cols['allow_manage'])) $db->exec("ALTER TABLE groups ADD COLUMN allow_manage INTEGER NOT NULL DEFAULT 0");
    if (!isset($group_cols['allow_admin'])) $db->exec("ALTER TABLE groups ADD COLUMN allow_admin INTEGER NOT NULL DEFAULT 0");
    if (isset($group_cols['is_admin'])) $db->exec("UPDATE groups SET allow_manage=1,allow_admin=1 WHERE is_admin=1");
    $notify_cols = [];
    foreach ($db->query("PRAGMA table_info(notifications)") as $col) $notify_cols[(string)$col['name']] = $col;
    $notify_mismatch = isset($notify_cols['topic_id']) && ((int)$notify_cols['topic_id']['notnull'] === 1 || (string)($notify_cols['topic_id']['dflt_value'] ?? '') === '0');
    $notify_mismatch = $notify_mismatch || (isset($notify_cols['reply_id']) && ((int)$notify_cols['reply_id']['notnull'] === 1 || (string)($notify_cols['reply_id']['dflt_value'] ?? '') === '0'));
    $notify_mismatch = $notify_mismatch || (isset($notify_cols['sender_id']) && ((int)$notify_cols['sender_id']['notnull'] === 1 && (string)($notify_cols['sender_id']['dflt_value'] ?? '') === '0'));
    if (!$notify_cols) {
        $db->exec("CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
    } elseif ($notify_mismatch) {
        $count = (int)val("SELECT COUNT(*) FROM notifications");
        if ($count === 0) {
            $db->exec("DROP TABLE IF EXISTS notifications");
            $db->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS notifications_new(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
            $db->exec("INSERT INTO notifications_new(id,recipient_id,sender_id,kind,content,topic_id,reply_id,read_at,created_at) SELECT id,recipient_id,NULLIF(sender_id,0),kind,content,NULLIF(topic_id,0),NULLIF(reply_id,0),read_at,created_at FROM notifications");
            $db->exec("DROP TABLE notifications");
            $db->exec("ALTER TABLE notifications_new RENAME TO notifications");
        }
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC)");
    $db->exec("CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC)");
    return $db;
}
function h($s): string
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
function db_schema_ready(): bool
{
    return is_file(INSTALL_LOCK_FILE);
}
function default_settings(): array
{
    return [
        'site_name' => 'FORUM',
        'site_keywords' => '',
        'site_description' => '',
        'header_html' => '',
        'footer_html' => '',
        'site_closed' => '0',
        'allow_register' => '1',
        'reserved_usernames' => 'admin,administrator,root,system',
        'default_group_id' => '2',
        'topics_per_page' => '30',
        'replies_per_page' => '50',
        'mail_from' => '',
    ];
}
function settings_cache(bool $refresh = false): array
{
    static $settings = null;
    if (!$refresh && $settings !== null) return $settings;
    if (!$refresh && is_file(SETTING_CACHE_FILE)) {
        $cached = include SETTING_CACHE_FILE;
        if (is_array($cached)) return $settings = array_merge(default_settings(), $cached);
    }
    $settings = default_settings();
    try {
        foreach (q("SELECT name,value FROM settings") as $row) $settings[(string)$row['name']] = (string)$row['value'];
        if (!is_dir(dirname(SETTING_CACHE_FILE))) mkdir(dirname(SETTING_CACHE_FILE), 0755, true);
        file_put_contents(SETTING_CACHE_FILE, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX);
    } catch (Throwable $e) {
    }
    return $settings;
}
function setting(string $key, string $default = ''): string
{
    $settings = settings_cache();
    return (string)($settings[$key] ?? $default);
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
function save_settings(): void
{
    $site_name = post('site_name', 80);
    if ($site_name === '') err('网站名不能为空');
    $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
    if (!group_by_id($gid)) err('默认用户组不存在');
    $values = [
        'site_name' => $site_name,
        'site_keywords' => post('site_keywords', 200),
        'site_description' => post('site_description', 500),
        'header_html' => post('header_html', 20000),
        'footer_html' => post('footer_html', 20000),
        'site_closed' => isset($_POST['site_closed']) ? '1' : '0',
        'allow_register' => isset($_POST['allow_register']) ? '1' : '0',
        'reserved_usernames' => post('reserved_usernames', 2000),
        'default_group_id' => (string)$gid,
        'topics_per_page' => (string)min(200, max(1, (int)($_POST['topics_per_page'] ?? 30))),
        'replies_per_page' => (string)min(200, max(1, (int)($_POST['replies_per_page'] ?? 50))),
        'mail_from' => post('mail_from', 120),
    ];
    foreach ($values as $name => $value) q("REPLACE INTO settings(name,value) VALUES(?,?)", [$name, $value]);
    settings_cache(true);
}
function reserved_usernames(): array
{
    $names = preg_split('/[\s,，]+/u', setting('reserved_usernames'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_map(fn($v) => function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v), $names);
}
function username_reserved(string $username): bool
{
    $name = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    return in_array($name, reserved_usernames(), true);
}
function forums_cache(bool $refresh = false): array
{
    static $forums = null;
    if (!$refresh && $forums !== null) return $forums;
    if (!$refresh && is_file(FORUM_CACHE_FILE)) {
        $cached = include FORUM_CACHE_FILE;
        if (is_array($cached)) return $forums = $cached;
    }
    $forums = q("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
    if (!is_dir(dirname(FORUM_CACHE_FILE))) mkdir(dirname(FORUM_CACHE_FILE), 0755, true);
    file_put_contents(FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);
    return $forums;
}
function forum_by_id(int $id): ?array
{
    foreach (forums_cache() as $f) if ((int)$f['id'] === $id) return $f;
    return null;
}
function groups_cache(bool $refresh = false): array
{
    static $groups = null;
    if (!$refresh && $groups !== null) return $groups;
    if (!$refresh && is_file(GROUP_CACHE_FILE)) {
        $cached = include GROUP_CACHE_FILE;
        if (is_array($cached)) return $groups = $cached;
    }
    $groups = q("SELECT id,name,is_banned,is_muted,allow_manage,allow_admin FROM groups ORDER BY id")->fetchAll();
    if (!is_dir(dirname(GROUP_CACHE_FILE))) mkdir(dirname(GROUP_CACHE_FILE), 0755, true);
    file_put_contents(GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);
    return $groups;
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
function notification_kind_label(string $kind): string
{
    return match ($kind) {
        'mention' => '提及',
        'direct' => '通知',
        default => '通知',
    };
}
function notification_excerpt(string $body, int $max = 120): string
{
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
function send_direct_notification(int $recipient_id, int $sender_id, string $content): bool
{
    return create_notification($recipient_id, $sender_id, 'direct', $content);
}
function notifications_list(int $uid, int $limit, int $offset = 0): array
{
    return q("SELECT n.*,u.username sender_username,u.avatar_style sender_avatar_style,u.avatar_seed sender_avatar_seed FROM notifications n LEFT JOIN users u ON u.id=n.sender_id WHERE n.recipient_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset])->fetchAll();
}
function notifications_total(int $uid): int
{
    return (int)val("SELECT COUNT(*) FROM notifications WHERE recipient_id=?", [$uid]);
}
function mark_notifications_read(int $uid): void
{
    q("UPDATE notifications SET read_at=CASE WHEN read_at=0 THEN ? ELSE read_at END WHERE recipient_id=?", [now(), $uid]);
    q("UPDATE users SET unread_notifications=0 WHERE id=?", [$uid]);
    $GLOBALS['__me_cache'] = null;
}
function notification_link(array $n): string
{
    if ((int)($n['topic_id'] ?? 0) > 0) {
        $url = 'index.php?a=topic&id=' . (int)$n['topic_id'];
        if ((int)($n['reply_id'] ?? 0) > 0) $url .= '&replyid=' . (int)$n['reply_id'];
        return $url;
    }
    if ((int)($n['sender_id'] ?? 0) > 0) return 'index.php?a=user&id=' . (int)$n['sender_id'];
    return 'index.php';
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
    $kind = notification_kind_label((string)($n['kind'] ?? ''));
    $unread = (int)($n['read_at'] ?? 0) === 0;
    return '<li class="post-item notification-item' . ($unread ? ' unread' : '') . '"><div class="post-avatar">' . avatar_tag($sender_id ?: 0, $sender_name, (string)($n['sender_avatar_style'] ?? ''), '', (string)($n['sender_avatar_seed'] ?? '')) . '</div><div class="post-body"><div class="post-title-row notification-head"><a class="post-title" href="index.php?a=user&id=' . $sender_id . '">' . h($sender_name) . '</a><span class="post-user-group notification-kind">' . h($kind) . '</span>' . ($unread ? '<span class="notification-unread">未读</span>' : '') . '</div><div class="post-meta"><span>' . human_time((int)$n['created_at']) . '</span></div><div class="post-content notification-content">' . $content_html . '</div></div><a class="post-tag post-forum-badge" href="' . h(notification_link($n)) . '">查看</a></li>';
}
function admin_user_form_data(int $id): array
{
    return $id ? (user_by_id($id) ?: err('用户不存在')) : ['id' => 0, 'username' => '', 'email' => '', 'bio' => '', 'avatar_style' => '', 'avatar_seed' => '', 'group_id' => (int)setting('default_group_id', '2')];
}
function admin_search_like(string $q): string
{
    return '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
}
function admin_users_list(string $query = ''): array
{
    if ($query !== '') {
        $like = admin_search_like($query);
        return q("SELECT * FROM users WHERE username LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR bio LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT 200", [$like, $like, $like])->fetchAll();
    }
    return q("SELECT * FROM users ORDER BY id DESC LIMIT 200")->fetchAll();
}
function admin_topics_list(string $query = ''): array
{
    if ($query !== '') {
        $like = admin_search_like($query);
        return q("SELECT t.id,t.title,t.user_id,u.username,u.avatar_style,u.avatar_seed FROM topics t JOIN users u ON u.id=t.user_id WHERE t.title LIKE ? ESCAPE '\\' OR t.body LIKE ? ESCAPE '\\' OR u.username LIKE ? ESCAPE '\\' ORDER BY t.id DESC LIMIT 200", [$like, $like, $like])->fetchAll();
    }
    return q("SELECT t.id,t.title,t.user_id,u.username,u.avatar_style,u.avatar_seed FROM topics t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 200")->fetchAll();
}
function admin_replies_list(string $query = ''): array
{
    if ($query !== '') {
        $like = admin_search_like($query);
        return q("SELECT r.id,r.body,r.topic_id,r.user_id,u.username,u.avatar_style,u.avatar_seed FROM replies r JOIN users u ON u.id=r.user_id WHERE r.body LIKE ? ESCAPE '\\' OR u.username LIKE ? ESCAPE '\\' ORDER BY r.id DESC LIMIT 200", [$like, $like])->fetchAll();
    }
    return q("SELECT r.id,r.body,r.topic_id,r.user_id,u.username,u.avatar_style,u.avatar_seed FROM replies r JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 200")->fetchAll();
}
function admin_search_form(string $tab, string $query): string
{
    return '<form class="admin-search" method="get" action="admin.php"><input type="hidden" name="tab" value="' . h($tab) . '"><input name="q" value="' . h($query) . '" placeholder="搜索"><button type="submit">搜索</button>' . ($query !== '' ? '<a class="btn alt" href="admin.php?tab=' . h($tab) . '">清空</a>' : '') . '</form>';
}
function admin_bulk_delete_form_open(string $tab, string $query): string
{
    return '<form method="post" action="admin.php?a=batch_delete" onsubmit="return confirm(\'确定批量删除选中项？\')">' . form_token() . '<input type="hidden" name="tab" value="' . h($tab) . '"><input type="hidden" name="q" value="' . h($query) . '">';
}
function admin_bulk_delete_bar(): string
{
    return '<div class="bulk-bar"><button class="danger" type="submit">批量删除</button></div>';
}
function admin_user_row(array $u, bool $manageable = true): string
{
    $g = group_by_id((int)$u['group_id']) ?: ['name' => ''];
    $ops = $manageable ? '<td class="ops"><a href="admin.php?a=edit&type=user&id=' . (int)$u['id'] . '">编辑</a> <a href="admin.php?a=delete&type=users&id=' . (int)$u['id'] . '&tab=users" onclick="return confirm(\'确定删除？\')">删除</a></td>' : '';
    return '<tr>' . ($manageable ? '<td class="check-col"><input type="checkbox" name="ids[]" value="' . (int)$u['id'] . '"></td>' : '') . '<td>' . (int)$u['id'] . '</td><td>' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), 'table-avatar', (string)($u['avatar_seed'] ?? '')) . h($u['username']) . '</td><td>' . h($g['name']) . '</td><td>' . h($u['email']) . '</td>' . $ops . '</tr>';
}
function admin_topic_row(array $t, bool $manageable = true): string
{
    $ops = '<td class="ops"><a href="index.php?a=topic&id=' . (int)$t['id'] . '">查看</a>' . ($manageable ? ' <a href="index.php?a=topic_edit&id=' . (int)$t['id'] . '">编辑</a> <a href="admin.php?a=delete&type=topics&id=' . (int)$t['id'] . '&tab=topics" onclick="return confirm(\'确定删除？\')">删除</a>' : '') . '</td>';
    return '<tr>' . ($manageable ? '<td class="check-col"><input type="checkbox" name="ids[]" value="' . (int)$t['id'] . '"></td>' : '') . '<td>' . (int)$t['id'] . '</td><td>' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), 'table-avatar', (string)($t['avatar_seed'] ?? '')) . h($t['title']) . '</td><td>' . h($t['username']) . '</td>' . $ops . '</tr>';
}
function admin_reply_row(array $r, bool $manageable = true): string
{
    $ops = '<td class="ops"><a href="index.php?a=topic&id=' . (int)$r['topic_id'] . '">查看</a>' . ($manageable ? ' <a href="index.php?a=reply_edit&id=' . (int)$r['id'] . '">编辑</a> <a href="admin.php?a=delete&type=replies&id=' . (int)$r['id'] . '&tab=replies" onclick="return confirm(\'确定删除？\')">删除</a>' : '') . '</td>';
    return '<tr>' . ($manageable ? '<td class="check-col"><input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '"></td>' : '') . '<td>' . (int)$r['id'] . '</td><td>' . avatar_tag((int)$r['user_id'], (string)$r['username'], (string)($r['avatar_style'] ?? ''), 'table-avatar', (string)($r['avatar_seed'] ?? '')) . h(cut($r['body'], 80)) . '</td><td>' . h($r['username']) . '</td>' . $ops . '</tr>';
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
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">最近浏览版块</div><ul class="quick-links">';
    foreach (recent_forums() as $f) $html .= '<li><a href="index.php?a=forum&id=' . (int)$f['id'] . '">' . h($f['name']) . '</a></li>';
    return $html . '</ul></div></div>';
}
function sidebar_notice_card_html(string $title, array $items): string
{
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">' . h($title) . '</div><ul class="quick-links notice-links">';
    foreach ($items as $item) $html .= '<li>' . h($item) . '</li>';
    return $html . '</ul></div></div>';
}
function markdown_help_card_html(): string
{
    return sidebar_notice_card_html('Markdown 说明', [
        '**粗体**，*斜体*',
        '`代码`',
        '- 列表项',
        '[链接文字](https://example.com)',
        '![图片描述](https://example.com/a.jpg)',
    ]);
}
function shell_html(string $main, string $sidebar): string
{
    return '<div class="home-shell"><div class="forum-layout"><div class="forum-main"><div class="main-panel">' . $main . '</div></div>' . $sidebar . '</div></div>';
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
        'login' => ['label' => '登录', 'href' => 'index.php?a=login'],
        'register' => ['label' => '注册', 'href' => 'index.php?a=register'],
    ], $active, 'auth-tabs');
}
function sidebar_stack_html(array $parts): string
{
    $html = '<aside class="sidebar">';
    foreach ($parts as $part) if ($part !== '') $html .= $part;
    return $html . '</aside>';
}
function sidebar_user_card_html(?array $m = null, bool $reply_button = false, int $fid = 0): string
{
    $m = $m ?: me();
    if (!$m) return '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big visitor-avatar">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div>' . guest_auth_html() . '</div></div>';
    $is_self = uid() && (int)$m['id'] === uid();
    $prefix = $is_self ? '我的' : 'TA的';
    $unread = $is_self ? (int)($m['unread_notifications'] ?? 0) : 0;
    $links = '<a href="index.php?a=user&id=' . (int)$m['id'] . '&tab=topics">' . svg_icon('topic') . $prefix . '主题</a><a href="index.php?a=user&id=' . (int)$m['id'] . '&tab=replies">' . svg_icon('reply') . $prefix . '回帖</a><a href="index.php?a=user&id=' . (int)$m['id'] . '&tab=favorites">' . svg_icon('favorite') . $prefix . '收藏</a>';
    if ($is_self) $links .= '<a href="index.php?a=user&id=' . (int)$m['id'] . '&tab=notifications">' . svg_icon('notify') . $prefix . '通知' . notification_badge_html($unread) . '</a><a href="index.php?a=profile">' . svg_icon('settings') . '个人设置</a>' . (can_access_admin() ? '<a href="admin.php">' . svg_icon('admin') . '后台面板</a>' : '');
    else $links .= '<a href="index.php?a=notify&id=' . (int)$m['id'] . '" onclick="openNotify(this.href);return false">' . svg_icon('notify') . '私信TA</a>';
    $html = '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</div><div><div class="user-name">' . h($m['username']) . '</div><div class="user-rank">' . h($m['group_name'] ?? '用户') . '</div></div></div></div><div class="user-links">' . $links . '</div></div>';
    if (can_speak()) $html .= '<a class="btn-post' . ($is_self ? '' : ' notify-link') . '" href="' . ($reply_button ? '#reply' : ($is_self ? 'index.php?a=topic_edit' . ($fid ? '&fid=' . $fid : '') : 'index.php?a=notify&id=' . (int)$m['id'])) . '"' . ($is_self || $reply_button ? '' : ' onclick="openNotify(this.href);return false"') . '>' . ($reply_button ? '回帖' : ($is_self ? '+ 发帖' : '私信TA')) . '</a>';
    return $html . '</div>';
}
function sidebar_stats_card_html(): string
{
    $stats = stats_cache();
    $html = '<div class="card sidebar-card stats-card"><div class="stats-wrap"><div class="stats-title">站点统计</div><div class="stats-sub">主题 ' . (int)$stats['topics'] . ' · 回复 ' . (int)$stats['replies'] . ' · 用户 ' . (int)$stats['users'] . '</div><div class="new-users-title">最新用户</div><div class="new-users">';
    foreach (($stats['latest_users'] ?? []) as $u) $html .= '<a class="nu-item" href="index.php?a=user&id=' . (int)$u['id'] . '"><div class="nu-avatar-circle">' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), '', (string)($u['avatar_seed'] ?? '')) . '</div><span class="nu-name">' . h($u['username']) . '</span></a>';
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
function guest_auth_html(): string
{
    $allow = setting('allow_register', '1') === '1';
    return '<div class="side-auth' . ($allow ? '' : ' single') . '"><a href="index.php?a=login">登录</a>' . ($allow ? '<a href="index.php?a=register">注册</a>' : '') . '</div>';
}
function user_card_html(?array $m = null, bool $reply_button = false, int $fid = 0): string
{
    return sidebar_user_card_html($m, $reply_button, $fid);
}
function form_shell(string $body, ?array $m = null): string
{
    return shell_html($body, sidebar_stack_html([sidebar_user_card_html($m)]));
}
function topic_form_shell(string $body): string
{
    return shell_html($body, sidebar_stack_html([sidebar_user_card_html(), markdown_help_card_html()]));
}
function stats_cache(bool $refresh = false): array
{
    static $stats = null;
    if (!$refresh && $stats !== null) return $stats;
    if (!$refresh && is_file(STATS_CACHE_FILE)) {
        $cached = include STATS_CACHE_FILE;
        if (is_array($cached)) return $stats = $cached;
    }
    $stats = [
        'topics' => (int)val("SELECT COUNT(*) FROM topics"),
        'replies' => (int)val("SELECT COUNT(*) FROM replies"),
        'users' => (int)val("SELECT COUNT(*) FROM users"),
        'latest_users' => q("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
    ];
    if (!is_dir(dirname(STATS_CACHE_FILE))) mkdir(dirname(STATS_CACHE_FILE), 0755, true);
    file_put_contents(STATS_CACHE_FILE, "<?php\nreturn " . var_export($stats, true) . ";\n", LOCK_EX);
    return $stats;
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
    return $GLOBALS['__me_cache'] = $u + ['group_name' => $g['name'], 'is_banned' => (int)$g['is_banned'], 'is_muted' => (int)$g['is_muted'], 'allow_manage' => (int)($g['allow_manage'] ?? 0), 'allow_admin' => (int)($g['allow_admin'] ?? 0)];
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
function is_banned(): bool
{
    if (is_super_user()) return false;
    $u = me();
    return $u && !can_access_admin() && (int)$u['is_banned'] === 1;
}
function is_muted(): bool
{
    if (is_super_user()) return false;
    $u = me();
    return $u && !can_access_admin() && (int)$u['is_muted'] === 1;
}
function can_speak(): bool
{
    return uid() && !is_muted();
}
function need_login(): void
{
    if (!uid()) go('index.php?a=login');
}
function need_speak(): void
{
    need_login();
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
    if (is_banned() && ($_GET['a'] ?? '') !== 'logout') err('禁止访问');
    $a = $_GET['a'] ?? 'home';
    if (setting('site_closed') === '1' && !can_access_admin() && !in_array($a, ['login', 'logout', 'forgot_password', 'reset_password'], true)) err('网站已关闭');
}
function token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function check(): void
{
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
function ajax_error(string $m): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => 0, 'message' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}
function simple_error_page(string $m): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>错误</title><style>body{margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fb;color:#222;font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}.box{max-width:420px;padding:28px 24px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.06)}</style></head><body><div class="box"><h1>' . h($m) . '</h1></div></body></html>';
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
function err(string $m): never
{
    if (ajax_request()) ajax_error($m);
    if (!is_file(INSTALL_LOCK_FILE)) simple_error_page($m);
    page('错误', shell_html('<div class="form-panel"><h2>错误</h2><p>' . h($m) . '</p></div>', sidebar_stack_html([sidebar_user_card_html()])));
    exit;
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
    $sep = str_contains($url, '?') ? '&' : '?';
    $h = '<div class="pagination"><ul>';
    if ($page > 1) $h .= '<li><a href="' . $url . $sep . 'p=' . ($page - 1) . '">上一页</a></li>';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $h .= '<li><a href="' . $url . $sep . 'p=1">1</a></li>';
        if ($start > 2) $h .= '<li><span class="ellipsis">...</span></li>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $h .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . $url . $sep . 'p=' . $i . '">' . $i . '</a></li>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $h .= '<li><span class="ellipsis">...</span></li>';
        $h .= '<li><a href="' . $url . $sep . 'p=' . $pages . '">' . $pages . '</a></li>';
    }
    if ($page < $pages) $h .= '<li><a href="' . $url . $sep . 'p=' . ($page + 1) . '">下一页</a></li>';
    $h .= '</ul></div>';
    return $h;
}
function topic_page_links(int $topic_id, int $reply_count): string
{
    $size = max(1, (int)setting('replies_per_page', '50'));
    $pages = (int)ceil($reply_count / $size);
    if ($pages <= 1) return '';
    $base = 'index.php?a=topic&id=' . $topic_id;
    $nums = [];
    foreach ([2, 3, $pages - 2, $pages - 1, $pages] as $n) if ($n >= 2 && $n <= $pages) $nums[$n] = true;
    $nums = array_keys($nums);
    sort($nums);
    $h = '<span class="topic-pages">' . svg_icon('pages');
    $prev = 1;
    foreach ($nums as $i) {
        if ($i - $prev > 1) $h .= '<span class="topic-pages-sep">…</span>';
        $h .= '<a href="' . $base . '&p=' . $i . '">' . $i . '</a>';
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
    return '<input type="hidden" name="_csrf" value="' . h(token()) . '">';
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
function avatar_seed(int $uid, string $seed = ''): string
{
    return $seed === '' ? (string)$uid : $seed;
}
function avatar_seed_options(string $seed = ''): array
{
    $seeds = array_map('strval', range(1, 48));
    if ($seed !== '' && !in_array($seed, $seeds, true)) array_unshift($seeds, $seed);
    return $seeds;
}
function avatar_url(int $uid, string $style = '', string $seed = ''): string
{
    $style = avatar_style($style) ?: 'dylan';
    return 'https://api.dicebear.com/10.x/' . rawurlencode($style) . '/svg?seed=' . rawurlencode(avatar_seed($uid, $seed));
}
function avatar_tag(int $uid, string $name, string $style = '', string $class = '', string $seed = ''): string
{
    $classes = trim('avatar-img ' . $class);
    return '<img class="' . h($classes) . '" src="' . h(avatar_url($uid, $style, $seed)) . '" alt="' . h($name) . '" loading="lazy">';
}
function markdown_inline(string $text): string
{
    $text = h($text);
    $codes = [];
    $text = preg_replace_callback('/`([^`\n]+)`/u', function ($m) use (&$codes) {
        $key = "\x1A" . count($codes) . "\x1A";
        $codes[$key] = '<code>' . $m[1] . '</code>';
        return $key;
    }, $text) ?? $text;
    $text = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace_callback('/!\[([^\]\n]*)\]\((https?:\/\/[^\s)<]+)\)/u', function ($m) use (&$codes) {
        $key = "\x1A" . count($codes) . "\x1A";
        $codes[$key] = '<img src="' . h($m[2]) . '" alt="' . $m[1] . '" loading="lazy">';
        return $key;
    }, $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)<]+)\)/u', function ($m) {
        return '<a href="' . h($m[2]) . '" target="_blank" rel="nofollow noopener">' . $m[1] . '</a>';
    }, $text) ?? $text;
    $text = preg_replace_callback('/(?<!["\'>=])(https?:\/\/[^\s<]+)/u', function ($m) {
        $url = rtrim($m[1], '.,;:!?');
        $tail = substr($m[1], strlen($url));
        return '<a href="' . h($url) . '" target="_blank" rel="nofollow noopener">' . h($url) . '</a>' . h($tail);
    }, $text) ?? $text;
    return strtr($text, $codes);
}
function markdown_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return '';
    $html = [];
    $paragraph = [];
    $code = [];
    $in_code = false;
    $flush = function () use (&$html, &$paragraph) {
        $block = trim(implode("\n", $paragraph));
        $paragraph = [];
        if ($block === '') return;
        $lines = explode("\n", $block);
        if (count($lines) === 1 && preg_match('/^(#{1,6})\s+(.+)$/u', $lines[0], $m)) {
            $level = strlen($m[1]);
            $html[] = '<h' . $level . '>' . markdown_inline($m[2]) . '</h' . $level . '>';
            return;
        }
        if (count($lines) > 1 && preg_match('/^\s*[-*]\s+/', $lines[0])) {
            $items = '';
            foreach ($lines as $line) if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $m)) $items .= '<li>' . markdown_inline($m[1]) . '</li>';
            if ($items !== '') {
                $html[] = '<ul>' . $items . '</ul>';
                return;
            }
        }
        $html[] = '<p>' . str_replace("\n", '<br>', markdown_inline($block)) . '</p>';
    };
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*```\s*[\w-]*\s*$/u', $line)) {
            if ($in_code) {
                $html[] = '<pre><code>' . h(rtrim(implode("\n", $code), "\n")) . '</code></pre>';
                $code = [];
                $in_code = false;
            } else {
                $flush();
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
        $paragraph[] = $line;
    }
    if ($in_code) $html[] = '<pre><code>' . h(rtrim(implode("\n", $code), "\n")) . '</code></pre>';
    else $flush();
    return implode('', $html);
}
function avatar_picker_html(array $u): string
{
    $uid = (int)$u['id'];
    $style = avatar_style((string)($u['avatar_style'] ?? ''));
    $seed = (string)($u['avatar_seed'] ?? '');
    $name = (string)($u['username'] ?? '');
    $html = '<div class="grid avatar-field"><span>头像设置</span><div class="avatar-picker" data-seed="' . $uid . '"><div class="avatar-picker-head"><div class="avatar-picker-preview">' . avatar_tag($uid, $name, $style, '', $seed) . '</div><select name="avatar_style"><option value=""' . ($style === '' ? ' selected' : '') . '>默认 Dylan</option>';
    foreach (avatar_styles() as $k => $v) $html .= '<option value="' . h($k) . '"' . ($k === $style ? ' selected' : '') . '>' . h($v) . '</option>';
    $html .= '</select></div><input type="hidden" name="avatar_seed" value="' . h($seed) . '"><div class="avatar-options"><div class="avatar-option' . ($seed === '' ? ' active' : '') . '" data-seed="">' . avatar_tag($uid, $name, $style, '', '') . '</div>';
    foreach (avatar_seed_options($seed) as $s) $html .= '<div class="avatar-option' . ($s === $seed ? ' active' : '') . '" data-seed="' . h($s) . '">' . avatar_tag($uid, $name, $style, '', $s) . '</div>';
    return $html . '</div></div></div>';
}
function topic_post_row(array $row, string $body, int $time, string $ops = '', string $title = '', string $stats = ''): string
{
    $has_title = $title !== '';
    $title_html = $has_title ? '<div class="post-topic-title"><h1 class="post-content-title">' . h($title) . '</h1>' . $stats . '</div>' : '';
    $avatar = avatar_tag((int)$row['user_id'], (string)$row['username'], (string)($row['avatar_style'] ?? ''), '', (string)($row['avatar_seed'] ?? ''));
    return '<li class="post-item post-entry' . ($has_title ? ' has-title' : '') . '" id="post-' . (int)($row['id'] ?? 0) . '">' . $title_html . '<div class="post-avatar">' . $avatar . '</div><div class="post-body"><div class="post-head"><a class="post-title post-author" href="index.php?a=user&id=' . (int)$row['user_id'] . '">' . h($row['username']) . '</a>' . topic_user_group_html($row) . $ops . '</div><div class="post-meta"><span>' . human_time($time) . '</span></div></div><div class="post-content">' . markdown_html($body) . '</div></li>';
}
function quote_reply_action(array $row): string
{
    return '<a class="icon-action icon-quote quote-reply" href="#reply" data-username="' . h((string)$row['username']) . '" title="引用回复"><span>引用回复</span></a>';
}
function topic_list_row(array $t, string $sort, string $url_prefix = 'index.php'): string
{
    $time = (int)($t['time'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
    $forum = $t['forum'] ?? ['id' => (int)$t['forum_id'], 'name' => ''];
    $user_link = '<a href="' . h($url_prefix) . '?a=user&id=' . (int)$t['user_id'] . '">' . svg_icon('user') . h($t['username']) . '</a>';
    $forum_link = '<a href="' . h($url_prefix) . '?a=forum&id=' . (int)$forum['id'] . '">' . h($forum['name']) . '</a>';
    $meta = '<span>' . $user_link . '</span><span class="post-forum-meta">' . svg_icon('forum') . $forum_link . '</span><span>' . svg_icon('reply') . (int)$t['reply_count'] . '</span><span>' . human_time($time) . '</span>';
    $pages = topic_page_links((int)$t['id'], (int)$t['reply_count']);
    return '<li class="post-item"><div class="post-avatar">' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), '', (string)($t['avatar_seed'] ?? '')) . '</div><div class="post-body"><div class="post-title-row"><a class="post-title" href="' . h($url_prefix) . '?a=topic&id=' . (int)$t['id'] . '">' . h($t['title']) . '</a>' . $pages . '</div><div class="post-meta">' . $meta . '</div></div><a class="post-tag post-forum-badge" href="' . h($url_prefix) . '?a=forum&id=' . (int)$forum['id'] . '">' . h($forum['name']) . '</a></li>';
}
function topic_stats_html(int $view_count, int $reply_count): string
{
    $stats = '';
    if ($view_count > 0) $stats .= '<span>' . svg_icon('view') . $view_count . '</span>';
    if ($reply_count > 0) $stats .= '<span>' . svg_icon('reply') . $reply_count . '</span>';
    return $stats ? '<div class="post-content-stats">' . $stats . '</div>' : '';
}
function app_script_html(): string
{
    return <<<'HTML'
<script>
const toastEl = () => document.getElementById("toast");
const showToast = (message) => {
    const toast = toastEl();
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.hidden = true, 1800);
};
const modal = document.getElementById("notify-modal");
const modalBody = document.getElementById("notify-modal-body");
const closeModal = () => {
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
};
window.openNotify = async function (url) {
    try {
        const response = await fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}});
        const html = await response.text();
        if (modal && modalBody) {
            modalBody.innerHTML = html;
            modal.hidden = false;
            modalBody.querySelector("form")?.querySelector("textarea")?.focus();
        }
    } catch (_) {
        showToast("打开失败");
    }
    return false;
};
const runPageFlash = () => {
    if (window.__pageFlash) showToast(window.__pageFlash);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runPageFlash);
else runPageFlash();
function avatarPickerUrl(p, seed) {
    const s = p?.querySelector("select[name=avatar_style]");
    return "https://api.dicebear.com/10.x/" + encodeURIComponent(s?.value || "dylan") + "/svg?seed=" + encodeURIComponent(seed || p.dataset.seed || "0");
}
function refreshAvatarPicker(p) {
    const k = p?.querySelector("input[name=avatar_seed]");
    const v = k?.value || "";
    const i = p?.querySelector(".avatar-picker-preview img");
    if (i) i.src = avatarPickerUrl(p, v);
    p?.querySelectorAll(".avatar-option").forEach(b => {
        const seed = b.dataset.seed || "";
        const img = b.querySelector("img");
        if (img) img.src = avatarPickerUrl(p, seed);
        b.classList.toggle("active", seed === v);
    });
}
document.addEventListener("change", e => {
    const p = e.target.closest(".avatar-picker");
    if (p) refreshAvatarPicker(p);
});
document.addEventListener("click", e => {
    const b = e.target.closest(".avatar-option");
    if (!b) return;
    const p = b.closest(".avatar-picker");
    const k = p?.querySelector("input[name=avatar_seed]");
    if (k) {
        k.value = b.dataset.seed || "";
        refreshAvatarPicker(p);
    }
});
document.addEventListener("click", e => {
    if (e.target?.closest("[data-modal-close]") || e.target === modal) closeModal();
});
document.addEventListener("click", e => {
    const quote = e.target.closest(".quote-reply");
    if (!quote) return;
    e.preventDefault();
    const textarea = document.querySelector("#reply textarea[name=body]");
    const panel = document.getElementById("reply");
    if (!textarea || !panel) {
        window.location.href = quote.href;
        return;
    }
    const mention = "@" + (quote.dataset.username || "").trim() + " ";
    if (!textarea.value.includes(mention)) textarea.value = mention + textarea.value;
    panel.scrollIntoView({block:"center"});
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
});
document.addEventListener("submit", async e => {
    const replyForm = e.target.closest(".ajax-reply-form");
    if (replyForm) {
        e.preventDefault();
        const button = replyForm.querySelector("button");
        const status = replyForm.querySelector(".reply-status");
        const list = document.querySelector(".topic-post-list");
        button.disabled = true;
        if (status) status.textContent = "提交中";
        try {
            const response = await fetch(replyForm.action, {method: "POST", body: new FormData(replyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "提交失败");
            list?.querySelector(".empty-state")?.remove();
            list?.insertAdjacentHTML("beforeend", data.html);
            const title = document.querySelector(".post-topic-title");
            const stats = title?.querySelector(".post-content-stats");
            if (title) {
                if (data.stats_html) {
                    if (stats) stats.outerHTML = data.stats_html;
                    else title.insertAdjacentHTML("beforeend", data.stats_html);
                } else if (stats) stats.remove();
            }
            replyForm.reset();
            if (status) status.textContent = "已回复";
        } catch (_) {
            if (status) status.textContent = "提交失败";
        } finally {
            button.disabled = false;
        }
        return;
    }
    const notifyForm = e.target.closest(".notify-form");
    if (notifyForm) {
        e.preventDefault();
        const button = notifyForm.querySelector("button");
        const status = notifyForm.querySelector(".notify-status");
        button.disabled = true;
        if (status) status.textContent = "发送中";
        try {
            const response = await fetch(notifyForm.action, {method: "POST", body: new FormData(notifyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "发送失败");
            closeModal();
            showToast(data.message || "已发送");
        } catch (err) {
            showToast(err?.message || "发送失败");
        } finally {
            button.disabled = false;
            if (status) status.textContent = "";
        }
        return;
    }
    const form = e.target.closest("form");
    if (!form || (form.method || "").toLowerCase() !== "post") return;
    e.preventDefault();
    const button = e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]");
    if (button) button.disabled = true;
    try {
        const body = new FormData(form);
        if (button?.name) body.append(button.name, button.value ?? "1");
        const response = await fetch(form.action || window.location.href, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (_) {
            throw new Error("操作失败");
        }
        if (!data.ok) throw new Error(data.message || "操作失败");
        if (data.redirect) window.location.href = data.redirect;
        else showToast(data.message || "操作成功");
    } catch (err) {
        showToast(err?.message || "操作失败");
        if (button) button.disabled = false;
    }
});
</script>
HTML;
}
function page(string $title, string $body): void
{
    $settings = settings_cache();
    $site_name = trim((string)$settings['site_name']) ?: 'FORUM';
    $page_title = $title === '' || $title === $site_name ? $site_name : $title . ' - ' . $site_name;
    $meta = '';
    if ($settings['site_keywords'] !== '') $meta .= '<meta name="keywords" content="' . h($settings['site_keywords']) . '">';
    if ($settings['site_description'] !== '') $meta .= '<meta name="description" content="' . h($settings['site_description']) . '">';
    $q = trim((string)($_GET['q'] ?? ''));
    $active_forum = ($_GET['a'] ?? '') === 'forum' ? id() : 0;
    $flash = trim((string)($_COOKIE['__flash'] ?? ''));
    if ($flash !== '' && !headers_sent()) setcookie('__flash', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $meta . '<title>' . h($page_title) . '</title><link rel="stylesheet" href="style.css"></head><body>';
    $mine = me();
    $mine_link = $mine ? 'index.php?a=user&id=' . (int)$mine['id'] : 'index.php?a=login';
    $mine_label = $mine ? '我的' . notification_badge_html((int)($mine['unread_notifications'] ?? 0)) : '登录';
    echo '<div class="top"><div class="bar"><a class="brand" href="index.php">' . h($site_name) . '</a><nav class="forum-nav">';
    foreach (array_slice(forums_cache(), 0, 7) as $f) echo '<a class="forum-link' . ((int)$f['id'] === $active_forum ? ' active' : '') . '" href="index.php?a=forum&id=' . (int)$f['id'] . '">' . h($f['name']) . '</a>';
    echo '</nav><form class="search-form" method="get" action="index.php"><input class="search-input" type="search" name="q" placeholder="搜索主题" value="' . h($q) . '"><button class="search-btn" type="submit" aria-label="搜索"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9.5L13 13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></button></form><a class="nav-mine" href="' . $mine_link . '">' . $mine_label . '</a></div></div>';
    echo (string)$settings['header_html'] . '<main class="wrap">' . $body . '</main><footer class="footer">Copyright © 2022 - 2026 All rights Reserved</footer><div class="modal-backdrop" id="notify-modal" hidden><div class="modal-panel"><div class="modal-head"><strong>私信TA</strong><button type="button" class="modal-close" data-modal-close aria-label="关闭">×</button></div><div class="modal-body" id="notify-modal-body"></div></div></div><div class="toast" id="toast" hidden></div><script>window.__pageFlash=' . json_encode($flash, JSON_UNESCAPED_UNICODE) . ';</script>' . (string)$settings['footer_html'] . app_script_html() . '</body></html>';
}
function input(string $label, string $name, $value = '', string $type = 'text', bool $required = false): string
{
    return '<label class="grid"><span>' . h($label) . '</span><input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '') . '></label>';
}
function textarea(string $label, string $name, $value = '', bool $required = false): string
{
    return '<label class="grid"><span>' . h($label) . '</span><textarea name="' . h($name) . '"' . ($required ? ' required' : '') . '>' . h($value) . '</textarea></label>';
}
function select_group(int $gid): string
{
    $html = '<label class="grid"><span>用户组</span><select name="group_id">';
    foreach (groups_cache() as $g) $html .= '<option value="' . (int)$g['id'] . '"' . ((int)$g['id'] === $gid ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    return $html . '</select></label>';
}
function select_forum(int $fid): string
{
    $html = '<label class="grid"><span>版块</span><select name="forum_id">';
    foreach (forums_cache() as $f) $html .= '<option value="' . (int)$f['id'] . '"' . ((int)$f['id'] === $fid ? ' selected' : '') . '>' . h($f['name']) . '</option>';
    return $html . '</select></label>';
}
function can_topic(array $t): bool
{
    return can_manage() || (uid() && (int)$t['user_id'] === uid());
}
function can_reply(array $r): bool
{
    return can_manage() || (uid() && (int)$r['user_id'] === uid());
}
function can_manage_topic(array $t): bool
{
    return can_manage();
}
function can_manage_reply(array $r): bool
{
    return can_manage();
}
function refresh_topic_stats(int $tid): void
{
    q("UPDATE topics SET reply_count=(SELECT COUNT(*) FROM replies WHERE topic_id=?),last_reply_at=COALESCE((SELECT MAX(created_at) FROM replies WHERE topic_id=?),created_at) WHERE id=?", [$tid, $tid, $tid]);
}
function save_user(bool $admin = false): void
{
    $username = post('username', 40);
    $email = post('email', 120);
    $bio = post('bio', 1000);
    $avatar_style = avatar_style(post('avatar_style', 40));
    $avatar_seed = post('avatar_seed', 80);
    if ($username === '') err('用户名不能为空');
    $user_id = id();
    $old_user = $user_id ? one("SELECT username,group_id FROM users WHERE id=?", [$user_id]) : null;
    if ($user_id && !$old_user) err('用户不存在');
    if (!$admin && (!$old_user || (string)$old_user['username'] !== $username) && username_reserved($username)) err('用户名已保留');
    $gid = $admin ? max(1, (int)$_POST['group_id']) : ($old_user ? (int)$old_user['group_id'] : (int)setting('default_group_id', '2'));
    if (!group_by_id($gid)) err('用户组不存在');
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if ($pwd !== '' && $pwd !== $pwd2) err('两次密码不一致');
    if ($user_id) {
        $p = [$username, $email, $bio, $avatar_style, $avatar_seed, $gid, $user_id];
        $sql = "UPDATE users SET username=?,email=?,bio=?,avatar_style=?,avatar_seed=?,group_id=? WHERE id=?";
        if ($pwd !== '') {
            $sql = "UPDATE users SET username=?,email=?,bio=?,avatar_style=?,avatar_seed=?,group_id=?,password=? WHERE id=?";
            $p = [$username, $email, $bio, $avatar_style, $avatar_seed, $gid, password_hash($pwd, PASSWORD_DEFAULT), $user_id];
        }
        q($sql, $p);
    } else {
        if ($pwd === '') err('密码不能为空');
        q("INSERT INTO users(username,password,email,bio,avatar_style,avatar_seed,group_id,created_at) VALUES(?,?,?,?,?,?,?,?)", [$username, password_hash($pwd, PASSWORD_DEFAULT), $email, $bio, $avatar_style, $avatar_seed, $gid, now()]);
    }
    stats_cache(true);
}
function user_notifications_page(): void
{
    need_login();
    $me = me();
    mark_notifications_read(uid());
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = 30;
    $off = ($p - 1) * $size;
    $rows = notifications_list(uid(), $size, $off);
    $total = notifications_total(uid());
    $main = '<div class="post-topic-title"><h1 class="post-content-title">我的通知</h1></div><ul class="post-list">';
    if (!$rows) {
        $main .= '<li class="empty-state">暂无通知</li>';
    } else {
        foreach ($rows as $n) $main .= notification_row_html($n);
    }
    $main .= '</ul><div class="pagination-bar">' . paginate($total, $p, $size, 'index.php?a=user&id=' . uid() . '&tab=notifications') . '</div>';
    page('我的通知', shell_html($main, sidebar_stack_html([sidebar_user_card_html($me, false)])));
}
function user_notify_page(): void
{
    need_login();
    $target = one("SELECT id,username,avatar_style,avatar_seed,group_id FROM users WHERE id=?", [id()]) ?: err('用户不存在');
    if ((int)$target['id'] === uid()) err('不能通知自己');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $content = post('content', 500);
        if ($content === '') {
            ajax_request() ? ajax_error('通知内容不能为空') : err('通知内容不能为空');
        }
        send_direct_notification((int)$target['id'], uid(), $content);
        if (ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'message' => '已发送'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        go('index.php?a=user&id=' . (int)$target['id'] . '&tab=notifications');
    }
    $target['group_name'] = (group_by_id((int)$target['group_id']) ?: ['name' => '用户'])['name'];
    $html = '<div class="notify-pop"><div class="notify-target"><div class="notify-target-avatar">' . avatar_tag((int)$target['id'], (string)$target['username'], (string)$target['avatar_style'], '', (string)$target['avatar_seed']) . '</div><div class="notify-target-info"><strong>' . h($target['username']) . '</strong><span>' . h($target['group_name']) . '</span></div></div><form class="notify-form" method="post" action="index.php?a=notify&id=' . (int)$target['id'] . '">' . form_token() . '<textarea name="content" placeholder="输入私信内容" required></textarea><div class="notify-actions"><span class="notify-status"></span><button type="submit">发送</button></div></form></div>';
    if (ajax_request()) {
        echo $html;
        exit;
    }
    page('通知TA', form_shell('<div class="form-panel"><h2>通知TA</h2>' . $html . '</div>', me()));
}
function user_notify_link_html(int $uid): string
{
    return '<a class="notify-link" href="index.php?a=notify&id=' . $uid . '" data-user-id="' . $uid . '">私信TA</a>';
}
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . ($dir === '' ? '' : $dir);
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
    if (uid()) go('index.php');
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = post('username', 40);
        $email = post('email', 120);
        $u = one("SELECT id,username,email FROM users WHERE username=? AND email=?", [$username, $email]);
        if (!$u || !filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) err('用户名和邮箱不匹配');
        $token = create_password_reset($u);
        $link = base_url() . '/index.php?a=reset_password&token=' . $token;
        $subject = '重置密码 - ' . (trim(setting('site_name')) ?: 'FORUM');
        $body = "你好，" . $u['username'] . "\n\n请打开以下链接重置密码：\n" . $link . "\n\n链接有效期为 1 小时。如果不是你本人操作，请忽略本邮件。";
        if (!send_mail_text((string)$u['email'], $subject, $body)) err('邮件发送失败，请稍后再试');
        if (ajax_request()) go('index.php?a=login');
        $sent = true;
    }
    $body = '<div class="form-panel auth-panel"><h2>忘记密码</h2>';
    if ($sent) {
        $body .= '<p class="muted">重置密码邮件已经发送，请查收邮箱。</p><p class="auth-extra"><a href="index.php?a=login">返回登录</a></p>';
    } else {
        $body .= '<form method="post">' . form_token() . input('用户名', 'username', '', 'text', true) . input('邮箱', 'email', '', 'email', true) . '<button>发送重置邮件</button></form><p class="auth-extra"><a href="index.php?a=login">返回登录</a></p>';
    }
    page('忘记密码', shell_html(auth_tabs_html('login') . $body . '</div>', password_reset_notice_sidebar('forgot')));
}
function reset_password_page(): void
{
    if (uid()) go('index.php');
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') err('重置链接无效');
    $row = one("SELECT pr.*,u.username FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at=0 AND pr.expires_at>=?", [hash('sha256', $token), now()]);
    if (!$row) err('重置链接无效或已过期');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pwd = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password2'] ?? '');
        if ($pwd === '') err('密码不能为空');
        if ($pwd !== $pwd2) err('两次密码不一致');
        q("UPDATE users SET password=? WHERE id=?", [password_hash($pwd, PASSWORD_DEFAULT), (int)$row['user_id']]);
        q("UPDATE password_resets SET used_at=? WHERE id=?", [now(), (int)$row['id']]);
        if (ajax_request()) go('index.php?a=login');
        page('密码已重置', shell_html(auth_tabs_html('login') . '<div class="form-panel auth-panel"><h2>密码已重置</h2><p class="muted">请使用新密码登录。</p><p class="auth-extra"><a href="index.php?a=login">去登录</a></p></div>', password_reset_notice_sidebar('reset')));
        return;
    }
    $form = '<div class="form-panel auth-panel"><h2>重置密码</h2><form method="post">' . form_token() . '<input type="hidden" name="token" value="' . h($token) . '">' . input('新密码', 'password', '', 'password', true) . input('确认密码', 'password2', '', 'password', true) . '<button>保存新密码</button></form></div>';
    page('重置密码', shell_html(auth_tabs_html('login') . $form, password_reset_notice_sidebar('reset')));
}
function save_forum(): void
{
    $name = post('name', 80);
    if ($name === '') err('版块名不能为空');
    $p = [$name, post('description', 300), (int)$_POST['sort']];
    id() ? q("UPDATE forums SET name=?,description=?,sort=? WHERE id=?", [...$p, id()]) : q("INSERT INTO forums(name,description,sort) VALUES(?,?,?)", $p);
    forums_cache(true);
}
function save_group(): void
{
    $name = post('name', 60);
    if ($name === '') err('组名不能为空');
    $allow_manage = isset($_POST['allow_manage']) ? 1 : 0;
    $allow_admin = isset($_POST['allow_admin']) ? 1 : 0;
    $banned = isset($_POST['is_banned']) ? 1 : 0;
    $muted = isset($_POST['is_muted']) ? 1 : 0;
    id() ? q("UPDATE groups SET name=?,allow_manage=?,allow_admin=?,is_banned=?,is_muted=? WHERE id=?", [$name, $allow_manage, $allow_admin, $banned, $muted, id()]) : q("INSERT INTO groups(name,allow_manage,allow_admin,is_banned,is_muted) VALUES(?,?,?,?,?)", [$name, $allow_manage, $allow_admin, $banned, $muted]);
    groups_cache(true);
}
function save_topic(): int
{
    need_speak();
    $fid = max(1, (int)$_POST['forum_id']);
    $title = post('title', 120);
    $body = post('body', 20000);
    if ($title === '' || $body === '') err('标题和内容不能为空');
    if (id()) {
        $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
        q("UPDATE topics SET forum_id=?,title=?,body=?,updated_at=? WHERE id=?", [$fid, $title, $body, now(), id()]);
        if ((int)$t['forum_id'] !== $fid) q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [0, '']);
        q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [id(), $title]);
        forums_cache(true);
        return id();
    }
    q("INSERT INTO topics(forum_id,user_id,title,body,created_at,updated_at,last_reply_at) VALUES(?,?,?,?,?,?,?)", [$fid, uid(), $title, $body, now(), now(), now()]);
    $tid = (int)db()->lastInsertId();
    q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [$tid, $title, $fid]);
    forums_cache(true);
    stats_cache(true);
    return $tid;
}
function save_reply(): array
{
    need_speak();
    $ajax = ajax_request();
    $tid = max(1, (int)$_POST['topic_id']);
    one("SELECT id FROM topics WHERE id=?", [$tid]) ?: ($ajax ? ajax_error('主题不存在') : err('主题不存在'));
    $body = post('body', 10000);
    if ($body === '') $ajax ? ajax_error('回复不能为空') : err('回复不能为空');
    if (id()) {
        $r = one("SELECT * FROM replies WHERE id=?", [id()]) ?: err('回复不存在');
        if (!can_manage_reply($r)) $ajax ? ajax_error('无权限') : err('无权限');
        q("UPDATE replies SET body=?,updated_at=? WHERE id=?", [$body, now(), id()]);
        return ['topic_id' => (int)$r['topic_id'], 'reply_id' => (int)$r['id']];
    }
    $ts = now();
    q("INSERT INTO replies(topic_id,user_id,body,created_at,updated_at) VALUES(?,?,?,?,?)", [$tid, uid(), $body, $ts, $ts]);
    $rid = (int)db()->lastInsertId();
    q("UPDATE topics SET updated_at=?,reply_count=reply_count+1,last_reply_at=? WHERE id=?", [$ts, $ts, $tid]);
    create_reply_notifications($tid, $rid, $body, uid());
    stats_cache(true);
    return ['topic_id' => $tid, 'reply_id' => $rid];
}
function del(string $table, int $id): void
{
    $allow = ['users', 'groups', 'forums', 'topics', 'replies'];
    if (!in_array($table, $allow, true)) err('参数错误');
    if ($table === 'users' && $id === uid()) err('不能删除自己');
    if ($table === 'groups' && $id <= 2) err('内置用户组不能删除');
    if ($table === 'groups' && $id === (int)setting('default_group_id', '2')) err('默认用户组不能删除');
    if ($table === 'forums' && count(forums_cache()) <= 1) err('至少保留一个版块');
    if ($table === 'replies') {
        $r = one("SELECT topic_id FROM replies WHERE id=?", [$id]);
        q("DELETE FROM replies WHERE id=?", [$id]);
        if ($r) refresh_topic_stats((int)$r['topic_id']);
        stats_cache(true);
        return;
    }
    if ($table === 'users') {
        $tids = q("SELECT DISTINCT topic_id FROM replies WHERE user_id=?", [$id])->fetchAll();
        q("DELETE FROM users WHERE id=?", [$id]);
        foreach ($tids as $r) refresh_topic_stats((int)$r['topic_id']);
        stats_cache(true);
        return;
    }
    q("DELETE FROM $table WHERE id=?", [$id]);
    if ($table === 'forums') {
        forums_cache(true);
        stats_cache(true);
    }
    if ($table === 'groups') groups_cache(true);
    if ($table === 'topics') stats_cache(true);
}
function login_page(): void
{
    if (uid()) go('index.php');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = one("SELECT id,password FROM users WHERE username=?", [post('username', 40)]);
        if ($u && password_verify((string)$_POST['password'], $u['password'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            go('index.php');
        }
        err('用户名或密码错误');
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('登录注意事项', ['请使用用户名登录。', '密码区分大小写。', '公共设备登录后请及时退出。']),
    ]);
    page('登录', shell_html(auth_tabs_html('login') . '<div class="form-panel auth-panel"><h2>登录</h2><form method="post">' . form_token() . input('用户名', 'username', '', 'text', true) . input('密码', 'password', '', 'password', true) . '<button>登录</button></form><p class="auth-extra"><a href="index.php?a=forgot_password">忘记密码？</a></p></div>', $sidebar));
}
function register_page(): void
{
    if (uid()) go('index.php');
    if (setting('allow_register', '1') !== '1') err('注册已关闭');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        save_user(false);
        $_SESSION['uid'] = (int)db()->lastInsertId();
        go('index.php');
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('注册注意事项', ['用户名注册后可在个人资料中调整。', '邮箱保密，仅忘记密码时可用。', '请不要使用保留用户名或冒充他人。']),
    ]);
    page('注册', shell_html(auth_tabs_html('register') . '<div class="form-panel auth-panel"><h2>注册</h2><form method="post">' . form_token() . input('用户名', 'username', '', 'text', true) . input('邮箱', 'email', '', 'email') . input('密码', 'password', '', 'password', true) . input('确认密码', 'password2', '', 'password', true) . '<button>注册</button></form></div>', $sidebar));
}
function profile_page(): void
{
    need_login();
    $u = me();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST['id'] = uid();
        save_user(false);
        go('index.php?a=profile');
    }
    page('个人资料', form_shell('<div class="form-panel"><h2>个人资料</h2><form method="post">' . form_token() . input('用户名', 'username', $u['username'], 'text', true) . input('邮箱', 'email', $u['email'], 'email') . input('新密码', 'password', '', 'password') . input('确认密码', 'password2', '', 'password') . avatar_picker_html($u) . textarea('简介', 'bio', $u['bio']) . '<button>保存</button></form><div class="profile-exit"><a href="index.php?a=logout"><span>安全退出</span><small>退出当前登录状态</small></a></div></div>', $u));
}
function user_page(): void
{
    $user = one("SELECT id,username,bio,avatar_style,avatar_seed,group_id FROM users WHERE id=?", [id()]) ?: err('用户不存在');
    $g = group_by_id((int)$user['group_id']) ?: ['name' => '用户'];
    $user['group_name'] = $g['name'];
    $tab = $_GET['tab'] ?? 'topics';
    if ($tab === 'notifications' && uid() === (int)$user['id']) user_notifications_page();
    else if ($tab === 'notify') user_notify_page();
    else topic_index_page(null, $user);
}
function notification_page(): void
{
    need_login();
    user_notify_page();
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
    go('index.php?a=topic&id=' . $tid);
}
function topic_index_page(?array $filter_forum = null, ?array $filter_user = null): void
{
    $fid = (int)($filter_forum['id'] ?? 0);
    $profile_uid = (int)($filter_user['id'] ?? 0);
    $own_profile = $profile_uid && uid() === $profile_uid;
    $base = $profile_uid ? 'index.php?a=user&id=' . $profile_uid : ($fid ? 'index.php?a=forum&id=' . $fid : 'index.php');
    $url = function (string $query) use ($base): string {
        return $base . (str_contains($base, '?') ? '&' : '?') . $query;
    };
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = max(1, (int)setting('topics_per_page', '30'));
    $off = ($p - 1) * $size;
    $profile_tab = $_GET['tab'] ?? 'topics';
    if (!in_array($profile_tab, ['topics', 'replies', 'favorites', 'notifications'], true)) $profile_tab = 'topics';
    $sort = $profile_uid ? 'post' : (($_GET['sort'] ?? 'comment') === 'post' ? 'post' : 'comment');
    $order = $sort === 'post' ? 't.created_at DESC,t.id DESC' : 't.last_reply_at DESC,t.id DESC';
    $q = trim((string)($_GET['q'] ?? ''));
    $where_parts = [];
    $params = [];
    if ($fid) {
        $where_parts[] = 't.forum_id=?';
        $params[] = $fid;
    }
    if ($q !== '') {
        $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
        $forum_ids = [];
        foreach (forums_cache() as $f) if (stripos((string)$f['name'], $q) !== false) $forum_ids[] = (int)$f['id'];
        $where_parts[] = "(t.title LIKE ? ESCAPE '\\' OR t.body LIKE ? ESCAPE '\\' OR u.username LIKE ? ESCAPE '\\'" . ($forum_ids ? ' OR t.forum_id IN (' . implode(',', array_fill(0, count($forum_ids), '?')) . ')' : '') . ')';
        $params = array_merge($params, [$like, $like, $like]);
        if ($forum_ids) $params = array_merge($params, $forum_ids);
    }
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    $stats = stats_cache();
    if ($profile_uid && $profile_tab === 'notifications') {
        $total = notifications_total($profile_uid);
        $rows = notifications_list($profile_uid, $size, $off);
    } elseif ($profile_uid && $profile_tab === 'replies') {
        $where2 = $where ? $where . ' AND r.user_id=?' : 'WHERE r.user_id=?';
        $params2 = array_merge($params, [$profile_uid]);
        $total = (int)q("SELECT COUNT(DISTINCT t.id) FROM replies r JOIN topics t ON t.id=r.topic_id JOIN users u ON u.id=t.user_id $where2", $params2)->fetchColumn();
        $rows = q("SELECT t.id,t.title,t.created_at,t.updated_at,t.reply_count,t.last_reply_at,t.forum_id,t.user_id,u.username,u.avatar_style,u.avatar_seed,MAX(r.created_at) my_reply_at FROM replies r JOIN topics t ON t.id=r.topic_id JOIN users u ON u.id=t.user_id $where2 GROUP BY t.id ORDER BY my_reply_at DESC LIMIT ? OFFSET ?", array_merge($params2, [$size, $off]))->fetchAll();
    } elseif ($profile_uid && $profile_tab === 'favorites') {
        $where2 = $where ? $where . ' AND fa.user_id=?' : 'WHERE fa.user_id=?';
        $params2 = array_merge($params, [$profile_uid]);
        $total = (int)q("SELECT COUNT(*) FROM favorites fa JOIN topics t ON t.id=fa.topic_id JOIN users u ON u.id=t.user_id $where2", $params2)->fetchColumn();
        $rows = q("SELECT t.id,t.title,t.created_at,t.updated_at,t.reply_count,t.last_reply_at,t.forum_id,t.user_id,u.username,u.avatar_style,u.avatar_seed,fa.created_at favorite_at FROM favorites fa JOIN topics t ON t.id=fa.topic_id JOIN users u ON u.id=t.user_id $where2 ORDER BY fa.created_at DESC LIMIT ? OFFSET ?", array_merge($params2, [$size, $off]))->fetchAll();
    } else {
        if ($profile_uid) {
            $where = $where ? $where . ' AND t.user_id=?' : 'WHERE t.user_id=?';
            $params[] = $profile_uid;
        }
        $total = ($q === '' && !$fid && !$profile_uid) ? (int)$stats['topics'] : (int)q("SELECT COUNT(*) FROM topics t JOIN users u ON u.id=t.user_id $where", $params)->fetchColumn();
        $rows = q("SELECT t.id,t.title,t.created_at,t.updated_at,t.reply_count,t.last_reply_at,t.forum_id,t.user_id,u.username,u.avatar_style,u.avatar_seed FROM topics t JOIN users u ON u.id=t.user_id $where ORDER BY $order LIMIT ? OFFSET ?", array_merge($params, [$size, $off]))->fetchAll();
    }
    $main = '';
    if ($profile_uid) {
        $prefix = $own_profile ? '我的' : 'TA的';
        if (!$own_profile && $profile_tab === 'notifications') $profile_tab = 'topics';
        $tab_items = [
            'topics' => ['label' => $prefix . '主题', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=topics')],
            'replies' => ['label' => $prefix . '回帖', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=replies')],
            'favorites' => ['label' => $prefix . '收藏', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=favorites')],
        ];
        if ($own_profile) $tab_items['notifications'] = ['label' => $prefix . '通知', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=notifications')];
        $main .= '<div class="profile-toolbar">' . tab_bar_html($tab_items, $profile_tab) . ($own_profile ? '<span class="tab-actions"><a href="index.php?a=profile">设置</a>' . (can_access_admin() ? '<a href="admin.php">后台</a>' : '') . '</span>' : '<span class="tab-actions"><a class="notify-link" href="index.php?a=notify&id=' . $profile_uid . '" onclick="openNotify(this.href);return false">私信TA</a></span>') . '</div>';
    } else {
        $tab_items = [
            'comment' => ['label' => '新评论', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=comment')],
            'post' => ['label' => '新帖子', 'href' => $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=post')],
        ];
        $main .= '<div class="topic-toolbar">' . tab_bar_html($tab_items, $sort) . (can_speak() ? '<a class="tab-post" href="index.php?a=topic_edit' . ($fid ? '&fid=' . $fid : '') . '">+ 发帖</a>' : '') . '</div>';
    }
    $main .= '<ul class="post-list">';
    if ($profile_uid && $profile_tab === 'notifications') {
        mark_notifications_read($profile_uid);
        if (!$rows) $main .= '<li class="empty-state">暂无通知</li>';
        else foreach ($rows as $n) $main .= notification_row_html($n);
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
    $main .= '</ul><div class="pagination-bar">' . paginate($total, $p, $size, $url($page_query)) . '</div>';
    $sidebar_user = $profile_uid ? $filter_user : null;
    $sidebar = sidebar_stack_html([sidebar_user_card_html($sidebar_user, false, $fid), sidebar_bio_card_html($filter_user), (!$profile_uid ? quick_forums_html() . sidebar_stats_card_html() : '')]);
    page($profile_uid ? $filter_user['username'] : ($filter_forum ? $filter_forum['name'] : '首页'), shell_html($main, $sidebar));
}
function home_page(): void
{
    topic_index_page();
}
function forum_page(): void
{
    $fid = id();
    $f = forum_by_id($fid) ?: err('版块不存在');
    remember_forum($fid);
    topic_index_page($f);
}
function topic_page(): void
{
    $t = one("SELECT t.*,u.username,u.avatar_style,u.avatar_seed,u.group_id FROM topics t JOIN users u ON u.id=t.user_id WHERE t.id=?", [id()]) ?: err('主题不存在');
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
        }
    }
    $p = max(1, (int)($_GET['p'] ?? 1));
    $off = ($p - 1) * $size;
    $replies = q("SELECT r.*,u.username,u.avatar_style,u.avatar_seed,u.group_id FROM replies r JOIN users u ON u.id=r.user_id WHERE r.topic_id=? ORDER BY r.created_at,r.id LIMIT ? OFFSET ?", [(int)$t['id'], $size, $off])->fetchAll();
    $fav = uid() ? one("SELECT 1 FROM favorites WHERE user_id=? AND topic_id=?", [uid(), (int)$t['id']]) : null;
    $topic_ops = '';
    if (uid()) $topic_ops .= quote_reply_action($t);
    if (uid()) $topic_ops .= '<a class="fav-btn' . ($fav ? ' active' : '') . '" href="index.php?a=favorite&id=' . (int)$t['id'] . '" title="' . ($fav ? '已收藏' : '收藏') . '" aria-label="' . ($fav ? '已收藏' : '收藏') . '">' . svg_icon($fav ? 'favorite_fill' : 'favorite') . '<span>' . ($fav ? '已收藏' : '收藏') . '</span></a>';
    if (can_manage_topic($t)) $topic_ops .= '<a class="icon-action icon-edit" href="index.php?a=topic_edit&id=' . (int)$t['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="index.php?a=delete&type=topics&id=' . (int)$t['id'] . '&back=home" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a>';
    $main = '<div class="post-topic-title"><h1 class="post-content-title">' . h($t['title']) . '</h1>' . topic_stats_html((int)$t['view_count'], (int)$t['reply_count']) . '</div><ul class="post-list topic-post-list">';
    $main .= topic_post_row($t, $t['body'], (int)$t['created_at'], $topic_ops ? '<div class="post-ops">' . $topic_ops . '</div>' : '');
    foreach ($replies as $r) {
        $reply_ops = uid() ? quote_reply_action($r) : '';
        if (can_manage_reply($r)) $reply_ops .= '<a class="icon-action icon-edit" href="index.php?a=reply_edit&id=' . (int)$r['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="index.php?a=delete&type=replies&id=' . (int)$r['id'] . '&back=topic&tid=' . (int)$t['id'] . '" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a>';
        $reply_ops = $reply_ops !== '' ? '<div class="post-ops">' . $reply_ops . '</div>' : '';
        $main .= topic_post_row($r, $r['body'], (int)$r['created_at'], $reply_ops);
    }
    if (!$replies && (int)$t['reply_count'] === 0) $main .= '<li class="empty-state">暂无回复</li>';
    $main .= '</ul><div class="pagination-bar">' . paginate((int)$t['reply_count'], $p, $size, 'index.php?a=topic&id=' . (int)$t['id']) . '</div>';
    $main .= '<div class="reply-panel" id="reply"><div class="reply-panel-head"><h3>发表回复</h3><span class="reply-status">' . (uid() ? (can_speak() ? '说两句' : '禁止发言') : '登录后回复') . '</span></div>';
    if (can_speak()) {
        $main .= '<form class="ajax-reply-form" method="post" action="index.php?a=reply_edit">' . form_token() . '<input type="hidden" name="topic_id" value="' . (int)$t['id'] . '">' . textarea('内容', 'body', '', true) . '<button>回复</button></form>';
    } elseif (!uid()) {
        $main .= '<div class="reply-login-box"><a href="index.php?a=login">登录后回复</a></div>';
    } else {
        $main .= '<div class="reply-login-box disabled">当前用户组禁止发言</div>';
    }
    $main .= '</div>';
    if ($replyid > 0) $main .= '<script>window.addEventListener("load",function(){const target=document.getElementById("post-' . $replyid . '");if(!target)return;const imgs=[...document.images].filter(img=>!img.complete);let done=false;const scroll=()=>{if(done)return;done=true;target.scrollIntoView({block:"center"});};if(!imgs.length){scroll();return;}let left=imgs.length;imgs.forEach(img=>{const next=()=>{left--;if(left<=0)scroll();};img.addEventListener("load",next,{once:true});img.addEventListener("error",next,{once:true});});setTimeout(scroll,3000);});</script>';
    page($t['title'], shell_html($main, sidebar_stack_html([sidebar_user_card_html(null, true), quick_forums_html(), sidebar_stats_card_html()])));
}
function topic_edit_page(): void
{
    need_speak();
    $t = ['id' => 0, 'forum_id' => id('fid') ?: 1, 'title' => '', 'body' => '', 'user_id' => uid()];
    if (id()) {
        $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') go('index.php?a=topic&id=' . save_topic());
    $title = id() ? '编辑主题' : '发表主题';
    page($title, topic_form_shell('<div class="form-panel topic-form-panel"><h2>' . $title . '</h2><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '">' . select_forum((int)$t['forum_id']) . input('标题', 'title', $t['title'], 'text', true) . textarea('内容', 'body', $t['body'], true) . '<button>保存</button></form></div>'));
}
function reply_edit_page(): void
{
    need_speak();
    $r = ['id' => 0, 'topic_id' => id('topic_id'), 'body' => '', 'user_id' => uid()];
    if (id()) {
        $r = one("SELECT * FROM replies WHERE id=?", [id()]) ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $editing = id() > 0;
        $saved = save_reply();
        if (ajax_request() && $editing) go('index.php?a=topic&id=' . $saved['topic_id'] . '&replyid=' . $saved['reply_id']);
        if (ajax_request()) {
            $row = one("SELECT r.*,u.username,u.avatar_style,u.avatar_seed,u.group_id FROM replies r JOIN users u ON u.id=r.user_id WHERE r.id=?", [$saved['reply_id']]) ?: err('回复不存在');
            $ops = quote_reply_action($row);
            if (can_manage_reply($row)) $ops .= '<a class="icon-action icon-edit" href="index.php?a=reply_edit&id=' . (int)$row['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="index.php?a=delete&type=replies&id=' . (int)$row['id'] . '&back=topic&tid=' . (int)$saved['topic_id'] . '" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a>';
            $ops = '<div class="post-ops">' . $ops . '</div>';
            $topic = one("SELECT view_count,reply_count FROM topics WHERE id=?", [$saved['topic_id']]) ?: ['view_count' => 0, 'reply_count' => 0];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'html' => topic_post_row($row, $row['body'], (int)$row['created_at'], $ops), 'stats_html' => topic_stats_html((int)$topic['view_count'], (int)$topic['reply_count'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        go('index.php?a=topic&id=' . $saved['topic_id'] . '&replyid=' . $saved['reply_id']);
    }
    page('编辑回复', form_shell('<div class="form-panel"><h2>编辑回复</h2><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="topic_id" value="' . (int)$r['topic_id'] . '">' . textarea('内容', 'body', $r['body'], true) . '<button>保存</button></form></div>'));
}
