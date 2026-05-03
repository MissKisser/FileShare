<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件上传与文本存储系统</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
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

    <!-- 主内容区 -->
    <main class="main-content">
        <!-- 页面标题 -->
        <header class="page-header">
            <h1>文件暂存</h1>
            <p>轻量级文件上传与文本存储系统</p>
        </header>

        <!-- 统计条 -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-label">文件存储</div>
                <div class="stat-value"><?php echo count(array_filter($data, function($i) { return $i['type'] === 'file'; })); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">文本片段</div>
                <div class="stat-value"><?php echo count(array_filter($data, function($i) { return $i['type'] === 'text'; })); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">总项目数</div>
                <div class="stat-value"><?php echo count($data); ?></div>
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
                    <button type="submit" class="btn btn-success">持久化文本</button>
                </form>
            </div>

            <!-- 存储概览 -->
            <div class="grid-card section-full">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                        <polyline points="13,2 13,9 20,9"/>
                    </svg>
                    存储概览
                </h2>
                <div class="list">
                    <?php if (empty($data)): ?>
                        <div class="empty">云端无挂载数据</div>
                    <?php else: ?>
                        <?php foreach ($data as $index => $item): ?>
                            <div class="item">
                                <div class="item-info">
                                    <div class="item-name">
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
                                        <?php if (isset($item['ip'])): ?>
                                            • <?php echo htmlspecialchars($item['ip']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($item['type'] === 'text'): ?>
                                        <div class="text-preview">
                                            <pre><?php echo htmlspecialchars(mb_substr($item['content'], 0, 150)); ?><?php if (mb_strlen($item['content']) > 150) echo '...'; ?></pre>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="item-actions">
                                    <?php if ($item['type'] === 'file'): ?>
                                        <a href="?download=<?php echo $index; ?>" class="btn-small btn-secondary">拉取</a>
                                    <?php else: ?>
                                        <button class="btn-small btn-secondary btn-view" data-index="<?php echo $index; ?>" data-content="<?php echo htmlspecialchars($item['content'], ENT_QUOTES, 'UTF-8'); ?>">展开</button>
                                        <button class="btn-small btn-secondary btn-copy" data-content="<?php echo htmlspecialchars($item['content'], ENT_QUOTES, 'UTF-8'); ?>">复制</button>
                                    <?php endif; ?>
                                    <button class="btn-small btn-danger btn-delete" data-index="<?php echo $index; ?>">移除</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 上行日志 -->
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
    </script>
</body>
</html>
