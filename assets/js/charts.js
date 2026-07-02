/**
 * FileShare 统计可视化 - Canvas 图表绘制
 * 轻量级实现，不引入重型库
 */

var FileShareCharts = (function() {
    'use strict';

    /**
     * 绘制饼图
     * @param {HTMLCanvasElement} canvas
     * @param {Array} data [{label, value, color}]
     * @param {Object} options
     */
    function drawPieChart(canvas, data, options) {
        options = options || {};
        var ctx = canvas.getContext('2d');
        var width = canvas.width;
        var height = canvas.height;
        var centerX = width / 2;
        var centerY = height / 2;
        var radius = Math.min(width, height) / 2 - 40;

        ctx.clearRect(0, 0, width, height);

        var total = 0;
        for (var i = 0; i < data.length; i++) {
            total += data[i].value;
        }

        if (total === 0) {
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#9CA3AF';
            ctx.font = '14px "Noto Sans SC", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('暂无数据', centerX, centerY);
            return;
        }

        var startAngle = -Math.PI / 2;
        var legendX = width - 140;
        var legendY = 20;

        for (var i = 0; i < data.length; i++) {
            var slice = data[i];
            if (slice.value === 0) continue;
            var sliceAngle = (slice.value / total) * 2 * Math.PI;

            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle, startAngle + sliceAngle);
            ctx.closePath();
            ctx.fillStyle = slice.color;
            ctx.fill();

            // 标签
            if (sliceAngle > 0.2) {
                var midAngle = startAngle + sliceAngle / 2;
                var labelX = centerX + (radius * 0.65) * Math.cos(midAngle);
                var labelY = centerY + (radius * 0.65) * Math.sin(midAngle);
                ctx.fillStyle = '#FFFFFF';
                ctx.font = '12px "Noto Sans SC", sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                var pct = Math.round((slice.value / total) * 100);
                if (pct >= 5) {
                    ctx.fillText(pct + '%', labelX, labelY);
                }
            }

            // 图例
            ctx.fillStyle = slice.color;
            ctx.fillRect(legendX, legendY + i * 22, 12, 12);
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() || '#111827';
            ctx.font = '12px "Noto Sans SC", sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            ctx.fillText(slice.label + ' (' + formatBytes(slice.value) + ')', legendX + 18, legendY + i * 22);

            startAngle += sliceAngle;
        }
    }

    /**
     * 绘制柱状图
     * @param {HTMLCanvasElement} canvas
     * @param {Array} data [{label, value}]
     * @param {Object} options
     */
    function drawBarChart(canvas, data, options) {
        options = options || {};
        var ctx = canvas.getContext('2d');
        var width = canvas.width;
        var height = canvas.height;
        var padding = { top: 20, right: 20, bottom: 40, left: 50 };
        var chartWidth = width - padding.left - padding.right;
        var chartHeight = height - padding.top - padding.bottom;

        ctx.clearRect(0, 0, width, height);

        if (data.length === 0) {
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#9CA3AF';
            ctx.font = '14px "Noto Sans SC", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('暂无数据', width / 2, height / 2);
            return;
        }

        var maxValue = 0;
        for (var i = 0; i < data.length; i++) {
            if (data[i].value > maxValue) maxValue = data[i].value;
        }
        if (maxValue === 0) maxValue = 1;

        var barWidth = Math.min(40, (chartWidth / data.length) * 0.6);
        var barGap = (chartWidth - barWidth * data.length) / (data.length + 1);

        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        var textColor = isDark ? '#94A3B8' : '#6B7280';
        var barColor = isDark ? '#60A5FA' : '#3B82F6';
        var gridColor = isDark ? '#334155' : '#E5E7EB';

        // Y 轴网格线
        var gridLines = 5;
        ctx.font = '11px "JetBrains Mono", monospace';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (var g = 0; g <= gridLines; g++) {
            var y = padding.top + chartHeight - (g / gridLines) * chartHeight;
            var val = Math.round((g / gridLines) * maxValue);
            ctx.fillStyle = textColor;
            ctx.fillText(val, padding.left - 8, y);
            ctx.strokeStyle = gridColor;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();
        }

        // 柱子
        for (var i = 0; i < data.length; i++) {
            var x = padding.left + barGap + i * (barWidth + barGap);
            var barHeight = (data[i].value / maxValue) * chartHeight;
            var y = padding.top + chartHeight - barHeight;

            // 渐变
            var gradient = ctx.createLinearGradient(x, y, x, padding.top + chartHeight);
            gradient.addColorStop(0, barColor);
            gradient.addColorStop(1, isDark ? '#1E40AF' : '#93C5FD');
            ctx.fillStyle = gradient;

            // 圆角矩形
            var r = 4;
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + barWidth - r, y);
            ctx.quadraticCurveTo(x + barWidth, y, x + barWidth, y + r);
            ctx.lineTo(x + barWidth, padding.top + chartHeight);
            ctx.lineTo(x, padding.top + chartHeight);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.fill();

            // 数值标签
            if (data[i].value > 0) {
                ctx.fillStyle = textColor;
                ctx.font = '11px "JetBrains Mono", monospace';
                ctx.textAlign = 'center';
                ctx.fillText(data[i].value, x + barWidth / 2, y - 6);
            }

            // X 轴标签
            ctx.fillStyle = textColor;
            ctx.font = '11px "Noto Sans SC", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(data[i].label, x + barWidth / 2, padding.top + chartHeight + 20);
        }
    }

    /**
     * 格式化字节数
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < 3) {
            bytes /= 1024;
            i++;
        }
        return Math.round(bytes * 10) / 10 + ' ' + units[i];
    }

    // 类别颜色映射
    var categoryColors = {
        image: '#3B82F6',
        video: '#EF4444',
        audio: '#F59E0B',
        doc: '#10B981',
        code: '#8B5CF6',
        archive: '#6366F1',
        text: '#EC4899'
    };

    var categoryLabels = {
        image: '图片',
        video: '视频',
        audio: '音频',
        doc: '文档',
        code: '代码',
        archive: '压缩包',
        text: '文本'
    };

    return {
        drawPieChart: drawPieChart,
        drawBarChart: drawBarChart,
        categoryColors: categoryColors,
        categoryLabels: categoryLabels,
        formatBytes: formatBytes
    };
})();
