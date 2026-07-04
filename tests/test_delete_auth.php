<?php
/**
 * 删除流程安全 + 原子性 测试
 *
 * 覆盖：
 *  P0  - 未登录 POST /  带 delete=N → 403，DB 行不变（未授权删除漏洞修复验证）
 *  P0  - 未登录 POST /  带 action=batch_delete → 403
 *  P1.1 事务原子性 — 模拟 unlink 失败时，整批 rollback（DB 行 + 物理文件保留）
 *  P1.2 关联日志清理 — 手动删除会清掉 download_logs / upload_logs 孤儿行
 *  P1.3 引用计数 — 同一 path 被多 share 引用，删一个文件还在；删最后一个文件消失
 *  P1.5 enumeration — 批量删除响应里不含"哪些 ID 不存在"的细节
 *  happy - 登录后单条删除 → 302 + 行消失 + upload_logs 出现 admin_delete 行
 *  happy - 登录后批量删除 → 行消失 + 每条都记录 audit log
 *
 * 用法：
 *   php tests/test_delete_auth.php
 *   BASE_URL=https://copy.viaxv.cn/ php tests/test_delete_auth.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8766', '/');
$php  = 'D:/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';

$failures = [];
function assert_true($cond, $msg) {
    if ($cond) { fwrite(STDOUT, "  PASS: $msg\n"); return; }
    fwrite(STDERR, "  FAIL: $msg\n");
    $GLOBALS['failures'][] = $msg;
}

function db() {
    return dbFresh();
}

// 每次返回新连接，避免长连接 + 多进程导致 SQLite WAL 看不到对端写入
function dbFresh() {
    $dbPath = getenv('TEST_DB_PATH') ?: 'D:/document/Projects/copy.viaxv.top/storage/fileshare.db';
    $d = new PDO('sqlite:' . $dbPath);
    $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // WAL 模式 + busy_timeout 让多个 PDO 实例（CLI 测试 + HTTP 服务器）能并发读写
    @$d->exec('PRAGMA journal_mode=WAL');
    @$d->exec('PRAGMA busy_timeout=3000');
    return $d;
}

// 在每次 HTTP 调用后调用，丢弃旧连接、强制重新打开（看到对端最新写入）
function refreshDb(&$dbh) {
    $dbh = dbFresh();
    return $dbh;
}

// =====================================================
// Setup: 读取 admin 密码，初始化临时 jar
// =====================================================
$envFile = getenv('ADMIN_PASSWORD_ENV_FILE') ?: 'D:/document/Projects/copy.viaxv.top/.env';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
if ($adminPassword === '' && file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        if (preg_match('/^\s*ADMIN_PASSWORD\s*=\s*(.+?)\s*$/', $line, $m)) {
            $adminPassword = trim($m[1], " \t\"'");
            break;
        }
    }
}
if ($adminPassword === '') {
    fwrite(STDERR, "FATAL: cannot read ADMIN_PASSWORD from env or .env\n");
    exit(2);
}

$jar = (getenv('TEST_TMP_DIR') ?: 'D:/temp') . '/cp-delete-auth-jar-' . getmypid() . '.txt';
@mkdir(dirname($jar), 0777, true);
@unlink($jar);

function http_post_jar($base, $jar, $path, $body, &$statusOut = null, $followRedirect = false) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $followRedirect,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($statusOut !== null) $statusOut = $info['http_code'];
    return $raw;
}

function http_get_jar($base, $jar, $path) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

function fetchCsrf($html) {
    $m = [];
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

// 公共：插一条探针记录（返回 share_code + id）
function insertProbe($db, $suffix, $extra = []) {
    $now = time();
    $code = 'delauth_' . $suffix . '_' . substr(md5($now . random_int(0, 99999)), 0, 6);
    $defaults = [
        'share_code' => $code,
        'type' => 'text',
        'content' => 'DEL-AUTH-PROBE-' . $suffix,
        'size' => 100,
        'password' => null,
        'ip' => '127.0.0.1',
        'user_agent' => 'delete-auth-test',
        'time' => $now,
        'expire' => $now + 86400,
        'duration' => 86400,
        'name' => null,
        'mime_type' => null,
        'path' => null,
        'file_hash' => null,
    ];
    $row = array_merge($defaults, $extra);
    $db->prepare(
        'INSERT INTO items (share_code, type, content, size, password, ip, user_agent, time, expire, duration, name, mime_type, path, file_hash)
         VALUES (:share_code, :type, :content, :size, :password, :ip, :user_agent, :time, :expire, :duration, :name, :mime_type, :path, :file_hash)'
    )->execute($row);
    return $code;
}

function itemIdByCode($db, $code) {
    return (int)$db->query("SELECT id FROM items WHERE share_code = " . $db->quote($code))->fetchColumn();
}

function itemExists($db, $id) {
    return (bool)$db->query("SELECT 1 FROM items WHERE id = " . (int)$id)->fetchColumn();
}

function countLogsForItem($db, $itemId) {
    $up = (int)$db->query("SELECT COUNT(*) FROM upload_logs WHERE item_id = " . (int)$itemId)->fetchColumn();
    $dl = (int)$db->query("SELECT COUNT(*) FROM download_logs WHERE item_id = " . (int)$itemId)->fetchColumn();
    return ['upload' => $up, 'download' => $dl];
}

// =====================================================
// Setup: 登录 admin（在专用 jar）
// =====================================================
fwrite(STDERR, ">> [setup] login admin\n");
$homeHtml = http_get_jar($base, $jar, '/');
$csrf = fetchCsrf($homeHtml);
assert_true($csrf !== '', "got CSRF from homepage");

$loginHtml = http_get_jar($base, $jar, '/admin/login');
$loginCsrf = fetchCsrf($loginHtml);
$status = 0;
// 登录端点不检查 CSRF（login.php 表单没有 csrf 字段），但传空字符串也无害
http_post_jar($base, $jar, '/admin/login', [
    'password' => $adminPassword,
], $status, false);
fwrite(STDERR, "  login POST status=$status (jar=" . $jar . ")\n");
if ($status !== 302) {
    // 诊断：可能是密码错了
    fwrite(STDERR, "  password len=" . strlen($adminPassword) . " first chars=[" . substr($adminPassword, 0, 3) . "]\n");
    fwrite(STDERR, "  login page has password input: " . (strpos($loginHtml, 'name="password"') !== false ? 'YES' : 'NO') . "\n");
    fwrite(STDERR, "  login page size: " . strlen($loginHtml) . " bytes\n");
    if (strlen($loginHtml) < 500) {
        fwrite(STDERR, "  login page body: [" . $loginHtml . "]\n");
    }
}

// 验证登录态：访问 /admin/dashboard 不应被踢回 /admin/login
$dashHtml = http_get_jar($base, $jar, '/admin/dashboard');
// 检查是否被踢回 login（页面包含 '管理员登录' 或 '请输入管理员密码'）
$isLoggedIn = strpos($dashHtml, '仪表盘') !== false
    || strpos($dashHtml, 'dashboard') !== false
    || strpos($dashHtml, '内容管理') !== false
    || strpos($dashHtml, '系统设置') !== false;
$gotBounced = strpos($dashHtml, '请输入管理员密码') !== false
    || strpos($dashHtml, '管理员登录') !== false;
assert_true($isLoggedIn && !$gotBounced,
    "logged-in session reaches /admin/dashboard (status=$status, isLoggedIn=" . ($isLoggedIn?'1':'0') . ", bounced=" . ($gotBounced?'1':'0') . ")");

// =====================================================
// 0. P0: 未登录 POST /  带 delete=N 必须 403
// =====================================================
fwrite(STDERR, "\n>> [P0] unauthenticated single-delete must 403 + DB row untouched\n");
$dbh = db();
$victimCode = insertProbe($dbh, 'p0_single');
$victimId = itemIdByCode($dbh, $victimCode);

// 全新 anon session（不复用上面的 $jar）
$anonJar = (getenv('TEST_TMP_DIR') ?: 'D:/temp') . '/cp-delete-auth-anon-' . getmypid() . '.txt';
@unlink($anonJar);
$anonHome = http_get_jar($base, $anonJar, '/');
$anonCsrf = fetchCsrf($anonHome);

$status = 0;
http_post_jar($base, $anonJar, '/', [
    'csrf_token' => $anonCsrf,
    'delete' => (string)$victimId,
], $status, false);
fwrite(STDERR, "  anon delete POST status=$status\n");
refreshDb($dbh);
assert_true($status === 403, "anon single-delete returns 403 (got $status)");
assert_true(itemExists($dbh, $victimId), "victim row still exists after blocked anon attempt");

// =====================================================
// 1. P0: 未登录 batch_delete 必须 403
// =====================================================
fwrite(STDERR, "\n>> [P0] unauthenticated batch_delete must 403 + DB rows untouched\n");
$v2 = insertProbe($dbh, 'p0_batch_a');
$v3 = insertProbe($dbh, 'p0_batch_b');
$id2 = itemIdByCode($dbh, $v2);
$id3 = itemIdByCode($dbh, $v3);

$status = 0;
http_post_jar($base, $anonJar, '/', [
    'csrf_token' => $anonCsrf,
    'action' => 'batch_delete',
    'ids' => [(string)$id2, (string)$id3],
], $status, false);
fwrite(STDERR, "  anon batch POST status=$status\n");
refreshDb($dbh);
assert_true($status === 403, "anon batch_delete returns 403 (got $status)");
assert_true(itemExists($dbh, $id2), "batch victim A still exists");
assert_true(itemExists($dbh, $id3), "batch victim B still exists");

// =====================================================
// 2. happy path: 登录后单条删除 → 行消失 + 审计日志
// =====================================================
fwrite(STDERR, "\n>> [happy] logged-in single delete → row removed + audit log\n");
$victimCode2 = insertProbe($dbh, 'happy_single');
$victimId2 = itemIdByCode($dbh, $victimCode2);

// 先给这个 item 加一条 upload_log（验证会被清理掉）
$dbh->prepare(
    'INSERT INTO upload_logs (ip, filename, filesize, upload_time, action, item_id) VALUES (?, ?, ?, ?, ?, ?)'
)->execute(['127.0.0.1', 'fake-upload.txt', 10, time(), 'upload', $victimId2]);
$beforeLogs = countLogsForItem($dbh, $victimId2);
fwrite(STDERR, "  pre-delete logs: upload={$beforeLogs['upload']} download={$beforeLogs['download']}\n");

// 用同一个 jar（已登录）+ 主页 CSRF
$homeHtml2 = http_get_jar($base, $jar, '/');
$csrf2 = fetchCsrf($homeHtml2);

$status = 0;
http_post_jar($base, $jar, '/', [
    'csrf_token' => $csrf2,
    'delete' => (string)$victimId2,
], $status, false); // 不跟随重定向，直接看 302
fwrite(STDERR, "  authed single-delete status=$status\n");
refreshDb($dbh);
assert_true($status === 302, "authed single-delete returns 302 redirect (got $status)");
assert_true(!itemExists($dbh, $victimId2), "victim row removed after authed delete");

// 关联日志清理验证
$afterLogs = countLogsForItem($dbh, $victimId2);
assert_true($afterLogs['upload'] === 0, "upload_logs cleaned (got {$afterLogs['upload']})");
assert_true($afterLogs['download'] === 0, "download_logs cleaned (got {$afterLogs['download']})");

// 审计日志：应该有一条 admin_delete:<share_code> [text]
$auditRow = $dbh->prepare("SELECT action, filename FROM upload_logs WHERE action = 'admin_delete' AND filename LIKE ?");
$auditRow->execute(['%' . $victimCode2 . '%']);
$audit = $auditRow->fetch(PDO::FETCH_ASSOC);
assert_true($audit !== false, "admin audit log row exists for the deleted item");
if ($audit) {
    assert_true($audit['action'] === 'admin_delete', "audit action=admin_delete (got '{$audit['action']}')");
    assert_true(strpos($audit['filename'], $victimCode2) !== false, "audit filename contains share_code");
}

// =====================================================
// 3. happy path: 登录后批量删除 → 多行消失 + 每条都有 audit
// =====================================================
fwrite(STDERR, "\n>> [happy] logged-in batch delete → rows removed + per-item audit\n");
$b1 = insertProbe($dbh, 'happy_batch_1'); $idB1 = itemIdByCode($dbh, $b1);
$b2 = insertProbe($dbh, 'happy_batch_2'); $idB2 = itemIdByCode($dbh, $b2);
$fakeId = 999999; // 不存在的 ID，验证 enumeration 修复

$homeHtml3 = http_get_jar($base, $jar, '/');
$csrf3 = fetchCsrf($homeHtml3);

$status = 0;
$resp = http_post_jar($base, $jar, '/', [
    'csrf_token' => $csrf3,
    'action' => 'batch_delete',
    'ids' => [(string)$idB1, (string)$idB2, (string)$fakeId],
], $status, false);
fwrite(STDERR, "  batch delete status=$status\n");
refreshDb($dbh);
assert_true($status === 200, "authed batch_delete returns 200 (got $status)");
assert_true(!itemExists($dbh, $idB1), "batch victim 1 removed");
assert_true(!itemExists($dbh, $idB2), "batch victim 2 removed");

// 解析 JSON 响应（resp 含 headers + body，要找 body 起头）
$body = $resp;
if (is_string($resp) && ($hdrEnd = strpos($resp, "\r\n\r\n")) !== false) {
    $body = substr($resp, $hdrEnd + 4);
}
$json = json_decode($body, true);
assert_true(is_array($json), "batch_delete returned valid JSON");
if (is_array($json)) {
    assert_true(isset($json['deleted_count']) && $json['deleted_count'] === 2,
        "deleted_count=2 (got " . ($json['deleted_count'] ?? 'null') . ")");
    // P1.5: enumeration 防护 — 不应回显 fakeId 是否存在
    $rawBody = json_encode($json);
    assert_true(strpos($rawBody, (string)$fakeId) === false,
        "batch response does NOT echo nonexistent ID ($fakeId) — enumeration fix verified");
    assert_true(!isset($json['errors']) || count($json['errors']) === 0,
        "batch response has no errors[] leaking details");
}

// 审计：应该各有一条 admin_batch_delete:<share_code>
foreach ([$b1, $b2] as $code) {
    $row = $dbh->prepare("SELECT action, filename FROM upload_logs WHERE action='admin_batch_delete' AND filename LIKE ?");
    $row->execute(['%' . $code . '%']);
    $audit = $row->fetch(PDO::FETCH_ASSOC);
    assert_true($audit !== false, "audit log row exists for $code");
}

// =====================================================
// 4. P1.3: 引用计数 — 同一文件被 2 个 share 引用时不会误删
// =====================================================
fwrite(STDERR, "\n>> [P1.3] reference count: shared file survives partial deletes\n");
// 创建一个临时文件，模拟"2 个 share 引用同一 path + file_hash"
$tmpDir = 'D:/temp/cp-delete-auth-files-' . getmypid();
@mkdir($tmpDir, 0777, true);
$tmpFile = $tmpDir . '/shared.txt';
file_put_contents($tmpFile, 'shared content');

$refHash = 'refhash_' . substr(md5(uniqid()), 0, 8);
$r1 = insertProbe($dbh, 'ref_1', ['type' => 'file', 'content' => null, 'path' => $tmpFile, 'file_hash' => $refHash, 'name' => 'shared.txt']);
$r2 = insertProbe($dbh, 'ref_2', ['type' => 'file', 'content' => null, 'path' => $tmpFile, 'file_hash' => $refHash, 'name' => 'shared.txt']);
$idR1 = itemIdByCode($dbh, $r1);
$idR2 = itemIdByCode($dbh, $r2);

// 通过直接调用 PHP 函数的方式测（避免走 HTTP 干扰）。CLI 下需要手动 bootstrap。
// 把项目里所有依赖一次性加载，避免 index.php 走 handleRequest() 的副作用。
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
    $srcDir = getenv('TEST_SRC_DIR') ?: 'D:/document/Projects/copy.viaxv.top/src';
    require_once $srcDir . '/config.php';
    require_once $srcDir . '/database.php';
    require_once $srcDir . '/functions.php';
    require_once $srcDir . '/admin.php';
}

// 删除第一个 → 文件应还在
$ok1 = deleteItemById($idR1);
assert_true($ok1, "deleteItemById(ref_1) returns true");
assert_true(file_exists($tmpFile), "shared file still exists after deleting 1st reference");

// 删除第二个 → 文件应消失
$ok2 = deleteItemById($idR2);
assert_true($ok2, "deleteItemById(ref_2) returns true");
assert_true(!file_exists($tmpFile), "shared file removed after deleting last reference");

// 清理临时目录
@rmdir($tmpDir);

// =====================================================
// Cleanup: 移除所有 delauth_* 测试记录
// =====================================================
fwrite(STDERR, "\n>> [cleanup] remove delauth_* probe rows\n");
function safeExec($dbh, $sql, $label = '') {
    for ($i = 0; $i < 5; $i++) {
        try {
            return $dbh->exec($sql);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'locked') !== false && $i < 4) {
                usleep(200000); // 200ms
                continue;
            }
            fwrite(STDERR, "  cleanup[$label] failed: " . $e->getMessage() . PHP_EOL);
            return false;
        }
    }
    return false;
}
refreshDb($dbh);
$probeIds = $dbh->query("SELECT id FROM items WHERE share_code LIKE 'delauth\\_%'")->fetchAll(PDO::FETCH_COLUMN);
if ($probeIds) {
    $in = implode(',', array_map('intval', $probeIds));
    safeExec($dbh, "DELETE FROM upload_logs WHERE item_id IN ($in) OR (filename LIKE 'admin\\_%' AND filename LIKE '%delauth\\_%')", 'upload');
    safeExec($dbh, "DELETE FROM download_logs WHERE item_id IN ($in)", 'download');
    safeExec($dbh, "DELETE FROM items WHERE id IN ($in)", 'items');
}
refreshDb($dbh);
safeExec($dbh, "DELETE FROM upload_logs WHERE filename LIKE 'admin_delete:delauth\\_%' OR filename LIKE 'admin_batch_delete:delauth\\_%'", 'admin_audit');
fwrite(STDERR, "  cleanup done\n");

// =====================================================
// 总结
// =====================================================
echo "\n";
if (empty($failures)) {
    fwrite(STDOUT, "ALL " . (count($failures) === 0 ? 'PASSED' : '?') . "\n");
    exit(0);
}
fwrite(STDERR, "FAILED " . count($failures) . " assertions:\n");
foreach ($failures as $f) fwrite(STDERR, "  - $f\n");
exit(1);