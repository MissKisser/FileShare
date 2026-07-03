/**
 * FileShare 图片灯箱
 * 作者：FileShare Contributors
 *
 * 用法：所有 [data-gallery] 图片元素会在分享页被绑定，点击触发灯箱。
 * 灯箱内可左右切换同分享页所有图片。
 */
(function () {
    'use strict';

    var overlay = null;
    var currentIdx = 0;
    var images = [];
    var scale = 1;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'gallery-overlay';
        overlay.innerHTML =
            '<div class="gallery-toolbar">' +
                '<button class="gallery-btn" data-act="zoom-in">+</button>' +
                '<button class="gallery-btn" data-act="zoom-out">\u2212</button>' +
                '<button class="gallery-btn" data-act="close">\u00d7</button>' +
            '</div>' +
            '<button class="gallery-nav prev" data-act="prev">\u2039</button>' +
            '<div class="gallery-stage"><img class="gallery-image" /></div>' +
            '<button class="gallery-nav next" data-act="next">\u203a</button>' +
            '<div class="gallery-counter"></div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) {
            var t = e.target;
            if (t.dataset.act) {
                e.stopPropagation();
                actions[t.dataset.act]();
            } else if (t === overlay) {
                close();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('active')) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') actions.prev();
            else if (e.key === 'ArrowRight') actions.next();
            else if (e.key === '+' || e.key === '=') actions['zoom-in']();
            else if (e.key === '-') actions['zoom-out']();
        });
    }

    var actions = {
        close: function () { overlay.classList.remove('active'); },
        prev: function () { navigate(-1); },
        next: function () { navigate(1); },
        'zoom-in': function () {
            scale = Math.min(scale * 1.25, 5);
            applyZoom();
        },
        'zoom-out': function () {
            scale = Math.max(scale / 1.25, 0.5);
            applyZoom();
        },
    };

    function navigate(delta) {
        if (images.length <= 1) return;
        currentIdx = (currentIdx + delta + images.length) % images.length;
        show();
    }

    function applyZoom() {
        var img = overlay.querySelector('.gallery-image');
        img.style.transform = 'scale(' + scale + ')';
        if (scale > 1) {
            img.classList.add('zoomed');
        } else {
            img.classList.remove('zoomed');
        }
    }

    function show() {
        var img = overlay.querySelector('.gallery-image');
        img.src = images[currentIdx];
        scale = 1;
        applyZoom();
        var counter = overlay.querySelector('.gallery-counter');
        counter.textContent = images.length > 1 ? (currentIdx + 1) + ' / ' + images.length : '';
        overlay.querySelector('.gallery-nav.prev').style.display = images.length > 1 ? '' : 'none';
        overlay.querySelector('.gallery-nav.next').style.display = images.length > 1 ? '' : 'none';
    }

    function open(idx) {
        if (!overlay) buildOverlay();
        currentIdx = idx;
        show();
        overlay.classList.add('active');
    }

    function close() {
        overlay.classList.remove('active');
    }

    function bindImages() {
        var galleryEls = document.querySelectorAll('[data-gallery]');
        images = Array.from(galleryEls).map(function (img) {
            return img.src || img.dataset.gallery;
        });
        if (images.length === 0) return;

        galleryEls.forEach(function (img, idx) {
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', function (e) {
                e.preventDefault();
                open(idx);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindImages);
    } else {
        bindImages();
    }
})();
