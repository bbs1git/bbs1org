<?php

declare(strict_types=1);
define('DB_FILE', __DIR__ . '/data/forum.sqlite');
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
    foreach ([
        'PRAGMA journal_mode=WAL',
        'PRAGMA synchronous=NORMAL',
        'PRAGMA temp_store=MEMORY',
        'PRAGMA busy_timeout=5000',
        'PRAGMA foreign_keys=ON',
    ] as $sql) $db->exec($sql);
    return $db;
}

$db = db();
$cols = [];
foreach ($db->query("PRAGMA table_info(users)") as $col) $cols[(string)$col['name']] = true;
if (!isset($cols['avatar_style'])) $db->exec("ALTER TABLE users ADD COLUMN avatar_style TEXT NOT NULL DEFAULT ''");
if (!isset($cols['avatar_seed'])) $db->exec("ALTER TABLE users ADD COLUMN avatar_seed TEXT NOT NULL DEFAULT ''");
if (!isset($cols['unread_notifications'])) $db->exec("ALTER TABLE users ADD COLUMN unread_notifications INTEGER NOT NULL DEFAULT 0");
$group_cols = [];
foreach ($db->query("PRAGMA table_info(groups)") as $col) $group_cols[(string)$col['name']] = true;
if (!isset($group_cols['allow_manage'])) $db->exec("ALTER TABLE groups ADD COLUMN allow_manage INTEGER NOT NULL DEFAULT 0");
if (!isset($group_cols['allow_admin'])) $db->exec("ALTER TABLE groups ADD COLUMN allow_admin INTEGER NOT NULL DEFAULT 0");
if (isset($group_cols['is_admin'])) $db->exec("UPDATE groups SET allow_manage=1,allow_admin=1 WHERE is_admin=1");
$tables = array_map('strval', $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
if (!in_array('notifications', $tables, true)) {
    $db->exec("CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
} else {
    $ncols = [];
    foreach ($db->query("PRAGMA table_info(notifications)") as $col) $ncols[(string)$col['name']] = $col;
    if (isset($ncols['topic_id']) && (int)$ncols['topic_id']['notnull'] === 1) {
        $count = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
        if ($count === 0) {
            $db->exec("DROP TABLE notifications");
            $db->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS notifications_new(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE)");
            $db->exec("INSERT INTO notifications_new(id,recipient_id,sender_id,kind,content,topic_id,reply_id,read_at,created_at) SELECT id,recipient_id,NULLIF(sender_id,0),kind,content,NULLIF(topic_id,0),NULLIF(reply_id,0),read_at,created_at FROM notifications");
            $db->exec("DROP TABLE notifications");
            $db->exec("ALTER TABLE notifications_new RENAME TO notifications");
        }
    }
}
$db->exec("INSERT OR IGNORE INTO groups(id,name,allow_manage,allow_admin,is_banned,is_muted) VALUES(1,'管理员',1,1,0,0),(2,'会员',0,0,0,0)");
$db->exec("CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL DEFAULT '')");
$db->exec("CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
$settings = [
    'site_name' => 'PHPLite Forum',
    'site_keywords' => '',
    'site_description' => '',
    'header_html' => '',
    'footer_html' => '',
    'site_closed' => '0',
    'allow_register' => '1',
    'reserved_usernames' => 'admin,administrator,root,system',
    'default_group_id' => '2',
    'mail_from' => '',
];
$stmt = $db->prepare("INSERT OR IGNORE INTO settings(name,value) VALUES(?,?)");
foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
$db->exec("UPDATE topics SET last_reply_at=created_at WHERE last_reply_at=0");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_last_reply ON topics(forum_id,last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_topic_created ON replies(topic_id,created_at,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user_id ON replies(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC)");
$db->exec("DROP INDEX IF EXISTS idx_topics_forum_time");
$db->exec("DROP INDEX IF EXISTS idx_topics_forum_updated");
$db->exec("DROP INDEX IF EXISTS idx_topics_updated");

$forums = $db->query("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
if (!is_dir(dirname(FORUM_CACHE_FILE))) mkdir(dirname(FORUM_CACHE_FILE), 0755, true);
file_put_contents(FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);

$groups = $db->query("SELECT id,name,is_banned,is_muted,allow_manage,allow_admin FROM groups ORDER BY id")->fetchAll();
if (!is_dir(dirname(GROUP_CACHE_FILE))) mkdir(dirname(GROUP_CACHE_FILE), 0755, true);
file_put_contents(GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);

$stats = [
    'topics' => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
    'replies' => (int)$db->query("SELECT COUNT(*) FROM replies")->fetchColumn(),
    'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'latest_users' => $db->query("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
];
if (!is_dir(dirname(STATS_CACHE_FILE))) mkdir(dirname(STATS_CACHE_FILE), 0755, true);
file_put_contents(STATS_CACHE_FILE, "<?php\nreturn " . var_export($stats, true) . ";\n", LOCK_EX);

$settings = array_column($db->query("SELECT name,value FROM settings")->fetchAll(), 'value', 'name');
if (!is_dir(dirname(SETTING_CACHE_FILE))) mkdir(dirname(SETTING_CACHE_FILE), 0755, true);
file_put_contents(SETTING_CACHE_FILE, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX);

echo 'updated';
