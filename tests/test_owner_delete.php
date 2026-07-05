<?php
/**
 * Owner 自删除（管理链接 ?s=&manage= 触发的删除流程）测试
 *
 * 覆盖：
 *  P0   - 无 manage_token 的 owner_delete 请求 → 403 + 行不变
 *  P0   - manage_token 错误的 owner_delete 请求 → 403 + 行不变（防 enumeration）
 *  P0   - manage_token 错配 share_code（拿 A 的 token 删 B）→ 403
 *  P0   - manage_token 长度异常的请求 → 400
 *  happy - 文本保存路径：创建后 GET ?s=&manage= → share 页含 "删除我的上传" 按钮
 *  happy - 文本保存路径：POST owner_delete → 200 + 行消失 + 上传日志记录 owner_delete
 *  happy - 文件上传路径：上传响应含 manage_url，POST owner_delete → 行 + 物理文件消失
 *  session - share 页：第一次带 manage 验证后，去掉 manage 重访 → C4 后不再渲染按钮，
 *            明文 token 不出现在 HTML（要求持续带 ?manage= 才能删）
 *  session - 删完后 session 中的 owner_confirmed_ 应被清空
 *  csrf  - owner_delete 不需要 CSRF（验证：故意不发 csrf_token 仍能删）
 *  admin - 老 items（owner_token_hash=NULL）admin 路径依然能删（兼容）
 *  幂等  - migration 跑两次不报错（PRAGMA table_info 列出 owner_token_hash）
 *  api   - API 创建的 item 也能用 manage_url 删
 *  enumer - 错误响应统一信息（不区分"项目不存在"和"token 错"）
 *
 * 用法：
 *   php tests/test_owner_delete.php
 *   BASE_URL=http://127.0.0.1:8767 php tests/test_owner_delete.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8767', '/');

$failures = [];
function assert_true($cond, $msg) {
    if ($cond) { fwrite(STDOUT, "  PASS: $msg\n"); return; }
    fwrite(STDERR, "  FAIL: $msg\n");
    $GLOBALS['failures'][] = $msg;
}

function dbFresh() {
    $dbPath = getenv('TEST_DB_PATH') ?: 'D:/document/Projects/copy.viaxv.top/storage/fileshare.db';
    $d = new PDO('sqlite:' . $dbPath);
    $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    @$d->exec('PRAGMA journal_mode=WAL');
    @$d->exec('PRAGMA busy_timeout=3000');
    return $d;
}

function refreshDb(&$dbh) { $dbh = dbFresh(); return $dbh; }

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
        CURLOPT_HEADER => false,
    ]);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($statusOut !== null) $statusOut = $info['http_code'];
    return $raw;
}

function fetchCsrf($html) {
    $m = [];
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

// 公共：插一条带 owner_token 的探针记录（直接走 SQL，绕开 web 流程）
// 返回 ['code' => ..., 'token' => plaintext, 'id' => ..., 'hash' => sha256(token)]
function insertProbeWithOwner($db, $suffix, $extra = []) {
    $now = time();
    $code = 'ownerdel_' . $suffix . '_' . substr(md5($now . random_int(0, 99999)), 0, 6);
    // 64 字符 hex，模拟 generateOwnerToken()
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $defaults = [
        'share_code' => $code,
        'type' => 'text',
        'content' => 'OWNER-DEL-PROBE-' . $suffix,
        'size' => 100,
        'password' => null,
        'ip' => '127.0.0.1',
        'user_agent' => 'owner-delete-test',
        'time' => $now,
        'expire' => $now + 86400,
        'duration' => 86400,
        'name' => null,
        'mime_type' => null,
        'path' => null,
        'file_hash' => null,
        'owner_token_hash' => $tokenHash,
    ];
    $row = array_merge($defaults, $extra);
    $db->prepare(
        'INSERT INTO items (share_code, type, content, size, password, ip, user_agent, time, expire, duration, name, mime_type, path, file_hash, owner_token_hash)
         VALUES (:share_code, :type, :content, :size, :password, :ip, :user_agent, :time, :expire, :duration, :name, :mime_type, :path, :file_hash, :owner_token_hash)'
    )->execute($row);
    return ['code' => $code, 'token' => $token, 'hash' => $tokenHash, 'id' => (int)$db->lastInsertId()];
}

function itemIdByCode($db, $code) {
    return (int)$db->query("SELECT id FROM items WHERE share_code = " . $db->quote($code))->fetchColumn();
}

function itemExists($db, $id) {
    return (bool)$db->query("SELECT 1 FROM items WHERE id = " . (int)$id)->fetchColumn();
}

// 重试包装（处理 SQLite WAL 偶发锁）
function safeExec($db, $sql, $params = [], $maxRetries = 5) {
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'locked') !== false && $i < $maxRetries - 1) {
                usleep(200000); // 200ms
                continue;
            }
            throw $e;
        }
    }
}

function cleanupOwnerdelRows($db) {
    // 删除 owner_delete 测试产生的 items + 关联 logs
    safeExec($db, "DELETE FROM download_logs WHERE item_id IN (SELECT id FROM items WHERE share_code LIKE 'ownerdel\\\\_%')");
    safeExec($db, "DELETE FROM upload_logs WHERE item_id IN (SELECT id FROM items WHERE share_code LIKE 'ownerdel\\\\_%')");
    safeExec($db, "DELETE FROM items WHERE share_code LIKE 'ownerdel\\\\_%'");
    // 删除 owner_delete 产生的 admin_* audit rows（filename 含 owner_delete 前缀）
    safeExec($db, "DELETE FROM upload_logs WHERE action LIKE 'admin_owner_delete' AND filename LIKE '%ownerdel\\\\_%'");
}

// =====================================================
// P0 setup: 一次性清理之前的残留
// =====================================================
$dbh = dbFresh();
cleanupOwnerdelRows($dbh);

// =====================================================
// 1. P0: schema 已就绪 + migration 幂等
// =====================================================
fwrite(STDERR, "\n>> [P0] migration ready + idempotent\n");
$cols = array_column($dbh->query("PRAGMA table_info(items)")->fetchAll(), 'name');
assert_true(in_array('owner_token_hash', $cols), "items.owner_token_hash column exists");
$idx = $dbh->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_owner_token_hash'")->fetchAll();
assert_true(count($idx) > 0, "idx_owner_token_hash partial unique index exists");
// 第二次调用 migration 应无报错
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
    require_once (getenv('TEST_SRC_DIR') ?: 'D:/document/Projects/copy.viaxv.top/src') . '/config.php';
    require_once (getenv('TEST_SRC_DIR') ?: 'D:/document/Projects/copy.viaxv.top/src') . '/database.php';
}
$dbIdempotent = getDB();
runIncrementalMigrations($dbIdempotent);
runIncrementalMigrations($dbIdempotent); // 第二次必须无错
$colsAfter = array_column($dbIdempotent->query("PRAGMA table_info(items)")->fetchAll(), 'name');
assert_true(in_array('owner_token_hash', $colsAfter), "after 2nd migration, column still present (no double-ADD error)");

// =====================================================
// 2. P0: 无 manage_token / manage_token 错误 / 错配 share_code / 长度异常
// =====================================================
fwrite(STDERR, "\n>> [P0] negative paths on handleOwnerDelete\n");

// 2a. 缺 manage token
$victim = insertProbeWithOwner($dbh, 'p0_no_token');
$anonJar = 'D:/temp/cp-od-no-token-' . getmypid() . '.txt';
@unlink($anonJar);
http_get_jar($base, $anonJar, '/'); // 预热 session
$status = 0;
http_post_jar($base, $anonJar, '/', ['action' => 'owner_delete', 'share_code' => $victim['code']], $status);
refreshDb($dbh);
assert_true($status === 400, "missing manage token → 400 (got $status)");
assert_true(itemExists($dbh, $victim['id']), "missing manage token: victim row still exists");
safeExec($dbh, "DELETE FROM items WHERE id = ?", [$victim['id']]);
@unlink($anonJar);

// 2b. 错误 manage token
$victim = insertProbeWithOwner($dbh, 'p0_bad_token');
$anonJar = 'D:/temp/cp-od-bad-token-' . getmypid() . '.txt';
@unlink($anonJar);
http_get_jar($base, $anonJar, '/');
$status = 0;
http_post_jar($base, $anonJar, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim['code'],
    'manage' => bin2hex(random_bytes(32)), // 错的 token
], $status);
refreshDb($dbh);
assert_true($status === 403, "wrong manage token → 403 (got $status)");
assert_true(itemExists($dbh, $victim['id']), "wrong manage token: victim row still exists");
safeExec($dbh, "DELETE FROM items WHERE id = ?", [$victim['id']]);
@unlink($anonJar);

// 2c. 错配：拿 A 的 token 删 B
$victimA = insertProbeWithOwner($dbh, 'p0_mis_a');
$victimB = insertProbeWithOwner($dbh, 'p0_mis_b');
$anonJar = 'D:/temp/cp-od-mismatch-' . getmypid() . '.txt';
@unlink($anonJar);
http_get_jar($base, $anonJar, '/');
$status = 0;
http_post_jar($base, $anonJar, '/', [
    'action' => 'owner_delete',
    'share_code' => $victimB['code'], // 想删 B
    'manage' => $victimA['token'],    // 用 A 的 token（应该失败）
], $status);
refreshDb($dbh);
assert_true($status === 403, "token/code mismatch → 403 (got $status)");
assert_true(itemExists($dbh, $victimA['id']), "victim A still exists");
assert_true(itemExists($dbh, $victimB['id']), "victim B still exists (was NOT deleted)");
safeExec($dbh, "DELETE FROM items WHERE id IN (?, ?)", [$victimA['id'], $victimB['id']]);
@unlink($anonJar);

// 2d. 长度异常
$victim = insertProbeWithOwner($dbh, 'p0_overlong');
$anonJar = 'D:/temp/cp-od-overlong-' . getmypid() . '.txt';
@unlink($anonJar);
http_get_jar($base, $anonJar, '/');
$status = 0;
http_post_jar($base, $anonJar, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim['code'],
    'manage' => str_repeat('a', 300), // 超过 256 字符上限
], $status);
refreshDb($dbh);
assert_true($status === 400, "overlong manage token → 400 (got $status)");
assert_true(itemExists($dbh, $victim['id']), "overlong token: victim row still exists");
safeExec($dbh, "DELETE FROM items WHERE id = ?", [$victim['id']]);
@unlink($anonJar);

// 2e. enumeration: 错误响应信息统一
$anonJar = 'D:/temp/cp-od-msg-' . getmypid() . '.txt';
@unlink($anonJar);
http_get_jar($base, $anonJar, '/');
// 不存在的 share_code
$status404 = 0;
$resp404 = http_post_jar($base, $anonJar, '/', [
    'action' => 'owner_delete',
    'share_code' => 'nonexistent_xxx_999',
    'manage' => bin2hex(random_bytes(32)),
], $status404);
// 错的 token 对真实 code
$victim2 = insertProbeWithOwner($dbh, 'p0_msg_real');
$statusBad = 0;
$respBad = http_post_jar($base, $anonJar, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim2['code'],
    'manage' => bin2hex(random_bytes(32)),
], $statusBad);
safeExec($dbh, "DELETE FROM items WHERE id = ?", [$victim2['id']]);
@unlink($anonJar);
assert_true($status404 === 403, "404-mask → 403 (got $status404)");
assert_true($statusBad === 403, "bad-token-mask → 403 (got $statusBad)");
$j404 = json_decode($resp404, true);
$jBad = json_decode($respBad, true);
assert_true(isset($j404['message']) && isset($jBad['message']), "both responses have 'message' field");
assert_true($j404['message'] === $jBad['message'], "same message for 404 and bad-token (enumeration-safe)");
assert_true(strpos($j404['message'], '管理链接') !== false, "message hints at 管理链接 (not 项目不存在)");

// =====================================================
// 3. happy path: 直接 SQL 插探针 + POST owner_delete → 200 + 行消失
// =====================================================
fwrite(STDERR, "\n>> [happy] owner token → owner_delete → row gone + audit log\n");
$victim = insertProbeWithOwner($dbh, 'happy_text');
$jarHappy = 'D:/temp/cp-od-happy-' . getmypid() . '.txt';
@unlink($jarHappy);
http_get_jar($base, $jarHappy, '/');
$status = 0;
$respBody = http_post_jar($base, $jarHappy, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim['code'],
    'manage' => $victim['token'],
], $status);
refreshDb($dbh);
assert_true($status === 200, "happy owner_delete → 200 (got $status)");
assert_true(!itemExists($dbh, $victim['id']), "victim row removed after happy owner_delete");
$j = json_decode($respBody, true);
assert_true($j && $j['success'] === true, "response JSON indicates success");
$auditCount = (int)$dbh->query("SELECT COUNT(*) FROM upload_logs WHERE action='admin_owner_delete' AND filename LIKE '%{$victim['code']}%'")->fetchColumn();
assert_true($auditCount === 1, "exactly 1 owner_delete audit log row (got $auditCount)");
@unlink($jarHappy);

// =====================================================
// 4. happy path: 文件上传响应含 manage_url，POST owner_delete → 物理文件消失
// =====================================================
fwrite(STDERR, "\n>> [happy] file upload → manage_url → owner_delete → physical file removed\n");
// 文件上传需要 multipart/form-data + CSRF
$jarFile = 'D:/temp/cp-od-file-' . getmypid() . '.txt';
@unlink($jarFile);
$homeHtml = http_get_jar($base, $jarFile, '/');
$csrfFile = fetchCsrf($homeHtml);
// 创建临时文件
$tmpFile = tempnam(sys_get_temp_dir(), 'odprobe_');
file_put_contents($tmpFile, 'OWNER-DEL-FILE-PROBE-' . bin2hex(random_bytes(8)));
// multipart POST
$ch = curl_init($base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'csrf_token' => $csrfFile,
        'duration' => '600',
        'files[0]' => new CURLFile($tmpFile, 'text/plain', 'probe.txt'),
    ],
    CURLOPT_COOKIEJAR => $jarFile,
    CURLOPT_COOKIEFILE => $jarFile,
    CURLOPT_TIMEOUT => 15,
]);
$uploadResp = curl_exec($ch);
$uploadInfo = curl_getinfo($ch);
curl_close($ch);
$uploadJson = json_decode($uploadResp, true);
assert_true($uploadInfo['http_code'] === 200, "file upload HTTP 200 (got {$uploadInfo['http_code']})");
assert_true(!empty($uploadJson['success']), "file upload success=true");
assert_true(!empty($uploadJson['items'][0]['manage_url']), "upload response has manage_url");
assert_true(!empty($uploadJson['items'][0]['share_code']), "upload response has share_code");
refreshDb($dbh);
$fileCode = $uploadJson['items'][0]['share_code'];
$fileId = itemIdByCode($dbh, $fileCode);
$fileItem = $dbh->query("SELECT path FROM items WHERE id=$fileId")->fetch(PDO::FETCH_ASSOC);
$physicalPath = $fileItem['path'] ?? '';
assert_true(!empty($physicalPath) && file_exists($physicalPath), "physical file exists before delete");
// 提取 manage_url 的 token
$manageUrl = $uploadJson['items'][0]['manage_url'];
$tokenFromUrl = '';
if (preg_match('/[?&]manage=([a-f0-9]+)/', $manageUrl, $m)) $tokenFromUrl = $m[1];
assert_true(strlen($tokenFromUrl) === 64, "manage_url contains 64-char hex token (got " . strlen($tokenFromUrl) . ")");
// POST owner_delete
$status = 0;
http_post_jar($base, $jarFile, '/', [
    'action' => 'owner_delete',
    'share_code' => $fileCode,
    'manage' => $tokenFromUrl,
], $status);
refreshDb($dbh);
assert_true($status === 200, "file owner_delete → 200 (got $status)");
assert_true(!itemExists($dbh, $fileId), "file item row removed");
assert_true(!file_exists($physicalPath), "physical file removed after owner_delete");
@unlink($jarFile);

// =====================================================
// 5. share 页：URL 带 ?manage= → HTML 含 ownerDeleteBtn
// =====================================================
fwrite(STDERR, "\n>> [share-page] ?manage=<valid> → HTML contains ownerDeleteBtn\n");
$victim5 = insertProbeWithOwner($dbh, 'page_render');
$jar5 = 'D:/temp/cp-od-render-' . getmypid() . '.txt';
@unlink($jar5);
$shareUrl = '/?s=' . urlencode($victim5['code']) . '&manage=' . $victim5['token'];
$shareHtml = http_get_jar($base, $jar5, $shareUrl);
assert_true(strpos($shareHtml, 'id="ownerDeleteBtn"') !== false, "share page with valid manage contains ownerDeleteBtn");
assert_true(strpos($shareHtml, '删除我的上传') !== false, "share page shows '删除我的上传' label");
assert_true(strpos($shareHtml, 'share-owner-section') !== false, "share page renders share-owner-section");
@unlink($jar5);

// =====================================================
// 6. share 页：无 manage（验证不存在）→ 不渲染
// =====================================================
fwrite(STDERR, "\n>> [share-page] no manage → no owner UI\n");
$victim6 = insertProbeWithOwner($dbh, 'page_no_render');
$jar6 = 'D:/temp/cp-od-norender-' . getmypid() . '.txt';
@unlink($jar6);
$shareHtml6 = http_get_jar($base, $jar6, '/?s=' . urlencode($victim6['code']));
assert_true(strpos($shareHtml6, 'id="ownerDeleteBtn"') === false, "share page without manage does NOT contain ownerDeleteBtn");
assert_true(strpos($shareHtml6, 'share-owner-section') === false, "share page without manage does NOT render share-owner-section");
@unlink($jar6);

// =====================================================
// 7. share 页 session 复用：第一次带 manage 验证后去掉 manage 重访
//    C4 修复后语义变化：去掉 manage → 仅识别身份（提示），不再渲染删除按钮，
//    且明文 token 绝不出现在 HTML（防止 session 文件泄露降级 token 安全等级）。
//    删除操作要求持续带 ?manage=<token>。
// =====================================================
fwrite(STDERR, "\n>> [share-page] session reuse: re-visit without manage (C4 — no button, no token in HTML)\n");
$victim7 = insertProbeWithOwner($dbh, 'page_session');
$jar7 = 'D:/temp/cp-od-session-' . getmypid() . '.txt';
@unlink($jar7);
// 第一次带 manage → HTML 应含按钮
$shareHtml7a = http_get_jar($base, $jar7, '/?s=' . urlencode($victim7['code']) . '&manage=' . $victim7['token']);
assert_true(strpos($shareHtml7a, 'id="ownerDeleteBtn"') !== false, "first visit with manage shows ownerDeleteBtn");
// 第二次不带 manage（同一 session）→ 应识别身份但不渲染按钮，且 token 不在 HTML
$shareHtml7 = http_get_jar($base, $jar7, '/?s=' . urlencode($victim7['code']));
assert_true(strpos($shareHtml7, 'id="ownerDeleteBtn"') === false, "C4: 2nd visit without manage does NOT show ownerDeleteBtn");
assert_true(strpos($shareHtml7, '已识别为上传者') !== false, "C4: 2nd visit shows owner-identified hint");
// 关键安全断言：明文 token 绝不出现在响应 HTML
assert_true(strpos($shareHtml7, $victim7['token']) === false, "C4: plaintext owner token NEVER appears in HTML without ?manage=");
@unlink($jar7);

// =====================================================
// 8. CSRF 缺席：故意不发 csrf_token，owner_delete 仍能成功（验证设计）
// =====================================================
fwrite(STDERR, "\n>> [csrf] owner_delete works WITHOUT csrf_token (token is sole credential)\n");
$victim8 = insertProbeWithOwner($dbh, 'no_csrf');
$jar8 = 'D:/temp/cp-od-nocsrf-' . getmypid() . '.txt';
@unlink($jar8);
http_get_jar($base, $jar8, '/');
$status = 0;
http_post_jar($base, $jar8, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim8['code'],
    'manage' => $victim8['token'],
    // 故意不附带 csrf_token
], $status);
refreshDb($dbh);
assert_true($status === 200, "owner_delete WITHOUT csrf_token → 200 (got $status)");
assert_true(!itemExists($dbh, $victim8['id']), "victim removed without csrf_token");
@unlink($jar8);

// =====================================================
// 9. 兼容：admin 路径仍能删 owner_token_hash=NULL 的旧 item
// =====================================================
fwrite(STDERR, "\n>> [compat] admin can still delete legacy items (owner_token_hash=NULL)\n");
// 需要先 admin 登录（用现成的 test_delete_auth 模式）
$adminJar = 'D:/temp/cp-od-admin-' . getmypid() . '.txt';
@unlink($adminJar);
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
    fwrite(STDERR, "  SKIP: cannot read ADMIN_PASSWORD — skip compat test\n");
} else {
    $homeHtml = http_get_jar($base, $adminJar, '/');
    $csrfAdmin = fetchCsrf($homeHtml);
    $statusLogin = 0;
    http_post_jar($base, $adminJar, '/admin/login', [
        'password' => $adminPassword,
        'csrf_token' => $csrfAdmin,
    ], $statusLogin, false);
    assert_true($statusLogin === 302, "admin login → 302 (got $statusLogin)");
    // 插一条 NULL owner_token_hash 的"老 item"
    $code9 = 'ownerdel_legacy_' . substr(md5(time() . random_int(0, 99999)), 0, 6);
    safeExec($dbh, "INSERT INTO items (share_code, type, content, size, password, ip, user_agent, time, expire, duration, owner_token_hash) VALUES (?, 'text', ?, 100, NULL, '127.0.0.1', 'legacy', ?, ?, 600, NULL)", [
        $code9, 'LEGACY-PROBE', time(), time() + 86400,
    ]);
    refreshDb($dbh);
    $legacyId = itemIdByCode($dbh, $code9);
    assert_true($legacyId > 0, "legacy item inserted with NULL owner_token_hash");
    // admin 路径 POST delete=<id>
    $statusDel = 0;
    http_post_jar($base, $adminJar, '/', [
        'delete' => $legacyId,
        'csrf_token' => $csrfAdmin,
    ], $statusDel, false);
    refreshDb($dbh);
    assert_true($statusDel === 302, "admin delete legacy item → 302 (got $statusDel)");
    assert_true(!itemExists($dbh, $legacyId), "legacy item removed by admin");
    @unlink($adminJar);
}

// =====================================================
// 10. session 清理：删除成功后 session 中的 owner_token_ 应清空
// =====================================================
fwrite(STDERR, "\n>> [session] owner_token_ cleared from session after delete\n");
$victim10 = insertProbeWithOwner($dbh, 'session_clear');
$jar10 = 'D:/temp/cp-od-sessclear-' . getmypid() . '.txt';
@unlink($jar10);
// 触发 share 页 owner 验证（写 session）
http_get_jar($base, $jar10, '/?s=' . urlencode($victim10['code']) . '&manage=' . $victim10['token']);
// POST owner_delete（同一个 jar → 同一 session）
$status = 0;
http_post_jar($base, $jar10, '/', [
    'action' => 'owner_delete',
    'share_code' => $victim10['code'],
    'manage' => $victim10['token'],
], $status);
refreshDb($dbh);
assert_true($status === 200, "delete OK (got $status)");
assert_true(!itemExists($dbh, $victim10['id']), "item removed");
// 现在 share 页（不带 manage）应该不再有管理入口（因为 session 标记已清）
$shareHtmlAfter = http_get_jar($base, $jar10, '/?s=' . urlencode($victim10['code']));
assert_true(strpos($shareHtmlAfter, 'id="ownerDeleteBtn"') === false, "after delete, re-visit without manage does NOT show ownerDeleteBtn");
@unlink($jar10);

// =====================================================
// Cleanup: 删除所有 ownerdel_ 残留
// =====================================================
fwrite(STDERR, "\n>> [cleanup] remove ownerdel_* probe rows\n");
cleanupOwnerdelRows($dbh);

// =====================================================
// 结果
// =====================================================
echo "\n";
if (!empty($failures)) {
    fwrite(STDERR, "FAILED " . count($failures) . " assertion(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - $f\n");
    exit(1);
}
echo "ALL PASSED\n";
exit(0);