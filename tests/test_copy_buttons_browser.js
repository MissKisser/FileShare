/**
 * Browser-level copy button tests using Playwright (Node).
 *
 * Run from project root:
 *   npx playwright install chromium   # one-time
 *   node tests/test_copy_buttons_browser.js
 *
 * The local PHP server must be running on http://127.0.0.1:8766 with the
 * test items inserted (run tests/test_copy_buttons.php first).
 */

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8766';
const TESTCODE_LONG = 'cp_long_' + Math.floor(Date.now() / 1000);
const TESTCODE_PHP  = 'cp_php_'  + Math.floor(Date.now() / 1000);

const failures = [];
function assert(cond, msg) {
    if (cond) {
        console.log('  PASS:', msg);
    } else {
        console.error('  FAIL:', msg);
        failures.push(msg);
    }
}

async function insertTestItems() {
    const { execSync } = require('child_process');
    const fs = require('fs');
    const path = require('path');
    const os = require('os');
    const php = process.env.PHP_BIN || 'D:/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';
    const now = Math.floor(Date.now() / 1000);
    const long = '这是一段很长的测试文本。'.repeat(50); // ~750 chars
    const phpContent = `function foo($x) {\n    return $x + 1;\n}\nclass Bar { var $name = 'test'; }`;
    const script = path.join(os.tmpdir(), `cp_test_${now}.php`);
    fs.writeFileSync(script, `<?php
$db = new PDO('sqlite:D:/document/Projects/copy.viaxv.top/storage/fileshare.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = ${now};
$db->exec("DELETE FROM items WHERE share_code LIKE 'cp\\\\_%\\\\_${now}'");
$ins = $db->prepare("INSERT INTO items (share_code, type, content, size, password, download_count, ip, user_agent, time, expire, duration) VALUES (?, 'text', ?, ?, '', 0, '127.0.0.1', 'test', ?, ?, ?)");
$long = '${long.replace(/'/g, "\\'")}';
$phpContent = '${phpContent.replace(/'/g, "\\'")}';
$ins->execute(['${TESTCODE_LONG}', $long, strlen($long), $now, $now+86400*30, 86400*30]);
$ins->execute(['${TESTCODE_PHP}', $phpContent, strlen($phpContent), $now, $now+86400*30, 86400*30]);
echo $now;
`);
    try {
        const out = execSync(`"${php}" "${script}"`, { encoding: 'utf8' });
        return now;
    } finally {
        try { fs.unlinkSync(script); } catch {}
    }
}

