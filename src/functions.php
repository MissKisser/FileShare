<?php
/**
 * 数据处理函数库
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

function loadData() {
    if (file_exists(DATA_FILE)) {
        $content = file_get_contents(DATA_FILE);
        return json_decode($content, true) ?: [];
    }
    return [];
}

function saveData($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cleanExpired(&$data) {
    $now = time();
    $cleanedCount = 0;

    foreach ($data as $key => $item) {
        $expire = isset($item['expire']) ? intval($item['expire']) : 0;
        if ($expire > 0 && $expire < $now) {
            if ($item['type'] === 'file' && isset($item['path'])) {
                $filepath = $item['path'];
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
            unset($data[$key]);
            $cleanedCount++;
        }
    }

    if ($cleanedCount > 0) {
        $data = array_values($data);
        saveData($data);
    }
}

function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function formatExpire($expire) {
    if ($expire === 0) return '永久';
    $diff = $expire - time();
    if ($diff < 0) return '已过期';
    if ($diff < 3600) return floor($diff / 60) . '分钟';
    if ($diff < 86400) return floor($diff / 3600) . '小时';
    return floor($diff / 86400) . '天';
}

function formatDuration($seconds) {
    if ($seconds === 0) return '永久';
    if ($seconds < 3600) return ($seconds / 60) . '分钟';
    if ($seconds < 86400) return ($seconds / 3600) . '小时';
    return ($seconds / 86400) . '天';
}

function maskIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return $parts[0] . '.' . $parts[1] . '.***.***';
    }
    return $ip;
}
