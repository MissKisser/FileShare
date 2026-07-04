<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?php echo htmlspecialchars(SITE_TITLE); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/variables.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/reset.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/components.css?v=<?php echo APP_VERSION; ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?php echo APP_VERSION; ?>">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-sidebar">
            <div class="admin-logo">
                <h2>管理后台</h2>
            </div>
            <ul class="admin-nav">
                <li><a href="/admin/dashboard" class="<?php echo $adminPage === 'dashboard' ? 'active' : ''; ?>">仪表盘</a></li>
                <li><a href="/admin/items" class="<?php echo $adminPage === 'items' ? 'active' : ''; ?>">内容管理</a></li>
                <li><a href="/admin/logs" class="<?php echo $adminPage === 'logs' ? 'active' : ''; ?>">日志审计</a></li>
                <li><a href="/admin/settings" class="<?php echo $adminPage === 'settings' ? 'active' : ''; ?>">系统设置</a></li>
            </ul>
            <div class="admin-nav-footer">
                <a href="/">返回前台</a>
                <a href="/admin/logout">退出登录</a>
            </div>
        </nav>

        <main class="admin-main">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="admin-message success">
                    <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($adminPage === 'dashboard'): ?>
                <h1>仪表盘</h1>
                <div class="admin-stats-grid">
                    <div class="admin-stat-card">
                        <div class="admin-stat-label">总项目数</div>
                        <div class="admin-stat-value"><?php echo $adminData['stats']['total_items']; ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-label">文件数</div>
                        <div class="admin-stat-value"><?php echo $adminData['stats']['file_count']; ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-label">文本数</div>
                        <div class="admin-stat-value"><?php echo $adminData['stats']['text_count']; ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-label">今日上传</div>
                        <div class="admin-stat-value"><?php echo $adminData['today_uploads']; ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-label">今日下载</div>
                        <div class="admin-stat-value"><?php echo $adminData['today_downloads']; ?></div>
                    </div>
                </div>

                <div class="admin-disk-card">
                    <div class="admin-disk-header">
                        <h2 style="margin: 0;">磁盘使用情况</h2>
                        <span class="admin-disk-total">
                            总容量 <?php echo htmlspecialchars($adminData['disk']['disk_total_formatted']); ?>
                        </span>
                    </div>
                    <div class="disk-usage-bar">
                        <div class="progress-track">
                            <div class="progress-bar-fill" style="width: <?php echo $adminData['disk']['disk_used_pct']; ?>%"></div>
                        </div>
                        <div class="disk-usage-label">
                            <span>
                                已用 <?php echo htmlspecialchars($adminData['disk']['disk_used_formatted']); ?>
                                （<?php echo number_format($adminData['disk']['disk_used_pct'], 1); ?>%）
                            </span>
                            <span>
                                剩余 <?php echo htmlspecialchars($adminData['disk']['disk_free_formatted']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="admin-disk-detail">
                        <span>
                            上传目录占用：
                            <strong><?php echo htmlspecialchars($adminData['disk']['upload_dir_size_formatted']); ?></strong>
                            （占系统磁盘 <?php echo number_format($adminData['disk']['upload_dir_pct'], 2); ?>%）
                        </span>
                    </div>
                </div>

                <div class="admin-actions" style="margin: 20px 0; display: flex; align-items: center; gap: 12px;">
                    <button type="button" id="batchThumbnailBtn" class="btn btn-primary">批量生成缩略图</button>
                    <span id="batchThumbnailStatus" style="font-size: 13px; color: var(--text-secondary);"></span>
                </div>

                <h2>最近上传</h2>
                <table class="log-table">
                    <thead><tr><th>时间</th><th>文件</th><th>大小</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminData['recent_uploads'] as $log): ?>
                        <tr>
                            <td><?php echo date('H:i:s', $log['upload_time']); ?></td>
                            <td><?php echo htmlspecialchars($log['filename']); ?></td>
                            <td><?php echo formatSize($log['filesize']); ?></td>
                            <td><?php echo htmlspecialchars($log['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($adminPage === 'items'): ?>
                <h1>内容管理</h1>
                <form method="GET" action="/admin/items" class="admin-search-form">
                    <input type="text" name="q" value="<?php echo htmlspecialchars($adminData['query']); ?>" placeholder="搜索...">
                    <select name="type">
                        <option value="all" <?php echo $adminData['type_filter'] === 'all' ? 'selected' : ''; ?>>全部</option>
                        <option value="file" <?php echo $adminData['type_filter'] === 'file' ? 'selected' : ''; ?>>文件</option>
                        <option value="text" <?php echo $adminData['type_filter'] === 'text' ? 'selected' : ''; ?>>文本</option>
                    </select>
                    <button type="submit" class="btn btn-primary">搜索</button>
                </form>
                <div class="batch-toolbar" style="margin: 16px 0; display: flex; gap: 12px; align-items: center;">
                    <button type="button" id="batchDeleteBtn" class="btn btn-danger" disabled>批量删除 (0)</button>
                    <span style="font-size: 13px; color: var(--text-secondary);">提示：勾选行后点击按钮，或按 F7 触发批量删除（不可恢复）</span>
                </div>
                <table class="log-table">
                    <thead><tr><th style="width:32px"><input type="checkbox" id="batchCheckAll"></th><th>ID</th><th>类型</th><th>名称</th><th>大小</th><th>上传时间</th><th>过期</th><th>下载次数</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminData['items'] as $item): ?>
                        <tr>
                            <td><input type="checkbox" class="batch-check" value="<?php echo (int)$item['id']; ?>"></td>
                            <td><?php echo $item['id']; ?></td>
                            <td><?php echo $item['type']; ?></td>
                            <td><?php echo htmlspecialchars($item['name'] ?? mb_substr($item['content'] ?? '', 0, 30)); ?></td>
                            <td><?php echo formatSize($item['size'] ?? 0); ?></td>
                            <td><?php echo date('Y-m-d H:i', $item['time']); ?></td>
                            <td><?php echo formatExpire($item['expire']); ?></td>
                            <td><?php echo $item['download_count']; ?></td>
                            <td>
                                <a href="?s=<?php echo $item['share_code']; ?>" class="btn-small btn-secondary">查看</a>
                                <form method="POST" action="/admin/items" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="delete" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger" onclick="return confirm('确认删除？')">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($adminPage === 'logs'): ?>
                <h1>日志审计</h1>
                <h2>上传日志 (共 <?php echo $adminData['total_uploads']; ?> 条)</h2>
                <table class="log-table">
                    <thead><tr><th>时间</th><th>文件</th><th>大小</th><th>IP</th><th>策略</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminData['upload_logs'] as $log): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', $log['upload_time']); ?></td>
                            <td><?php echo htmlspecialchars($log['filename']); ?></td>
                            <td><?php echo formatSize($log['filesize']); ?></td>
                            <td><?php echo htmlspecialchars($log['ip']); ?></td>
                            <td><?php echo formatDuration($log['duration']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2>下载日志 (共 <?php echo $adminData['total_downloads']; ?> 条)</h2>
                <table class="log-table">
                    <thead><tr><th>时间</th><th>项目ID</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminData['download_logs'] as $log): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', $log['download_time']); ?></td>
                            <td><?php echo $log['item_id']; ?></td>
                            <td><?php echo htmlspecialchars($log['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

<?php elseif ($adminPage === 'settings'): ?>
                <?php
                // 取出 session 中的保存结果消息（页面刷新后一次性显示）
                $settingsBannerMsg = $_SESSION['admin_message'] ?? '';
                $settingsBannerType = $_SESSION['admin_message_type'] ?? 'success';
                $settingsBannerError = $_SESSION['admin_error'] ?? '';
                if ($settingsBannerMsg) unset($_SESSION['admin_message'], $_SESSION['admin_message_type']);
                if ($settingsBannerError) { unset($_SESSION['admin_error']); $settingsBannerMsg = $settingsBannerError; $settingsBannerType = 'error'; }
                ?>
                <h1>系统设置</h1>
                <div id="settingsToast" class="settings-toast" hidden></div>
                <?php if ($settingsBannerMsg): ?>
                    <div id="settingsBanner" data-msg="<?php echo htmlspecialchars($settingsBannerMsg); ?>" data-type="<?php echo htmlspecialchars($settingsBannerType); ?>" hidden></div>
                <?php endif; ?>

                <form method="POST" id="settingsForm" class="admin-settings-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <?php foreach ($adminData['groups'] as $group): ?>
                        <section class="admin-settings-group" data-group="<?php echo htmlspecialchars($group['key']); ?>">
                            <h2 class="admin-settings-group-title">
                                <span class="admin-settings-group-bar"></span>
                                <?php echo htmlspecialchars($group['label']); ?>
                            </h2>
                            <div class="admin-settings-items">
                            <?php foreach ($group['items'] as $setting): ?>
                                <?php
                                    $key = $setting['key'];
                                    $value = $setting['value'];
                                    $label = $setting['label'] ?: $key;
                                    $desc = $setting['description'] ?? '';
                                    $type = $setting['control_type'] ?: 'text';
                                    $fieldId = 'setting_' . $key;
                                ?>
                                <div class="admin-setting-row" data-key="<?php echo htmlspecialchars($key); ?>" data-type="<?php echo htmlspecialchars($type); ?>">
                                    <div class="admin-setting-label-col">
                                        <label for="<?php echo $fieldId; ?>" class="admin-setting-label"><?php echo htmlspecialchars($label); ?></label>
                                        <?php if ($desc): ?>
                                            <div class="admin-setting-desc"><?php echo htmlspecialchars($desc); ?></div>
                                        <?php endif; ?>
                                        <div class="admin-setting-key"><?php echo htmlspecialchars($key); ?></div>
                                    </div>
                                    <div class="admin-setting-control-col">
                                        <?php if ($type === 'switch'): ?>
                                            <label class="admin-switch">
                                                <input type="checkbox" name="settings[<?php echo htmlspecialchars($key); ?>]" id="<?php echo $fieldId; ?>" value="1" <?php echo $value === '1' ? 'checked' : ''; ?>>
                                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                                <span class="admin-switch-state" data-on="已启用" data-off="已关闭"><?php echo $value === '1' ? '已启用' : '已关闭'; ?></span>
                                            </label>
                                        <?php elseif ($type === 'textarea'): ?>
                                            <textarea name="settings[<?php echo htmlspecialchars($key); ?>]" id="<?php echo $fieldId; ?>" rows="4" spellcheck="false"><?php echo htmlspecialchars($value); ?></textarea>
                                        <?php elseif ($type === 'number'): ?>
                                            <input type="number" name="settings[<?php echo htmlspecialchars($key); ?>]" id="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($value); ?>" step="1">
                                        <?php else: ?>
                                            <input type="text" name="settings[<?php echo htmlspecialchars($key); ?>]" id="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($value); ?>" spellcheck="false">
                                        <?php endif; ?>
                                        <div class="admin-setting-error" data-for="<?php echo htmlspecialchars($key); ?>" hidden></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>

                    <div class="admin-settings-actions">
                        <button type="submit" class="btn btn-primary" id="settingsSubmitBtn">保存设置</button>
                        <span id="settingsHint" class="admin-settings-hint"></span>
                    </div>
                </form>
            <?php endif; ?>
        </main>
    </div>
    <script>
    (function() {
        var btn = document.getElementById('batchThumbnailBtn');
        var status = document.getElementById('batchThumbnailStatus');
        if (!btn || !status) return;

        btn.addEventListener('click', function() {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.textContent = '生成中…';
            status.textContent = '';

            var formData = new FormData();
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('?admin=batch-thumbnails', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    status.textContent = '错误: ' + data.error;
                    status.style.color = 'var(--accent-red)';
                } else {
                    status.textContent = '完成！成功 ' + data.success + ' 个，失败 ' + data.failed + ' 个，共 ' + data.total + ' 个';
                    status.style.color = data.failed > 0 ? 'var(--accent-orange, #f59e0b)' : 'var(--accent-green, #22c55e)';
                }
            })
            .catch(function() {
                status.textContent = '请求失败，请重试';
                status.style.color = 'var(--accent-red)';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = '批量生成缩略图';
            });
        });
    })();

    // ===== 系统设置页：原生 form 提交 + 客户端校验 + Toast =====
    (function() {
        var form = document.getElementById('settingsForm');
        if (!form) return;

        var submitBtn = document.getElementById('settingsSubmitBtn');
        var toast = document.getElementById('settingsToast');

        function showToast(message, type) {
            if (!toast) return;
            toast.textContent = message;
            toast.className = 'settings-toast ' + (type || '');
            toast.hidden = false;
            clearTimeout(showToast._t);
            showToast._t = setTimeout(function() { toast.hidden = true; }, 3500);
        }

        function clearErrors() {
            var rows = form.querySelectorAll('.admin-setting-row');
            for (var i = 0; i < rows.length; i++) {
                rows[i].querySelectorAll('input, textarea').forEach(function(el){ el.classList.remove('invalid'); });
                var err = rows[i].querySelector('.admin-setting-error');
                if (err) { err.hidden = true; err.textContent = ''; }
            }
        }

        function showFieldError(row, msg) {
            var ctrl = row.querySelector('input, textarea');
            if (ctrl) ctrl.classList.add('invalid');
            var err = row.querySelector('.admin-setting-error');
            if (err) { err.textContent = msg; err.hidden = false; }
        }

        function clientValidate() {
            var ok = true;
            clearErrors();
            var rows = form.querySelectorAll('.admin-setting-row');
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var type = row.getAttribute('data-type');
                var ctrl = row.querySelector('input, textarea');
                if (!ctrl) continue;
                var val = (type === 'switch') ? (ctrl.checked ? '1' : '0') : ctrl.value;

                if (type === 'int') {
                    if (val === '' || isNaN(parseInt(val, 10))) {
                        showFieldError(row, '必须是整数'); ok = false;
                    }
                } else if (type === 'bytes') {
                    if (!/^\d+(\.\d+)?\s*(B|KB|MB|GB|TB)?$/i.test(val.trim())) {
                        showFieldError(row, '格式无效，例如 200MB / 2GB'); ok = false;
                    }
                }
            }
            return ok;
        }

        // 同步 switch 状态文字
        form.addEventListener('change', function(e) {
            var t = e.target;
            if (t && t.type === 'checkbox') {
                var label = t.closest('.admin-switch');
                if (label) {
                    var state = label.querySelector('.admin-switch-state');
                    if (state) state.textContent = t.checked ? state.getAttribute('data-on') : state.getAttribute('data-off');
                }
            }
        });

        form.addEventListener('submit', function(e) {
            if (!clientValidate()) {
                e.preventDefault();
                showToast('请修正标红字段', 'error');
                return;
            }
            // 原生表单提交：浏览器自动 POST 到当前 URL,页面刷新
            submitBtn.disabled = true;
            submitBtn.textContent = '保存中…';
            // 不 preventDefault,让浏览器继续 POST
        });

        // 页面加载时显示服务端 message（如果有）
        var banner = document.getElementById('settingsBanner');
        if (banner) {
            var msg = banner.getAttribute('data-msg') || '';
            var type = banner.getAttribute('data-type') || '';
            if (msg) {
                showToast(msg, type);
                // 滚动到顶部让用户看到
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    })();
    </script>

    <!-- 批量删除（F7）— 仅在 items 页面渲染时挂载 -->
    <script>
    (function() {
        var btn = document.getElementById('batchDeleteBtn');
        if (!btn) return; // 非 items 页面直接退出

        var allBtn = document.getElementById('batchCheckAll');
        var checks = function() { return document.querySelectorAll('.batch-check'); };

        function refreshCount() {
            var n = document.querySelectorAll('.batch-check:checked').length;
            btn.textContent = '批量删除 (' + n + ')';
            btn.disabled = (n === 0);
        }

        // 每行 checkbox 变化 → 刷新计数
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('batch-check')) {
                refreshCount();
            }
        });

        // 表头全选
        if (allBtn) {
            allBtn.addEventListener('change', function() {
                checks().forEach(function(c) { c.checked = allBtn.checked; });
                refreshCount();
            });
        }

        // 取 CSRF token（meta 标签）
        function csrfToken() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }

        // 批量删除点击
        btn.addEventListener('click', function() {
            var ids = [];
            checks().forEach(function(c) { if (c.checked) ids.push(c.value); });
            if (!ids.length) return;
            if (!confirm('确认删除选中的 ' + ids.length + ' 个项目？\n此操作不可恢复。')) return;

            var fd = new FormData();
            fd.append('csrf_token', csrfToken());
            fd.append('action', 'batch_delete');
            ids.forEach(function(id) { fd.append('ids[]', id); });

            btn.disabled = true;
            var originalText = btn.textContent;
            btn.textContent = '删除中...';

            fetch('/', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
                .then(function(out) {
                    if (out.ok && out.data && out.data.success) {
                        alert(out.data.message || '删除完成');
                        location.reload();
                    } else {
                        alert((out.data && out.data.message) || '删除失败');
                        btn.disabled = false;
                        btn.textContent = originalText;
                        refreshCount();
                    }
                })
                .catch(function(err) {
                    alert('请求失败：' + (err && err.message ? err.message : err));
                    btn.disabled = false;
                    btn.textContent = originalText;
                    refreshCount();
                });
        });

        // F7 快捷键
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F7') {
                e.preventDefault();
                if (!btn.disabled) btn.click();
            }
        });

        refreshCount();
    })();
    </script>
</body>
</html>
