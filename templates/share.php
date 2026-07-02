<?php
/**
 * 分享页面模板
 * 用于展示单个文件/文本的分享页面
 * 
 * 可用变量：
 * - $item: 项目数据（可能为 null）
 * - $unlocked: 是否已解锁密码保护
 * - $shareError: 错误信息（项目不存在/已过期）
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

$baseUrl = getBaseUrl();
$shareCode = $item['share_code'] ?? '';
$shareUrl = $baseUrl . '?s=' . $shareCode;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0F172A" media="(prefers-color-scheme: dark)">
    <title><?php echo $item ? htmlspecialchars(($item['type'] === 'file' ? $item['name'] : '文本片段')) . ' - ' . htmlspecialchars(SITE_TITLE) : '分享 - ' . htmlspecialchars(SITE_TITLE); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/reset.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/upload.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo time(); ?>">
    <style>
        .share-page {
            max-width: 680px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .share-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 32px;
            box-shadow: var(--card-shadow);
        }
        .share-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .share-header svg {
            flex-shrink: 0;
            color: var(--accent-blue);
        }
        .share-header h1 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            word-break: break-all;
        }
        .share-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            color: var(--text-secondary);
            font-size: 13px;
            margin-bottom: 20px;
        }
        .share-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .share-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        .share-actions .btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
        .share-link-box {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }
        .share-link-box input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-family: JetBrains Mono, monospace;
            font-size: 13px;
        }
        .share-qr {
            text-align: center;
            margin-top: 20px;
        }
        .share-qr canvas {
            border-radius: 8px;
        }
        .share-password-form {
            text-align: center;
            padding: 40px 20px;
        }
        .share-password-form h2 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .share-password-form p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        .share-password-form input {
            width: 100%;
            max-width: 300px;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 14px;
            margin-bottom: 12px;
            box-sizing: border-box;
        }
        .share-password-form .password-error {
            color: var(--accent-red);
            font-size: 13px;
            margin-bottom: 8px;
        }
        .share-error {
            text-align: center;
            padding: 60px 20px;
        }
        .share-error svg {
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        .share-error h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .share-error p {
            color: var(--text-secondary);
        }
        .share-text-content {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
        }
        .share-text-content pre {
            margin: 0;
            font-family: JetBrains Mono, monospace;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text-primary);
            white-space: pre-wrap;
            word-break: break-all;
        }
        .share-download-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--text-muted);
            font-size: 12px;
        }
        .share-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 20px;
            transition: color 0.2s;
        }
        .share-back:hover {
            color: var(--accent-blue);
        }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle" id="themeToggle" title="切换主题">
        <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
        <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
            <line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
    </button>

    <div class="share-page">
        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="share-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12,19 5,12 12,5"/>
            </svg>
            返回首页
        </a>

        <?php if (isset($shareError)): ?>
            <!-- 错误状态 -->
            <div class="share-card share-error">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <h2><?php echo htmlspecialchars($shareError); ?></h2>
                <p>该分享链接无效或内容已过期</p>
            </div>
        <?php elseif (!$unlocked): ?>
            <!-- 密码保护（F2） -->
            <div class="share-card share-password-form">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                <h2>需要密码访问</h2>
                <p>此内容已设置密码保护</p>
                <div id="passwordError" class="password-error" style="display:none"></div>
                <form id="sharePasswordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="verify_share_password">
                    <input type="hidden" name="share_code" value="<?php echo htmlspecialchars($shareCode); ?>">
                    <input type="password" name="password" id="sharePasswordInput" placeholder="输入访问密码" required autofocus>
                    <button type="submit" class="btn btn-primary" style="width:100%;max-width:300px">验证</button>
                </form>
            </div>
        <?php else: ?>
            <!-- 已解锁 / 无密码 -->
            <div class="share-card">
                <div class="share-header">
                    <?php if ($item['type'] === 'file'): ?>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                            <polyline points="13,2 13,9 20,9"/>
                        </svg>
                        <h1><?php echo htmlspecialchars($item['name']); ?></h1>
                    <?php else: ?>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                        <h1>文本片段</h1>
                    <?php endif; ?>
                </div>

                <div class="share-meta">
                    <?php if ($item['type'] === 'file'): ?>
                        <span><?php echo formatSize($item['size']); ?></span>
                    <?php endif; ?>
                    <span>上传于 <?php echo date('Y-m-d H:i', $item['time']); ?></span>
                    <span>有效期: <?php echo formatExpire($item['expire']); ?></span>
                    <?php if ($item['download_count'] > 0): ?>
                        <span class="share-download-count">↓ <?php echo $item['download_count']; ?> 次访问</span>
                    <?php endif; ?>
                </div>

                <?php if ($item['type'] === 'text'): ?>
                    <div class="share-text-content">
                        <pre class="line-numbers"><code id="shareTextContent" class="language-plaintext"><?php echo htmlspecialchars($item['content'] ?? ''); ?></code></pre>
                    </div>
                <?php endif; ?>

                <div class="share-actions">
                    <?php if ($item['type'] === 'file'): ?>
                        <a href="?download=<?php echo $item['id']; ?>" class="btn btn-primary">下载文件</a>
                        <?php
                            $ext = strtolower(pathinfo($item['name'] ?? '', PATHINFO_EXTENSION));
                            $previewableExts = array_merge(
                                ['jpg','jpeg','png','gif','webp','bmp','svg','ico'],
                                ['mp4','webm','ogv','ogg'],
                                ['mp3','wav','aac','flac','m4a','opus'],
                                ['pdf','md','markdown']
                            );
                            if (in_array($ext, $previewableExts)):
                        ?>
                            <a href="?preview=<?php echo $item['share_code']; ?>" class="btn btn-secondary" target="_blank">在线预览</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" id="shareCopyBtn">复制文本</button>
                    <?php endif; ?>
                </div>

                <div class="share-link-box">
                    <input type="text" id="shareLinkInput" value="<?php echo htmlspecialchars($shareUrl); ?>" readonly>
                    <button type="button" class="btn btn-secondary" id="shareLinkCopyBtn">复制</button>
                </div>

                <div class="share-qr" id="shareQrContainer">
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
    <script src="assets/js/qrcode.min.js?v=<?php echo time(); ?>"></script>
    <script>
        // 主题切换
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
            themeToggle.addEventListener('click', function() {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', newTheme === 'dark' ? 'dark' : '');
                localStorage.setItem('theme', newTheme);
            });
        })();

        // 文本语法高亮
        (function() {
            var codeEl = document.getElementById('shareTextContent');
            if (codeEl) {
                var text = codeEl.textContent || '';
                var lang = 'plaintext';
                if (/\b(function|var|let|const|=>|async|await)\b/.test(text)) lang = 'javascript';
                else if (/\b(def |import |from |class |if __name__)\b/.test(text)) lang = 'python';
                else if (/<\?php|\$\w+/.test(text)) lang = 'php';
                else if (/<\/?[a-z][\s\S]*>/i.test(text)) lang = 'markup';
                else if (/\{[\s\S]*?:[\s\S]*?;/.test(text)) lang = 'css';
                else if (/^\s*[\[{]/.test(text)) lang = 'json';
                else if (/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)\b/i.test(text)) lang = 'sql';
                else if (/\b(#!\/bin\/|npm |yarn |pip |apt |sudo )\b/.test(text)) lang = 'bash';
                codeEl.className = 'language-' + lang;
                Prism.highlightElement(codeEl);
            }
        })();

        // 二维码生成
        (function() {
            var container = document.getElementById('shareQrContainer');
            var input = document.getElementById('shareLinkInput');
            if (container && input && typeof QRCode !== 'undefined') {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                new QRCode(container, {
                    text: input.value,
                    width: 200,
                    height: 200,
                    colorDark: isDark ? '#F1F5F9' : '#111827',
                    colorLight: isDark ? '#1E293B' : '#FFFFFF',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        })();

        // 复制链接
        (function() {
            var copyBtn = document.getElementById('shareLinkCopyBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var input = document.getElementById('shareLinkInput');
                    input.select();
                    navigator.clipboard.writeText(input.value).then(function() {
                        copyBtn.textContent = '已复制';
                        setTimeout(function() { copyBtn.textContent = '复制'; }, 2000);
                    }).catch(function() {
                        document.execCommand('copy');
                        copyBtn.textContent = '已复制';
                        setTimeout(function() { copyBtn.textContent = '复制'; }, 2000);
                    });
                });
            }
        })();

        // 复制文本
        (function() {
            var copyBtn = document.getElementById('shareCopyBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var codeEl = document.getElementById('shareTextContent');
                    if (codeEl) {
                        navigator.clipboard.writeText(codeEl.textContent).then(function() {
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制文本'; }, 2000);
                        }).catch(function() {
                            var range = document.createRange();
                            range.selectNodeContents(codeEl);
                            var sel = window.getSelection();
                            sel.removeAllRanges();
                            sel.addRange(range);
                            document.execCommand('copy');
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制文本'; }, 2000);
                        });
                    }
                });
            }
        })();

        // 密码验证
        (function() {
            var form = document.getElementById('sharePasswordForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var formData = new FormData(form);
                    var errorEl = document.getElementById('passwordError');

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            errorEl.textContent = data.message || '密码错误';
                            errorEl.style.display = 'block';
                        }
                    })
                    .catch(function() {
                        errorEl.textContent = '验证失败，请重试';
                        errorEl.style.display = 'block';
                    });
                });
            }
        })();
    </script>
</body>
</html>
