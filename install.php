<?php

declare(strict_types=1);

const INSTALL_FILE = __DIR__ . '/data/forum.sqlite';
const INSTALL_LOCK_FILE = __DIR__ . '/data/install.lock';
const INSTALL_CACHE_DIR = __DIR__ . '/cache';
const INSTALL_FORUM_CACHE_FILE = INSTALL_CACHE_DIR . '/forums.php';
const INSTALL_GROUP_CACHE_FILE = INSTALL_CACHE_DIR . '/groups.php';
const INSTALL_STATS_CACHE_FILE = INSTALL_CACHE_DIR . '/stats.php';
const INSTALL_SETTING_CACHE_FILE = INSTALL_CACHE_DIR . '/settings.php';

function i_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function i_now(): int { return time(); }
function i_db(): PDO
{
    static $db;
    if ($db) return $db;
    $dir = dirname(INSTALL_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new PDO('sqlite:' . INSTALL_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    foreach (['PRAGMA journal_mode=WAL', 'PRAGMA synchronous=NORMAL', 'PRAGMA temp_store=MEMORY', 'PRAGMA busy_timeout=5000', 'PRAGMA foreign_keys=ON'] as $sql) $db->exec($sql);
    return $db;
}
function i_readme_text(): string
{
    $text = is_file(__DIR__ . '/README.md') ? (string)file_get_contents(__DIR__ . '/README.md') : '';
    $text = preg_replace('/\A#.*?\n+/s', '', $text, 1) ?? $text;
    $text = trim($text);
    return $text !== '' ? $text : '欢迎使用 bbs1org。';
}
function i_save_cache(PDO $db): void
{
    if (!is_dir(INSTALL_CACHE_DIR)) mkdir(INSTALL_CACHE_DIR, 0755, true);
    $forums = $db->query("SELECT id,name,description,sort,last_topic_id,last_topic_title FROM forums ORDER BY sort,id")->fetchAll();
    file_put_contents(INSTALL_FORUM_CACHE_FILE, "<?php\nreturn " . var_export($forums, true) . ";\n", LOCK_EX);
    $groups = $db->query("SELECT id,name,is_banned,is_muted,allow_manage,allow_admin FROM groups ORDER BY id")->fetchAll();
    file_put_contents(INSTALL_GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);
    $stats = [
        'topics' => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
        'replies' => (int)$db->query("SELECT COUNT(*) FROM replies")->fetchColumn(),
        'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'latest_users' => $db->query("SELECT id,username,avatar_style,avatar_seed FROM users ORDER BY id DESC LIMIT 8")->fetchAll(),
    ];
    file_put_contents(INSTALL_STATS_CACHE_FILE, "<?php\nreturn " . var_export($stats, true) . ";\n", LOCK_EX);
    $settings = array_column($db->query("SELECT name,value FROM settings")->fetchAll(), 'value', 'name');
    file_put_contents(INSTALL_SETTING_CACHE_FILE, "<?php\nreturn " . var_export($settings, true) . ";\n", LOCK_EX);
}
function i_html(string $title, string $body): void
{
    $meta = '<meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8">';
    echo '<!doctype html><html lang="zh-CN"><head>' . $meta . '<title>' . i_h($title) . '</title><style>
    :root{--bg:#eef2f7;--panel:#fff;--line:#dfe6ee;--line2:#edf1f5;--text:#1f2937;--muted:#6b7280;--brand:#2563eb;--brand2:#1d4ed8;--ok:#059669;--warn:#b45309;--danger:#dc2626;--radius:10px}
    *{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#f8fbff 0,#eef2f7 100%);color:var(--text);font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}
    a{color:var(--brand);text-decoration:none}a:hover{color:var(--brand2)}.wrap{max-width:1060px;margin:0 auto;padding:24px 16px 40px}
    .hero{display:grid;gap:8px;margin-bottom:18px}.hero h1{margin:0;font-size:28px;line-height:1.2}.hero p{margin:0;color:var(--muted)}
    .grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:16px;align-items:start}.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 10px 24px rgba(15,23,42,.05)}
    .card .hd{padding:16px 18px;border-bottom:1px solid var(--line2)}.card .hd h2{margin:0;font-size:16px}.card .bd{padding:16px 18px}
    .note{padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;border-radius:8px}.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
    .form{display:grid;gap:12px}.row{display:grid;gap:6px}.row label{font-size:12px;color:var(--muted)}.row small{color:var(--muted);font-size:11px;line-height:1.4}.row.compact{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}.row.compact .field{display:grid;gap:6px}input[type=text],input[type=password],input[type=email],textarea{width:100%;border:1px solid #d6dbe3;border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:var(--text)}textarea{min-height:128px;resize:vertical}
    input:focus,textarea:focus{outline:0;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.12)}.checks{display:grid;gap:10px}.check{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:#fafcff}.check input{margin-top:3px}
    .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border:0;border-radius:8px;background:var(--brand);color:#fff;cursor:pointer;font:inherit;font-weight:600}.btn:hover{background:var(--brand2);color:#fff}.btn.alt{background:#fff;color:#374151;border:1px solid #d1d5db}.btn.alt:hover{background:#f8fafc;color:#111;border-color:#cbd5e1}
    .list{margin:0;padding-left:18px;color:#374151}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.kv{display:grid;grid-template-columns:120px minmax(0,1fr);gap:8px 12px;font-size:13px}.kv div:nth-child(odd){color:var(--muted)}.admin-pass{padding:14px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;word-break:break-all}.footer{margin-top:16px;color:var(--muted);font-size:12px;text-align:center}
    @media (max-width:860px){.grid{grid-template-columns:1fr}.hero h1{font-size:24px}.wrap{padding:18px 12px 30px}}
    </style></head><body><main class="wrap">' . $body . '</main></body></html>';
    exit;
}
function i_result(string $title, string $admin_user, string $admin_pass, string $admin_email, string $site_name): void
{
    i_html($title, '<div class="hero"><h1>安装完成</h1><p>站点已初始化，管理员信息已保存。</p></div><div class="grid"><section class="card"><div class="hd"><h2>安装结果</h2></div><div class="bd"><div class="note ok">可以直接进入论坛使用，建议立即登录后台修改密码。</div><div style="height:12px"></div><div class="kv"><div>站点名</div><div>' . i_h($site_name) . '</div><div>管理员用户名</div><div class="mono">' . i_h($admin_user) . '</div><div>管理员邮箱</div><div class="mono">' . i_h($admin_email) . '</div><div>管理员密码</div><div class="admin-pass mono">' . i_h($admin_pass) . '</div></div><div style="height:14px"></div><div class="actions"><a class="btn alt" href="index.php">进入首页</a><a class="btn" href="index.php?a=admin">进入后台</a></div></div></section><aside class="card"><div class="hd"><h2>已完成内容</h2></div><div class="bd"><ul class="list"><li>创建 SQLite 数据库</li><li>创建默认版块</li><li>创建第一个管理员</li><li>导入 README 作为首个主题</li><li>生成缓存文件</li><li>管理员密码已保存到 `data/install-admin.json`</li></ul></div></aside></div><div class="footer">请妥善保存管理员密码。</div>');
}
function i_form(string $site_name, string $admin_user, string $admin_email, string $admin_pass, string $default_forum, string $topic_title): void
{
    i_html('安装 bbs1org', '<div class="hero"><h1>安装 bbs1org</h1><p>一页完成初始化，创建管理员、默认版块和首个主题。</p></div><div class="grid"><section class="card"><div class="hd"><h2>安装配置</h2></div><div class="bd"><form class="form" method="post"><input type="hidden" name="step" value="install"><div class="row"><label>站点名称</label><input type="text" name="site_name" value="' . i_h($site_name) . '" placeholder="我的论坛" required></div><div class="row"><label>管理员用户名</label><input type="text" name="admin_username" value="' . i_h($admin_user) . '" placeholder="admin" required></div><div class="row"><label>管理员邮箱</label><input type="email" name="admin_email" value="' . i_h($admin_email) . '" placeholder="name@example.com" required><small>用于找回密码与通知。</small></div><div class="row"><label>管理员密码</label><input type="password" name="admin_password" value="' . i_h($admin_pass) . '" placeholder="请输入密码" required></div><div class="row"><label>确认管理员密码</label><input type="password" name="admin_password2" value="' . i_h($admin_pass) . '" placeholder="再次输入密码" required></div><div class="row"><label>默认版块名称</label><input type="text" name="forum_name" value="' . i_h($default_forum) . '" required></div><div class="row"><label>首个主题标题</label><input type="text" name="topic_title" value="' . i_h($topic_title) . '" required></div><div class="row"><label>首个主题内容</label><textarea name="topic_body" required>' . i_h(i_readme_text()) . '</textarea></div><div class="checks"><label class="check"><input type="checkbox" name="confirm_clean" value="1" required><span>我确认这是全新安装，数据将被清理。</span></label><label class="check"><input type="checkbox" name="confirm_admin" value="1" required><span>我确认需要手工设置第一个管理员密码。</span></label><label class="check"><input type="checkbox" name="confirm_readme" value="1" required><span>我确认将 README 内容作为第一个主题发布。</span></label></div><div class="actions"><button class="btn" type="submit">开始安装</button></div></form></div></section><aside class="card"><div class="hd"><h2>安装说明</h2></div><div class="bd"><ul class="list"><li>会创建默认用户组和默认版块</li><li>第一个管理员将拥有全部权限</li><li>管理员邮箱可用于找回密码</li><li>README 将作为论坛首帖发布</li></ul></div></aside></div>');
}
if (is_file(INSTALL_LOCK_FILE)) {
    $info = is_file(__DIR__ . '/data/install-admin.json') ? json_decode((string)file_get_contents(__DIR__ . '/data/install-admin.json'), true) : null;
    $admin_user = (string)($info['username'] ?? 'admin');
    $admin_pass = (string)($info['password'] ?? '已安装');
    $admin_email = (string)($info['email'] ?? '');
    i_result('已安装', $admin_user, $admin_pass, $admin_email, 'bbs1org');
}
$step = (string)($_POST['step'] ?? '');
if ($step !== 'install') {
    i_form('我的论坛', 'admin', '', '', '默认版块', '欢迎使用 bbs1org');
}
if (!isset($_POST['confirm_clean'], $_POST['confirm_admin'], $_POST['confirm_readme'])) i_form('我的论坛', 'admin', '', '', '默认版块', '欢迎使用 bbs1org');
$site_name = trim((string)($_POST['site_name'] ?? '我的论坛'));
$admin_username = trim((string)($_POST['admin_username'] ?? 'admin'));
$admin_email = trim((string)($_POST['admin_email'] ?? ''));
$admin_password = (string)($_POST['admin_password'] ?? '');
$admin_password2 = (string)($_POST['admin_password2'] ?? '');
$forum_name = trim((string)($_POST['forum_name'] ?? '默认版块'));
$topic_title = trim((string)($_POST['topic_title'] ?? '欢迎使用 bbs1org'));
$topic_body = trim((string)($_POST['topic_body'] ?? ''));
if ($site_name === '' || $admin_username === '' || $admin_email === '' || $admin_password === '' || $forum_name === '' || $topic_title === '' || $topic_body === '') i_form($site_name ?: '我的论坛', $admin_username ?: 'admin', $admin_email, $admin_password, $forum_name ?: '默认版块', $topic_title ?: '欢迎使用 bbs1org');
if ($admin_password !== $admin_password2) i_form($site_name, $admin_username, $admin_email, $admin_password, $forum_name, $topic_title);
if (is_file(INSTALL_LOCK_FILE)) i_html('安装失败', '<div class="hero"><h1>安装失败</h1><p>已完成安装。</p></div><div class="card"><div class="bd"><div class="note warn">请先删除 `data/install.lock` 后再重新安装。</div></div></div>');
$db = i_db();
$db->exec("
CREATE TABLE IF NOT EXISTS groups(id INTEGER PRIMARY KEY,name TEXT NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0,is_banned INTEGER NOT NULL DEFAULT 0,is_muted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,username TEXT NOT NULL UNIQUE,password TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',bio TEXT NOT NULL DEFAULT '',avatar_style TEXT NOT NULL DEFAULT '',avatar_seed TEXT NOT NULL DEFAULT '',group_id INTEGER NOT NULL DEFAULT 2,unread_notifications INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(group_id) REFERENCES groups(id));
CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,recipient_id INTEGER NOT NULL,sender_id INTEGER DEFAULT NULL,kind TEXT NOT NULL DEFAULT 'direct',content TEXT NOT NULL,topic_id INTEGER DEFAULT NULL,reply_id INTEGER DEFAULT NULL,read_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(reply_id) REFERENCES replies(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS forums(id INTEGER PRIMARY KEY,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',sort INTEGER NOT NULL DEFAULT 0,last_topic_id INTEGER NOT NULL DEFAULT 0,last_topic_title TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS topics(id INTEGER PRIMARY KEY,forum_id INTEGER NOT NULL,user_id INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,reply_count INTEGER NOT NULL DEFAULT 0,view_count INTEGER NOT NULL DEFAULT 0,last_reply_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,FOREIGN KEY(forum_id) REFERENCES forums(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS replies(id INTEGER PRIMARY KEY,topic_id INTEGER NOT NULL,user_id INTEGER NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS favorites(user_id INTEGER NOT NULL,topic_id INTEGER NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(user_id,topic_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS password_resets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at INTEGER NOT NULL,used_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS ip_logs(ip TEXT PRIMARY KEY,register_count INTEGER NOT NULL DEFAULT 0,register_at INTEGER NOT NULL DEFAULT 0,login_fail_count INTEGER NOT NULL DEFAULT 0,login_fail_at INTEGER NOT NULL DEFAULT 0,reset_fail_count INTEGER NOT NULL DEFAULT 0,reset_fail_at INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL DEFAULT '');
");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_group ON users(group_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_forums_sort ON forums(sort,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user ON topics(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_topic_time ON replies(topic_id,created_at,id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_replies_user ON replies(user_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_recipient_read ON notifications(recipient_id,read_at,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_sender ON notifications(sender_id,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id,created_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_ip_logs_updated ON ip_logs(updated_at DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_created ON topics(created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_last_reply ON topics(last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_user_updated ON topics(user_id,updated_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_created ON topics(forum_id,created_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_topics_forum_last_reply ON topics(forum_id,last_reply_at DESC,id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_users_created ON users(id DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_created ON favorites(user_id,created_at DESC)");
$db->exec("INSERT OR IGNORE INTO groups(id,name,allow_manage,allow_admin,is_banned,is_muted) VALUES(1,'管理员',1,1,0,0),(2,'会员',0,0,0,0)");
$db->exec("INSERT OR IGNORE INTO forums(id,name,description,sort,last_topic_id,last_topic_title) VALUES(1," . $db->quote($forum_name) . ",'欢迎发帖',0,0,'')");
$settings = [
    'site_name' => $site_name,
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
    'register_per_hour' => '1',
    'login_fail_per_hour' => '5',
    'reset_fail_per_hour' => '5',
];
$stmt = $db->prepare("INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)");
foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
$admin_pass = $admin_password;
$db->prepare("INSERT INTO users(username,password,email,bio,avatar_style,avatar_seed,group_id,created_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$admin_username, password_hash($admin_pass, PASSWORD_DEFAULT), $admin_email, '站点管理员', '', '', 1, i_now()]);
$admin_id = (int)$db->lastInsertId();
$readme = i_readme_text();
$db->prepare("INSERT INTO topics(forum_id,user_id,title,body,reply_count,view_count,last_reply_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([1, $admin_id, $topic_title, $readme, 0, 0, i_now(), i_now(), i_now()]);
$topic_id = (int)$db->lastInsertId();
$db->prepare("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=1")->execute([$topic_id, $topic_title]);
if (!is_dir(INSTALL_CACHE_DIR)) mkdir(INSTALL_CACHE_DIR, 0755, true);
i_save_cache($db);
file_put_contents(__DIR__ . '/data/install-admin.json', json_encode(['username' => $admin_username, 'password' => $admin_pass, 'email' => $admin_email, 'created_at' => i_now(), 'topic_id' => $topic_id], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
file_put_contents(INSTALL_LOCK_FILE, (string)i_now(), LOCK_EX);
i_result('安装完成', $admin_username, $admin_pass, $admin_email, $site_name);
