<?php

declare(strict_types=1);
session_start();
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
    foreach (
        [
            'PRAGMA journal_mode=WAL',
            'PRAGMA synchronous=NORMAL',
            'PRAGMA temp_store=MEMORY',
            'PRAGMA busy_timeout=5000',
            'PRAGMA foreign_keys=ON',
        ] as $sql
    ) $db->exec($sql);
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
function default_settings(): array
{
    return [
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
    $groups = q("SELECT id,name,is_admin,is_banned,is_muted FROM groups ORDER BY id")->fetchAll();
    if (!is_dir(dirname(GROUP_CACHE_FILE))) mkdir(dirname(GROUP_CACHE_FILE), 0755, true);
    file_put_contents(GROUP_CACHE_FILE, "<?php\nreturn " . var_export($groups, true) . ";\n", LOCK_EX);
    return $groups;
}
function group_by_id(int $id): ?array
{
    foreach (groups_cache() as $g) if ((int)$g['id'] === $id) return $g;
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
function quick_forums_html(): string
{
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">最近浏览版块</div><ul class="quick-links">';
    foreach (recent_forums() as $f) $html .= '<li><a href="?a=forum&id=' . (int)$f['id'] . '">' . h($f['name']) . '</a></li>';
    return $html . '</ul></div></div>';
}
function guest_auth_html(): string
{
    $allow = setting('allow_register', '1') === '1';
    return '<div class="side-auth' . ($allow ? '' : ' single') . '"><a href="?a=login">登录</a>' . ($allow ? '<a href="?a=register">注册</a>' : '') . '</div>';
}
function user_card_html(?array $m = null, bool $reply_button = false, int $fid = 0): string
{
    if (!$m) {
        $m = me();
    }
    if (!$m) return '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big visitor-avatar">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div>' . guest_auth_html() . '</div></div>';
    $html = '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</div><div><div class="user-name">' . h($m['username']) . '</div><div class="user-rank">' . h($m['group_name']) . '</div></div></div></div><div class="user-links"><a href="?a=user&id=' . (int)$m['id'] . '&tab=topics">' . svg_icon('topic') . '我的主题</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=replies">' . svg_icon('reply') . '我的回帖</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=favorites">' . svg_icon('favorite') . '我的收藏</a><a href="?a=profile">' . svg_icon('settings') . '个人设置</a>' . (is_admin() ? '<a href="admin.php">' . svg_icon('admin') . '后台面板</a>' : '') . '</div></div>';
    if (can_speak()) $html .= '<a class="btn-post" href="' . ($reply_button ? '#reply' : '?a=topic_edit' . ($fid ? '&fid=' . $fid : '')) . '">' . ($reply_button ? '回帖' : '+ 发帖') . '</a>';
    return $html . '</div>';
}
function form_shell(string $body, ?array $m = null): string
{
    return '<div class="home-shell"><div class="forum-layout"><div class="forum-main"><div class="main-panel">' . $body . '</div></div><aside class="sidebar">' . user_card_html($m) . '</aside></div></div>';
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
function me(): ?array
{
    static $u;
    if (!uid()) return null;
    if ($u !== null) return $u;
    $u = one("SELECT * FROM users WHERE id=?", [uid()]);
    if (!$u) return null;
    $g = group_by_id((int)$u['group_id']) ?: err('用户组不存在');
    return $u += ['group_name' => $g['name'], 'is_admin' => (int)$g['is_admin'], 'is_banned' => (int)$g['is_banned'], 'is_muted' => (int)$g['is_muted']];
}
function is_admin(): bool
{
    $u = me();
    return $u && (int)$u['is_admin'] === 1;
}
function is_banned(): bool
{
    $u = me();
    return $u && !is_admin() && (int)$u['is_banned'] === 1;
}
function is_muted(): bool
{
    $u = me();
    return $u && !is_admin() && (int)$u['is_muted'] === 1;
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
    if (!is_admin()) err('无权限');
}
function need_site_access(): void
{
    if (is_banned() && ($_GET['a'] ?? '') !== 'logout') err('禁止访问');
    $a = $_GET['a'] ?? 'home';
    if (setting('site_closed') === '1' && !is_admin() && !in_array($a, ['login', 'logout'], true)) err('网站已关闭');
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
function ajax_error(string $m): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => 0, 'message' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}
function go(string $u): never
{
    header("Location: $u");
    exit;
}
function err(string $m): never
{
    page('错误', '<div class="box"><h2>错误</h2><p>' . h($m) . '</p><p><a href="?">返回首页</a></p></div>');
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
        'forum' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5h16v14H4z" stroke="currentColor" stroke-width="2"/><path d="M8 9h8M8 13h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'topic' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4h14v16H5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'view' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>',
        'favorite' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
        'favorite_fill' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/></svg>',
        'settings' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm8.5 3.5-.9-.5c-.3-.2-.4-.6-.3-.9l.8-1.4-1.8-1.8-1.4.8c-.3.2-.7.1-.9-.3l-.5-.9h-2l-.5.9c-.2.4-.6.5-.9.3l-1.4-.8-1.8 1.8.8 1.4c.2.3.1.7-.3.9l-.9.5v2l.9.5c.3.2.4.6.3.9l-.8 1.4 1.8 1.8 1.4-.8c.3-.2.7-.1.9.3l.5.9h2l.5-.9c.2-.4.6-.5.9-.3l1.4.8 1.8-1.8-.8-1.4c-.2-.3-.1-.7.3-.9l.9-.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
        'admin' => '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 7.8 8 9 4.6-1.2 8-4 8-9V6l-8-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 12l2 2 4-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
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
    return '<li class="post-item post-entry' . ($has_title ? ' has-title' : '') . '">' . $title_html . '<div class="post-avatar">' . $avatar . '</div><div class="post-body"><div class="post-head"><a class="post-title" href="?a=user&id=' . (int)$row['user_id'] . '">' . h($row['username']) . '</a>' . $ops . '</div><div class="post-meta"><span>' . human_time($time) . '</span></div></div><div class="post-content">' . h($body) . '</div></li>';
}
function topic_stats_html(int $view_count, int $reply_count): string
{
    $stats = '';
    if ($view_count > 0) $stats .= '<span>' . svg_icon('view') . $view_count . '</span>';
    if ($reply_count > 0) $stats .= '<span>' . svg_icon('reply') . $reply_count . '</span>';
    return $stats ? '<div class="post-content-stats">' . $stats . '</div>' : '';
}
function page(string $title, string $body): void
{
    $settings = settings_cache();
    $site_name = trim((string)$settings['site_name']) ?: 'PHPLite Forum';
    $page_title = $title === '' || $title === $site_name ? $site_name : $title . ' - ' . $site_name;
    $meta = '';
    if ($settings['site_keywords'] !== '') $meta .= '<meta name="keywords" content="' . h($settings['site_keywords']) . '">';
    if ($settings['site_description'] !== '') $meta .= '<meta name="description" content="' . h($settings['site_description']) . '">';
    $q = trim((string)($_GET['q'] ?? ''));
    $active_forum = ($_GET['a'] ?? '') === 'forum' ? id() : 0;
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $meta . '<title>' . h($page_title) . '</title><link rel="stylesheet" href="style.css"></head><body>';
    echo '<div class="top"><div class="bar"><a class="brand" href="index.php">' . h($site_name) . '</a><nav class="forum-nav">';
    foreach (array_slice(forums_cache(), 0, 7) as $f) echo '<a class="forum-link' . ((int)$f['id'] === $active_forum ? ' active' : '') . '" href="index.php?a=forum&id=' . (int)$f['id'] . '">' . h($f['name']) . '</a>';
    echo '</nav><form class="search-form" method="get" action="index.php"><input class="search-input" type="search" name="q" placeholder="搜索主题" value="' . h($q) . '"><button class="search-btn" type="submit" aria-label="搜索"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M9.5 9.5L13 13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></button></form></div></div>';
    echo (string)$settings['header_html'] . '<main class="wrap">' . $body . '</main><footer class="footer">Copyright © 2022 - 2026 All rights Reserved</footer>' . (string)$settings['footer_html'] . '<script>function avatarPickerUrl(p,seed){const s=p?.querySelector("select[name=avatar_style]");return "https://api.dicebear.com/10.x/"+encodeURIComponent(s?.value||"dylan")+"/svg?seed="+encodeURIComponent(seed||p.dataset.seed||"0")}function refreshAvatarPicker(p){const k=p?.querySelector("input[name=avatar_seed]"),v=k?.value||"",i=p?.querySelector(".avatar-picker-preview img");if(i)i.src=avatarPickerUrl(p,v);p?.querySelectorAll(".avatar-option").forEach(b=>{const seed=b.dataset.seed||"",img=b.querySelector("img");if(img)img.src=avatarPickerUrl(p,seed);b.classList.toggle("active",seed===v)})}document.addEventListener("change",e=>{const p=e.target.closest(".avatar-picker");if(p)refreshAvatarPicker(p)});document.addEventListener("click",e=>{const b=e.target.closest(".avatar-option");if(!b)return;const p=b.closest(".avatar-picker"),k=p?.querySelector("input[name=avatar_seed]");if(k){k.value=b.dataset.seed||"";refreshAvatarPicker(p)}});document.addEventListener("submit",async e=>{const f=e.target.closest(".ajax-reply-form");if(!f)return;e.preventDefault();const b=f.querySelector("button"),s=f.querySelector(".reply-status"),l=document.querySelector(".topic-post-list");b.disabled=true;if(s)s.textContent="提交中";try{const r=await fetch(f.action,{method:"POST",body:new FormData(f),headers:{"X-Requested-With":"XMLHttpRequest"}}),d=await r.json();if(!d.ok)throw new Error(d.message||"提交失败");l?.querySelector(".empty-state")?.remove();l?.insertAdjacentHTML("beforeend",d.html);const t=document.querySelector(".post-topic-title"),st=t?.querySelector(".post-content-stats");if(t){if(d.stats_html){if(st)st.outerHTML=d.stats_html;else t.insertAdjacentHTML("beforeend",d.stats_html)}else if(st)st.remove()}f.reset();if(s)s.textContent="已回复"}catch(_){if(s)s.textContent="提交失败"}finally{b.disabled=false}});</script></body></html>';
}
function input(string $label, string $name, $value = '', string $type = 'text'): string
{
    return '<label class="grid"><span>' . h($label) . '</span><input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"></label>';
}
function textarea(string $label, string $name, $value = ''): string
{
    return '<label class="grid"><span>' . h($label) . '</span><textarea name="' . h($name) . '">' . h($value) . '</textarea></label>';
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
    return is_admin() || (uid() && (int)$t['user_id'] === uid());
}
function can_reply(array $r): bool
{
    return is_admin() || (uid() && (int)$r['user_id'] === uid());
}
function refresh_topic_stats(int $tid): void
{
    q("UPDATE topics SET reply_count=(SELECT COUNT(*) FROM replies WHERE topic_id=?),last_reply_at=COALESCE((SELECT MAX(created_at) FROM replies WHERE topic_id=?),0) WHERE id=?", [$tid, $tid, $tid]);
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
    $is = isset($_POST['is_admin']) ? 1 : 0;
    $banned = isset($_POST['is_banned']) ? 1 : 0;
    $muted = isset($_POST['is_muted']) ? 1 : 0;
    id() ? q("UPDATE groups SET name=?,is_admin=?,is_banned=?,is_muted=? WHERE id=?", [$name, $is, $banned, $muted, id()]) : q("INSERT INTO groups(name,is_admin,is_banned,is_muted) VALUES(?,?,?,?)", [$name, $is, $banned, $muted]);
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
        if (!can_topic($t)) err('无权限');
        q("UPDATE topics SET forum_id=?,title=?,body=?,updated_at=? WHERE id=?", [$fid, $title, $body, now(), id()]);
        if ((int)$t['forum_id'] !== $fid) q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [0, '']);
        q("UPDATE forums SET last_topic_id=?,last_topic_title=? WHERE id=?", [id(), $title]);
        forums_cache(true);
        return id();
    }
    q("INSERT INTO topics(forum_id,user_id,title,body,created_at,updated_at) VALUES(?,?,?,?,?,?)", [$fid, uid(), $title, $body, now(), now()]);
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
        if (!can_reply($r)) $ajax ? ajax_error('无权限') : err('无权限');
        q("UPDATE replies SET body=?,updated_at=? WHERE id=?", [$body, now(), id()]);
        return ['topic_id' => (int)$r['topic_id'], 'reply_id' => (int)$r['id']];
    }
    $ts = now();
    q("INSERT INTO replies(topic_id,user_id,body,created_at,updated_at) VALUES(?,?,?,?,?)", [$tid, uid(), $body, $ts, $ts]);
    $rid = (int)db()->lastInsertId();
    q("UPDATE topics SET updated_at=?,reply_count=reply_count+1,last_reply_at=? WHERE id=?", [$ts, $ts, $tid]);
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = one("SELECT id,password FROM users WHERE username=?", [post('username', 40)]);
        if ($u && password_verify((string)$_POST['password'], $u['password'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            go('?');
        }
        err('用户名或密码错误');
    }
    page('登录', '<div class="box form-panel"><h2>登录</h2><form method="post">' . form_token() . input('用户名', 'username') . input('密码', 'password', '', 'password') . '<button>登录</button></form></div>');
}
function register_page(): void
{
    if (setting('allow_register', '1') !== '1') err('注册已关闭');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        save_user(false);
        $_SESSION['uid'] = (int)db()->lastInsertId();
        go('?');
    }
    page('注册', '<div class="box form-panel"><h2>注册</h2><form method="post">' . form_token() . input('用户名', 'username') . input('邮箱', 'email', '', 'email') . input('密码', 'password', '', 'password') . input('确认密码', 'password2', '', 'password') . '<button>注册</button></form></div>');
}
function profile_page(): void
{
    need_login();
    $u = me();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST['id'] = uid();
        save_user(false);
        go('?a=profile');
    }
    page('个人资料', form_shell('<div class="box form-panel"><h2>个人资料</h2><form method="post">' . form_token() . input('用户名', 'username', $u['username']) . input('邮箱', 'email', $u['email'], 'email') . input('新密码', 'password', '', 'password') . input('确认密码', 'password2', '', 'password') . avatar_picker_html($u) . textarea('简介', 'bio', $u['bio']) . '<button>保存</button></form></div>', $u));
}
function user_page(): void
{
    $user = one("SELECT id,username,bio,avatar_style,avatar_seed,group_id FROM users WHERE id=?", [id()]) ?: err('用户不存在');
    $g = group_by_id((int)$user['group_id']) ?: ['name' => '用户'];
    $user['group_name'] = $g['name'];
    topic_index_page(null, $user);
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
    go('?a=topic&id=' . $tid);
}
function topic_index_page(?array $filter_forum = null, ?array $filter_user = null): void
{
    $fid = (int)($filter_forum['id'] ?? 0);
    $profile_uid = (int)($filter_user['id'] ?? 0);
    $own_profile = $profile_uid && uid() === $profile_uid;
    $base = $profile_uid ? '?a=user&id=' . $profile_uid : ($fid ? '?a=forum&id=' . $fid : '?');
    $url = function (string $query) use ($base): string {
        return $base . (str_contains($base, '?') ? '&' : '?') . $query;
    };
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = 30;
    $off = ($p - 1) * $size;
    $profile_tab = $_GET['tab'] ?? 'topics';
    if (!in_array($profile_tab, ['topics', 'replies', 'favorites'], true)) $profile_tab = 'topics';
    $sort = $profile_uid ? 'post' : (($_GET['sort'] ?? 'comment') === 'post' ? 'post' : 'comment');
    $order = $sort === 'post' ? 't.created_at DESC,t.id DESC' : 'COALESCE(NULLIF(t.last_reply_at,0),t.created_at) DESC,t.id DESC';
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
    if ($profile_uid && $profile_tab === 'replies') {
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
    $html = '<div class="home-shell"><div class="forum-layout"><div class="forum-main"><div class="main-panel">';
    if ($profile_uid) {
        $prefix = $own_profile ? '我的' : 'TA的';
        $html .= '<div class="tab-bar"><a class="tab' . ($profile_tab === 'topics' ? ' active' : '') . '" href="' . $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=topics') . '">' . $prefix . '主题</a><a class="tab' . ($profile_tab === 'replies' ? ' active' : '') . '" href="' . $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=replies') . '">' . $prefix . '回帖</a><a class="tab' . ($profile_tab === 'favorites' ? ' active' : '') . '" href="' . $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'tab=favorites') . '">' . $prefix . '收藏</a></div>';
    } else {
        $html .= '<div class="tab-bar"><a class="tab' . ($sort === 'comment' ? ' active' : '') . '" href="' . $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=comment') . '">新评论</a><a class="tab' . ($sort === 'post' ? ' active' : '') . '" href="' . $url(($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . 'sort=post') . '">新帖子</a></div>';
    }
    $html .= '<ul class="post-list">';
    if (!$rows) {
        $empty = $profile_uid ? ($profile_tab === 'replies' ? '暂无回帖' : ($profile_tab === 'favorites' ? '暂无收藏' : '暂无主题')) : '暂无主题';
        $html .= '<li class="empty-state">' . ($q !== '' ? '没有找到匹配的主题' : $empty) . '</li>';
    } else {
        foreach ($rows as $t) {
            $time = (int)($t['my_reply_at'] ?? $t['favorite_at'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
            $forum = forum_by_id((int)$t['forum_id']) ?: ['id' => 0, 'name' => ''];
            $user_link = '<a href="?a=user&id=' . (int)$t['user_id'] . '">' . svg_icon('user') . h($t['username']) . '</a>';
            $forum_link = '<a href="?a=forum&id=' . (int)$forum['id'] . '">' . h($forum['name']) . '</a>';
            $meta = '<span>' . $user_link . '</span><span class="post-forum-meta">' . svg_icon('forum') . $forum_link . '</span><span>' . svg_icon('reply') . (int)$t['reply_count'] . '</span><span>' . human_time($time) . '</span>';
            $html .= '<li class="post-item"><div class="post-avatar">' . avatar_tag((int)$t['user_id'], (string)$t['username'], (string)($t['avatar_style'] ?? ''), '', (string)($t['avatar_seed'] ?? '')) . '</div><div class="post-body"><a class="post-title" href="?a=topic&id=' . (int)$t['id'] . '">' . h($t['title']) . '</a><div class="post-meta">' . $meta . '</div></div><a class="post-tag post-forum-badge" href="?a=forum&id=' . (int)$forum['id'] . '">' . h($forum['name']) . '</a></li>';
        }
    }
    $page_query = ($q !== '' ? 'q=' . rawurlencode($q) . '&' : '') . ($profile_uid ? 'tab=' . $profile_tab : 'sort=' . $sort);
    $html .= '</ul><div class="pagination-bar">' . paginate($total, $p, $size, $url($page_query)) . '</div></div></div>';
    $html .= '<aside class="sidebar"><div class="card sidebar-card user-card">';
    if ($profile_uid) {
        $m = $filter_user;
        $prefix = $own_profile ? '我的' : 'TA的';
        $html .= '<div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</div><div><div class="user-name">' . h($m['username']) . '</div><div class="user-rank">' . h($m['group_name'] ?? '用户') . '</div></div></div></div><div class="user-links"><a href="?a=user&id=' . (int)$m['id'] . '&tab=topics">' . svg_icon('topic') . $prefix . '主题</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=replies">' . svg_icon('reply') . $prefix . '回帖</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=favorites">' . svg_icon('favorite') . $prefix . '收藏</a>' . ($own_profile ? '<a href="?a=profile">' . svg_icon('settings') . '个人设置</a>' . (is_admin() ? '<a href="admin.php">' . svg_icon('admin') . '后台面板</a>' : '') : '') . '</div></div>';
        if (can_speak()) $html .= '<a class="btn-post" href="?a=topic_edit' . ($fid ? '&fid=' . $fid : '') . '">+ 发帖</a>';
    } elseif (uid()) {
        $m = me();
        $html .= '<div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</div><div><div class="user-name">' . h($m['username']) . '</div><div class="user-rank">' . h($m['group_name']) . '</div></div></div></div><div class="user-links"><a href="?a=user&id=' . (int)$m['id'] . '&tab=topics">' . svg_icon('topic') . '我的主题</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=replies">' . svg_icon('reply') . '我的回帖</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=favorites">' . svg_icon('favorite') . '我的收藏</a><a href="?a=profile">' . svg_icon('settings') . '个人设置</a>' . (is_admin() ? '<a href="admin.php">' . svg_icon('admin') . '后台面板</a>' : '') . '</div></div>';
        if (can_speak()) $html .= '<a class="btn-post" href="?a=topic_edit' . ($fid ? '&fid=' . $fid : '') . '">+ 发帖</a>';
    } else {
        $html .= '<div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div>' . guest_auth_html() . '</div>';
    }
    $html .= '</div>';
    if ($profile_uid && trim((string)($filter_user['bio'] ?? '')) !== '') {
        $html .= '<div class="card sidebar-card bio-card"><div class="quick-wrap"><div class="quick-title">个人简介</div><div class="sidebar-bio">' . h($filter_user['bio']) . '</div></div></div>';
    }
    if (!$profile_uid) {
        $html .= quick_forums_html() . '<div class="card sidebar-card stats-card"><div class="stats-wrap"><div class="stats-title">站点统计</div><div class="stats-sub">主题 ' . (int)$stats['topics'] . ' · 回复 ' . (int)$stats['replies'] . ' · 用户 ' . (int)$stats['users'] . '</div><div class="new-users-title">最新用户</div><div class="new-users">';
        foreach (($stats['latest_users'] ?? []) as $u) $html .= '<a class="nu-item" href="?a=user&id=' . (int)$u['id'] . '"><div class="nu-avatar-circle">' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), '', (string)($u['avatar_seed'] ?? '')) . '</div><span class="nu-name">' . h($u['username']) . '</span></a>';
        $html .= '</div></div></div>';
    }
    $html .= '</aside></div></div>';
    page($profile_uid ? $filter_user['username'] : ($filter_forum ? $filter_forum['name'] : '首页'), $html);
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
    $t = one("SELECT t.*,u.username,u.avatar_style,u.avatar_seed FROM topics t JOIN users u ON u.id=t.user_id WHERE t.id=?", [id()]) ?: err('主题不存在');
    remember_forum((int)$t['forum_id']);
    q("UPDATE topics SET view_count=view_count+1 WHERE id=?", [(int)$t['id']]);
    $t['view_count'] = (int)$t['view_count'] + 1;
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = 50;
    $off = ($p - 1) * $size;
    $replies = q("SELECT r.*,u.username,u.avatar_style,u.avatar_seed FROM replies r JOIN users u ON u.id=r.user_id WHERE r.topic_id=? ORDER BY r.created_at,r.id LIMIT ? OFFSET ?", [(int)$t['id'], $size, $off])->fetchAll();
    $fav = uid() ? one("SELECT 1 FROM favorites WHERE user_id=? AND topic_id=?", [uid(), (int)$t['id']]) : null;
    $topic_ops = '';
    if (uid()) $topic_ops .= '<a class="fav-btn' . ($fav ? ' active' : '') . '" href="?a=favorite&id=' . (int)$t['id'] . '" title="' . ($fav ? '已收藏' : '收藏') . '" aria-label="' . ($fav ? '已收藏' : '收藏') . '">' . svg_icon($fav ? 'favorite_fill' : 'favorite') . '<span>' . ($fav ? '已收藏' : '收藏') . '</span></a>';
    if (can_topic($t)) $topic_ops .= '<a class="icon-action icon-edit" href="?a=topic_edit&id=' . (int)$t['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="?a=delete&type=topics&id=' . (int)$t['id'] . '&back=home" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a>';
    $html = '<div class="home-shell"><div class="forum-layout"><div class="forum-main"><div class="main-panel"><ul class="post-list topic-post-list">';
    $html .= topic_post_row($t, $t['body'], (int)$t['created_at'], $topic_ops ? '<div class="post-ops">' . $topic_ops . '</div>' : '', $t['title'], topic_stats_html((int)$t['view_count'], (int)$t['reply_count']));
    foreach ($replies as $r) {
        $reply_ops = can_reply($r) ? '<div class="post-ops"><a class="icon-action icon-edit" href="?a=reply_edit&id=' . (int)$r['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="?a=delete&type=replies&id=' . (int)$r['id'] . '&back=topic&tid=' . (int)$t['id'] . '" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a></div>' : '';
        $html .= topic_post_row($r, $r['body'], (int)$r['created_at'], $reply_ops);
    }
    if (!$replies && (int)$t['reply_count'] === 0) $html .= '<li class="empty-state">暂无回复</li>';
    $html .= '</ul><div class="pagination-bar">' . paginate((int)$t['reply_count'], $p, $size, '?a=topic&id=' . (int)$t['id']) . '</div>';
    if (can_speak()) $html .= '<div class="reply-panel" id="reply"><div class="reply-panel-head"><h3>发表回复</h3><span class="reply-status">说两句</span></div><form class="ajax-reply-form" method="post" action="?a=reply_edit">' . form_token() . '<input type="hidden" name="topic_id" value="' . (int)$t['id'] . '">' . textarea('内容', 'body') . '<button>回复</button></form></div>';
    $html .= '</div></div><aside class="sidebar"><div class="card sidebar-card user-card">';
    if (uid()) {
        $m = me();
        $html .= '<div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">' . avatar_tag((int)$m['id'], (string)$m['username'], (string)($m['avatar_style'] ?? ''), '', (string)($m['avatar_seed'] ?? '')) . '</div><div><div class="user-name">' . h($m['username']) . '</div><div class="user-rank">' . h($m['group_name']) . '</div></div></div></div><div class="user-links"><a href="?a=user&id=' . (int)$m['id'] . '&tab=topics">' . svg_icon('topic') . '我的主题</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=replies">' . svg_icon('reply') . '我的回帖</a><a href="?a=user&id=' . (int)$m['id'] . '&tab=favorites">' . svg_icon('favorite') . '我的收藏</a><a href="?a=profile">' . svg_icon('settings') . '个人设置</a>' . (is_admin() ? '<a href="admin.php">' . svg_icon('admin') . '后台面板</a>' : '') . '</div></div>';
        if (can_speak()) $html .= '<a class="btn-post" href="#reply">回帖</a>';
    } else {
        $html .= '<div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div>' . guest_auth_html() . '</div>';
    }
    $stats = stats_cache();
    $html .= '</div>' . quick_forums_html() . '<div class="card sidebar-card stats-card"><div class="stats-wrap"><div class="stats-title">站点统计</div><div class="stats-sub">主题 ' . (int)$stats['topics'] . ' · 回复 ' . (int)$stats['replies'] . ' · 用户 ' . (int)$stats['users'] . '</div><div class="new-users-title">最新用户</div><div class="new-users">';
    foreach (($stats['latest_users'] ?? []) as $u) $html .= '<a class="nu-item" href="?a=user&id=' . (int)$u['id'] . '"><div class="nu-avatar-circle">' . avatar_tag((int)$u['id'], (string)$u['username'], (string)($u['avatar_style'] ?? ''), '', (string)($u['avatar_seed'] ?? '')) . '</div><span class="nu-name">' . h($u['username']) . '</span></a>';
    $html .= '</div></div></div></aside></div></div>';
    page($t['title'], $html);
}
function topic_edit_page(): void
{
    need_speak();
    $t = ['id' => 0, 'forum_id' => id('fid') ?: 1, 'title' => '', 'body' => '', 'user_id' => uid()];
    if (id()) {
        $t = one("SELECT * FROM topics WHERE id=?", [id()]) ?: err('主题不存在');
        if (!can_topic($t)) err('无权限');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') go('?a=topic&id=' . save_topic());
    $title = id() ? '编辑主题' : '发表主题';
    page($title, form_shell('<div class="box form-panel topic-form-panel"><h2>' . $title . '</h2><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '">' . select_forum((int)$t['forum_id']) . input('标题', 'title', $t['title']) . textarea('内容', 'body', $t['body']) . '<button>保存</button></form></div>'));
}
function reply_edit_page(): void
{
    need_speak();
    $r = ['id' => 0, 'topic_id' => id('topic_id'), 'body' => '', 'user_id' => uid()];
    if (id()) {
        $r = one("SELECT * FROM replies WHERE id=?", [id()]) ?: err('回复不存在');
        if (!can_reply($r)) err('无权限');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $saved = save_reply();
        if (ajax_request()) {
            $row = one("SELECT r.*,u.username,u.avatar_style,u.avatar_seed FROM replies r JOIN users u ON u.id=r.user_id WHERE r.id=?", [$saved['reply_id']]) ?: err('回复不存在');
            $ops = '<div class="post-ops"><a class="icon-action icon-edit" href="?a=reply_edit&id=' . (int)$row['id'] . '" title="编辑"><span>编辑</span></a><a class="icon-action icon-delete" href="?a=delete&type=replies&id=' . (int)$row['id'] . '&back=topic&tid=' . (int)$saved['topic_id'] . '" onclick="return confirm(\'确定删除？\')" title="删除"><span>删除</span></a></div>';
            $topic = one("SELECT view_count,reply_count FROM topics WHERE id=?", [$saved['topic_id']]) ?: ['view_count' => 0, 'reply_count' => 0];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'html' => topic_post_row($row, $row['body'], (int)$row['created_at'], $ops), 'stats_html' => topic_stats_html((int)$topic['view_count'], (int)$topic['reply_count'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        go('?a=topic&id=' . $saved['topic_id']);
    }
    page('编辑回复', form_shell('<div class="box form-panel"><h2>编辑回复</h2><form method="post">' . form_token() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="topic_id" value="' . (int)$r['topic_id'] . '">' . textarea('内容', 'body', $r['body']) . '<button>保存</button></form></div>'));
}
