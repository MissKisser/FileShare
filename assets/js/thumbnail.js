/**
 * 缩略图展示与懒加载。
 */
(function () {
    'use strict';

    var FAILED_PREFIX = 'failed:';

    function getDefaultIcon(item) {
        var mime = item.dataset.mime || '';
        var name = (item.dataset.name || '').toLowerCase();
        if (mime.indexOf('image/') === 0) return '🖼️';
        if (mime.indexOf('video/') === 0) return '🎬';
        if (mime.indexOf('audio/') === 0) return '🎵';
        if (mime === 'application/pdf') return '📕';
        if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return '📦';
        if (/\.(doc|docx)$/i.test(name)) return '📘';
        if (/\.(xls|xlsx)$/i.test(name)) return '📗';
        if (/\.(ppt|pptx)$/i.test(name)) return '📙';
        if (/\.(txt|md|json|xml|csv)$/i.test(name)) return '📄';
        return '📎';
    }

    function renderPlaceholder(item, reason) {
        var icon = getDefaultIcon(item);
        item.innerHTML = '<span class="thumb-placeholder" title="' + (reason || '无缩略图') + '">' + icon + '</span>';
    }

    // I2 安全加固：HTML 属性转义，防止 thumbPath 注入引号/尖括号导致 XSS。
    // 虽然当前 thumbPath 来自后端 basename() 受控，但任何字符串拼接到 HTML 属性
    // 都必须转义，防止 thumbPath 属性注入。
    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderThumbnail(item, thumbPath) {
        // 改走 ?thumb=ID 鉴权路由，避免缩略图通过 /uploads/ 直接静态访问泄露
        // thumbPath 仅作为缓存键使用；显示时以 itemId 拉取
        // I2：所有动态值经过 escapeAttr 转义后再拼接到 HTML 属性
        var itemId = escapeAttr(item.dataset.thumbnailItem);
        var safeThumbPath = escapeAttr(thumbPath);
        item.innerHTML = '<img src="?thumb=' + itemId +
            '" alt="" loading="lazy" class="thumb-image" data-thumb-path="' + safeThumbPath + '" />';
    }

    function loadThumbnail(item) {
        var itemId = item.dataset.thumbnailItem;
        var existing = item.dataset.thumbnailPath || '';

        if (existing && existing.indexOf(FAILED_PREFIX) !== 0) {
            renderThumbnail(item, existing);
            return;
        }
        if (existing && existing.indexOf(FAILED_PREFIX) === 0) {
            renderPlaceholder(item, existing.substring(FAILED_PREFIX.length));
            return;
        }

        // 懒生成
        fetch('?action=thumb&item_id=' + itemId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.thumbnail_path && data.thumbnail_path.indexOf(FAILED_PREFIX) !== 0) {
                    renderThumbnail(item, data.thumbnail_path);
                    item.dataset.thumbnailPath = data.thumbnail_path;
                } else {
                    renderPlaceholder(item, data.thumbnail_path || '生成失败');
                    if (data.thumbnail_path) {
                        item.dataset.thumbnailPath = data.thumbnail_path;
                    }
                }
            })
            .catch(function () { renderPlaceholder(item, '网络错误'); });
    }

    function init() {
        var items = document.querySelectorAll('[data-thumbnail-item]');
        items.forEach(loadThumbnail);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
