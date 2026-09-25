/**
 * 语法高亮封装。
 */
(function () {
    'use strict';

    var EXT_LANG_MAP = {
        'js': 'javascript', 'ts': 'typescript', 'py': 'python',
        'java': 'java', 'c': 'c', 'cpp': 'cpp', 'h': 'c', 'hpp': 'cpp',
        'cs': 'csharp', 'go': 'go', 'rs': 'rust', 'swift': 'swift',
        'kt': 'kotlin', 'rb': 'ruby', 'sh': 'bash', 'bash': 'bash',
        'ps1': 'powershell', 'sql': 'sql', 'json': 'json', 'xml': 'xml',
        'html': 'markup', 'htm': 'markup', 'css': 'css', 'scss': 'scss',
        'less': 'less', 'yaml': 'yaml', 'yml': 'yaml', 'md': 'markdown',
        'markdown': 'markdown',
    };

    function detectLanguageByName(name) {
        if (!name) return null;
        var m = name.toLowerCase().match(/\.([a-z0-9]+)$/);
        if (!m) return null;
        return EXT_LANG_MAP[m[1]] || null;
    }

    function highlight(elm) {
        if (!window.Prism) return;
        var code = elm.querySelector('code') || elm;
        var lang = elm.dataset.language || code.dataset.language ||
                   detectLanguageByName(elm.dataset.filename || '');
        if (lang && window.Prism.languages[lang]) {
            code.classList.add('language-' + lang);
            window.Prism.highlightElement(code);
        } else if (elm.tagName === 'PRE' || elm.tagName === 'CODE') {
            window.Prism.highlightElement(elm);
        }
    }

    function init() {
        // 文本项目（share.php 渲染的 .text-content）
        var textBlocks = document.querySelectorAll('.text-content pre code, .text-content pre');
        textBlocks.forEach(function (elm) {
            highlight(elm.tagName === 'CODE' ? elm.parentElement : elm);
        });

        // 代码文件预览（.code-preview）
        document.querySelectorAll('.code-preview, [data-syntax]').forEach(highlight);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
