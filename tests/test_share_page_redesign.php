<?php
/**
 * 分享页重构 + 日志不泄露内容 测试
 *
 * 覆盖：
 *  1. 日志不再泄露文本内容（filename 不再含 "文本内容 (...)"）
 *  2. 分享页布局：左侧主内容（标题/文本/动作），右侧侧边栏（链接+二维码+元信息）
 *  3. 二维码在侧边栏中居中
 *  4. 侧边栏含：链接、二维码、上传时间、IP、剩余时间倒计时、下载次数
 *  5. 日志表在主页面渲染时，不再泄露任何 secret
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8766';
$php = 'D:/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';

$failures = [];
function assert_true($cond, $msg) {
    if ($cond) { fwrite(STDOUT, "  PASS: $msg\n"); return; }
    fwrite(STDERR, "  FAIL: $msg\n");
    $GLOBALS['failures'][] = $msg;
}

function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

function db() {
    $d = new PDO('sqlite:D:/document/Projects/copy.viaxv.top/storage/fileshare.db');
    $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $d;
}

// =====================================================
// Setup: insert a password-protected text item
// =====================================================
$now = time();
$secret = 'SECRET-' . bin2hex(random_bytes(8));
$shareCode = 'redesign_' . substr(md5($now), 0, 8);
$content = "这是密码保护的秘密内容 {$secret}，行1\n行2 with special & chars: <>&\"";

$dbh = db();
$dbh->exec("DELETE FROM items WHERE share_code LIKE 'redesign\\_%'");
$dbh->exec("DELETE FROM upload_logs WHERE filename LIKE '%$secret%'");
$ins = $dbh->prepare("INSERT INTO items (share_code, type, content, size, password, download_count, ip, user_agent, time, expire, duration, name, mime_type) VALUES (?, 'text', ?, ?, ?, 0, '127.0.0.1', 'test', ?, ?, ?, null, null)");
$ins->execute([$shareCode, $content, strlen($content), password_hash('test123', PASSWORD_BCRYPT), $now, $now + 86400 * 30, 86400 * 30]);
fwrite(STDERR, ">> Setup: inserted share_code=$shareCode with secret=$secret\n\n");

// =====================================================
// 1. 主页日志表（HTML 渲染）不泄露 secret
// =====================================================
fwrite(STDERR, ">> [1] Home page log table should NOT leak secret content\n");
$home = http_get($base . '/');
assert_true(strpos($home, $secret) === false, "secret NOT in homepage HTML");

// =====================================================
// 2. 触发真实文本保存，验证 upload_logs 不含 secret
// =====================================================
fwrite(STDERR, ">> [2] Trigger a real text save and verify upload_logs does NOT contain secret\n");
$logTestSecret = 'LOGSECRET-' . bin2hex(random_bytes(8));
$logTestContent = "这是日志测试文本 {$logTestSecret}";
// 用独立 cookie jar 避免与其他 section 串扰
$jar = 'D:/temp/cp-redesign-jar-' . getmypid() . '.txt';
@mkdir(dirname($jar), 0777, true);
@unlink($jar);
// 先获取主页拿到 CSRF
$ch = curl_init("$base/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$homeBody = curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $homeBody, $m);
$homeCsrf = $m[1] ?? '';

// 提交文本保存（带密码保护）
$ch = curl_init("$base/");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'text' => $logTestContent,
        'text_duration' => 600,
        'access_password' => 'logpass',
        'csrf_token' => $homeCsrf,
    ]),
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
curl_close($ch);

// 查询 upload_logs 表
$logs = $dbh->query("SELECT * FROM upload_logs WHERE filename LIKE '%{$logTestSecret}%' OR filename LIKE '%LOGSECRET%'")->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($logs) === 0, "upload_logs has NO row containing secret (got " . count($logs) . ")");

// 查询最近一行文本类型的日志
$latest = $dbh->query("SELECT * FROM upload_logs WHERE filename LIKE '文本片段%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($latest) {
    assert_true(strpos($latest['filename'], $logTestSecret) === false, "text log filename does not contain secret");
    assert_true($latest['filename'] === '文本片段' || $latest['filename'] === '文本片段 [密码保护]', "text log filename is a generic label: '{$latest['filename']}'");
    assert_true(strpos($latest['filename'], '[密码保护]') !== false, "text log filename indicates password protection");
} else {
    assert_true(false, "no text log row found (text save may have failed)");
}

// 清理
$dbh->exec("DELETE FROM upload_logs WHERE filename LIKE '%LOGSECRET%'");
$dbh->exec("DELETE FROM items WHERE content LIKE '%{$logTestSecret}%'");

// =====================================================
// 3. 分享页：未解锁时不可见 secret
// =====================================================
fwrite(STDERR, ">> [3] Share page locked should NOT leak secret\n");
$ch = curl_init("$base/?s=$shareCode");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$shareLocked = curl_exec($ch);
curl_close($ch);
assert_true(strpos($shareLocked, $secret) === false, "secret NOT in locked share page");

// =====================================================
// 4. 分享页：解锁后显示 secret
// =====================================================
fwrite(STDERR, ">> [4] Share page unlocked should show secret (sanity check)\n");
// (jar is already set up in section 3 with the same session)
$shareLockedHtml = $shareLocked;
$csrf = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $shareLockedHtml, $m)) {
    $csrf = $m[1];
}
$ch = curl_init("$base/?s=$shareCode");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['action' => 'verify_share_password', 'share_code' => $shareCode, 'password' => 'test123', 'csrf_token' => $csrf]),
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$resp = curl_exec($ch);
curl_close($ch);
// Re-fetch share page WITH cookie jar so we keep the unlock session
$ch = curl_init("$base/?s=$shareCode");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$shareUnlocked = curl_exec($ch);
curl_close($ch);
assert_true(strpos($shareUnlocked, $secret) !== false, "secret IS in unlocked share page (sanity)");

// =====================================================
// 5. 分享页布局：左右分栏
// =====================================================
fwrite(STDERR, ">> [5] Share page has sidebar layout (left content + right sidebar)\n");
assert_true(strpos($shareUnlocked, 'class="share-layout"') !== false || strpos($shareUnlocked, 'class="share-grid"') !== false || strpos($shareUnlocked, 'class="share-main"') !== false, "share page has layout container with main/sidebar");
assert_true(strpos($shareUnlocked, 'share-sidebar') !== false, "share page has sidebar element");

// =====================================================
// 6. 侧边栏含：链接输入、二维码容器、上传时间、IP、剩余时间倒计时
// =====================================================
fwrite(STDERR, ">> [6] Sidebar contains: link input, QR container, upload time, IP, countdown\n");
assert_true(strpos($shareUnlocked, 'id="shareLinkInput"') !== false, "sidebar has shareLinkInput");
assert_true(strpos($shareUnlocked, 'id="shareQrContainer"') !== false, "sidebar has shareQrContainer");
assert_true(strpos($shareUnlocked, 'class="share-meta-time"') !== false || strpos($shareUnlocked, '上传于') !== false, "sidebar has upload time");
assert_true(strpos($shareUnlocked, 'class="share-meta-ip"') !== false || strpos($shareUnlocked, $shareCode) !== false, "sidebar has IP or share code info");
assert_true(strpos($shareUnlocked, 'id="shareCountdown"') !== false || strpos($shareUnlocked, 'share-countdown') !== false, "sidebar has countdown element");

// =====================================================
// 7. 二维码容器居中（share-qr canvas 在内联居中容器内）
// =====================================================
fwrite(STDERR, ">> [7] QR code container is centered in sidebar\n");
// 检查 share-qr-section 容器（带居中样式）
$qrIdx = strpos($shareUnlocked, 'id="shareQrContainer"');
$qrParentClass = '';
if ($qrIdx !== false) {
    $before = substr($shareUnlocked, max(0, $qrIdx - 500), 500);
    if (strpos($before, 'share-qr-section') !== false) {
        $qrParentClass = 'share-qr-section';
    }
}
assert_true($qrParentClass === 'share-qr-section', "QR container is inside share-qr-section wrapper");

// 验证 CSS 规则：share-qr-section .share-qr { ... text-align: center; ... }
$cssOk = preg_match('/\.share-qr-section\s+\.share-qr\s*\{[^}]*text-align:\s*center/s', $shareUnlocked)
       || preg_match('/\.share-qr\s*\{[^}]*text-align:\s*center/s', $shareUnlocked);
assert_true($cssOk, "CSS for .share-qr has text-align: center");

// =====================================================
// 8. 文本框样式改进
// =====================================================
fwrite(STDERR, ">> [8] Text content area has improved styling (not tiny bare pre)\n");
assert_true(strpos($shareUnlocked, 'class="share-text-content"') !== false, "share page has share-text-content container");
assert_true(strpos($shareUnlocked, 'class="text-info"') !== false || strpos($shareUnlocked, 'share-text-toolbar') !== false, "share page has text toolbar (count, copy button) inline with content");

// =====================================================
// 结果
// =====================================================
echo "\n";
if (!empty($GLOBALS['failures'])) {
    fwrite(STDERR, "FAILED " . count($GLOBALS['failures']) . " assertion(s):\n");
    foreach ($GLOBALS['failures'] as $f) fwrite(STDERR, "  - $f\n");
    exit(1);
}
echo "ALL ASSERTIONS PASSED\n";
exit(0);