<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

const UPDATE_VERSION = 'v1.1';
const UPDATE_DATA_DIR = __DIR__ . '/data';
const UPDATE_DB_CONFIG_FILE = UPDATE_DATA_DIR . '/db.php';
const UPDATE_DEFAULT_DB_FILE = UPDATE_DATA_DIR . '/forum.sqlite';
const UPDATE_INSTALL_LOCK_FILE = UPDATE_DATA_DIR . '/install.lock';
const UPDATE_RUN_LOCK_FILE = UPDATE_DATA_DIR . '/update.lock';
const UPDATE_INSTALL_FILE = __DIR__ . '/install.php';
const UPDATE_REPOSITORY = 'bbs1org/bbs1org';
const UPDATE_BRANCH = 'main';
const UPDATE_STATE_FILE = UPDATE_DATA_DIR . '/update-state.json';
const UPDATE_MAX_ARCHIVE_BYTES = 52428800;
const UPDATE_NOTICE_CHECK_INTERVAL = 21600;
const UPDATE_PROTECTED_DIRS = ['data', 'cache', 'avatars', 'upload', 'plugins', '.git'];
const UPDATE_CODE_FILES = ['index.php', 'index.js', 'index.css', 'install.php', 'update.php'];

function us_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function us_secure_session_start(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function us_unlock(): void
{
    if (isset($GLOBALS['update_lock_handle']) && is_resource($GLOBALS['update_lock_handle'])) {
        flock($GLOBALS['update_lock_handle'], LOCK_UN);
        fclose($GLOBALS['update_lock_handle']);
        unset($GLOBALS['update_lock_handle']);
    }
}

function us_styles(): string
{
    return '.update-page{min-height:100vh;padding:28px 12px;background:#f6f7f8}.update-card{width:min(720px,100%);margin:auto;padding:28px;border:1px solid #e8e8e8;border-radius:8px;background:#fff;box-shadow:0 18px 45px rgba(16,24,40,.08)}.update-title{display:flex;align-items:center;gap:9px;margin:0;color:#111;font-size:22px;line-height:1.3}.update-file-version{padding:2px 6px;border:1px solid #dfe4e1;border-radius:4px;background:#f7f9f8;color:#6d7571;font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace}.update-sub{margin:7px 0 20px;color:#777;font-size:13px;line-height:1.7}.update-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:0 0 18px}.update-panel{padding:16px;border:1px solid #eee;border-radius:6px;background:#fafafa}.update-panel strong{display:block;margin-bottom:6px;color:#222}.update-panel span{display:block;color:#777;font-size:13px;line-height:1.6}.update-version{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.update-notice,.update-warning,.update-error{margin:0 0 18px;padding:14px;border:1px solid #dfe8e3;border-radius:6px;background:#f8fcfa;color:#376348;font-size:13px;line-height:1.7;word-break:break-word}.update-warning{border-color:#f3d6a2;background:#fffaf0;color:#8a5a13}.update-error{border-color:#ffd8d8;background:#fff8f8;color:#b42318}.update-list{list-style:none;margin:0 0 20px;padding:0;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff}.update-list li{padding:0;border-bottom:1px solid #edf0f2;color:#444;font-size:13px}.update-list li:last-child{border-bottom:0}.update-list label{display:flex;align-items:center;gap:12px;min-height:48px;padding:10px 14px;cursor:pointer;transition:background .15s ease}.update-list label:hover{background:#f7faf8}.update-list li:has(input:checked){background:#f8fcfa}.update-list input[type=checkbox]{width:18px;height:18px;margin:0;accent-color:#20a45a;cursor:pointer;flex:0 0 18px}.update-file-type{display:inline-flex;align-items:center;justify-content:center;min-width:44px;padding:3px 7px;border-radius:4px;background:#eef8f2;color:#267247;font-size:12px;line-height:1.2}.update-file-path{min-width:0;color:#252b2e;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.update-schema-item{background:#fafafa}.update-schema-copy{display:grid;gap:2px;min-width:0}.update-schema-copy strong{color:#30363a;font-size:13px}.update-schema-copy span{color:#7a8185;font-size:12px;line-height:1.5}.update-result-item{padding:11px 14px!important;line-height:1.6}.update-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.update-actions a,.update-actions button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border:1px solid #ddd;border-radius:6px;background:#fff;color:#555;font:inherit;text-decoration:none;cursor:pointer}.update-actions button.primary{border-color:#2ecc71;background:#2ecc71;color:#fff}.update-actions button:disabled{cursor:not-allowed;opacity:.55}@media(max-width:600px){.update-card{padding:20px}.update-grid{grid-template-columns:1fr}.update-list label{gap:10px;padding:10px 12px}.update-file-type{min-width:40px}.update-actions{justify-content:stretch}.update-actions a,.update-actions button{flex:1}}';
}

function us_render(string $title, string $body): void
{
    us_unlock();
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . us_h($title) . '</title><link rel="stylesheet" href="/index.css?v=v2.0"><style>' . us_styles() . '</style></head><body><main class="update-page"><section class="update-card">' . $body . '</section></main></body></html>';
    exit;
}

function us_result_page(string $title, array $changes, string $error = ''): void
{
    $body = '<h1 class="update-title">' . us_h($title) . '</h1><p class="update-sub">勾选数据库同步时，将根据 install.php 幂等同步数据库结构和索引。</p>';
    if ($error !== '') {
        $body .= '<div class="update-error">' . us_h($error) . '</div>';
    } elseif ($changes) {
        $body .= '<ul class="update-list">';
        foreach ($changes as $change) $body .= '<li class="update-result-item">' . us_h($change) . '</li>';
        $body .= '</ul>';
    } else {
        $body .= '<div class="update-notice">数据库结构和索引已是最新，无需调整。</div>';
    }
    $body .= '<div class="update-actions"><a href="update.php">返回升级页</a><a href="index.php">进入首页</a></div>';
    us_render($title, $body);
}

function us_db_file_path(): string
{
    if (is_file(UPDATE_DB_CONFIG_FILE)) {
        $config = include UPDATE_DB_CONFIG_FILE;
        $name = is_array($config) ? basename((string)($config['db_file'] ?? '')) : '';
        if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) return UPDATE_DATA_DIR . '/' . $name;
    }
    return UPDATE_DEFAULT_DB_FILE;
}

