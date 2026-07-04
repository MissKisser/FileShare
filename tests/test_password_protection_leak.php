<?php
/**
 * 集成测试：密码保护文本不应在未授权路径泄露内容
 *
 * 用法：
 *   1. 在项目根目录启动 PHP 内置服务器
 *        php -S 127.0.0.1:8766
 *   2. 运行：
 *        ADMIN_PASSWORD=xxx php tests/test_password_protection_leak.php http://127.0.0.1:8766
 *
 * 清理：测试结束时会通过 DELETE API 移除测试插入的文本项（如果创建了的话）。
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: ADMIN_PASSWORD=xxx php {$argv[0]} <base_url>\n");
    exit(2);
}

$base = rtrim($argv[1], '/');

$secret = 'SECRET-' . bin2hex(random_bytes(8)) . '-NOSEE';
$plainPassword = 'pw-' . bin2hex(random_bytes(4));

$failures = [];
$total = 0;
$createdItemIds = [];

function check_absent($name, $haystack, $needle) {
    global $failures, $total;
    $total++;
    if (strpos($haystack, $needle) !== false) {
        $failures[] = "FAIL: $name — secret leaked";
        fwrite(STDERR, "  ✗ $name — secret FOUND in response (LEAK)\n");
    } else {
        fwrite(STDERR, "  ✓ $name — secret absent\n");
    }
}

function check_present($name, $haystack, $needle) {
    global $failures, $total;
    $total++;
    if (strpos($haystack, $needle) === false) {
        $failures[] = "FAIL: $name — expected content missing";
        fwrite(STDERR, "  ✗ $name — expected content MISSING\n");
    } else {
        fwrite(STDERR, "  ✓ $name — expected content present\n");
    }
}

// 拿 csrf_token + session cookie
function fetch_home($base, $jar) {
    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $body, $m)) {
        throw new RuntimeException('failed to extract csrf_token from homepage');
    }
    return ['csrf' => $m[1], 'html' => $body];
}

function http_post($base, $jar, $fields, $withCookie = true) {
    $ch = curl_init($base . '/');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($withCookie) {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

function http_get($base, $jar, $path = '/') {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

function http_get_anon($base, $path = '/') {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

fwrite(STDERR, ">> Setup: creating password-protected text with secret=$secret\n");
$jar = tempnam(sys_get_temp_dir(), 'cookies');

$home = fetch_home($base, $jar);
$csrf = $home['csrf'];

// 提交文本
http_post($base, $jar, [
    'csrf_token' => $csrf,
    'text' => $secret,
    'text_duration' => 600,
    'access_password' => $plainPassword,
]);

// 重新拉取主页（修复后密码项不展示预览，所以从列表匹配不到 secret）
// 改用 API（修复后 API 也不返回 content_preview，但仍返回 has_password）
// 最简单方式：用 ADMIN_PASSWORD 直接查 DB 拿到刚插入的 share_code
$home = fetch_home($base, $jar);

// 获取 API token 用于查询最近项
$token = null;
$adminPw = getenv('ADMIN_PASSWORD');
if ($adminPw) {
    $ch = curl_init($base . '/?api=auth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['password' => $adminPw]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $tokRaw = curl_exec($ch);
    curl_close($ch);
    $tokData = json_decode($tokRaw, true);
    if (!empty($tokData['access_token'])) {
        $token = $tokData['access_token'];
    }
}

$itemId = null;
$shareCode = null;
if ($token) {
    // 翻列表找最新一项带密码的 text
    $ch = curl_init($base . '/?api=items&type=text&sort=time&order=desc&per_page=10');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    $listJson = curl_exec($ch);
    curl_close($ch);
    $list = json_decode($listJson, true);
    if (!empty($list['items'])) {
        foreach ($list['items'] as $it) {
            if (!empty($it['has_password']) && empty($it['content_preview'])) {
                // 修复后密码项 content_preview=null；匹配最新
                $itemId = (int)$it['id'];
                $shareCode = $it['share_code'];
                break;
            }
        }
    }
}

if (!$itemId || !$shareCode) {
    fwrite(STDERR, "  ! could not locate inserted password-protected text via API\n");
    fwrite(STDERR, "  list response: $listJson\n");
    exit(2);
}

// 二次校验：尝试访问 ?s=CODE 解锁，确认 secret 确实在里面
$preUnlock = http_get($base, $jar, '/?s=' . $shareCode);
if (strpos($preUnlock, '需要密码访问') === false) {
    fwrite(STDERR, "  ! share page did not show password form; item setup seems wrong\n");
    exit(2);
}

$createdItemIds[] = $itemId;
fwrite(STDERR, "   share_code=$shareCode  item_id=$itemId\n\n");

// =============================================================
// 测试 1：未登录主页 HTML 不应包含 secret
// =============================================================
fwrite(STDERR, ">> [1] Anonymous homepage should NOT leak secret content\n");
$homeAnon = http_get_anon($base, '/');
check_absent('anonymous homepage text-preview', $homeAnon, $secret);

// =============================================================
// 测试 2：未解锁的分享页不应包含 secret
// =============================================================
fwrite(STDERR, ">> [2] ?s=CODE before unlock should NOT leak secret\n");
$shareLocked = http_get($base, $jar, '/?s=' . $shareCode);
check_absent('share page locked', $shareLocked, $secret);

// =============================================================
// 测试 3：API ?api=item 不应在 content_preview 包含 secret
// =============================================================
fwrite(STDERR, ">> [3] API ?api=item content_preview should NOT leak secret\n");
if (!$token) {
    fwrite(STDERR, "  (skipped — set ADMIN_PASSWORD env var to enable)\n");
} else {
    $ch = curl_init($base . '/?api=item&id=' . $itemId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    $apiItem = curl_exec($ch);
    curl_close($ch);
    check_absent('api item content_preview', $apiItem, $secret);

    // =============================================================
    // 测试 4：API ?api=items 列表不应包含 secret
    // =============================================================
    fwrite(STDERR, ">> [4] API ?api=items list should NOT leak secret\n");
    $ch = curl_init($base . '/?api=items&type=text');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    $apiList = curl_exec($ch);
    curl_close($ch);
    check_absent('api items list', $apiList, $secret);
}

// =============================================================
// 测试 5：缩略图静态 URL 应不可公开访问（或通过 ?thumb=ID 鉴权）
// =============================================================
fwrite(STDERR, ">> [5] Thumbnail static URL should require auth\n");
// 找出首页带 thumbnail_path 的密码保护文件项；本测试只针对文本，不强求有缩略图
// 这里只验证缩略图路由 ?thumb=ID 对未授权请求返回 403
$ch = curl_init($base . '/?thumb=' . $itemId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
$thumbCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$total++;
if ($thumbCode === 200) {
    // 如果直接 200 拿到图（说明走的是 /uploads/），记为失败
    fwrite(STDERR, "  ✗ thumbnail route returned 200 without auth\n");
    $failures[] = 'thumbnail route leaked';
} elseif ($thumbCode === 403 || $thumbCode === 404 || $thumbCode === 302) {
    fwrite(STDERR, "  ✓ thumbnail route blocked (HTTP $thumbCode)\n");
} else {
    fwrite(STDERR, "  ? thumbnail route returned $thumbCode (acceptable if not 200)\n");
}

// =============================================================
// 测试 6（健全性）：用正确密码解锁后 ?s=CODE 应当展示内容
// =============================================================
fwrite(STDERR, ">> [6] Sanity: with correct password, share page SHOULD show secret\n");
http_post($base, $jar, [
    'csrf_token' => $csrf,
    'action' => 'verify_share_password',
    'share_code' => $shareCode,
    'password' => $plainPassword,
]);
$shareUnlocked = http_get($base, $jar, '/?s=' . $shareCode);
check_present('share page unlocked with correct pw', $shareUnlocked, $secret);

// =============================================================
// 清理：删除测试插入的项
// =============================================================
fwrite(STDERR, "\n>> Cleanup: removing test items\n");
foreach ($createdItemIds as $id) {
    // 用主页里的 csrf_token 重新拿一次
    $home = fetch_home($base, $jar);
    http_post($base, $jar, [
        'csrf_token' => $home['csrf'],
        'delete' => $id,
    ]);
    fwrite(STDERR, "   deleted item id=$id\n");
}
@unlink($jar);

// =============================================================
// 报告
// =============================================================
echo "\n";
echo "Total checks: $total\n";
echo "Failures:     " . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $f) echo "  $f\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);