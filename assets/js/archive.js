/**
 * FileShare 压缩包在线预览
 * 作者：FileShare Contributors
 *
 * 用法：在分享页，压缩包类型文件会显示「在线预览」按钮，
 *       点击后调用 archive.js 加载文件列表和内容。
 * 依赖：后端 ?archive=list&item_id=N 和 ?archive=read&item_id=N&path=xxx
 */
(function () {
    'use strict';

    var itemId = null;
    var container = null;
    var entries = [];
    var currentPath = null;

    /**
     * 初始化：检测页面上的压缩包预览入口
     */
    function init() {
        var trigger = document.getElementById('archivePreviewBtn');
        if (!trigger) return;

        itemId = parseInt(trigger.dataset.itemId, 10);
        if (!itemId) return;

        container = document.getElementById('archivePreviewContainer');
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (container.style.display === 'none' || !container.style.display) {
                loadList();
            } else {
                container.style.display = 'none';
            }
        });
    }

    /**
     * 加载压缩包文件列表
     */
    function loadList() {
        if (!container) return;
        container.style.display = 'block';
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-secondary)">加载中…</div>';

        fetch('?archive=list&item_id=' + itemId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    container.innerHTML = '<div class="archive-error">' + escapeHtml(data.error) + '</div>';
                    return;
                }
                entries = data.entries || [];
                renderTree(entries);
            })
            .catch(function () {
                container.innerHTML = '<div class="archive-error">加载失败，请重试</div>';
            });
    }

    /**
     * 渲染文件树
     */
    function renderTree(items) {
        if (items.length === 0) {
            container.innerHTML = '<div class="archive-empty">压缩包为空</div>';
            return;
        }

        // 构建树结构
        var tree = buildTree(items);

        var html = '<div class="archive-tree">';
        html += '<div class="archive-header">';
        html += '<span class="archive-title">文件列表</span>';
        html += '<span class="archive-count">' + items.length + ' 个条目</span>';
        html += '</div>';
        html += renderTreeNode(tree, '');
        html += '</div>';

        // 文件内容查看区
        html += '<div id="archiveContentView" class="archive-content-view" style="display:none">';
        html += '<div class="archive-content-header">';
        html += '<span id="archiveContentPath"></span>';
        html += '<button type="button" id="archiveContentClose" class="archive-close-btn">&times;</button>';
        html += '</div>';
        html += '<pre id="archiveContentBody" class="archive-content-body"></pre>';
        html += '</div>';

        container.innerHTML = html;

        // 绑定关闭按钮
        var closeBtn = document.getElementById('archiveContentClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                document.getElementById('archiveContentView').style.display = 'none';
            });
        }

        // 绑定文件夹折叠
        bindFolderToggle();
        // 绑定文件点击
        bindFileClick();
    }

    /**
     * 将扁平列表构建为树结构
     */
    function buildTree(items) {
        var root = { _children: {} };

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var parts = item.name.split('/');
            var node = root;

            for (var j = 0; j < parts.length; j++) {
                var part = parts[j];
                if (part === '') continue;
                if (!node._children[part]) {
                    node._children[part] = { _children: {} };
                }
                if (j === parts.length - 1) {
                    // 叶节点
                    node._children[part]._isDir = item.is_dir;
                    node._children[part]._size = item.size;
                    node._children[part]._fullPath = item.name;
                    node._children[part]._name = part;
                } else {
                    node._children[part]._isDir = true;
                    if (!node._children[part]._name) {
                        node._children[part]._name = part;
                    }
                }
                node = node._children[part];
            }
        }

        return root;
    }

    /**
     * 渲染树节点
     */
    function renderTreeNode(node, prefix) {
        var html = '';
        var keys = Object.keys(node._children).sort(function (a, b) {
            var aDir = node._children[a]._isDir;
            var bDir = node._children[b]._isDir;
            if (aDir && !bDir) return -1;
            if (!aDir && bDir) return 1;
            return a.localeCompare(b);
        });

        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var child = node._children[key];
            if (child._isDir) {
                html += '<div class="archive-folder">';
                html += '<div class="archive-folder-header" data-path="' + escapeAttr(prefix + key + '/') + '">';
                html += '<span class="archive-toggle">▶</span>';
                html += '<span class="archive-icon">📁</span>';
                html += '<span class="archive-name">' + escapeHtml(key) + '</span>';
                html += '</div>';
                html += '<div class="archive-folder-body" style="display:none">';
                html += renderTreeNode(child, prefix + key + '/');
                html += '</div>';
                html += '</div>';
            } else {
                var sizeStr = formatSize(child._size || 0);
                var icon = getFileIcon(child._name || key);
                html += '<div class="archive-file" data-path="' + escapeAttr(child._fullPath || (prefix + key)) + '">';
                html += '<span class="archive-icon">' + icon + '</span>';
                html += '<span class="archive-name">' + escapeHtml(key) + '</span>';
                html += '<span class="archive-size">' + sizeStr + '</span>';
                html += '</div>';
            }
        }
        return html;
    }

    /**
     * 绑定文件夹展开/折叠
     */
    function bindFolderToggle() {
        var headers = container.querySelectorAll('.archive-folder-header');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', function () {
                var body = this.nextElementSibling;
                var toggle = this.querySelector('.archive-toggle');
                if (body.style.display === 'none') {
                    body.style.display = 'block';
                    toggle.textContent = '▼';
                } else {
                    body.style.display = 'none';
                    toggle.textContent = '▶';
                }
            });
        }
    }

    /**
     * 绑定文件点击读取
     */
    function bindFileClick() {
        var files = container.querySelectorAll('.archive-file');
        for (var i = 0; i < files.length; i++) {
            files[i].addEventListener('click', function () {
                var path = this.dataset.path;
                readFile(path);
            });
        }
    }

    /**
     * 读取压缩包内单个文件
     */
    function readFile(path) {
        var view = document.getElementById('archiveContentView');
        var pathEl = document.getElementById('archiveContentPath');
        var bodyEl = document.getElementById('archiveContentBody');

        if (!view || !pathEl || !bodyEl) return;

        pathEl.textContent = path;
        bodyEl.textContent = '加载中…';
        view.style.display = 'block';

        fetch('?archive=read&item_id=' + itemId + '&path=' + encodeURIComponent(path))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    bodyEl.textContent = data.error;
                    return;
                }
                bodyEl.textContent = data.content || '';
                // 如果有语法高亮，触发
                if (typeof Prism !== 'undefined') {
                    var ext = path.split('.').pop().toLowerCase();
                    var langMap = {
                        js: 'javascript', ts: 'typescript', py: 'python', rb: 'ruby',
                        php: 'php', java: 'java', c: 'c', cpp: 'cpp', cs: 'csharp',
                        go: 'go', rs: 'rust', sql: 'sql', sh: 'bash', bash: 'bash',
                        json: 'json', xml: 'markup', html: 'markup', htm: 'markup',
                        css: 'css', yaml: 'yaml', yml: 'yaml', md: 'markdown'
                    };
                    var lang = langMap[ext] || 'plaintext';
                    bodyEl.className = 'archive-content-body language-' + lang;
                    Prism.highlightElement(bodyEl);
                }
            })
            .catch(function () {
                bodyEl.textContent = '读取失败';
            });
    }

    // ============================================================
    // 辅助函数
    // ============================================================

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function getFileIcon(name) {
        var ext = name.split('.').pop().toLowerCase();
        var icons = {
            jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️', svg: '🖼️', bmp: '🖼️',
            mp4: '🎬', webm: '🎬', avi: '🎬', mkv: '🎬', mov: '🎬',
            mp3: '🎵', wav: '🎵', flac: '🎵', aac: '🎵', ogg: '🎵',
            pdf: '📄', doc: '📝', docx: '📝', xls: '📊', xlsx: '📊', ppt: '📊', pptx: '📊',
            zip: '📦', rar: '📦', '7z': '📦', tar: '📦', gz: '📦',
            js: '📜', ts: '📜', py: '🐍', java: '☕', php: '🐘', go: '🔵', rs: '🦀',
            html: '🌐', css: '🎨', json: '📋', xml: '📋', sql: '🗃️',
            md: '📝', txt: '📝', log: '📝', cfg: '⚙️', ini: '⚙️', yaml: '⚙️', yml: '⚙️',
            sh: '💻', bat: '💻',
        };
        return icons[ext] || '📄';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
