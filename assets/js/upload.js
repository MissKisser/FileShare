/**
 * 文件上传与拖拽上传功能
 * 作者：Hackerdallas
 */
document.addEventListener('DOMContentLoaded', function() {
    // ========================================
    // 配置参数
    // ========================================
    const UPLOAD_CONFIG = {
        maxFileSize: 100 * 1024 * 1024,  // 100MB
        maxFiles: 20,
        allowedTypes: [
            // 图片
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/ico',
            // 视频
            'video/mp4', 'video/webm', 'video/ogg', 'video/avi', 'video/quicktime',
            // 音频
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/flac',
            // 文档
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'text/html', 'text/css',
            // 压缩包
            'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
            'application/x-tar', 'application/gzip', 'application/x-zip-compressed',
            // 代码
            'text/javascript', 'application/javascript', 'application/json', 'text/xml',
            'application/xml', 'text/x-python', 'text/x-php'
        ],
        // 文件类型图标映射
        typeIcons: {
            'image': '🖼️',
            'video': '🎬',
            'audio': '🎵',
            'pdf': '📄',
            'document': '📝',
            'spreadsheet': '📊',
            'presentation': '📽️',
            'archive': '📦',
            'code': '💻',
            'text': '📃',
            'other': '📁'
        }
    };

    // ========================================
    // DOM 元素引用
    // ========================================
    const uploadForm = document.getElementById('uploadForm');
    const fileInput = document.getElementById('fileInput');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const statusText = document.getElementById('statusText');
    const uploadBtn = document.getElementById('uploadBtn');

    // 拖拽上传元素
    const dragOverlay = document.getElementById('dragOverlay');
    
    // 上传区域内的文件列表元素
    const selectedFilesContainer = document.getElementById('selectedFilesContainer');
    const selectedFileList = document.getElementById('selectedFileList');
    const selectedFileCount = document.getElementById('selectedFileCount');
    const selectedTotalSize = document.getElementById('selectedTotalSize');
    const clearSelectedFilesBtn = document.getElementById('clearSelectedFiles');
    
    // 保留旧的引用以保持兼容性（用于上传进度面板）
    const dragFilePanel = document.getElementById('dragFilePanel');
    const dragFileList = document.getElementById('dragFileList');
    const dragFileCount = document.getElementById('dragFileCount');
    const dragTotalSize = document.getElementById('dragTotalSize');
    const clearAllFilesBtn = document.getElementById('clearAllFiles');
    const cancelDragUpload = document.getElementById('cancelDragUpload');
    const startDragUpload = document.getElementById('startDragUpload');

    // 上传进度面板
    const uploadProgressPanel = document.getElementById('uploadProgressPanel');
    const overallProgress = document.getElementById('overallProgress');
    const overallProgressBar = document.getElementById('overallProgressBar');
    const fileProgressList = document.getElementById('fileProgressList');
    const cancelUpload = document.getElementById('cancelUpload');

    // 成功面板
    const successPanel = document.getElementById('successPanel');
    const successMessage = document.getElementById('successMessage');
    const viewFilesBtn = document.getElementById('viewFilesBtn');
    const continueUploadBtn = document.getElementById('continueUploadBtn');

    // ========================================
    // 状态变量
    // ========================================
    let pendingFiles = [];  // 待上传文件列表
    let currentXhr = null;  // 当前上传请求
    let dragCounter = 0;    // 拖拽计数器（用于处理嵌套元素）
    let startTime = null;
    let lastLoaded = 0;
    let lastTime = null;

    // ========================================
    // 工具函数
    // ========================================

    /**
     * 字节数转换为可读文件大小格式
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        if (bytes < 1024) {
            return bytes.toFixed(2) + ' B';
        } else if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        } else if (bytes < 1024 * 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        } else {
            return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }
    }

    /**
     * 格式化剩余时间
     */
    function formatTimeRemaining(seconds) {
        if (!isFinite(seconds) || seconds < 0) return '计算中...';
        if (seconds < 60) {
            return Math.round(seconds) + ' 秒';
        } else if (seconds < 3600) {
            return Math.round(seconds / 60) + ' 分钟';
        } else {
            return Math.round(seconds / 3600) + ' 小时';
        }
    }

    /**
     * 获取文件类型图标
     */
    function getFileTypeIcon(file) {
        const type = file.type || '';
        const name = file.name.toLowerCase();

        // 图片
        if (type.startsWith('image/')) return UPLOAD_CONFIG.typeIcons.image;

        // 视频
        if (type.startsWith('video/')) return UPLOAD_CONFIG.typeIcons.video;

        // 音频
        if (type.startsWith('audio/')) return UPLOAD_CONFIG.typeIcons.audio;

        // PDF
        if (type === 'application/pdf') return UPLOAD_CONFIG.typeIcons.pdf;

        // Word 文档
        if (type.includes('word') || type.includes('document')) return UPLOAD_CONFIG.typeIcons.document;

        // Excel 表格
        if (type.includes('excel') || type.includes('spreadsheet')) return UPLOAD_CONFIG.typeIcons.spreadsheet;

        // PowerPoint 演示
        if (type.includes('powerpoint') || type.includes('presentation')) return UPLOAD_CONFIG.typeIcons.presentation;

        // 压缩包
        if (type.includes('zip') || type.includes('rar') || type.includes('7z') ||
            type.includes('tar') || type.includes('gzip') || type.includes('compressed')) {
            return UPLOAD_CONFIG.typeIcons.archive;
        }

        // 代码文件
        const codeExtensions = ['.js', '.ts', '.py', '.php', '.java', '.c', '.cpp', '.h', '.css', '.scss',
            '.html', '.xml', '.json', '.sql', '.sh', '.bat', '.ps1'];
        if (codeExtensions.some(ext => name.endsWith(ext))) {
            return UPLOAD_CONFIG.typeIcons.code;
        }

        // 文本文件
        if (type.startsWith('text/')) return UPLOAD_CONFIG.typeIcons.text;

        return UPLOAD_CONFIG.typeIcons.other;
    }

    /**
     * 获取文件类型名称
     */
    function getFileTypeName(file) {
        const type = file.type || '';
        const name = file.name.toLowerCase();
        const ext = name.split('.').pop();

        if (type.startsWith('image/')) return '图片';
        if (type.startsWith('video/')) return '视频';
        if (type.startsWith('audio/')) return '音频';
        if (type === 'application/pdf') return 'PDF';
        if (type.includes('word') || type.includes('document')) return '文档';
        if (type.includes('excel') || type.includes('spreadsheet')) return '表格';
        if (type.includes('powerpoint') || type.includes('presentation')) return '演示';
        if (type.includes('zip') || type.includes('rar') || type.includes('7z') ||
            type.includes('tar') || type.includes('gzip')) return '压缩包';

        return ext.toUpperCase() || '文件';
    }

    /**
     * 验证文件
     */
    function validateFile(file) {
        const errors = [];

        // 检查文件大小
        if (file.size > UPLOAD_CONFIG.maxFileSize) {
            errors.push(`文件过大（最大 ${formatFileSize(UPLOAD_CONFIG.maxFileSize)}）`);
        }

        // 检查文件类型（宽松检查，允许所有常见类型）
        // 如果需要严格检查，取消下面的注释
        // if (UPLOAD_CONFIG.allowedTypes.indexOf(file.type) === -1 && file.type !== '') {
        //     errors.push('不支持的文件类型');
        // }

        return {
            valid: errors.length === 0,
            errors: errors
        };
    }

    /**
     * 生成唯一ID
     */
    function generateId() {
        return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    // ========================================
    // 拖拽上传功能
    // ========================================

    /**
     * 初始化拖拽事件
     */
    function initDragEvents() {
        // 阻止浏览器默认拖拽行为
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.addEventListener(eventName, preventDefaults, false);
        });

        // 拖拽进入
        document.addEventListener('dragenter', handleDragEnter, false);

        // 拖拽悬停
        document.addEventListener('dragover', handleDragOver, false);

        // 拖拽离开
        document.addEventListener('dragleave', handleDragLeave, false);

        // 拖拽放下
        document.addEventListener('drop', handleDrop, false);
    }

    /**
     * 阻止默认事件
     */
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    /**
     * 处理拖拽进入
     */
    function handleDragEnter(e) {
        // 检查是否是文件拖拽
        if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1) {
            dragCounter++;
            if (dragCounter === 1) {
                showDragOverlay();
            }
        }
    }

    /**
     * 处理拖拽悬停
     */
    function handleDragOver(e) {
        if (e.dataTransfer) {
            e.dataTransfer.dropEffect = 'copy';
        }
    }

    /**
     * 处理拖拽离开
     */
    function handleDragLeave(e) {
        dragCounter--;
        if (dragCounter === 0) {
            hideDragOverlay();
        }
    }

    /**
     * 处理拖拽放下
     */
    function handleDrop(e) {
        dragCounter = 0;
        hideDragOverlay();

        const files = e.dataTransfer ? e.dataTransfer.files : [];
        if (files.length > 0) {
            handleFiles(files);
        }
    }

    /**
     * 显示拖拽遮罩
     */
    function showDragOverlay() {
        if (dragOverlay) {
            dragOverlay.classList.add('active');
        }
    }

    /**
     * 隐藏拖拽遮罩
     */
    function hideDragOverlay() {
        if (dragOverlay) {
            dragOverlay.classList.remove('active');
        }
    }

    /**
     * 处理拖入的文件
     */
    function handleFiles(files) {
        // 检查文件数量限制
        if (pendingFiles.length + files.length > UPLOAD_CONFIG.maxFiles) {
            showToast(`最多同时上传 ${UPLOAD_CONFIG.maxFiles} 个文件`, 'error');
            return;
        }

        // 处理每个文件
        Array.from(files).forEach(file => {
            const validation = validateFile(file);
            const fileData = {
                id: generateId(),
                file: file,
                name: file.name,
                size: file.size,
                type: file.type,
                valid: validation.valid,
                errors: validation.errors,
                preview: null
            };

            // 生成图片预览
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    fileData.preview = e.target.result;
                    updateSelectedFileItem(fileData);
                };
                reader.readAsDataURL(file);
            }

            pendingFiles.push(fileData);
        });

        // 更新UI - 在上传区域内显示文件列表
        updateSelectedFileList();
        showSelectedFilesContainer();
    }

    /**
     * 更新上传区域内的文件列表UI
     */
    function updateSelectedFileList() {
        if (!selectedFileList) return;

        // 更新文件数量
        if (selectedFileCount) {
            selectedFileCount.textContent = pendingFiles.length;
        }

        // 更新总大小
        const totalSize = pendingFiles.reduce((sum, f) => sum + f.size, 0);
        if (selectedTotalSize) {
            selectedTotalSize.textContent = '总计: ' + formatFileSize(totalSize);
        }

        // 渲染文件列表
        selectedFileList.innerHTML = pendingFiles.map(fileData => createSelectedFileItem(fileData)).join('');

        // 绑定移除按钮事件
        selectedFileList.querySelectorAll('.btn-remove-selected-file').forEach(btn => {
            btn.addEventListener('click', function() {
                const fileId = this.getAttribute('data-file-id');
                removeSelectedFile(fileId);
            });
        });
    }

    /**
     * 创建上传区域内文件列表项HTML
     */
    function createSelectedFileItem(fileData) {
        const icon = getFileTypeIcon(fileData);
        const typeName = getFileTypeName(fileData);

        let previewHtml = '';
        if (fileData.preview) {
            previewHtml = `<img src="${fileData.preview}" alt="${fileData.name}">`;
        } else {
            previewHtml = `<span class="file-type-icon">${icon}</span>`;
        }

        let errorHtml = '';
        if (!fileData.valid && fileData.errors.length > 0) {
            errorHtml = `<div class="selected-file-error">${fileData.errors.join(', ')}</div>`;
        }

        return `
            <div class="selected-file-item" data-file-id="${fileData.id}">
                <div class="selected-file-preview">${previewHtml}</div>
                <div class="selected-file-info">
                    <div class="selected-file-name" title="${fileData.name}">${fileData.name}</div>
                    <div class="selected-file-meta">
                        <span class="selected-file-size">${formatFileSize(fileData.size)}</span>
                        <span class="selected-file-type">${typeName}</span>
                    </div>
                    ${errorHtml}
                </div>
                <button type="button" class="btn-remove-selected-file" data-file-id="${fileData.id}" title="移除文件">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        `;
    }

    /**
     * 更新单个文件项（用于图片预览更新）
     */
    function updateSelectedFileItem(fileData) {
        const item = selectedFileList.querySelector(`[data-file-id="${fileData.id}"]`);
        if (item && fileData.preview) {
            const preview = item.querySelector('.selected-file-preview');
            if (preview) {
                preview.innerHTML = `<img src="${fileData.preview}" alt="${fileData.name}">`;
            }
        }
    }

    /**
     * 移除文件
     */
    function removeSelectedFile(fileId) {
        const item = selectedFileList.querySelector(`[data-file-id="${fileId}"]`);
        if (item) {
            item.classList.add('removing');
            setTimeout(() => {
                pendingFiles = pendingFiles.filter(f => f.id !== fileId);
                updateSelectedFileList();

                // 如果没有文件了，隐藏容器
                if (pendingFiles.length === 0) {
                    hideSelectedFilesContainer();
                }
            }, 300);
        }
    }

    /**
     * 清空所有文件
     */
    function clearAllFiles() {
        pendingFiles = [];
        updateSelectedFileList();
        hideSelectedFilesContainer();
    }

    /**
     * 显示上传区域内的文件列表容器
     */
    function showSelectedFilesContainer() {
        if (selectedFilesContainer) {
            selectedFilesContainer.style.display = 'block';
        }
    }

    /**
     * 隐藏上传区域内的文件列表容器
     */
    function hideSelectedFilesContainer() {
        if (selectedFilesContainer) {
            selectedFilesContainer.style.display = 'none';
        }
    }

    /**
     * 更新上传按钮状态（兼容性保留）
     */
    function updateUploadButtonState() {
        if (startDragUpload) {
            const hasValidFiles = pendingFiles.some(f => f.valid);
            startDragUpload.disabled = !hasValidFiles || pendingFiles.length === 0;
        }
    }

    // ========================================
    // 保留旧的函数以兼容模板（但不显示）
    // ========================================

    /**
     * 更新文件列表UI（旧版本，保留兼容性）
     */
    function updateFileList() {
        if (!dragFileList) return;

        // 更新文件数量
        if (dragFileCount) {
            dragFileCount.textContent = pendingFiles.length;
        }

        // 更新总大小
        const totalSize = pendingFiles.reduce((sum, f) => sum + f.size, 0);
        if (dragTotalSize) {
            dragTotalSize.textContent = '总计: ' + formatFileSize(totalSize);
        }

        // 渲染文件列表
        dragFileList.innerHTML = pendingFiles.map(fileData => createFileListItem(fileData)).join('');

        // 绑定移除按钮事件
        dragFileList.querySelectorAll('.btn-remove-file').forEach(btn => {
            btn.addEventListener('click', function() {
                const fileId = this.getAttribute('data-file-id');
                removeFile(fileId);
            });
        });

        // 更新上传按钮状态
        updateUploadButtonState();
    }

    /**
     * 创建文件列表项HTML（旧版本，保留兼容性）
     */
    function createFileListItem(fileData) {
        const icon = getFileTypeIcon(fileData);
        const typeName = getFileTypeName(fileData);

        let previewHtml = '';
        if (fileData.preview) {
            previewHtml = `<img src="${fileData.preview}" alt="${fileData.name}">`;
        } else {
            previewHtml = `<span class="file-type-icon">${icon}</span>`;
        }

        let errorHtml = '';
        if (!fileData.valid && fileData.errors.length > 0) {
            errorHtml = `<div class="file-error">${fileData.errors.join(', ')}</div>`;
        }

        return `
            <div class="drag-file-item" data-file-id="${fileData.id}">
                <div class="file-preview">${previewHtml}</div>
                <div class="file-info">
                    <div class="file-name" title="${fileData.name}">${fileData.name}</div>
                    <div class="file-meta">
                        <span class="file-size">${formatFileSize(fileData.size)}</span>
                        <span class="file-type">${typeName}</span>
                    </div>
                    ${errorHtml}
                </div>
                <button type="button" class="btn-remove-file" data-file-id="${fileData.id}" title="移除文件">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        `;
    }

    /**
     * 更新单个文件项（用于图片预览更新）
     */
    function updateFileListItem(fileData) {
        const item = dragFileList.querySelector(`[data-file-id="${fileData.id}"]`);
        if (item && fileData.preview) {
            const preview = item.querySelector('.file-preview');
            if (preview) {
                preview.innerHTML = `<img src="${fileData.preview}" alt="${fileData.name}">`;
            }
        }
    }

    /**
     * 移除文件（旧版本，保留兼容性）
     */
    function removeFile(fileId) {
        const item = dragFileList.querySelector(`[data-file-id="${fileId}"]`);
        if (item) {
            item.classList.add('removing');
            setTimeout(() => {
                pendingFiles = pendingFiles.filter(f => f.id !== fileId);
                updateFileList();

                // 如果没有文件了，隐藏面板
                if (pendingFiles.length === 0) {
                    hideFilePanel();
                }
            }, 300);
        }
    }

    /**
     * 清空所有文件
     */
    function clearAllFiles() {
        pendingFiles = [];
        updateSelectedFileList();
        hideSelectedFilesContainer();
    }

    /**
     * 显示文件面板（旧版本，保留兼容性但不实际使用）
     */
    function showFilePanel() {
        // 旧版本函数，现在不再使用
    }

    /**
     * 隐藏文件面板（旧版本，保留兼容性但不实际使用）
     */
    function hideFilePanel() {
        // 旧版本函数，现在不再使用
    }

    // ========================================
    // 上传功能
    // ========================================

    /**
     * 开始拖拽上传
     */
    function startUpload() {
        // 过滤有效文件
        const validFiles = pendingFiles.filter(f => f.valid);
        if (validFiles.length === 0) {
            showToast('没有可上传的有效文件', 'error');
            return;
        }

        // 隐藏上传区域内的文件列表容器，显示进度面板
        hideSelectedFilesContainer();
        showProgressPanel();

        // 获取保存时长
        const durationSelect = document.querySelector('select[name="duration"]');
        const duration = durationSelect ? durationSelect.value : '86400';

        // 创建 FormData
        const formData = new FormData();
        formData.append('duration', duration);

        validFiles.forEach(fileData => {
            formData.append('files[]', fileData.file);
        });

        // 初始化进度
        initializeProgress(validFiles);

        // 发送上传请求
        uploadFiles(formData, validFiles);
    }

    /**
     * 初始化进度显示
     */
    function initializeProgress(files) {
        if (overallProgress) overallProgress.textContent = '0%';
        if (overallProgressBar) overallProgressBar.style.width = '0%';

        if (fileProgressList) {
            fileProgressList.innerHTML = files.map(fileData => `
                <div class="file-progress-item uploading" data-file-id="${fileData.id}">
                    <div class="file-progress-preview">
                        ${fileData.preview
                            ? `<img src="${fileData.preview}" alt="${fileData.name}">`
                            : `<span class="file-type-icon">${getFileTypeIcon(fileData)}</span>`
                        }
                    </div>
                    <div class="file-progress-info">
                        <div class="file-progress-name">${fileData.name}</div>
                        <div class="file-progress-bar">
                            <div class="file-progress-bar-fill" style="width: 0%"></div>
                        </div>
                        <div class="file-progress-status">等待上传...</div>
                    </div>
                    <div class="file-progress-percent">0%</div>
                </div>
            `).join('');
        }
    }

    /**
     * 上传文件
     */
    function uploadFiles(formData, files) {
        const xhr = new XMLHttpRequest();
        currentXhr = xhr;

        startTime = Date.now();
        lastTime = startTime;
        lastLoaded = 0;

        // 上传进度
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;

                // 更新整体进度
                if (overallProgress) overallProgress.textContent = Math.round(percentComplete) + '%';
                if (overallProgressBar) overallProgressBar.style.width = percentComplete + '%';

                // 计算剩余时间
                const currentTime = Date.now();
                const timeDiff = (currentTime - lastTime) / 1000;

                if (timeDiff >= 0.2) {
                    const bytesDiff = e.loaded - lastLoaded;
                    const currentSpeed = bytesDiff / timeDiff;

                    if (currentSpeed > 0 && percentComplete < 100) {
                        const remainingBytes = e.total - e.loaded;
                        const timeRemaining = remainingBytes / currentSpeed;
                        updateAllFileStatus('上传中... 剩余 ' + formatTimeRemaining(timeRemaining));
                    }

                    lastLoaded = e.loaded;
                    lastTime = currentTime;
                }

                // 更新每个文件的进度（平均分配）
                const filePercent = percentComplete;
                files.forEach(fileData => {
                    updateFileProgress(fileData.id, filePercent, 'uploading');
                });
            }
        });

        // 上传开始
        xhr.upload.addEventListener('loadstart', function() {
            updateAllFileStatus('正在上传...');
        });

        // 上传完成
        xhr.addEventListener('load', function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    let response = {};
                    if (xhr.responseText && xhr.status !== 204) {
                        response = JSON.parse(xhr.responseText);
                    }

                    // 更新所有文件为成功状态
                    files.forEach(fileData => {
                        updateFileProgress(fileData.id, 100, 'success');
                    });

                    if (overallProgress) overallProgress.textContent = '100%';
                    if (overallProgressBar) overallProgressBar.style.width = '100%';

                    // 显示成功面板
                    setTimeout(() => {
                        hideProgressPanel();
                        // 清空待上传文件列表
                        pendingFiles = [];
                        hideSelectedFilesContainer();
                        // 无感提示：显示 Toast 而不是刷新页面
                        showToast(`成功上传 ${files.length} 个文件`, 'success');
                        // 如果有刷新按钮，触发刷新列表（静默更新，不整页刷新）
                        refreshStorageList();
                    }, 500);

                } catch (error) {
                    console.error('解析响应失败：', error);
                    handleUploadError(files, '服务器响应格式异常');
                }
            } else {
                let errorMessage = '上传失败';
                switch (xhr.status) {
                    case 400: errorMessage = '请求格式错误'; break;
                    case 403: errorMessage = '权限不足'; break;
                    case 404: errorMessage = '请求资源不存在'; break;
                    case 413: errorMessage = '文件过大，服务器拒绝'; break;
                    case 500: errorMessage = '服务器内部错误'; break;
                    case 502: errorMessage = '服务器网关错误'; break;
                    case 503: errorMessage = '服务不可用'; break;
                    default: errorMessage = 'HTTP ' + xhr.status;
                }
                handleUploadError(files, errorMessage);
            }
        });

        // 上传错误
        xhr.addEventListener('error', function() {
            handleUploadError(files, '网络错误，请检查连接');
        });

        // 上传中止
        xhr.addEventListener('abort', function() {
            handleUploadError(files, '上传已取消');
        });

        // 超时
        xhr.timeout = 6000000;
        xhr.addEventListener('timeout', function() {
            handleUploadError(files, '上传超时');
        });

        xhr.open('POST', location.pathname + (location.search || ''), true);
        xhr.send(formData);
    }

    /**
     * 更新单个文件进度
     */
    function updateFileProgress(fileId, percent, status) {
        const item = fileProgressList.querySelector(`[data-file-id="${fileId}"]`);
        if (!item) return;

        const barFill = item.querySelector('.file-progress-bar-fill');
        const statusEl = item.querySelector('.file-progress-status');
        const percentEl = item.querySelector('.file-progress-percent');

        if (barFill) barFill.style.width = percent + '%';
        if (percentEl) percentEl.textContent = Math.round(percent) + '%';

        item.classList.remove('uploading', 'success', 'error');
        item.classList.add(status);

        if (statusEl) {
            if (status === 'success') {
                statusEl.textContent = '上传成功';
                statusEl.classList.add('success');
            } else if (status === 'error') {
                statusEl.textContent = '上传失败';
                statusEl.classList.add('error');
            }
        }
    }

    /**
     * 更新所有文件状态
     */
    function updateAllFileStatus(message) {
        const statusEls = fileProgressList.querySelectorAll('.file-progress-status');
        statusEls.forEach(el => {
            if (!el.classList.contains('success') && !el.classList.contains('error')) {
                el.textContent = message;
            }
        });
    }

    /**
     * 处理上传错误
     */
    function handleUploadError(files, message) {
        files.forEach(fileData => {
            updateFileProgress(fileData.id, 0, 'error');
        });

        const statusEls = fileProgressList.querySelectorAll('.file-progress-status');
        statusEls.forEach(el => {
            el.textContent = message;
            el.classList.add('error');
        });

        showToast(message, 'error');
    }

    /**
     * 显示进度面板
     */
    function showProgressPanel() {
        if (uploadProgressPanel) {
            uploadProgressPanel.classList.add('active');
        }
    }

    /**
     * 隐藏进度面板
     */
    function hideProgressPanel() {
        if (uploadProgressPanel) {
            uploadProgressPanel.classList.remove('active');
        }
    }

    /**
     * 显示成功面板
     */
    function showSuccessPanel(fileCount) {
        if (successPanel) {
            if (successMessage) {
                successMessage.textContent = `成功上传 ${fileCount} 个文件`;
            }
            successPanel.classList.add('active');
        }
    }

    /**
     * 隐藏成功面板
     */
    function hideSuccessPanel() {
        if (successPanel) {
            successPanel.classList.remove('active');
        }
    }

    /**
     * 取消上传
     */
    function cancelCurrentUpload() {
        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }
        hideProgressPanel();
        pendingFiles = [];
    }

    // ========================================
    // Toast 提示
    // ========================================

    /**
     * 显示Toast提示
     */
    function showToast(message, type = 'success') {
        const existingToast = document.querySelector('.toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${type === 'success' ? '✓' : '✕'}</span>
            <span class="toast-message">${message}</span>
        `;
        document.body.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.add('toast-show');
        });

        setTimeout(() => {
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * 无刷新更新存储列表
     */
    function refreshStorageList() {
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // 更新统计数字
                const statCards = doc.querySelectorAll('.stat-card');
                const currentStats = document.querySelectorAll('.stat-card');
                if (statCards.length >= 3 && currentStats.length >= 3) {
                    currentStats[0].querySelector('.stat-value').textContent = statCards[0].querySelector('.stat-value').textContent;
                    currentStats[1].querySelector('.stat-value').textContent = statCards[1].querySelector('.stat-value').textContent;
                    currentStats[2].querySelector('.stat-value').textContent = statCards[2].querySelector('.stat-value').textContent;
                }

                // 更新存储列表
                const newList = doc.querySelector('.list');
                const currentList = document.querySelector('.grid-card.section-full .list');
                if (newList && currentList) {
                    currentList.innerHTML = newList.innerHTML;
                    // 重新绑定列表按钮事件
                    bindListButtonEvents();
                }

                // 更新侧边栏统计
                const sidebarStat = doc.querySelector('.sidebar-stat');
                const currentSidebarStat = document.querySelector('.sidebar-stat');
                if (sidebarStat && currentSidebarStat) {
                    currentSidebarStat.innerHTML = sidebarStat.innerHTML;
                }
            })
            .catch(err => {
                console.error('刷新列表失败：', err);
            });
    }

    /**
     * 绑定列表按钮事件（动态添加的列表项需要重新绑定）
     */
    function bindListButtonEvents() {
        // 重新绑定查看按钮
        document.querySelectorAll('.btn-view').forEach(button => {
            button.onclick = null;
            button.addEventListener('click', function() {
                const content = this.getAttribute('data-content');
                if (content) {
                    openCodeModal(content);
                }
            });
        });

        // 重新绑定复制按钮
        document.querySelectorAll('.btn-copy').forEach(button => {
            button.onclick = null;
            button.addEventListener('click', function() {
                const content = this.getAttribute('data-content');
                if (content) {
                    navigator.clipboard.writeText(content).then(() => {
                        showToast('文本已复制到剪贴板', 'success');
                    }).catch(err => {
                        console.error('复制失败：', err);
                        showToast('复制失败，请手动复制', 'error');
                    });
                }
            });
        });

        // 重新绑定删除按钮
        document.querySelectorAll('.btn-delete[data-delete-url]').forEach(button => {
            button.onclick = null;
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const deleteUrl = this.getAttribute('data-delete-url');
                showCyberConfirm('确定要删除该文件吗？此操作不可撤销。', () => {
                    window.location.href = deleteUrl;
                });
            });
        });
    }

    // ========================================
    // 事件绑定
    // ========================================

    // 初始化拖拽事件
    initDragEvents();

    // 绑定存储列表按钮事件（页面加载时初始化）
    bindListButtonEvents();

    // 清空选中文件按钮（上传区域内）
    if (clearSelectedFilesBtn) {
        clearSelectedFilesBtn.addEventListener('click', clearAllFiles);
    }

    // 清空所有文件按钮（旧版本，保留兼容性）
    if (clearAllFilesBtn) {
        clearAllFilesBtn.addEventListener('click', clearAllFiles);
    }

    // 取消拖拽上传按钮
    if (cancelDragUpload) {
        cancelDragUpload.addEventListener('click', function() {
            clearAllFiles();
        });
    }

    // 开始上传按钮
    if (startDragUpload) {
        startDragUpload.addEventListener('click', startUpload);
    }

    // 取消上传按钮
    if (cancelUpload) {
        cancelUpload.addEventListener('click', cancelCurrentUpload);
    }

    // 查看文件按钮
    if (viewFilesBtn) {
        viewFilesBtn.addEventListener('click', function() {
            window.location.reload();
        });
    }

    // 继续上传按钮
    if (continueUploadBtn) {
        continueUploadBtn.addEventListener('click', function() {
            hideSuccessPanel();
            pendingFiles = [];
            hideSelectedFilesContainer();
        });
    }

    // ========================================
    // 原有表单上传功能
    // ========================================

    // 文件选择时显示文件列表
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const files = this.files;
            if (files.length > 0) {
                // 检查文件数量限制
                if (files.length > UPLOAD_CONFIG.maxFiles) {
                    showToast(`最多同时上传 ${UPLOAD_CONFIG.maxFiles} 个文件`, 'error');
                    this.value = ''; // 清空选择
                    return;
                }

                // 清空之前的文件列表
                pendingFiles = [];

                // 处理每个文件
                Array.from(files).forEach(file => {
                    const validation = validateFile(file);
                    const fileData = {
                        id: generateId(),
                        file: file,
                        name: file.name,
                        size: file.size,
                        type: file.type,
                        valid: validation.valid,
                        errors: validation.errors,
                        preview: null
                    };

                    // 生成图片预览
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            fileData.preview = e.target.result;
                            updateSelectedFileItem(fileData);
                        };
                        reader.readAsDataURL(file);
                    }

                    pendingFiles.push(fileData);
                });

                // 更新UI - 在上传区域内显示文件列表
                updateSelectedFileList();
                showSelectedFilesContainer();
            }
        });
    }

    // ========================================
    // 上传按钮事件处理
    // ========================================
    if (uploadBtn) {
        uploadBtn.addEventListener('click', function(e) {
            // 阻止按钮的默认提交行为
            e.preventDefault();
            e.stopPropagation();

            // 优先使用拖拽上传的文件（pendingFiles）
            if (pendingFiles.length > 0) {
                startUpload();
                return;
            }

            // 检查是否有通过fileInput选择的文件
            if (!fileInput || fileInput.files.length === 0) {
                showToast('请先选择文件', 'error');
                return;
            }

            // 使用FormData上传（AJAX方式，无刷新）
            const formData = new FormData(uploadForm);

            // 显示进度
            if (progressContainer) progressContainer.style.display = 'block';
            uploadBtn.disabled = true;
            if (progressBar) progressBar.style.width = '0%';
            if (progressText) progressText.textContent = '0%';
            if (statusText) {
                statusText.textContent = '正在连接服务器...';
                statusText.style.color = '#a1a1aa';
            }

            // 使用fetch进行无刷新上传
            fetch(location.pathname, {
                method: 'POST',
                body: formData
            }).then(function(response) {
                return response.json();
            }).then(function(data) {
                if (progressBar) progressBar.style.width = '100%';
                if (progressText) progressText.textContent = '100%';
                if (statusText) {
                    statusText.textContent = '上传成功！';
                    statusText.style.color = '#22c55e';
                }

                // 延迟重置UI并显示结果
                setTimeout(function() {
                    resetUploadUI();
                    if (data.success) {
                        showToast(data.message || '上传成功', 'success');
                        // 无刷新更新列表
                        refreshStorageList();
                        // 清空fileInput
                        fileInput.value = '';
                    } else {
                        showToast(data.message || '上传失败', 'error');
                    }
                }, 300);
            }).catch(function(error) {
                console.error('上传失败：', error);
                showToast('网络错误，请检查连接', 'error');
                resetUploadUI();
            });
        });
    }

    // 完全阻止表单的默认提交行为
    if (uploadForm) {
        // 显式设置action为空字符串，防止表单提交到其他URL
        uploadForm.action = '';

        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        });

        // 额外检查：确保没有其他方式触发表单提交
        uploadForm.addEventListener('click', function(e) {
            if (e.target && e.target.type === 'submit') {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    function resetUploadUI() {
        if (progressContainer) progressContainer.style.display = 'none';
        if (uploadBtn) uploadBtn.disabled = false;
        if (progressBar) progressBar.style.width = '0%';
        if (progressText) progressText.textContent = '0%';
        if (statusText) {
            statusText.textContent = '准备上传...';
            statusText.style.color = '#71717a';
        }
    }

    // ========================================
    // 代码查看模态弹窗功能
    // ========================================
    const codeModal = document.getElementById('codeModal');
    const codeModalContent = document.getElementById('codeModalContent');
    const codeModalCopy = document.getElementById('codeModalCopy');
    const codeModalClose = document.getElementById('codeModalClose');
    let currentCodeContent = '';

    // 语言检测函数
    function detectLanguage(code) {
        const patterns = {
            'javascript': /^(const|let|var|function|class|import|export|require|async|await|=>|console\.|document\.|window\.)/m,
            'python': /^(def|class|import|from|if __name__|print\(|async def|await |return |self\.)/m,
            'php': /^(<\?php|function |class |\$|use |namespace |public |private |protected )/m,
            'html': /^(<!DOCTYPE|<html|<head|<body|<div|<span|<script|<style|<link)/m,
            'css': /^(body|div|span|\.|#|@media|@keyframes|import|url\()/m,
            'json': /^\s*[{"\[\]|"[\w]+":/m,
            'sql': /^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FROM|WHERE|JOIN)/im,
            'bash': /^(#!|echo |export |cd |ls |mkdir |cp |mv |rm |grep |awk |sed )/m
        };

        for (const [lang, pattern] of Object.entries(patterns)) {
            if (pattern.test(code)) {
                return lang;
            }
        }
        return 'plaintext';
    }

    // 打开模态窗
    function openCodeModal(content) {
        try {
            currentCodeContent = content;
            const language = detectLanguage(content);
            codeModalContent.className = `language-${language}`;
            codeModalContent.textContent = content;
            codeModal.classList.add('active');

            // 重新初始化Prism高亮
            if (window.Prism) {
                Prism.highlightElement(codeModalContent);
            }
        } catch (e) {
            console.error('打开弹窗失败:', e);
        }
    }

    // 关闭模态窗
    function closeCodeModal() {
        codeModal.classList.remove('active');
        currentCodeContent = '';
    }

    // 查看按钮事件
    const viewButtons = document.querySelectorAll('.btn-view');
    console.log('找到查看按钮数量:', viewButtons.length);
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            console.log('查看按钮被点击');
            const content = this.getAttribute('data-content');
            console.log('内容长度:', content ? content.length : 0);
            if (content) {
                openCodeModal(content);
            }
        });
    });

    // 复制按钮事件
    const copyButtons = document.querySelectorAll('.btn-copy');
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const content = this.getAttribute('data-content');
            if (content) {
                navigator.clipboard.writeText(content).then(() => {
                    showToast('文本已复制到剪贴板', 'success');
                }).catch(err => {
                    console.error('复制失败：', err);
                    showToast('复制失败，请手动复制', 'error');
                });
            }
        });
    });

    // 模态窗关闭按钮
    if (codeModalClose) {
        codeModalClose.addEventListener('click', closeCodeModal);
    }

    // 模态窗复制按钮
    if (codeModalCopy) {
        codeModalCopy.addEventListener('click', function() {
            navigator.clipboard.writeText(currentCodeContent).then(() => {
                this.classList.add('copied');
                this.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg> 已复制`;
                setTimeout(() => {
                    this.classList.remove('copied');
                    this.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg> 一键复制`;
                }, 2000);
            }).catch(err => {
                showToast('复制失败', 'error');
            });
        });
    }

    // 点击遮罩层关闭
    if (codeModal) {
        codeModal.addEventListener('click', function(e) {
            if (e.target === codeModal) {
                closeCodeModal();
            }
        });
    }

    // ESC键关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && codeModal && codeModal.classList.contains('active')) {
            closeCodeModal();
        }
    });

    // ========================================
    // 删除按钮功能
    // ========================================
    const deleteButtons = document.querySelectorAll('.btn-delete[data-delete-url]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const deleteUrl = this.getAttribute('data-delete-url');
            showCyberConfirm('确定要删除该文件吗？此操作不可撤销。', () => {
                window.location.href = deleteUrl;
            });
        });
    });

    // ========================================
    // 赛博朋克风格确认模态框
    // ========================================
    window.showCyberConfirm = function(message, onConfirm) {
        const existingModal = document.querySelector('.cyber-modal-overlay');
        if (existingModal) return;

        const modal = document.createElement('div');
        modal.className = 'cyber-modal-overlay';
        modal.innerHTML = `
            <div class="cyber-modal">
                <div class="cyber-modal-header">
                    <span class="modal-icon">⚠</span>
                    <span class="modal-title">确认操作</span>
                </div>
                <div class="cyber-modal-body">${message}</div>
                <div class="cyber-modal-footer">
                    <button class="cyber-btn cyber-btn-cancel" id="cyberCancelBtn">取消</button>
                    <button class="cyber-btn cyber-btn-confirm" id="cyberConfirmBtn">确认删除</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const modalEl = modal.querySelector('.cyber-modal');
        modalEl.style.top = '50%';
        modalEl.style.left = '50%';
        modalEl.style.transform = 'translate(-50%, -50%)';

        requestAnimationFrame(() => {
            modal.classList.add('cyber-modal-show');
        });

        const cancelBtn = document.getElementById('cyberCancelBtn');
        const confirmBtn = document.getElementById('cyberConfirmBtn');

        const closeModal = () => {
            modal.classList.remove('cyber-modal-show');
            setTimeout(() => modal.remove(), 300);
        };

        cancelBtn.addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', () => {
            closeModal();
            onConfirm();
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        });
    };
});