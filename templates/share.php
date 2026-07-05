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
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/reset.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/upload.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/responsive.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/gallery.css?v=<?php echo APP_VERSION; ?>">
    <style>
        .share-page {
            max-width: 960px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .share-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--card-shadow);
        }
        /* 两栏布局：左侧主内容，右侧侧边栏（≥768px 横向） */
        .share-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 28px;
            align-items: start;
        }
        .share-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .share-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding: 18px;
            background: var(--bg-secondary);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            position: sticky;
            top: 20px;
        }
        .share-sidebar-section + .share-sidebar-section {
            padding-top: 16px;
            border-top: 1px dashed var(--card-border);
        }
        .share-sidebar-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }
        .share-link-box {
            display: flex;
            gap: 6px;
        }
        .share-link-box input {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            color: var(--text-primary);
            font-family: JetBrains Mono, monospace;
            font-size: 12px;
        }
        .share-link-box .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
        }
        .share-qr-section .share-qr {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .share-qr canvas,
        .share-qr img {
            display: block;
            margin: 0 auto;
            border-radius: 8px;
            max-width: 100%;
            height: auto;
        }
        .share-qr {
            text-align: center;
        }
        .share-meta-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .share-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 13px;
        }
        .share-meta-item svg {
            flex-shrink: 0;
            color: var(--text-muted);
        }
        .share-meta-item .countdown-value {
            color: var(--accent-blue);
            font-family: JetBrains Mono, monospace;
            font-weight: 600;
        }
        .share-meta-item.share-meta-countdown[data-expired="1"] .countdown-value {
            color: var(--accent-red);
        }
        .share-header {
            display: flex;
            align-items: center;
            gap: 12px;
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
            margin: 0;
        }
        .share-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .share-actions .btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
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
            border: 1px solid var(--card-border);
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
        /* 文本内容：工具栏 + 预览框 */
        .share-text-content {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        .share-text-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--card-border);
            gap: 12px;
        }
        .share-text-stats {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            font-family: JetBrains Mono, monospace;
        }
        .share-text-stats .stat-label {
            color: var(--text-muted);
        }
        .share-text-stats .stat-value {
            color: var(--text-primary);
            font-weight: 600;
        }
        .share-text-pre,
        pre[class*="language-"].share-text-pre {
            margin: 0;
            padding: 18px 20px 18px 22px; /* 左侧略多留白给 accent stripe */
            background: var(--bg-tertiary); /* 与外层 .share-card(--card-bg) 形成对比 */
            border: 1px solid var(--card-border);
            border-left: 3px solid var(--accent-blue); /* 代码块通用 accent 边线 */
            border-radius: var(--radius-md);
            max-height: 480px;
            overflow: auto;
            font-size: 13px;
            line-height: 1.6;
        }
        .share-text-pre code,
        pre[class*="language-"].share-text-pre code {
            font-family: JetBrains Mono, monospace;
            color: var(--text-primary);
            white-space: pre-wrap;
            word-break: break-word;
            background: transparent;
        }
        /* 移动端：单列堆叠，侧边栏在上/下都可（这里选择先内容后侧边栏） */
        @media (max-width: 768px) {
            .share-page { max-width: 100%; }
            .share-layout {
                grid-template-columns: minmax(0, 1fr);
                gap: 20px;
            }
            .share-sidebar {
                position: static;
                order: -1; /* 移动端侧边栏置顶 */
            }
            .share-text-pre {
                max-height: 360px;
                font-size: 12px;
            }
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
        .share-image-preview {
            margin-top: 16px;
            text-align: center;
        }
        .share-image-preview img {
            max-width: 100%;
            max-height: 500px;
            border-radius: var(--radius-md);
            cursor: zoom-in;
            object-fit: contain;
        }
        /* 压缩包预览 (B3) */
        .archive-preview-container {
            margin-top: 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        .archive-tree {
            max-height: 400px;
            overflow-y: auto;
            padding: 12px;
        }
        .archive-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .archive-title { font-weight: 600; font-size: 14px; }
        .archive-count { font-size: 12px; color: var(--text-secondary); }
        .archive-folder-header {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 13px;
        }
        .archive-folder-header:hover { background: var(--bg-secondary); }
        .archive-toggle { font-size: 10px; width: 14px; }
        .archive-folder-body { padding-left: 20px; }
        .archive-file {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 13px;
        }
        .archive-file:hover { background: var(--bg-secondary); }
        .archive-icon { font-size: 14px; flex-shrink: 0; }
        .archive-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .archive-size { font-size: 11px; color: var(--text-secondary); flex-shrink: 0; }
        .archive-content-view {
            border-top: 1px solid var(--border-color);
            max-height: 300px;
            overflow: auto;
        }
        .archive-content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-secondary);
            position: sticky;
            top: 0;
        }
        .archive-close-btn {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0 4px;
        }
        .archive-content-body {
            margin: 0;
            padding: 12px;
            font-family: JetBrains Mono, monospace;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-all;
            overflow-x: auto;
        }
        .archive-error, .archive-empty {
            text-align: center;
            padding: 24px;
            color: var(--text-secondary);
            font-size: 14px;
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
            <!-- 已解锁 / 无密码：左侧主内容 + 右侧侧边栏（链接+二维码+元信息） -->
            <div class="share-card share-layout">
                <div class="share-main">
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

                    <?php if ($item['type'] === 'text'): ?>
                        <div class="share-text-content">
                            <div class="share-text-toolbar">
                                <span class="share-text-stats">
                                    <span class="stat-label">字符</span>
                                    <span class="stat-value" id="shareTextLength"><?php echo mb_strlen($item['content'] ?? ''); ?></span>
                                    <span class="stat-label">·</span>
                                    <span class="stat-label">行</span>
                                    <span class="stat-value" id="shareTextLines"><?php echo substr_count($item['content'] ?? '', "\n") + 1; ?></span>
                                </span>
                                <button type="button" class="btn btn-primary btn-sm" id="shareCopyBtn">复制文本</button>
                            </div>
                            <pre class="line-numbers share-text-pre"><code id="shareTextContent" class="language-plaintext"><?php echo htmlspecialchars($item['content'] ?? ''); ?></code></pre>
                        </div>
                    <?php endif; ?>

                    <?php
                        // 图片内联预览（A2 灯箱）
                        $imageExts = array('jpg','jpeg','png','gif','webp','bmp','svg','ico');
                        $fileExt = strtolower(pathinfo($item['name'] ?? '', PATHINFO_EXTENSION));
                        if ($item['type'] === 'file' && in_array($fileExt, $imageExts)):
                    ?>
                        <div class="share-image-preview">
                            <img src="?preview=<?php echo htmlspecialchars($item['share_code']); ?>"
                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                 data-gallery>
                        </div>
                    <?php endif; ?>

                    <?php
                        // 压缩包在线预览（B3）
                        $archiveExts = array('zip','tar','gz','tgz');
                        if ($item['type'] === 'file' && in_array($fileExt, $archiveExts)):
                    ?>
                        <div id="archivePreviewContainer" class="archive-preview-container" style="display:none"></div>
                    <?php endif; ?>

                    <?php if ($item['type'] === 'file'): ?>
                        <div class="share-actions">
                            <a href="?download=<?php echo $item['id']; ?>" class="btn btn-primary">下载文件</a>
                            <?php
                                $previewableExts = array_merge(
                                    array('jpg','jpeg','png','gif','webp','bmp','svg','ico'),
                                    array('mp4','webm','ogv','ogg'),
                                    array('mp3','wav','aac','flac','m4a','opus'),
                                    array('pdf','md','markdown')
                                );
                                if (in_array($fileExt, $previewableExts)):
                            ?>
                                <a href="?preview=<?php echo $item['share_code']; ?>" class="btn btn-secondary" target="_blank">在线预览</a>
                            <?php elseif (in_array($fileExt, $archiveExts)): ?>
                                <button type="button" class="btn btn-secondary" id="archivePreviewBtn" data-item-id="<?php echo $item['id']; ?>">在线预览</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="share-sidebar">
                    <div class="share-sidebar-section">
                        <div class="share-sidebar-label">分享链接</div>
                        <div class="share-link-box">
                            <input type="text" id="shareLinkInput" value="<?php echo htmlspecialchars($shareUrl); ?>" readonly>
                            <button type="button" class="btn btn-secondary btn-sm" id="shareLinkCopyBtn">复制</button>
                        </div>
                    </div>

                    <div class="share-sidebar-section share-qr-section">
                        <div class="share-sidebar-label">扫码分享</div>
                        <div class="share-qr" id="shareQrContainer"></div>
                    </div>

                    <div class="share-sidebar-section share-meta-section">
                        <div class="share-meta-item share-meta-time">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="share-meta-text">上传于 <?php echo date('Y-m-d H:i', $item['time']); ?></span>
                        </div>
                        <div class="share-meta-item share-meta-ip">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <span class="share-meta-text">来源 IP: <?php echo htmlspecialchars(maskIP($item['ip'] ?? '')); ?></span>
                        </div>
                        <div class="share-meta-item share-meta-countdown" id="shareCountdown" data-expire="<?php echo (int)$item['expire']; ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                            <span class="share-meta-text">
                                <?php if ((int)$item['expire'] === 0): ?>
                                    永久有效
                                <?php else: ?>
                                    剩余 <span class="countdown-value" id="countdownValue">--</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($item['download_count'])): ?>
                        <div class="share-meta-item share-meta-count">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            <span class="share-meta-text">已访问 <?php echo (int)$item['download_count']; ?> 次</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($item['type'] === 'file'): ?>
                        <div class="share-meta-item share-meta-size">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            <span class="share-meta-text"><?php echo formatSize($item['size']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($isOwner)): ?>
                        <!--
                            Owner 管理入口（仅本人可见）
                            来源：URL ?manage=<token> 验证成功 / 本会话已验过
                            删除按钮需 manage_token 存在（保证后续 POST 能通过）
                        -->
                        <div class="share-sidebar-section share-owner-section">
                            <div class="share-sidebar-label">管理操作</div>
                            <?php if (!empty($manageToken)): ?>
                                <button type="button" class="btn btn-danger btn-sm btn-block" id="ownerDeleteBtn">删除我的上传</button>
                                <p class="share-owner-hint">这是上传者本人才能看到的操作</p>
                            <?php else: ?>
                                <p class="share-owner-hint">已识别为上传者；如需删除，请使用原始管理链接</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </aside>
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
    <script src="/assets/js/qrcode.min.js?v=<?php echo APP_VERSION; ?>"></script>
    <script>
        // 手动复制兜底弹窗（移动端 / 旧浏览器最后手段）
        function showManualCopyFallback(text) {
            var existing = document.getElementById('manualCopyFallback');
            if (existing) existing.remove();
            var overlay = document.createElement('div');
            overlay.id = 'manualCopyFallback';
            overlay.className = 'manual-copy-overlay';
            overlay.innerHTML = '<div class="manual-copy-modal">' +
                '<div class="manual-copy-header">' +
                '<div class="manual-copy-title">请手动复制</div>' +
                '<button type="button" class="manual-copy-close" aria-label="关闭">&times;</button>' +
                '</div>' +
                '<div class="manual-copy-body">' +
                '<p class="manual-copy-hint">长按下方文本框，然后选择"复制"</p>' +
                '<textarea class="manual-copy-textarea" readonly></textarea>' +
                '</div>' +
                '<div class="manual-copy-footer">' +
                '<button type="button" class="manual-copy-select-all">全选并复制</button>' +
                '</div>' +
                '</div>';
            document.body.appendChild(overlay);
            var textarea = overlay.querySelector('.manual-copy-textarea');
            textarea.value = text;
            setTimeout(function() {
                textarea.focus();
                textarea.select();
                textarea.setSelectionRange(0, text.length);
            }, 100);
            var closeBtn = overlay.querySelector('.manual-copy-close');
            var close = function() {
                overlay.classList.remove('show');
                setTimeout(function() { overlay.remove(); }, 200);
            };
            closeBtn.addEventListener('click', close);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) close();
            });
            var selectAllBtn = overlay.querySelector('.manual-copy-select-all');
            selectAllBtn.addEventListener('click', function() {
                textarea.focus();
                textarea.select();
                textarea.setSelectionRange(0, text.length);
                try {
                    if (document.execCommand('copy')) {
                        selectAllBtn.textContent = '✓ 已复制';
                        setTimeout(close, 1000);
                    }
                } catch (e) {}
            });
            var escHandler = function(e) {
                if (e.key === 'Escape') {
                    close();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            requestAnimationFrame(function() { overlay.classList.add('show'); });
        }

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

        // 复制链接（必须在 Prism 高亮前绑定，否则 Prism 抛错会中断后续脚本）
        (function() {
            var copyBtn = document.getElementById('shareLinkCopyBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var input = document.getElementById('shareLinkInput');
                    if (!input) return;
                    input.select();
                    var fallback = function() {
                        try {
                            input.focus();
                            input.select();
                            input.setSelectionRange(0, input.value.length);
                            document.execCommand('copy');
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制'; }, 2000);
                        } catch (e) {
                            // 最后兜底：弹窗让用户手动复制
                            showManualCopyFallback(input.value);
                        }
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(input.value).then(function() {
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制'; }, 2000);
                        }).catch(fallback);
                    } else {
                        fallback();
                    }
                });
            }
        })();

        // 复制文本（必须在 Prism 高亮前绑定）
        (function() {
            var copyBtn = document.getElementById('shareCopyBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function() {
                    var codeEl = document.getElementById('shareTextContent');
                    if (!codeEl) return;
                    var text = codeEl.textContent || '';
                    var fallback = function() {
                        try {
                            var range = document.createRange();
                            range.selectNodeContents(codeEl);
                            var sel = window.getSelection();
                            sel.removeAllRanges();
                            sel.addRange(range);
                            document.execCommand('copy');
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制文本'; }, 2000);
                        } catch (e) {
                            // 移动端 HTTP / iOS 旧浏览器：弹窗手动复制
                            showManualCopyFallback(text);
                        }
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(function() {
                            copyBtn.textContent = '已复制';
                            setTimeout(function() { copyBtn.textContent = '复制文本'; }, 2000);
                        }).catch(fallback);
                    } else {
                        fallback();
                    }
                });
            }
        })();

        // 文本语法高亮（必须包 try/catch；某些语言组合下 Prism 内部会抛错，
        // 抛错也不影响上方的复制按钮）
        (function() {
            var codeEl = document.getElementById('shareTextContent');
            if (!codeEl) return;
            try {
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
                if (typeof Prism !== 'undefined' && Prism.languages && Prism.languages[lang]) {
                    Prism.highlightElement(codeEl);
                }
            } catch (e) {
                // 高亮失败不影响功能（不影响上方的复制按钮）
                console.warn('Prism highlight failed:', e);
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

        // 剩余时间倒计时（每秒更新；过期后切换样式）
        (function() {
            var el = document.getElementById('shareCountdown');
            var valueEl = document.getElementById('countdownValue');
            if (!el || !valueEl) return;
            var expire = parseInt(el.getAttribute('data-expire') || '0', 10);
            if (!expire) return; // 0 = 永久
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            function tick() {
                var left = expire - Math.floor(Date.now() / 1000);
                if (left <= 0) {
                    valueEl.textContent = '已过期';
                    el.setAttribute('data-expired', '1');
                    return false;
                }
                var d = Math.floor(left / 86400);
                var h = Math.floor((left % 86400) / 3600);
                var m = Math.floor((left % 3600) / 60);
                var s = left % 60;
                if (d > 0) {
                    valueEl.textContent = d + '天 ' + pad(h) + ':' + pad(m) + ':' + pad(s);
                } else {
                    valueEl.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
                }
                return true;
            }
            if (tick()) {
                setInterval(function() { if (!tick()) clearInterval(this); }, 1000);
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
    <?php if (!empty($isOwner) && !empty($manageToken)): ?>
    <!-- Owner 删除按钮（仅本人可见，仅当 manage_token 可用时渲染） -->
    <script>
        (function() {
            var btn = document.getElementById('ownerDeleteBtn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                if (!window.confirm('确定要删除这个上传吗？此操作不可撤销。')) return;
                var fd = new FormData();
                fd.append('action', 'owner_delete');
                fd.append('share_code', <?php echo json_encode($item['share_code']); ?>);
                fd.append('manage', <?php echo json_encode($manageToken); ?>);
                fetch(window.location.pathname, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) {
                            window.location.href = '/';
                        } else {
                            window.alert('删除失败：' + (d.message || '未知错误'));
                        }
                    })
                    .catch(function() { window.alert('删除请求失败'); });
            });
        })();
    </script>
    <?php endif; ?>
    <!-- 语法高亮封装（A1） -->
    <script src="/assets/js/syntax.js?v=<?php echo APP_VERSION; ?>"></script>
    <!-- 图片灯箱（A2） -->
    <script src="/assets/js/gallery.js?v=<?php echo APP_VERSION; ?>"></script>
    <!-- 压缩包预览（B3） -->
    <script src="/assets/js/archive.js?v=<?php echo APP_VERSION; ?>"></script>
</body>
</html>
