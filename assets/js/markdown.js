/**
 * FileShare Markdown 渲染
 * 作者：FileShare Contributors
 *
 * 依赖：Marked.js + DOMPurify (CDN)
 * 用法：自动检测 .md/.markdown 文件内容，渲染为 HTML。
 */
(function () {
    'use strict';

    /**
     * 渲染 Markdown 内容到目标容器
     */
    function renderMarkdown() {
        var sourceEl = document.getElementById('markdownSource');
        var targetEl = document.getElementById('markdownBody');
        if (!sourceEl || !targetEl) return;

        var rawText = sourceEl.textContent || '';
        if (!rawText.trim()) return;

        // 检测依赖
        if (typeof marked === 'undefined' || typeof DOMPurify === 'undefined') {
            targetEl.innerHTML = '<pre style="white-space:pre-wrap">' +
                escapeHtml(rawText) + '</pre>';
            return;
        }

        // marked 配置
        marked.setOptions({
            breaks: true,
            gfm: true,
            headerIds: false,
            mangle: false
        });

        var html = marked.parse(rawText);
        targetEl.innerHTML = DOMPurify.sanitize(html, {
            ADD_TAGS: ['input'],
            ADD_ATTR: ['checked', 'type']
        });

        // 任务列表复选框设为只读
        var checkboxes = targetEl.querySelectorAll('input[type="checkbox"]');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].disabled = true;
        }

        // 让链接在新窗口打开
        var links = targetEl.querySelectorAll('a');
        for (var j = 0; j < links.length; j++) {
            links[j].setAttribute('target', '_blank');
            links[j].setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * 简易 HTML 转义（依赖不可用时降级）
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderMarkdown);
    } else {
        renderMarkdown();
    }
})();