function us_db(): PDO
{
    return new PDO('sqlite:' . us_db_file_path(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function us_need_admin(): void
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) us_result_page('请先登录', [], '请先登录管理员账号后再执行升级。');
    $stmt = us_db()->prepare('SELECT u.id,g.allow_admin FROM users u LEFT JOIN groups g ON g.id=u.group_id WHERE u.id=?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user || ((int)$user['id'] !== 1 && (int)($user['allow_admin'] ?? 0) !== 1)) us_result_page('无权限', [], '当前账号没有后台管理权限。');
}

function us_http(string $url): string
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/vnd.github+json\r\nUser-Agent: bbs1org-updater\r\n",
        'timeout' => 15,
        'follow_location' => 1,
        'max_redirects' => 3,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
    }
    if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException('连接 GitHub 失败（HTTP ' . ($status ?: '未知') . '）。');
    return $body;
}

function us_remote_release(): array
{
    $json = json_decode(us_http('https://api.github.com/repos/' . UPDATE_REPOSITORY . '/commits/' . UPDATE_BRANCH), true, 512, JSON_THROW_ON_ERROR);
    $sha = (string)($json['sha'] ?? '');
    $tree_url = (string)($json['commit']['tree']['url'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $sha) || $tree_url === '') throw new RuntimeException('GitHub 返回的版本信息无效。');
    $tree = json_decode(us_http($tree_url . '?recursive=1'), true, 512, JSON_THROW_ON_ERROR);
    if (!empty($tree['truncated']) || !is_array($tree['tree'] ?? null)) throw new RuntimeException('GitHub 返回的文件清单不完整。');
    $files = [];
    foreach ($tree['tree'] as $item) {
        if (($item['type'] ?? '') !== 'blob') continue;
        $path = (string)($item['path'] ?? '');
        if (in_array($path, UPDATE_CODE_FILES, true)) $files[$path] = (string)($item['sha'] ?? '');
    }
    return [
        'sha' => $sha,
        'short_sha' => substr($sha, 0, 12),
        'date' => (string)($json['commit']['committer']['date'] ?? ''),
        'message' => trim(strtok((string)($json['commit']['message'] ?? ''), "\r\n")),
        'files' => $files,
    ];
}