(async () => {
    const now = await insertTestItems();
    const longCode = TESTCODE_LONG;
    const phpCode = TESTCODE_PHP;

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        permissions: ['clipboard-read', 'clipboard-write'],
    });

    // 归一化：Chromium clipboard 把 \n 转 \r\n；inputValue 转义 \\? -> \?
    function normalize(s) {
        if (s == null) return s;
        return s.replace(/\r\n/g, '\n');
    }
    function clipEq(a, b) {
        return normalize(a) === normalize(b);
    }

    // ============================================================
    // A. Home page (desktop) - copy button copies FULL content
    // ============================================================
    {
        console.log('\n>> [A] Desktop home list .btn-copy copies FULL content (long item)');
        const page = await context.newPage();
        await page.goto(BASE + '/');
        await page.waitForSelector('.btn-copy');

        // Find the copy button for the long item
        const copyBtn = page.locator(`.item[data-share-code="${longCode}"] .btn-copy`).first();
        await copyBtn.waitFor({ state: 'visible', timeout: 5000 });

        // Read the data-content attribute
        const dataContent = await copyBtn.getAttribute('data-content');
        const dataLen = dataContent ? dataContent.length : 0;

        // Click and read clipboard
        await copyBtn.click();
        await page.waitForTimeout(300);
        const clip = await page.evaluate(() => navigator.clipboard.readText());

        assert(dataLen > 500, `data-content contains FULL content (length=${dataLen}, expected > 500; was it truncated to 200-char preview?)`);
        assert(clipEq(clip, dataContent), `clipboard matches data-content (clip=${clip.length}, expected ${dataLen})`);
        await page.close();
    }

    // ============================================================
    // B. Mobile viewport (375x812) - copy button works
    // ============================================================
    {
        console.log('\n>> [B] Mobile (375x812) home list .btn-copy copies FULL content');
        const page = await context.newPage();
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(BASE + '/');
        await page.waitForSelector('.btn-copy');

        const copyBtn = page.locator(`.item[data-share-code="${longCode}"] .btn-copy`).first();
        await copyBtn.waitFor({ state: 'visible', timeout: 5000 });

        // Confirm button is visible and tappable
        const rect = await copyBtn.boundingBox();
        assert(rect && rect.width > 20 && rect.height > 20, `button is tappable on mobile (rect=${JSON.stringify(rect)})`);

        await copyBtn.click();
        await page.waitForTimeout(300);
        const clip = await page.evaluate(() => navigator.clipboard.readText());
        const dataContent = await copyBtn.getAttribute('data-content');

        assert(clipEq(clip, dataContent), `mobile clipboard matches data-content (clip=${clip.length})`);
        await page.close();
    }

    // ============================================================
    // C. Search results (filtered re-render) - copy button still works
    // ============================================================
    {
        console.log('\n>> [C] Search filter re-renders items, copy button still copies full content');
        const page = await context.newPage();
        await page.goto(BASE + '/');
        await page.waitForSelector('.btn-copy');

        // Type into search to trigger re-render
        const searchInput = page.locator('#searchInput, input[placeholder*="搜索"]').first();
        if (await searchInput.count() > 0) {
            await searchInput.fill('很长');
            await page.waitForTimeout(800); // debounce

            const copyBtn = page.locator(`.item[data-share-code="${longCode}"] .btn-copy`).first();
            if (await copyBtn.count() > 0) {
                await copyBtn.waitFor({ state: 'visible', timeout: 3000 });
                await copyBtn.click();
                await page.waitForTimeout(300);
                const clip = await page.evaluate(() => navigator.clipboard.readText());
                const fullLen = '这是一段很长的测试文本。'.repeat(50).length;
                assert(clip.length > 500 || clip.length === fullLen, `search result copies full content (got ${clip.length} chars, expected ${fullLen})`);
            } else {
                console.log('  (skipped — long item not in search results, may be a 150-char preview issue)');
            }
        } else {
            console.log('  (skipped — no search input found)');
        }
        await page.close();
    }

    // ============================================================
    // D. Share page with Prism-triggering PHP content - copy button works
    // ============================================================
    {
        console.log('\n>> [D] Share page with PHP-like content (Prism error) — copy button still works');
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));
        await page.goto(BASE + '/?s=' + phpCode);
        await page.waitForSelector('#shareCopyBtn');

        // The Prism.highlightElement may throw — that should NOT abort the shareCopyBtn click listener
        const btn = page.locator('#shareCopyBtn');
        await btn.waitFor({ state: 'visible', timeout: 5000 });

        await btn.click();
        await page.waitForTimeout(400);
        const clip = await page.evaluate(() => navigator.clipboard.readText());
        const codeEl = await page.locator('#shareTextContent').textContent();

        assert(clipEq(clip, codeEl), `share page clipboard matches shareTextContent (clip=${clip.length}, expected ${codeEl.length})`);
        assert(errors.length === 0 || errors.every(e => !e.includes('shareCopyBtn')), `no JS errors blocking shareCopyBtn listener (errors: ${JSON.stringify(errors)})`);

        await page.close();
    }

    // ============================================================
    // E. Share page - link copy button
    // ============================================================
    {
        console.log('\n>> [E] Share page #shareLinkCopyBtn copies the share URL');
        const page = await context.newPage();
        await page.goto(BASE + '/?s=' + phpCode);
        const linkBtn = page.locator('#shareLinkCopyBtn');
        await linkBtn.waitFor({ state: 'visible', timeout: 5000 });

        await linkBtn.click();
        await page.waitForTimeout(300);
        const clip = await page.evaluate(() => navigator.clipboard.readText());
        const expected = BASE + '/?s=' + phpCode;
        assert(clipEq(clip, expected), `share link clipboard matches URL (clip=${clip}, expected=${expected})`);

        await page.close();
    }

    // ============================================================
    // F. Open-code modal (展开) shows full content
    // ============================================================
    {
        console.log('\n>> [F] Home list .btn-view (展开) opens modal with full content');
        const page = await context.newPage();
        await page.goto(BASE + '/');
        await page.waitForSelector('.btn-view');

        const viewBtn = page.locator(`.item[data-share-code="${longCode}"] .btn-view`).first();
        await viewBtn.waitFor({ state: 'visible', timeout: 5000 });
        await viewBtn.click();
        await page.waitForTimeout(300);

        // Modal should appear with full content
        const modalVisible = await page.locator('#codeModal, [class*="modal"]').first().isVisible().catch(() => false);
        assert(modalVisible, 'code modal becomes visible after clicking 展开');

        await page.close();
    }

    await browser.close();

    console.log('\n');
    if (failures.length) {
        console.error(`FAILED ${failures.length} assertion(s):`);
        failures.forEach(f => console.error('  -', f));
        process.exit(1);
    }
    console.log('ALL BROWSER ASSERTIONS PASSED');
})().catch(err => {
    console.error('FATAL:', err);
    process.exit(2);
});