<?php
declare(strict_types=1);

const UPDATE_DB_FILE = __DIR__ . '/data/forum.sqlite';
const UPDATE_LOCK_FILE = __DIR__ . '/data/install.lock';
const UPDATE_SETTINGS_CACHE = __DIR__ . '/cache/settings.php';

function u_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function u_page(string $title, string $message): void
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . u_h($title) . '</title><link rel="stylesheet" href="index.css"></head><body><main class="install-page"><section class="install-card"><h1>' . u_h($title) . '</h1><p>' . u_h($message) . '</p><a class="install-enter" href="index.php">进入首页</a></section></main></body></html>';
    exit;
}

function u_db(): PDO
{
    $db = new PDO('sqlite:' . UPDATE_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $db;
}

function u_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function u_column_exists(PDO $db, string $table, string $column): bool
{
    foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) return true;
    }
    return false;
}
function u_table_sql(PDO $db, string $table): string
{
    $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (string)$stmt->fetchColumn();
}

function u_autoincrement_table(PDO $db, string $table, string $create_sql): void
{
    if (stripos(u_table_sql($db, $table), 'AUTOINCREMENT') !== false) return;
    $tmp = $table . '_new';
    $db->exec("DROP TABLE IF EXISTS $tmp");
    $db->exec($create_sql);
    $cols = [];
    foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) $cols[] = (string)$row['name'];
    $list = implode(',', $cols);
    $db->exec("INSERT INTO $tmp($list) SELECT $list FROM $table");
    $db->exec("DROP TABLE $table");
    $db->exec("ALTER TABLE $tmp RENAME TO $table");
}

if (!is_file(UPDATE_LOCK_FILE) || !is_file(UPDATE_DB_FILE)) {
    u_page('请先安装', '请先执行安装操作。');
}

$db = u_db();
if (!u_table_exists($db, 'topics') || !u_table_exists($db, 'settings') || !u_table_exists($db, 'users') || !u_table_exists($db, 'groups')) {
    u_page('升级失败', '数据表不完整，请先确认安装状态。');
}

try {
    $db->beginTransaction();
    $db->exec("CREATE TABLE IF NOT EXISTS trash(id INTEGER PRIMARY KEY AUTOINCREMENT,table_name TEXT NOT NULL,row_id INTEGER NOT NULL,row_data TEXT NOT NULL,deleted_by INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL)");
    $db->exec("DROP TABLE IF EXISTS trash_users");
    $db->exec("DROP TABLE IF EXISTS trash_topics");
    $db->exec("DROP TABLE IF EXISTS trash_replies");
    if (!u_column_exists($db, 'topics', 'highlight_style')) {
        $db->exec("ALTER TABLE topics ADD COLUMN highlight_style TEXT NOT NULL DEFAULT ''");
    }
    if (!u_column_exists($db, 'users', 'is_banned')) {
        $db->exec("ALTER TABLE users ADD COLUMN is_banned INTEGER NOT NULL DEFAULT 0");
    }
    if (!u_column_exists($db, 'users', 'is_muted')) {
        $db->exec("ALTER TABLE users ADD COLUMN is_muted INTEGER NOT NULL DEFAULT 0");
    }
    if (!u_column_exists($db, 'trash', 'deleted_by')) {
        $db->exec("ALTER TABLE trash ADD COLUMN deleted_by INTEGER NOT NULL DEFAULT 0");
    }
    $group_cols = [];
    foreach ($db->query('PRAGMA table_info(groups)')->fetchAll() as $row) {
        $group_cols[] = (string)($row['name'] ?? '');
    }
    if (in_array('is_banned', $group_cols, true) || in_array('is_muted', $group_cols, true)) {
        $db->exec('DROP TABLE IF EXISTS groups_new');
        $db->exec('CREATE TABLE groups_new(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0)');
        $db->exec('INSERT INTO groups_new(id,name,allow_manage,allow_admin) SELECT id,name,allow_manage,allow_admin FROM groups');
        $db->exec('DROP TABLE groups');
        $db->exec('ALTER TABLE groups_new RENAME TO groups');
    }
    u_autoincrement_table($db, 'groups', 'CREATE TABLE groups_new(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0)');
    u_autoincrement_table($db, 'users', "CREATE TABLE users_new(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,password TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',bio TEXT NOT NULL DEFAULT '',avatar_style TEXT NOT NULL DEFAULT '',avatar_seed TEXT NOT NULL DEFAULT '',group_id INTEGER NOT NULL DEFAULT 2,is_banned INTEGER NOT NULL DEFAULT 0,is_muted INTEGER NOT NULL DEFAULT 0,unread_notifications INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL)");
    u_autoincrement_table($db, 'trash', 'CREATE TABLE trash_new(id INTEGER PRIMARY KEY AUTOINCREMENT,table_name TEXT NOT NULL,row_id INTEGER NOT NULL,row_data TEXT NOT NULL,deleted_by INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL)');
    u_autoincrement_table($db, 'notifications', "CREATE TABLE notifications_new(id INTEGER PRIMARY KEY AUTOINCREMENT,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL)");
    u_autoincrement_table($db, 'forums', "CREATE TABLE forums_new(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',sort INTEGER NOT NULL DEFAULT 0,last_topic_id INTEGER NOT NULL DEFAULT 0,last_topic_title TEXT NOT NULL DEFAULT '')");
    u_autoincrement_table($db, 'topics', "CREATE TABLE topics_new(id INTEGER PRIMARY KEY AUTOINCREMENT,forum_id INTEGER NOT NULL,user_id INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,highlight_style TEXT NOT NULL DEFAULT '',reply_count INTEGER NOT NULL DEFAULT 0,view_count INTEGER NOT NULL DEFAULT 0,last_reply_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)");
    u_autoincrement_table($db, 'replies', 'CREATE TABLE replies_new(id INTEGER PRIMARY KEY AUTOINCREMENT,topic_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)');
    u_autoincrement_table($db, 'password_resets', "CREATE TABLE password_resets_new(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_group ON users(group_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_forums_sort ON forums(sort,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_replies_topic_time ON replies(topic_id,created_at,id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user ON replies(user_id,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_last_reply ON topics(forum_id,last_reply_at DESC,id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC)");
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE name='pinned_topic_ids'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare("INSERT INTO settings(name,value) VALUES('pinned_topic_ids','')")->execute();
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    u_page('升级失败', $e->getMessage());
}

if (is_file(UPDATE_SETTINGS_CACHE)) {
    @unlink(UPDATE_SETTINGS_CACHE);
}

u_page('升级完成', '数据表升级完成。');