function us_git_blob_sha(string $file): string
{
    $content = (string)file_get_contents($file);
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function us_local_changes(array $remote_files): array
{
    $changes = [];
    foreach ($remote_files as $path => $sha) {
        $file = __DIR__ . '/' . $path;
        if (!is_file($file)) $changes[] = ['path' => $path, 'type' => '新增'];
        elseif (!hash_equals($sha, us_git_blob_sha($file))) $changes[] = ['path' => $path, 'type' => '更新'];
    }
    foreach ((array)(us_state()['files'] ?? []) as $path) {
        if (is_string($path) && !isset($remote_files[$path]) && !us_protected_path($path) && is_file(__DIR__ . '/' . $path)) {
            $changes[] = ['path' => $path, 'type' => '删除'];
        }
    }
    return $changes;
}

function us_state(): array
{
    if (!is_file(UPDATE_STATE_FILE)) return [];
    $state = json_decode((string)file_get_contents(UPDATE_STATE_FILE), true);
    return is_array($state) ? $state : [];
}

function us_state_write(array $state): void
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $swap = UPDATE_STATE_FILE . '.tmp-' . bin2hex(random_bytes(4));
    if ($json === false || file_put_contents($swap, $json, LOCK_EX) === false || !rename($swap, UPDATE_STATE_FILE)) {
        @unlink($swap);
        throw new RuntimeException('无法更新升级状态。');
    }
}

