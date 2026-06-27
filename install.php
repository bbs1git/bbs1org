<?php

declare(strict_types=1);

define('DB_FILE', __DIR__ . '/data/forum.sqlite');
define('CACHE_DIR', __DIR__ . '/cache');
define('FORUM_CACHE_FILE', CACHE_DIR . '/forums.php');
define('GROUP_CACHE_FILE', CACHE_DIR . '/groups.php');
define('STATS_CACHE_FILE', CACHE_DIR . '/stats.php');
define('SETTING_CACHE_FILE', CACHE_DIR . '/settings.php');

if (file_exists(DB_FILE)) die('已安装，请删除 ' . DB_FILE . ' 后重试');

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
        ] as $sql
    ) $db->exec($sql);
    return $db;
}

$db = db();
$db->exec("
CREATE TABLE IF NOT EXISTS groups(id INTEGER PRIMARY KEY,name TEXT NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0,is_banned INTEGER NOT NULL DEFAULT 0,is_muted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,username TEXT NOT NULL UNIQUE,password TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',bio TEXT NOT NULL DEFAULT '',avatar_style TEXT NOT NULL DEFAULT '',avatar_seed TEXT NOT NULL DEFAULT '',group_id INTEGER NOT NULL DEFAULT 2,created_at INTEGER NOT NULL,FOREIGN KEY(group_id) REFERENCES groups(id));
CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS forums(id INTEGER PRIMARY KEY,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',sort INTEGER NOT NULL DEFAULT 0,last_topic_id INTEGER NOT NULL DEFAULT 0,last_topic_title TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS topics(id INTEGER PRIMARY KEY,forum_id INTEGER NOT NULL,user_id INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,reply_count INTEGER NOT NULL DEFAULT 0,view_count INTEGER NOT NULL DEFAULT 0,last_reply_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,FOREIGN KEY(forum_id) REFERENCES forums(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS replies(id INTEGER PRIMARY KEY,topic_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS favorites(user_id INTEGER NOT NULL,topic_id INTEGER NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(user_id,topic_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL DEFAULT '');
CREATE INDEX IF NOT EXISTS idx_users_group ON users(group_id);
CREATE INDEX IF NOT EXISTS idx_forums_sort ON forums(sort,id);
CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_replies_topic_time ON replies(topic_id,created_at,id);
CREATE INDEX IF NOT EXISTS idx_replies_user ON replies(user_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_topics_forum_last_reply ON topics(forum_id,last_reply_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC);
CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC);
");
$db->exec("INSERT OR IGNORE INTO groups(id,name,allow_manage,allow_admin,is_banned,is_muted) VALUES(1,'管理员',1,1,0,0),(2,'会员',0,0,0,0)");
$db->exec("INSERT OR IGNORE INTO forums(id,name,description,sort) VALUES(1,'默认版块','欢迎发帖',0)");
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

if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
$forums = $db->query("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
file_put_contents(FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);
$groups = $db->query("SELECT id,name,is_banned,is_muted,allow_manage,allow_admin FROM groups ORDER BY id")->fetchAll();
file_put_contents(GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);
file_put_contents(STATS_CACHE_FILE, "<?php\nreturn " . var_export([
    'topics' => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
    'replies' => (int)$db->query("SELECT COUNT(*) FROM replies")->fetchColumn(),
    'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'latest_users' => $db->query("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
], true) . ";\n", LOCK_EX);
$settings = array_column($db->query("SELECT name,value FROM settings")->fetchAll(), 'value', 'name');
file_put_contents(SETTING_CACHE_FILE, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX);

echo '安装完成';
