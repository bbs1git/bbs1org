<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

const UPDATE_SCHEMA_DATA_DIR = __DIR__ . '/data';
const UPDATE_SCHEMA_DB_CONFIG_FILE = UPDATE_SCHEMA_DATA_DIR . '/db.php';
const UPDATE_SCHEMA_DEFAULT_DB_FILE = UPDATE_SCHEMA_DATA_DIR . '/forum.sqlite';
const UPDATE_SCHEMA_LOCK_FILE = UPDATE_SCHEMA_DATA_DIR . '/install.lock';
const UPDATE_SCHEMA_RUN_LOCK_FILE = UPDATE_SCHEMA_DATA_DIR . '/update.lock';
const UPDATE_SCHEMA_INSTALL_FILE = __DIR__ . '/install.php';

function us_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function us_secure_session_start(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function us_page(string $title, array $changes, string $error = ''): void
{
    if (isset($GLOBALS['update_lock_handle']) && is_resource($GLOBALS['update_lock_handle'])) {
        flock($GLOBALS['update_lock_handle'], LOCK_UN);
        fclose($GLOBALS['update_lock_handle']);
        unset($GLOBALS['update_lock_handle']);
    }
    $ok = $error === '';
    $count = count($changes);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . us_h($title) . '</title><link rel="stylesheet" href="index.css"><style>.update-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 12px;background:#f6f7f8}.update-card{width:min(680px,100%);padding:28px;border:1px solid #e8e8e8;border-radius:8px;background:#fff;box-shadow:0 18px 45px rgba(16,24,40,.08)}.update-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}.update-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 42px;font-size:22px;font-weight:700}.update-icon.ok{background:#eefaf3;color:#20a45a}.update-icon.err{background:#fff3f3;color:#d94b4b}.update-title{margin:0;color:#111;font-size:22px;line-height:1.3}.update-sub{margin:5px 0 0;color:#888;font-size:13px}.update-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 12px;padding:12px 14px;border:1px solid #f0f0f0;border-radius:6px;background:#fafafa;color:#555}.update-count{font-size:18px;font-weight:700;color:#111}.update-list{list-style:none;margin:0 0 20px;padding:0;border:1px solid #eee;border-radius:6px;overflow:hidden}.update-list li{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid #f3f3f3;color:#333;font-size:13px}.update-list li:last-child{border-bottom:0}.update-list li:before{content:"";width:7px;height:7px;border-radius:50%;background:#2ecc71;flex:0 0 7px}.update-empty{margin:18px 0 20px;padding:16px;border:1px solid #eef2f0;border-radius:6px;background:#fbfdfc;color:#555}.update-error{margin:18px 0 20px;padding:14px;border:1px solid #ffd8d8;border-radius:6px;background:#fff8f8;color:#b42318;word-break:break-word}.update-actions{display:flex;justify-content:flex-end}.update-actions .install-enter{margin:0}</style></head><body><main class="update-page"><section class="update-card"><div class="update-head"><div class="update-icon ' . ($ok ? 'ok' : 'err') . '">' . ($ok ? '&#10003;' : '!') . '</div><div><h1 class="update-title">' . us_h($title) . '</h1><p class="update-sub">根据 install.php 同步数据库结构和索引，不处理任何数据。</p></div></div>';
    if ($error !== '') {
        echo '<div class="update-error">' . us_h($error) . '</div>';
    } elseif (!$changes) {
        echo '<div class="update-summary"><span>本次结构调整</span><span class="update-count">0</span></div><div class="update-empty">数据库结构和索引已是最新，无需调整。</div>';
    } else {
        echo '<div class="update-summary"><span>本次结构调整</span><span class="update-count">' . $count . '</span></div><ul class="update-list">';
        foreach ($changes as $change) echo '<li>' . us_h($change) . '</li>';
        echo '</ul>';
    }
    echo '<div class="update-actions"><a class="install-enter" href="index.php">进入首页</a></div></section></main></body></html>';
    exit;
}

function us_confirm_page(): void
{
    $token = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>数据库升级</title><link rel="stylesheet" href="index.css"><style>.update-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 12px;background:#f6f7f8}.update-card{width:min(560px,100%);padding:28px;border:1px solid #e8e8e8;border-radius:8px;background:#fff;box-shadow:0 18px 45px rgba(16,24,40,.08)}.update-title{margin:0 0 8px;color:#111;font-size:22px;line-height:1.3}.update-sub{margin:0 0 18px;color:#666;font-size:13px;line-height:1.7}.update-actions{display:flex;justify-content:flex-end;gap:10px}.update-actions a,.update-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border:1px solid #ddd;border-radius:6px;background:#fff;color:#555;font:inherit;text-decoration:none;cursor:pointer}.update-actions button{border-color:#2ecc71;background:#2ecc71;color:#fff}</style></head><body><main class="update-page"><section class="update-card"><h1 class="update-title">数据库升级</h1><p class="update-sub">将根据 install.php 同步数据库结构和索引，不处理任何数据。请先确认代码文件已经更新完成。</p><form method="post"><input type="hidden" name="_csrf" value="' . us_h($token) . '"><div class="update-actions"><a href="index.php">取消</a><button type="submit">执行升级</button></div></form></section></main></body></html>';
    exit;
}

function us_db_file_path(): string
{
    if (is_file(UPDATE_SCHEMA_DB_CONFIG_FILE)) {
        $config = include UPDATE_SCHEMA_DB_CONFIG_FILE;
        $name = is_array($config) ? basename((string)($config['db_file'] ?? '')) : '';
        if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) return UPDATE_SCHEMA_DATA_DIR . '/' . $name;
    }
    return UPDATE_SCHEMA_DEFAULT_DB_FILE;
}

