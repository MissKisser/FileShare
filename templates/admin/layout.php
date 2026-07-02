<?php if (!defined('ACCESS_ALLOWED')) exit('Access Denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?php echo htmlspecialchars(SITE_TITLE); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/reset.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-sidebar">
            <div class="admin-logo">
                <h2>管理后台</h2>
            </div>
            <ul class="admin-nav">
                <li><a href="?admin=dashboard" class="<?php echo $adminPage === 'dashboard' ? 'active' : ''; ?>">仪表盘</a></li>
                <li><a href="?admin=items" class="<?php echo $adminPage === 'items' ? 'active' : ''; ?>">内容管理</a></li>
                <li><a href="?admin=logs" class="<?php echo $adminPage === 'logs' ? 'active' : ''; ?>">日志审计</a></li>
                <li><a href="?admin=settings" class="<?php echo $adminPage === 'settings' ? 'active' : ''; ?>">系统设置</a></li>
            </ul>
            <div class="admin-nav-footer">
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>">返回前台</a>
                <a href="?admin=logout">退出登录</a>
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
                        <div class="admin-stat-label">磁盘占用</div>
                        <div class="admin-stat-value"><?php echo formatSize($adminData['stats']['disk_usage']); ?></div>
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
                <form method="GET" class="admin-search-form">
                    <input type="hidden" name="admin" value="items">
                    <input type="text" name="q" value="<?php echo htmlspecialchars($adminData['query']); ?>" placeholder="搜索...">
                    <select name="type">
                        <option value="all" <?php echo $adminData['type_filter'] === 'all' ? 'selected' : ''; ?>>全部</option>
                        <option value="file" <?php echo $adminData['type_filter'] === 'file' ? 'selected' : ''; ?>>文件</option>
                        <option value="text" <?php echo $adminData['type_filter'] === 'text' ? 'selected' : ''; ?>>文本</option>
                    </select>
                    <button type="submit" class="btn btn-primary">搜索</button>
                </form>
                <table class="log-table">
                    <thead><tr><th>ID</th><th>类型</th><th>名称</th><th>大小</th><th>上传时间</th><th>过期</th><th>下载次数</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminData['items'] as $item): ?>
                        <tr>
                            <td><?php echo $item['id']; ?></td>
                            <td><?php echo $item['type']; ?></td>
                            <td><?php echo htmlspecialchars($item['name'] ?? mb_substr($item['content'] ?? '', 0, 30)); ?></td>
                            <td><?php echo formatSize($item['size'] ?? 0); ?></td>
                            <td><?php echo date('Y-m-d H:i', $item['time']); ?></td>
                            <td><?php echo formatExpire($item['expire']); ?></td>
                            <td><?php echo $item['download_count']; ?></td>
                            <td>
                                <a href="?s=<?php echo $item['share_code']; ?>" class="btn-small btn-secondary">查看</a>
                                <form method="POST" style="display:inline">
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
                <h1>系统设置</h1>
                <form method="POST" class="admin-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <?php foreach ($adminData['settings'] as $setting): ?>
                        <div class="admin-setting-row">
                            <label for="setting_<?php echo $setting['key']; ?>"><?php echo htmlspecialchars($setting['key']); ?></label>
                            <?php if ($setting['key'] === 'ip_blacklist'): ?>
                                <textarea name="settings[<?php echo $setting['key']; ?>]" id="setting_<?php echo $setting['key']; ?>" rows="3"><?php echo htmlspecialchars($setting['value']); ?></textarea>
                            <?php else: ?>
                                <input type="text" name="settings[<?php echo $setting['key']; ?>]" id="setting_<?php echo $setting['key']; ?>" value="<?php echo htmlspecialchars($setting['value']); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
