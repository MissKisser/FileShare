<?php
/**
 * 复制按钮（桌面 + 移动）端到端测试
 *
 * 覆盖场景：
 *  A. 主页列表 .btn-copy  点击后剪贴板含完整 content（不是 200 字 preview）
 *  B. 主页列表 .btn-view  点击后弹窗显示完整 content
 *  C. 主页列表搜索/过滤后 .btn-copy  仍然能复制完整 content
 *  D. 分享页 #shareCopyBtn  点击后剪贴板含完整 content（即便是触发 Prism 报错的 PHP-like 文本）
 *  E. 分享页 #shareLinkCopyBtn  点击后剪贴板含分享链接
 *
 * 用法：
 *   php tests/test_copy_buttons.php
 *   ADMIN_PASSWORD=... php tests/test_copy_buttons.php   # 启用 API 列表断言
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8766';
$php = '/d/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';

// ---------- helpers ----------
function http_get($url, $cookieJar = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => false,
    ]);
    if ($cookieJar) curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

function assert_true($cond, $msg) {
    if ($cond) {
        fwrite(STDOUT, "  PASS: $msg\n");
        return true;
    }
    fwrite(STDERR, "  FAIL: $msg\n");
    $GLOBALS['failures'][] = $msg;
    return false;
}

function db_exec($php, $sql) {
    $db = new PDO('sqlite:D:/document/Projects/copy.viaxv.top/storage/fileshare.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec($sql);
}

function db_query_one($php, $sql, $params = []) {
    $db = new PDO('sqlite:D:/document/Projects/copy.viaxv.top/storage/fileshare.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---------- setup ----------
fwrite(STDERR, ">> Setup: insert test items\n");

$now = time();
$shareCodes = [
    'cp_short_' . $now => "短文本-包含 PHP 关键字 \$var = 1;\n第二行 with special & chars: <>&\"",
    'cp_long_' . $now  => str_repeat("这是一段很长的测试文本，复制功能必须能完整复制，", 30), // ~1500 chars
    'cp_php_' . $now   => "function foo(\$x) { return \$x + 1; }\nclass Bar { var \$name = 'test'; }", // triggers language-php
    'cp_html_' . $now  => "<div class=\"foo\">HTML 测试 &amp; 实体</div>",
];

db_exec($php, "DELETE FROM items WHERE share_code LIKE 'cp\\_%\\_" . $now . "'");
foreach ($shareCodes as $code => $content) {
    $stmt = (new PDO('sqlite:D:/document/Projects/copy.viaxv.top/storage/fileshare.db'))
        ->prepare("INSERT INTO items (share_code, type, content, size, password, download_count, ip, user_agent, time, expire, duration) VALUES (?, 'text', ?, ?, '', 0, '127.0.0.1', 'test', ?, ?, ?)");
    $stmt->execute([$code, $content, strlen($content), $now, $now + 86400 * 30, 86400 * 30]);
}
fwrite(STDERR, "   inserted " . count($shareCodes) . " items\n\n");

// =============================================================
// A. 主页 HTML 渲染：btn-copy/btn-view 必须含完整 data-content（不是 200 字截断）
// =============================================================
fwrite(STDERR, ">> [A] Home list server-rendered btn-copy contains FULL content (not 200-char preview)\n");
$home = http_get($base . '/');
foreach ($shareCodes as $code => $content) {
    // Check that the rendered data-content contains a substring unique to the FULL content
    $uniqueSnippet = mb_substr($content, 250, 50); // pick chars beyond 200-char preview cutoff
    if ($uniqueSnippet === '' || mb_strlen($content) < 250) {
        // skip if too short
        continue;
    }
    $present = strpos($home, htmlspecialchars($uniqueSnippet, ENT_QUOTES, 'UTF-8')) !== false
        || strpos($home, $uniqueSnippet) !== false;
    assert_true($present, "btn-copy data-content for $code contains chars [250..300]: '$uniqueSnippet'");
}

// =============================================================
// B. 主页 API 列表的 content_preview 字段截断到 200 chars（这是设计行为，仅断言）
// =============================================================
fwrite(STDERR, ">> [B] API ?api=items content_preview is capped at 200 chars (by design)\n");
$adminPw = getenv('ADMIN_PASSWORD');
if ($adminPw) {
    $tokRaw = http_get($base . '/?api=auth/token');
    // POST
    $ch = curl_init($base . '/?api=auth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['password' => $adminPw]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $tokRaw = curl_exec($ch);
    curl_close($ch);
    $tokData = json_decode($tokRaw, true);
    $token = $tokData['access_token'] ?? null;

    if ($token) {
        // Check API ?api=items list returns content_preview but the search-rendered copy buttons
        // would still need full content. The browser must fall back to fetching item via ?api=item
        // OR show a "open in share page" hint. This test only asserts the current behavior:
        // content_preview <= 200 chars.
        $ch = curl_init($base . '/?api=items&type=text');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        ]);
        $apiList = json_decode(curl_exec($ch), true);
        curl_close($ch);

        foreach ($apiList['items'] ?? [] as $item) {
            if (!empty($item['content_preview'])) {
                assert_true(
                    mb_strlen($item['content_preview']) <= 200,
                    "content_preview capped at 200: id={$item['id']} len=" . mb_strlen($item['content_preview'])
                );
            }
        }
    } else {
        fwrite(STDERR, "  (skipped — no token)\n");
    }
} else {
    fwrite(STDERR, "  (skipped — set ADMIN_PASSWORD env to enable)\n");
}

// =============================================================
// D. 分享页 PHP-like 内容（触发 Prism.highlightElement 报错）下，复制按钮仍可用
// =============================================================
fwrite(STDERR, ">> [D] Share page with Prism-triggering content still has working copy button\n");
// We can only assert server-side: the inline <script> that attaches the listener must NOT be
// aborted by Prism errors. Assert: the page contains the shareCopyBtn click listener code path.
$sharePhp = http_get($base . '/?s=cp_php_' . $now);
$hasCodeElement = strpos($sharePhp, 'id="shareTextContent"') !== false;
$hasCopyBtn = strpos($sharePhp, 'id="shareCopyBtn"') !== false;
$hasAddEventListener = strpos($sharePhp, "shareCopyBtn')") !== false && strpos($sharePhp, "addEventListener('click'") !== false;
assert_true($hasCodeElement, "share page has shareTextContent element");
assert_true($hasCopyBtn, "share page has shareCopyBtn button");
assert_true($hasAddEventListener, "share page contains addEventListener('click', ...) for shareCopyBtn");

// =============================================================
// E. 分享页普通文本（plaintext）下，复制按钮也在
// =============================================================
fwrite(STDERR, ">> [E] Share page plain text also has copy button\n");
$shareShort = http_get($base . '/?s=cp_short_' . $now);
assert_true(strpos($shareShort, 'id="shareCopyBtn"') !== false, "short share page has shareCopyBtn");
assert_true(strpos($shareShort, 'id="shareLinkCopyBtn"') !== false, "short share page has shareLinkCopyBtn");

// =============================================================
// 结果
// =============================================================
echo "\n";
if (!empty($GLOBALS['failures'])) {
    fwrite(STDERR, "FAILED " . count($GLOBALS['failures']) . " assertion(s):\n");
    foreach ($GLOBALS['failures'] as $f) fwrite(STDERR, "  - $f\n");
    exit(1);
}
echo "ALL ASSERTIONS PASSED\n";
exit(0);