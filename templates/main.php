<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0F172A" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?php echo htmlspecialchars(SITE_TITLE); ?></title>
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
</head>
<body>
    <!-- 主题切换按钮 -->
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

    <!-- 拖拽上传面板 -->
    <div id="dragOverlay" class="drag-overlay">
        <div class="drag-content">
            <div class="drag-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17,8 12,3 7,8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
            </div>
            <div class="drag-text">松开鼠标开始上传</div>
            <div class="drag-hint">支持任何格式文件</div>
        </div>
    </div>

    <div id="dragFilePanel" class="drag-file-panel">
        <div class="drag-file-header">
            <span class="drag-file-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                </svg>
                排队上传 (<span id="dragFileCount">0</span>)
            </span>
            <button type="button" id="clearAllFiles" class="btn-clear-all">清空</button>
        </div>
        <div id="dragFileList" class="drag-file-list"></div>
        <div class="drag-file-footer">
            <span id="dragTotalSize" class="drag-total-size">0 B</span>
            <div class="drag-file-actions">
                <button type="button" id="cancelDragUpload" class="btn-cancel-upload">取消</button>
                <button type="button" id="startDragUpload" class="btn-start-upload">提交</button>
            </div>
        </div>
    </div>

    <div id="uploadProgressPanel" class="upload-progress-panel">
        <div class="progress-panel-header">
            <span class="progress-title">上传处理中...</span>
            <button type="button" id="cancelUpload" class="btn-cancel-upload">中止</button>
        </div>
        <div class="progress-overall">
            <div class="progress-label">
                <span>总进度</span><span id="overallProgress">0%</span>
            </div>
            <div class="progress-bar-container"><div id="overallProgressBar" class="progress-bar-fill"></div></div>
        </div>
        <div id="fileProgressList" class="file-progress-list"></div>
    </div>

    <div id="successPanel" class="success-panel">
        <div class="success-content">
            <div class="success-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="9,12 12,15 16,10"/>
                </svg>
            </div>
            <div class="success-title">传输完成</div>
            <div id="successMessage" class="success-message"></div>
            <div id="successShareLinks" class="success-share-links"></div>
            <div class="success-actions">
                <button type="button" id="viewFilesBtn" class="btn-view-files">刷新列表</button>
                <button type="button" id="continueUploadBtn" class="btn-continue-upload">继续</button>
            </div>
        </div>
    </div>

    <div id="codeModal" class="code-modal-overlay">
        <div class="code-modal">
            <div class="code-modal-header">
                <div class="code-modal-title">快速预览</div>
                <div class="code-modal-actions">
                    <button type="button" id="codeModalCopy" class="code-modal-btn">复制</button>
                    <button type="button" id="codeModalClose" class="code-modal-btn">关闭</button>
                </div>
            </div>
            <div class="code-modal-body">
                <pre class="line-numbers"><code id="codeModalContent" class="language-plaintext"></code></pre>
            </div>
        </div>
    </div>

    <!-- 分享弹窗（F1+F14） -->
    <div id="shareModal" class="code-modal-overlay">
        <div class="code-modal" style="max-width:480px">
            <div class="code-modal-header">
                <div class="code-modal-title">分享链接</div>
                <div class="code-modal-actions">
                    <button type="button" id="shareModalClose" class="code-modal-btn">关闭</button>
                </div>
            </div>
            <div class="code-modal-body" style="padding:24px;text-align:center">
                <div id="shareLinkContainer" style="margin-bottom:16px">
                    <input type="text" id="shareLinkInput" readonly style="width:100%;padding:10px 14px;border:1px solid var(--border-color);border-radius:var(--radius-md);background:var(--bg-secondary);color:var(--text-primary);font-family:JetBrains Mono,monospace;font-size:13px;box-sizing:border-box">
                    <button type="button" id="shareLinkCopy" class="btn btn-primary" style="margin-top:8px;width:100%">复制链接</button>
                </div>
                <div id="shareQrContainer" style="margin-top:16px">
                </div>
            </div>
        </div>
    </div>

    <!-- 主内容区 -->
    <main class="main-content">
        <!-- 页面标题 -->
        <header class="page-header">
            <h1><?php echo htmlspecialchars(SITE_TITLE); ?></h1>
            <p>轻量级文件上传与文本存储系统</p>
        </header>

        <!-- 统计条 -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-label">文件存储</div>
                <div class="stat-value"><?php echo $stats['file_count']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">文本片段</div>
                <div class="stat-value"><?php echo $stats['text_count']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">总项目数</div>
                <div class="stat-value"><?php echo $stats['total_items']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">磁盘占用</div>
                <div class="stat-value"><?php echo formatSize($stats['disk_usage']); ?></div>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- 功能卡片区 -->
        <div class="grid-layout">
            <!-- 上传区锚点 -->
            <div id="section-upload" class="section-anchor"></div>
            <!-- 文件上传卡片 -->
            <div class="grid-card card-upload" id="uploadCard">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17,8 12,3 7,8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    文件寄送
                </h2>
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label>选择需上传的文件</label>
                        <input type="file" name="files[]" id="fileInput" multiple required accept="*/*">
                    </div>
                    <div class="form-group">
                        <label>访问时效</label>
                        <select name="duration">
                            <option value="600">10分钟</option>
                            <option value="3600">1小时</option>
                            <option value="86400">1天</option>
                            <option value="0">无限制</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>访问密码（可选，留空则公开）</label>
                        <input type="password" name="access_password" id="uploadAccessPassword" placeholder="设置密码保护" autocomplete="new-password">
                    </div>
                    <div id="selectedFilesContainer" class="selected-files-container" style="display: none;">
                        <div class="selected-files-header">
                            <span class="selected-files-title">队列 (<span id="selectedFileCount">0</span>)</span>
                            <button type="button" id="clearSelectedFiles" class="btn-clear-selected">清空</button>
                        </div>
                        <div id="selectedFileList" class="selected-file-list"></div>
                        <div class="selected-files-footer">
                            <span id="selectedTotalSize" class="selected-total-size">0 B</span>
                        </div>
                    </div>
                    <div id="progressContainer" style="display: none;">
                        <div class="progress-stats">
                            <span id="statusText">传输中...</span>
                            <span id="progressText">0%</span>
                        </div>
                        <div class="progress-track"><div id="progressBar"></div></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">确认上传</button>
                </form>
            </div>

            <!-- 文本共享卡片 -->
            <div class="grid-card card-text" id="textCard">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    剪贴板共享
                </h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label>纯文本内容</label>
                        <textarea name="text" placeholder="输入或粘贴文本内容..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>留存时间</label>
                        <select name="text_duration">
                            <option value="600" selected>10分钟</option>
                            <option value="3600">1小时</option>
                            <option value="86400">1天</option>
                            <option value="0">无限制</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>访问密码（可选，留空则公开）</label>
                        <input type="password" name="access_password" placeholder="设置密码保护" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-success">持久化文本</button>
                </form>
            </div>

            <!-- 存储概览 -->
            <!-- 存储区锚点 -->
            <div id="section-storage" class="section-anchor"></div>
            <div class="grid-card section-full">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                        <polyline points="13,2 13,9 20,9"/>
                    </svg>
                    存储概览
                </h2>

                <!-- 搜索与过滤栏（F6） -->
                <div class="search-filter-bar">
                    <input type="text" id="searchInput" class="search-input" placeholder="搜索文件名或文本内容...">
                    <div class="filter-tags">
                        <button class="filter-tag active" data-filter="all">全部</button>
                        <button class="filter-tag" data-filter="file">文件</button>
                        <button class="filter-tag" data-filter="text">文本</button>
                        <button class="filter-tag" data-filter="image">图片</button>
                        <button class="filter-tag" data-filter="video">视频</button>
                        <button class="filter-tag" data-filter="audio">音频</button>
                        <button class="filter-tag" data-filter="doc">文档</button>
                        <button class="filter-tag" data-filter="code">代码</button>
                        <button class="filter-tag" data-filter="archive">压缩包</button>
                    </div>
                    <div class="sort-select">
                        <select id="sortSelect">
                            <option value="time-desc">时间 ↓</option>
                            <option value="time-asc">时间 ↑</option>
                            <option value="size-desc">大小 ↓</option>
                            <option value="size-asc">大小 ↑</option>
                            <option value="name-asc">名称 A-Z</option>
                        </select>
                    </div>
                </div>

                <!-- 批量操作栏（F7） -->
                <div class="batch-actions-bar" id="batchActionsBar" style="display:none">
                    <label class="batch-select-all">
                        <input type="checkbox" id="selectAllCheckbox"> 全选
                    </label>
                    <button type="button" class="btn-small btn-danger" id="batchDeleteBtn">批量删除</button>
                    <button type="button" class="btn-small btn-secondary" id="batchCopyLinksBtn">复制链接</button>
                    <span class="batch-count" id="batchCount">已选 0 项</span>
                </div>

                <div class="list" id="storageList">
                    <?php if (empty($data)): ?>
                        <div class="empty">云端无挂载数据</div>
                    <?php else: ?>
                        <?php foreach ($data as $item): ?>
                            <div class="item" data-id="<?php echo $item['id']; ?>" data-share-code="<?php echo $item['share_code']; ?>" data-type="<?php echo $item['type']; ?>">
                                <div class="item-select">
                                    <input type="checkbox" class="item-checkbox" data-id="<?php echo $item['id']; ?>">
                                </div>
                                <div class="item-info">
                                    <div class="item-name">
                                        <?php if (!empty($item['password'])): ?>
                                            <svg class="icon-lock" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;opacity:0.5">
                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                            </svg>
                                        <?php endif; ?>
                                        <?php if ($item['type'] === 'file'): ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                                                <polyline points="13,2 13,9 20,9"/>
                                            </svg>
                                            <?php echo htmlspecialchars($item['name']); ?>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <polyline points="14,2 14,8 20,8"/>
                                                <line x1="16" y1="13" x2="8" y2="13"/>
                                                <line x1="16" y1="17" x2="8" y2="17"/>
                                            </svg>
                                            文本片段
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-meta">
                                        <?php if ($item['type'] === 'file'): ?>
                                            <?php echo formatSize($item['size']); ?> •
                                        <?php endif; ?>
                                        入库: <?php echo date('Y-m-d H:i', $item['time']); ?> •
                                        剩余: <?php echo formatExpire($item['expire']); ?>
                                        <?php if (!empty($item['download_count'])): ?>
                                            • <span title="下载/查看次数">↓<?php echo $item['download_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($item['type'] === 'text'): ?>
                                        <div class="text-preview">
                                            <pre><?php echo htmlspecialchars(mb_substr($item['content'] ?? '', 0, 150)); ?><?php if (mb_strlen($item['content'] ?? '') > 150) echo '...'; ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="item-actions">
                                    <?php if ($item['type'] === 'file'): ?>
                                        <a href="?download=<?php echo $item['id']; ?>" class="btn-small btn-secondary">拉取</a>
                                        <?php
                                            // 判断是否可预览
                                            $ext = strtolower(pathinfo($item['name'] ?? '', PATHINFO_EXTENSION));
                                            $previewableExts = array_merge(
                                                ['jpg','jpeg','png','gif','webp','bmp','svg','ico'],
                                                ['mp4','webm','ogv','ogg'],
                                                ['mp3','wav','aac','flac','m4a','opus'],
                                                ['pdf','md','markdown']
                                            );
                                            if (in_array($ext, $previewableExts)):
                                        ?>
                                            <button class="btn-small btn-secondary btn-preview" data-share-code="<?php echo $item['share_code']; ?>">预览</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn-small btn-secondary btn-view" data-id="<?php echo $item['id']; ?>" data-content="<?php echo htmlspecialchars($item['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">展开</button>
                                        <button class="btn-small btn-secondary btn-copy" data-content="<?php echo htmlspecialchars($item['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">复制</button>
                                    <?php endif; ?>
                                    <button class="btn-small btn-secondary btn-share" data-share-code="<?php echo $item['share_code']; ?>">分享</button>
                                    <button class="btn-small btn-danger btn-delete" data-id="<?php echo $item['id']; ?>">移除</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 上行日志 -->
            <!-- 日志区锚点 -->
            <div id="section-log" class="section-anchor"></div>
            <div class="grid-card section-full">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    上行日志审计
                </h2>
                <div class="log-container">
                    <?php if (empty($uploadLogs)): ?>
                        <div class="empty">审计队列为空</div>
                    <?php else: ?>
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>时间戳</th>
                                    <th>资产</th>
                                    <th>大小</th>
                                    <th>远端</th>
                                    <th>策略</th>
                                    <th>TTL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploadLogs as $log): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', $log['upload_time']); ?></td>
                                        <td class="log-filename"><?php echo htmlspecialchars($log['filename']); ?></td>
                                        <td><?php echo formatSize($log['filesize']); ?></td>
                                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo formatDuration($log['duration']); ?></td>
                                        <td><?php echo ($log['expire_time'] === 0) ? '持久化' : date('Y-m-d H:i:s', $log['expire_time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 桌面端右侧导航栏 -->
        <nav class="side-nav" id="sideNav">
            <a href="#section-upload" class="side-nav-item active" data-target="section-upload" title="上传区">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17,8 12,3 7,8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <span class="side-nav-label">上传</span>
            </a>
            <a href="#section-storage" class="side-nav-item" data-target="section-storage" title="存储区">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                    <polyline points="13,2 13,9 20,9"/>
                </svg>
                <span class="side-nav-label">存储</span>
            </a>
            <a href="#section-log" class="side-nav-item" data-target="section-log" title="日志区">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14,2 14,8 20,8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span class="side-nav-label">日志</span>
            </a>
        </nav>

        <!-- 移动端底部导航栏 -->
        <nav class="bottom-nav" id="bottomNav">
            <a href="#section-upload" class="bottom-nav-item active" data-target="section-upload">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17,8 12,3 7,8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <span>上传</span>
            </a>
            <a href="#section-storage" class="bottom-nav-item" data-target="section-storage">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                    <polyline points="13,2 13,9 20,9"/>
                </svg>
                <span>存储</span>
            </a>
            <a href="#section-log" class="bottom-nav-item" data-target="section-log">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14,2 14,8 20,8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span>日志</span>
            </a>
        </nav>
    </main>

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
    <!-- 二维码生成库（F14） -->
    <script src="assets/js/qrcode.min.js?v=<?php echo time(); ?>"></script>
    <!-- 统计图表库（F8） -->
    <script src="assets/js/charts.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/upload.js?v=<?php echo time(); ?>"></script>
    <script>
        // 主题切换功能
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');

            // 初始化主题
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }

            // 切换主题
            themeToggle.addEventListener('click', function() {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', newTheme === 'dark' ? 'dark' : '');
                localStorage.setItem('theme', newTheme);
            });
        })();

        // 站点配置
        window.FILESHARE_BASE_URL = '<?php echo getBaseUrl(); ?>';
        window.FILESHARE_CSRF = '<?php echo $_SESSION['csrf_token']; ?>';

        // 导航栏：平滑滚动 + 滚动监听高亮
        (function() {
            var sections = ['section-upload', 'section-storage', 'section-log'];
            var sideNavItems = document.querySelectorAll('.side-nav-item');
            var bottomNavItems = document.querySelectorAll('.bottom-nav-item');

            // 点击导航 → 平滑滚动
            sideNavItems.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var target = document.getElementById(item.getAttribute('data-target'));
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            bottomNavItems.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var target = document.getElementById(item.getAttribute('data-target'));
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            // IntersectionObserver 监听当前区域
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var id = entry.target.id;
                        sideNavItems.forEach(function(item) {
                            item.classList.toggle('active', item.getAttribute('data-target') === id);
                        });
                        bottomNavItems.forEach(function(item) {
                            item.classList.toggle('active', item.getAttribute('data-target') === id);
                        });
                    }
                });
            }, { threshold: 0.3, rootMargin: '-80px 0px -50% 0px' });

            sections.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) observer.observe(el);
            });
        })();
    </script>
</body>
</html>
