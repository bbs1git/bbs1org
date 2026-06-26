<?php

declare(strict_types=1);
define('DB_FILE', __DIR__ . '/data/forum.sqlite');
define('FORUM_CACHE_FILE', __DIR__ . '/data/cache_forums.php');
define('GROUP_CACHE_FILE', __DIR__ . '/data/cache_groups.php');
define('STATS_CACHE_FILE', __DIR__ . '/data/cache_stats.php');
define('SETTING_CACHE_FILE', __DIR__ . '/data/cache_settings.php');

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
$db->exec("INSERT OR IGNORE INTO groups(id,name,is_admin,is_banned,is_muted) VALUES(1,'管理员',1,0,0),(2,'会员',0,0,0)");
$db->exec("CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL DEFAULT '')");
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
];
$stmt = $db->prepare("INSERT OR IGNORE INTO settings(name,value) VALUES(?,?)");
foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_time ON topics(forum_id,updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_updated ON topics(updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_topic_created ON replies(topic_id,created_at,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user_id ON replies(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC)");

$forums = $db->query("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
if (!is_dir(dirname(FORUM_CACHE_FILE))) mkdir(dirname(FORUM_CACHE_FILE), 0755, true);
file_put_contents(FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);

$groups = $db->query("SELECT id,name,is_admin,is_banned,is_muted FROM groups ORDER BY id")->fetchAll();
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