function us_db(): PDO
{
    return new PDO('sqlite:' . us_db_file_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function us_admin_user(): ?array
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) return null;
    $stmt = us_db()->prepare('SELECT u.id,u.group_id,g.allow_admin FROM users u LEFT JOIN groups g ON g.id=u.group_id WHERE u.id=?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function us_need_admin(): void
{
    $user = us_admin_user();
    if (!$user) us_page('请先登录', [], '请先登录管理员账号后再执行升级。');
    if ((int)$user['id'] !== 1 && (int)($user['allow_admin'] ?? 0) !== 1) us_page('无权限', [], '当前账号没有后台管理权限。');
}

function us_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function us_index_exists(PDO $db, string $index): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='index' AND name=?");
    $stmt->execute([$index]);
    return (bool)$stmt->fetchColumn();
}

function us_columns(PDO $db, string $table): array
{
    $columns = [];
    foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        $columns[(string)$row['name']] = true;
    }
    return $columns;
}

function us_split_defs(string $body): array
{
    $defs = [];
    $buf = '';
    $depth = 0;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '(') $depth++;
        if ($ch === ')') $depth--;
        if ($ch === ',' && $depth === 0) {
            $defs[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $defs[] = trim($buf);
    return $defs;
}

function us_parse_table_sql(string $sql): array
{
    if (!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s*\((.*)\)\s*;?\s*$/is', trim($sql), $m)) {
        return [];
    }
    $columns = [];
    foreach (us_split_defs($m[2]) as $def) {
        if (preg_match('/^(PRIMARY|UNIQUE|CHECK|FOREIGN|CONSTRAINT)\b/i', $def)) continue;
        if (preg_match('/^([a-zA-Z0-9_]+)\s+(.+)$/s', $def, $cm)) {
            $columns[$cm[1]] = $def;
        }
    }
    return ['name' => $m[1], 'sql' => rtrim(trim($sql), ';') . ';', 'columns' => $columns];
}

function us_install_schema(): array
{
    $source = (string)file_get_contents(UPDATE_SCHEMA_INSTALL_FILE);
    preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[a-zA-Z0-9_]+\s*\([^;]+?\);/is', $source, $table_matches);
    preg_match_all('/CREATE\s+VIRTUAL\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s+USING\s+fts5\s*\([^;]+?\);/is', $source, $virtual_table_matches, PREG_SET_ORDER);
    preg_match_all('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s+ON\s+[a-zA-Z0-9_]+\s*\([^;]+?\)/is', $source, $index_matches, PREG_SET_ORDER);
    $tables = [];
    foreach ($table_matches[0] as $sql) {
        $table = us_parse_table_sql($sql);
        if ($table) $tables[$table['name']] = $table;
    }
    $virtual_tables = [];
    foreach ($virtual_table_matches as $m) {
        $virtual_tables[$m[1]] = rtrim(trim($m[0]), ';') . ';';
    }
    $indexes = [];
    foreach ($index_matches as $m) {
        $indexes[$m[1]] = rtrim(trim($m[0]), ';') . ';';
    }
    return [$tables, $virtual_tables, $indexes];
}

us_secure_session_start();
if (!is_file(UPDATE_SCHEMA_LOCK_FILE) || !is_file(us_db_file_path())) {
    us_page('请先安装', [], '请先执行安装操作。');
}
if (!is_file(UPDATE_SCHEMA_INSTALL_FILE)) {
    us_page('升级失败', [], 'install.php 不存在。');
}
us_need_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    us_confirm_page();
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    us_page('升级失败', [], '请求已过期，请返回重试。');
}
$lock_handle = fopen(UPDATE_SCHEMA_RUN_LOCK_FILE, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    us_page('升级失败', [], '升级正在执行，请稍后再试。');
}
$GLOBALS['update_lock_handle'] = $lock_handle;

try {
    [$tables, $virtual_tables, $indexes] = us_install_schema();
    if (!$tables) us_page('升级失败', [], '未读取到 install.php 中的数据表结构。');
    $db = us_db();
    $changes = [];
    $db->beginTransaction();
    foreach ($virtual_tables as $table => $sql) {
        if (us_table_exists($db, $table)) continue;
        $db->exec($sql);
        $changes[] = '新增虚拟表：' . $table;
    }
    foreach ($tables as $table => $schema) {
        if (!us_table_exists($db, $table)) {
            $db->exec($schema['sql']);
            $changes[] = '新增表：' . $table;
            continue;
        }
        $current_columns = us_columns($db, $table);
        foreach ($schema['columns'] as $column => $definition) {
            if (isset($current_columns[$column])) continue;
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
            $changes[] = '新增字段：' . $table . '.' . $column;
        }
    }
    foreach ($indexes as $index => $sql) {
        if (us_index_exists($db, $index)) continue;
        $db->exec($sql);
        $changes[] = '新增索引：' . $index;
    }
    $db->commit();
    us_page('升级完成', $changes);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    us_page('升级失败', [], $e->getMessage());
}
