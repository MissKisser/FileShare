<?php
/**
 * JSON 至 SQLite 数据迁移入口。
 */
if (!defined('ACCESS_ALLOWED')) {
    if (php_sapi_name() === 'cli') {
        define('ACCESS_ALLOWED', true);
    } else {
        exit('Access Denied');
    }
}

require_once __DIR__ . '/database.php';

/**
 * 执行迁移
 *
 * @return array{success: bool, message: string, items_migrated: int, logs_migrated: int, errors: string[]}
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

        $existingCount = $db->query('SELECT COUNT(*) as cnt FROM items')->fetch()['cnt'];
        if ($existingCount > 0) {
            $result['success'] = false;
            $result['message'] = "数据库中已有 {$existingCount} 条记录，跳过迁移。如需重新迁移，请先清空数据库。";
            return $result;
        }

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
                        error_log("migrate data.json row {$i} failed: " . $e->getMessage());
                        $result['errors'][] = "第 {$i} 条记录迁移失败（详见服务器错误日志）";
                    }
                }

                $db->commit();

                if (!file_exists($dataFile . '.bak')) {
                    rename($dataFile, $dataFile . '.bak');
                }
            } else {
                $result['message'] .= 'data.json 为空，跳过。';
            }
        } else {
            $result['message'] .= 'data.json 不存在，跳过。';
        }

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
                        error_log("migrate upload_log.json row {$i} failed: " . $e->getMessage());
                        $result['errors'][] = "日志第 {$i} 条迁移失败（详见服务器错误日志）";
                    }
                }

                $db->commit();

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
        error_log('migrateJsonToSqlite fatal: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $result['success'] = false;
        $result['message'] = '迁移异常（详见服务器错误日志）';
        $result['errors'][] = '内部错误';
    }

    return $result;
}

if (php_sapi_name() === 'cli') {
    echo "FileShare JSON → SQLite 迁移工具\n";
    echo "================================\n\n";

    $result = runMigration();

    echo $result['message'] . "\n";

    if (!empty($result['errors'])) {
        echo "\n错误明细：\n";
        foreach ($result['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }

    echo "\n" . ($result['success'] ? '✓ 迁移成功' : '✗ 迁移失败') . "\n";
    exit($result['success'] ? 0 : 1);
}