function us_json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function us_notice_check(): never
{
    $lock = @fopen(UPDATE_RUN_LOCK_FILE, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) us_json(['ok' => 1, 'pending' => 1]);
    try {
        $state = us_state();
        $available = is_array($state['update_notice'] ?? null) || preg_match('/^[a-f0-9]{40}$/', (string)($state['update_notice_sent_sha'] ?? '')) === 1;
        $last_checked = strtotime((string)($state['last_notice_checked_at'] ?? '')) ?: 0;
        if (!$available && $last_checked > time() - UPDATE_NOTICE_CHECK_INTERVAL) {
            us_json(['ok' => 1, 'update_available' => 0, 'cached' => 1]);
        }
        if (!is_array($state['update_notice'] ?? null)) {
            $state['last_notice_checked_at'] = date(DATE_ATOM);
            us_state_write($state);
            $release = us_remote_release();
            $changes = us_local_changes((array)$release['files']);
            $sha = (string)$release['sha'];
            if ($changes && !hash_equals((string)($state['update_notice_sent_sha'] ?? ''), $sha)) {
                $state['update_notice'] = [
                    'sha' => $sha,
                    'message' => (string)($release['message'] ?? ''),
                    'checked_at' => date(DATE_ATOM),
                ];
                us_state_write($state);
                $available = true;
            }
        }
        us_json(['ok' => 1, 'update_available' => $available ? 1 : 0]);
    } catch (Throwable $e) {
        us_json(['ok' => 0, 'message' => $e->getMessage() ?: '检查升级失败']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function us_update_page(?array $release = null, string $error = ''): void
{
    $token = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    $state = us_state();
    $local = isset($state['sha']) ? substr((string)$state['sha'], 0, 12) : '未记录';
    $local_time = ($timestamp = strtotime((string)($state['updated_at'] ?? ''))) !== false ? date('Y-m-d H:i', $timestamp) : '';
    $body = '<h1 class="update-title">系统升级 <span class="update-file-version">' . us_h(UPDATE_VERSION) . '</span></h1><p class="update-sub">检测并安装 ' . us_h(UPDATE_REPOSITORY) . ' 主分支的最新代码，也可单独同步当前代码对应的数据库结构。</p>';
    if ($error !== '') $body .= '<div class="update-error">' . us_h($error) . '</div>';
    if ($release) {
        $changes = us_local_changes($release['files']);
        $remote_time = ($timestamp = strtotime((string)$release['date'])) !== false ? date('Y-m-d H:i', $timestamp) : (string)$release['date'];
        $body .= '<div class="update-grid"><div class="update-panel"><strong>本地记录</strong><span class="update-version">' . us_h($local) . '</span>' . ($local_time !== '' ? '<span>更新时间：' . us_h($local_time) . '</span>' : '') . '</div><div class="update-panel"><strong>远端最新</strong><span class="update-version">' . us_h($release['short_sha']) . '</span><span>最后提交：' . us_h($remote_time) . '</span><span>' . us_h($release['message']) . '</span></div></div>';
        if ($changes) {
            $body .= '<div class="update-notice">检测到 ' . count($changes) . ' 个代码文件需要新增或更新。</div><div class="update-warning"><strong>警告：</strong>勾选文件的本地内容和修改将被 GitHub main 分支版本覆盖。</div><ul class="update-list">';
            foreach ($changes as $change) {
                $path = (string)($change['path'] ?? '');
                $type = (string)($change['type'] ?? '变更');
                $body .= '<li><label><input type="checkbox" name="files[]" value="' . us_h($path) . '" form="online-update-form" checked><span class="update-file-type">' . us_h($type) . '</span><span class="update-file-path">' . us_h($path) . '</span></label></li>';
            }
            $body .= '<li class="update-schema-item"><label><input type="checkbox" name="sync_schema" value="1" form="online-update-form" checked><span class="update-schema-copy"><strong>同步数据库结构</strong><span>文件更新完成后，自动同步缺少的表、字段和索引</span></span></label></li>';
            $body .= '</ul>';
        } else {
            $body .= '<div class="update-notice">当前程序文件已是最新版本。</div>';
        }
    } else {
        $changes = [];
        $body .= '<div class="update-notice">点击“检测更新”连接 GitHub 并逐文件核对当前程序。</div>';
    }
    $body .= '<div class="update-actions"><a href="index.php">返回首页</a><a href="update.php?check=1">检测更新</a>';
    if (!$release || !$changes) $body .= '<form method="post"><input type="hidden" name="_csrf" value="' . us_h($token) . '"><input type="hidden" name="action" value="schema"><button type="submit">同步数据库</button></form>';
    if ($release && $changes) $body .= '<form id="online-update-form" method="post" onsubmit="return confirm(\'确定下载并覆盖已勾选的程序文件？\')"><input type="hidden" name="_csrf" value="' . us_h($token) . '"><input type="hidden" name="action" value="online"><input type="hidden" name="sha" value="' . us_h($release['sha']) . '"><button class="primary" type="submit">在线升级</button></form>';
    $body .= '</div>';
    us_render('系统升级', $body);
}

function us_protected_path(string $path): bool
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) return true;
    $first = explode('/', $path, 2)[0];
    return in_array($first, UPDATE_PROTECTED_DIRS, true);
}

function us_remove_dir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? us_remove_dir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function us_writable_parent(string $path): bool
{
    $parent = dirname($path);
    while (!is_dir($parent) && $parent !== dirname($parent)) $parent = dirname($parent);
    return is_dir($parent) && is_writable($parent);
}

function us_install_files(string $sha, array $remote_files, array $selected): int
{
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) throw new RuntimeException('升级版本无效。');
    $temp = UPDATE_DATA_DIR . '/update-' . bin2hex(random_bytes(6));
    if (!mkdir($temp, 0700, true)) throw new RuntimeException('无法创建升级临时目录。');
    try {
        $files = [];
        foreach ($remote_files as $path => $expected_sha) {
            if (!in_array($path, UPDATE_CODE_FILES, true)) continue;
            if (!in_array($path, $selected, true)) continue;
            $content = us_http('https://raw.githubusercontent.com/' . UPDATE_REPOSITORY . '/' . $sha . '/' . $path);
            if (strlen($content) > UPDATE_MAX_ARCHIVE_BYTES || !hash_equals($expected_sha, sha1('blob ' . strlen($content) . "\0" . $content))) throw new RuntimeException('远端文件校验失败：' . $path);
            $target = $temp . '/' . $path;
            if (file_put_contents($target, $content, LOCK_EX) === false) throw new RuntimeException('无法保存临时文件：' . $path);
            $files[] = $path;
        }
        if (!$files) throw new RuntimeException('请至少选择一个需要升级的文件。');
        $backups = [];
        foreach ($files as $path) {
            $target = __DIR__ . '/' . $path;
            if (!us_writable_parent($target)) throw new RuntimeException('文件所在目录不可写：' . $path);
            $backup = $temp . '/backup/' . $path;
            $existed = is_file($target);
            if ($existed) {
                if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true)) throw new RuntimeException('无法创建备份目录。');
                if (!copy($target, $backup) || !hash_equals((string)hash_file('sha256', $target), (string)hash_file('sha256', $backup))) throw new RuntimeException('备份文件失败：' . $path);
            }
            $backups[$path] = ['file' => $backup, 'existed' => $existed];
        }
        $state_existed = is_file(UPDATE_STATE_FILE);
        $state_backup = $temp . '/update-state.json';
        if ($state_existed && (!copy(UPDATE_STATE_FILE, $state_backup) || !hash_equals((string)hash_file('sha256', UPDATE_STATE_FILE), (string)hash_file('sha256', $state_backup)))) throw new RuntimeException('备份版本记录失败。');
        $replaced = [];
        try {
            foreach ($files as $path) {
                $source = $temp . '/' . $path;
                $target = __DIR__ . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new RuntimeException('无法创建目录：' . dirname($path));
                $swap = $target . '.update-' . bin2hex(random_bytes(4));
                if (!copy($source, $swap) || !rename($swap, $target)) {
                    @unlink($swap);
                    throw new RuntimeException('更新文件失败：' . $path);
                }
                $replaced[] = $path;
                if (!hash_equals((string)$remote_files[$path], us_git_blob_sha($target))) throw new RuntimeException('更新后校验失败：' . $path);
            }
            $state = json_encode(['sha' => $sha, 'updated_at' => date(DATE_ATOM), 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $state_swap = UPDATE_STATE_FILE . '.update-' . bin2hex(random_bytes(4));
            if ($state === false || file_put_contents($state_swap, $state, LOCK_EX) === false || !rename($state_swap, UPDATE_STATE_FILE)) {
                @unlink($state_swap);
                throw new RuntimeException('无法写入版本记录。');
            }
        } catch (Throwable $e) {
            $rollback_errors = [];
            foreach (array_reverse($replaced) as $path) {
                $target = __DIR__ . '/' . $path;
                $backup = $backups[$path];
                if ($backup['existed']) {
                    $swap = $target . '.rollback-' . bin2hex(random_bytes(4));
                    if (!copy($backup['file'], $swap) || !rename($swap, $target)) {
                        @unlink($swap);
                        $rollback_errors[] = $path;
                    }
                } elseif (is_file($target) && !unlink($target)) {
                    $rollback_errors[] = $path;
                }
            }
            if ($state_existed) {
                if (!copy($state_backup, UPDATE_STATE_FILE)) $rollback_errors[] = basename(UPDATE_STATE_FILE);
            } elseif (is_file(UPDATE_STATE_FILE) && !unlink(UPDATE_STATE_FILE)) {
                $rollback_errors[] = basename(UPDATE_STATE_FILE);
            }
            if ($rollback_errors) throw new RuntimeException($e->getMessage() . '；回滚失败：' . implode('、', $rollback_errors), 0, $e);
            throw new RuntimeException($e->getMessage() . '；已恢复升级前文件。', 0, $e);
        }
        $php_updated = (bool)array_filter($files, static fn(string $path): bool => str_ends_with(strtolower($path), '.php'));
        if ($php_updated && function_exists('opcache_reset')) @opcache_reset();
        return count($files);
    } finally {
        us_remove_dir($temp);
    }
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
    foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) $columns[(string)$row['name']] = true;
    return $columns;
}

