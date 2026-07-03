/**
 * FileShare PDF 在线预览
 * 作者：FileShare Contributors
 *
 * 依赖：PDF.js (CDN)
 * 用法：在 PDF 预览页面自动初始化，渲染 PDF 到 canvas。
 */
(function () {
    'use strict';

    var pdfDoc = null;
    var currentPage = 1;
    var totalPages = 0;
    var scale = 1.5;
    var rendering = false;

    /**
     * 初始化 PDF 预览
     */
    function init() {
        var container = document.getElementById('pdfViewerContainer');
        if (!container) return;

        var pdfUrl = container.dataset.src;
        if (!pdfUrl) return;

        // 检测 PDF.js
        if (typeof pdfjsLib === 'undefined') {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-secondary)">' +
                '<p>PDF.js 未加载，正在使用浏览器内置查看器…</p>' +
                '<a href="' + pdfUrl + '" target="_blank" style="color:var(--accent-blue)">直接打开 PDF</a></div>';
            return;
        }

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        var loadingTask = pdfjsLib.getDocument(pdfUrl);
        loadingTask.promise.then(function (pdf) {
            pdfDoc = pdf;
            totalPages = pdf.numPages;
            updatePageInfo();
            renderPage(currentPage);
        }).catch(function (err) {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--accent-red)">' +
                '<p>PDF 加载失败</p>' +
                '<a href="' + pdfUrl + '" target="_blank" style="color:var(--accent-blue)">直接下载</a></div>';
        });

        bindControls();
    }

    /**
     * 渲染指定页
     */
    function renderPage(num) {
        if (!pdfDoc || rendering) return;
        rendering = true;

        pdfDoc.getPage(num).then(function (page) {
            var viewport = page.getViewport({ scale: scale });
            var canvas = document.getElementById('pdfCanvas');
            if (!canvas) {
                rendering = false;
                return;
            }
            var ctx = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            var renderContext = {
                canvasContext: ctx,
                viewport: viewport
            };

            page.render(renderContext).promise.then(function () {
                rendering = false;
                updatePageInfo();
            }).catch(function () {
                rendering = false;
            });
        });
    }

    /**
     * 更新页码信息
     */
    function updatePageInfo() {
        var info = document.getElementById('pdfPageInfo');
        if (info) {
            info.textContent = currentPage + ' / ' + totalPages;
        }
        var prevBtn = document.getElementById('pdfPrev');
        var nextBtn = document.getElementById('pdfNext');
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
    }

    /**
     * 绑定控制按钮
     */
    function bindControls() {
        var prevBtn = document.getElementById('pdfPrev');
        var nextBtn = document.getElementById('pdfNext');
        var zoomInBtn = document.getElementById('pdfZoomIn');
        var zoomOutBtn = document.getElementById('pdfZoomOut');

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    renderPage(currentPage);
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderPage(currentPage);
                }
            });
        }
        if (zoomInBtn) {
            zoomInBtn.addEventListener('click', function () {
                scale = Math.min(scale + 0.25, 4);
                renderPage(currentPage);
            });
        }
        if (zoomOutBtn) {
            zoomOutBtn.addEventListener('click', function () {
                scale = Math.max(scale - 0.25, 0.5);
                renderPage(currentPage);
            });
        }

        // 键盘导航
        document.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft' && currentPage > 1) {
                currentPage--;
                renderPage(currentPage);
            } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
                currentPage++;
                renderPage(currentPage);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
