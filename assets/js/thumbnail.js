/**
 * FileShare 缩略图展示与懒生成
 * 作者：FileShare Contributors
 *
 * - 自动为 [data-thumbnail-item] 元素加载缩略图
 * - 缩略图缺失时请求懒生成
 * - 失败时显示默认图标
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

    function renderThumbnail(item, thumbPath) {
        item.innerHTML = '<img src="/uploads/' + encodeURIComponent(thumbPath) +
            '" alt="" loading="lazy" class="thumb-image" />';
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