function us_split_defs(string $body): array
{
    $defs = [];
    $buf = '';
    $depth = 0;
    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
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
    if (!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s*\((.*)\)\s*;?\s*$/is', trim($sql), $m)) return [];
    $columns = [];
    foreach (us_split_defs($m[2]) as $def) {
        if (preg_match('/^(PRIMARY|UNIQUE|CHECK|FOREIGN|CONSTRAINT)\b/i', $def)) continue;
        if (preg_match('/^([a-zA-Z0-9_]+)\s+(.+)$/s', $def, $cm)) $columns[$cm[1]] = $def;
    }
    return ['name' => $m[1], 'sql' => rtrim(trim($sql), ';') . ';', 'columns' => $columns];
}

function us_install_schema(): array
{
    $source = (string)file_get_contents(UPDATE_INSTALL_FILE);
    preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[a-zA-Z0-9_]+\s*\([^;]+?\);/is', $source, $table_matches);
    preg_match_all('/CREATE\s+VIRTUAL\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s+USING\s+fts5\s*\([^;]+?\);/is', $source, $virtual_table_matches, PREG_SET_ORDER);
    preg_match_all('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z0-9_]+)\s+ON\s+[a-zA-Z0-9_]+\s*\([^;]+?\)/is', $source, $index_matches, PREG_SET_ORDER);
    $tables = [];
    foreach ($table_matches[0] as $sql) {
        $table = us_parse_table_sql($sql);
        if ($table) $tables[$table['name']] = $table;
    }
    $virtual_tables = [];
    foreach ($virtual_table_matches as $m) $virtual_tables[$m[1]] = rtrim(trim($m[0]), ';') . ';';
    $indexes = [];
    foreach ($index_matches as $m) $indexes[$m[1]] = rtrim(trim($m[0]), ';') . ';';
    return [$tables, $virtual_tables, $indexes];
}

function us_sync_schema(): array
{
    [$tables, $virtual_tables, $indexes] = us_install_schema();
    if (!$tables) throw new RuntimeException('未读取到 install.php 中的数据表结构。');
    $db = us_db();
    $changes = [];
    try {
        $db->beginTransaction();
        foreach ($virtual_tables as $table => $sql) {
            if (!us_table_exists($db, $table)) {
                $db->exec($sql);
                $changes[] = '新增虚拟表：' . $table;
            }
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
            if (!us_index_exists($db, $index)) {
                $db->exec($sql);
                $changes[] = '新增索引：' . $index;
            }
        }
        $db->commit();
        return $changes;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

us_secure_session_start();
if (!is_file(UPDATE_INSTALL_LOCK_FILE) || !is_file(us_db_file_path())) us_result_page('请先安装', [], '请先执行安装操作。');
if (!is_file(UPDATE_INSTALL_FILE)) us_result_page('升级失败', [], 'install.php 不存在。');
us_need_admin();

if ((string)($_GET['notice_check'] ?? '') === '1') us_notice_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!isset($_GET['check'])) us_update_page();
    try {
        us_update_page(us_remote_release());
    } catch (Throwable $e) {
        us_update_page(null, $e->getMessage());
    }
}
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) us_result_page('升级失败', [], '请求已过期，请返回重试。');

