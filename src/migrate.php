<?php
/**
 * JSON → SQLite 数据迁移脚本
 * 作者：Hackerdallas
 * 
 * 可通过命令行运行：php src/migrate.php
 * 或通过浏览器访问：?action=migrate
 * 
 * 迁移完成后，旧 JSON 文件会被重命名为 .bak 备份
 */
if (!defined('ACCESS_ALLOWED')) {
    // 允许命令行直接运行
    if (php_sapi_name() === 'cli') {
        define('ACCESS_ALLOWED', true);
        define('ROOT_DIR', dirname(__DIR__));
        define('PUBLIC_DIR', ROOT_DIR);
        define('UPLOAD_DIR', PUBLIC_DIR . '/uploads/');
        define('STORAGE_DIR', PUBLIC_DIR . '/storage/');
        define('DATA_FILE', STORAGE_DIR . 'data.json');
    } else {
        exit('Access Denied');
    }
}

require_once __DIR__ . '/database.php';

/**
 * 执行迁移
 * 
 * @return array 返回迁移结果
 */
function runMigration() {
    $result = [
        'success' => true,
        'message' => '',
        'items_migrated' => 0,
        'logs_migrated' => 0,
        'errors' => []
    ];

    try {
        $db = getDB();

        // 检查是否已有数据（避免重复迁移）
        $existingCount = $db->query('SELECT COUNT(*) as cnt FROM items')->fetch()['cnt'];
        if ($existingCount > 0) {
            $result['success'] = false;
            $result['message'] = "数据库中已有 {$existingCount} 条记录，跳过迁移。如需重新迁移，请先清空数据库。";
            return $result;
        }

        // 迁移 data.json
        $dataFile = STORAGE_DIR . 'data.json';
        if (file_exists($dataFile)) {
            $content = file_get_contents($dataFile);
            $data = json_decode($content, true) ?: [];

            if (!empty($data)) {
                $db->beginTransaction();

                $stmt = $db->prepare('
                    INSERT INTO items (share_code, type, name, content, path, size, file_hash, mime_type, password, download_count, ip, user_agent, time, expire, duration)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                foreach ($data as $i => $item) {
                    try {
                        $shareCode = generateShareCode($db);
                        $type = $item['type'] ?? 'file';
                        $name = isset($item['name']) ? $item['name'] : null;
                        $contentVal = isset($item['content']) ? $item['content'] : null;
                        $path = isset($item['path']) ? $item['path'] : null;
                        $size = isset($item['size']) ? intval($item['size']) : 0;
                        $fileHash = null;
                        $mimeType = null;
                        $password = null;
                        $downloadCount = 0;
                        $ip = isset($item['ip']) ? $item['ip'] : 'unknown';
                        $userAgent = null;
                        $time = isset($item['time']) ? intval($item['time']) : time();
                        $expire = isset($item['expire']) ? intval($item['expire']) : 0;
                        $duration = ($expire > 0 && $time > 0) ? ($expire - $time) : 0;
                        if ($duration < 0) $duration = 0;

                        $stmt->execute([
                            $shareCode, $type, $name, $contentVal, $path, $size,
                            $fileHash, $mimeType, $password, $downloadCount,
                            $ip, $userAgent, $time, $expire, $duration
                        ]);
                        $result['items_migrated']++;
                    } catch (Exception $e) {
                        $result['errors'][] = "第 {$i} 条记录迁移失败: " . $e->getMessage();
                    }
                }

                $db->commit();

                // 备份旧文件
                if (!file_exists($dataFile . '.bak')) {
                    rename($dataFile, $dataFile . '.bak');
                }
            } else {
                $result['message'] .= 'data.json 为空，跳过。';
            }
        } else {
            $result['message'] .= 'data.json 不存在，跳过。';
        }

        // 迁移 upload_log.json
        $logFile = STORAGE_DIR . 'upload_log.json';
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $logs = json_decode($content, true) ?: [];

            if (!empty($logs)) {
                $db->beginTransaction();

                $stmt = $db->prepare('
                    INSERT INTO upload_logs (item_id, ip, filename, filesize, upload_time, duration, expire_time, user_agent, action)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                foreach ($logs as $i => $log) {
                    try {
                        $stmt->execute([
                            null,
                            $log['ip'] ?? 'unknown',
                            $log['filename'] ?? '',
                            $log['filesize'] ?? 0,
                            $log['upload_time'] ?? time(),
                            $log['duration'] ?? 0,
                            $log['expire_time'] ?? 0,
                            $log['user_agent'] ?? '',
                            'upload'
                        ]);
                        $result['logs_migrated']++;
                    } catch (Exception $e) {
                        $result['errors'][] = "日志第 {$i} 条迁移失败: " . $e->getMessage();
                    }
                }

                $db->commit();

                // 备份旧文件
                if (!file_exists($logFile . '.bak')) {
                    rename($logFile, $logFile . '.bak');
                }
            }
        }

        $result['message'] .= sprintf(
            '迁移完成：项目 %d 条，日志 %d 条。',
            $result['items_migrated'],
            $result['logs_migrated']
        );

        if (!empty($result['errors'])) {
            $result['message'] .= ' 部分记录迁移失败，详见 errors。';
            $result['success'] = false;
        }

    } catch (Exception $e) {
        $result['success'] = false;
        $result['message'] = '迁移异常: ' . $e->getMessage();
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

// 命令行直接运行
if (php_sapi_name() === 'cli') {
    echo "FileShare JSON → SQLite 迁移工具\n";
    echo "================================\n\n";

    $result = runMigration();

    echo $result['message'] . "\n";
    if (!empty($result['errors'])) {
        echo "\n错误详情:\n";
        foreach ($result['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }

    echo "\n" . ($result['success'] ? '✓ 迁移成功' : '✗ 迁移失败') . "\n";
    exit($result['success'] ? 0 : 1);
}