$lock_handle = fopen(UPDATE_RUN_LOCK_FILE, 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) us_result_page('升级失败', [], '升级正在执行，请稍后再试。');
$GLOBALS['update_lock_handle'] = $lock_handle;

try {
    $action = (string)($_POST['action'] ?? 'schema');
    $changes = [];
    if ($action === 'online') {
        $remote = us_remote_release();
        $requested_sha = (string)($_POST['sha'] ?? '');
        if (!hash_equals($remote['sha'], $requested_sha)) throw new RuntimeException('远端版本已变化，请重新检测后再升级。');
        $selected = array_values(array_unique(array_filter((array)($_POST['files'] ?? ''), static fn($path): bool => in_array((string)$path, UPDATE_CODE_FILES, true))));
        if ($selected) {
            $count = us_install_files($remote['sha'], $remote['files'], $selected);
            $changes[] = '程序代码已更新至 ' . $remote['short_sha'] . '（' . $count . ' 个文件）';
        } elseif (!isset($_POST['sync_schema'])) {
            throw new RuntimeException('请至少选择一个需要执行的升级操作。');
        }
    } elseif ($action !== 'schema') {
        throw new RuntimeException('未知升级操作。');
    }
    if ($action === 'schema' || isset($_POST['sync_schema'])) $changes = array_merge($changes, us_sync_schema());
    us_result_page('升级完成', $changes);
} catch (Throwable $e) {
    us_result_page('升级失败', [], $e->getMessage());
}
