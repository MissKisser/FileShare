<?php
/**
 * 请求处理器
 * 作者：Hackerdallas
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

function handleRequest() {
    $data = loadData();
    
    // 处理文件上传
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
        handleFileUpload($data);
        return;
    }
    
    // 处理文本保存
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
        handleTextSave($data);
        return;
    }
    
    // 处理删除
    if (isset($_GET['delete'])) {
        handleDelete($data);
        return;
    }
    
    // 处理下载
    if (isset($_GET['download'])) {
        handleDownload($data);
        return;
    }
}

// 获取真实IP地址
function getRealIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

// 记录上传日志
function logUpload($filename, $filesize, $duration) {
    $logFile = STORAGE_DIR . 'upload_log.json';
    
    $logs = [];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logs = json_decode($content, true) ?: [];
    }

    $logs[] = [
        'ip' => getRealIP(),
        'filename' => $filename,
        'filesize' => $filesize,
        'upload_time' => time(),
        'duration' => $duration,
        'expire_time' => $duration === 0 ? 0 : (time() + $duration),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    if (count($logs) > 500) {
        $logs = array_slice($logs, -500);
    }

    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 获取上传日志
function getUploadLogs($limit = 50) {
    $logFile = STORAGE_DIR . 'upload_log.json';
    
    if (!file_exists($logFile)) {
        return [];
    }
    
    $content = file_get_contents($logFile);
    $logs = json_decode($content, true) ?: [];
    
    return array_slice(array_reverse($logs), 0, $limit);
}

// 处理文件上传
function handleFileUpload(&$data) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $duration = intval($_POST['duration'] ?? 600);
        $files = $_FILES['files'];
        $uploadCount = 0;
        $errors = [];
        
        $fileCount = count($files['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $originalName = $files['name'][$i];
                $filename = time() . '_' . uniqid() . '_' . basename($originalName);
                $filepath = UPLOAD_DIR . $filename;
                
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }
                
                if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                    $expire = $duration === 0 ? 0 : time() + $duration;
                    $data[] = [
                        'type' => 'file',
                        'name' => $originalName,
                        'path' => $filepath,
                        'size' => $files['size'][$i],
                        'time' => time(),
                        'expire' => $expire,
                        'ip' => getRealIP()
                    ];
                    
                    logUpload($originalName, $files['size'][$i], $duration);
                    
                    $uploadCount++;
                } else {
                    $errors[] = "文件 {$originalName} 移动失败";
                }
            } else {
                $errors[] = "文件 {$files['name'][$i]} 上传错误（代码：{$files['error'][$i]}）";
            }
        }
        
        if ($uploadCount > 0) {
            saveData($data);
        }
        
        $response = [
            'success' => $uploadCount > 0,
            'message' => $uploadCount > 0 ? "成功上传 {$uploadCount} 个文件" : '上传失败',
            'uploaded' => $uploadCount,
            'errors' => $errors
        ];
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        echo json_encode($response, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        echo json_encode([
            'success' => false,
            'message' => '上传异常：' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    
    exit;
}

// 处理文本保存
function handleTextSave(&$data) {
    $text = $_POST['text'] ?? '';
    $duration = intval($_POST['text_duration'] ?? 600);
    if (!empty(trim($text))) {
        $data[] = [
            'type' => 'text',
            'content' => $text,
            'time' => time(),
            'expire' => $duration === 0 ? 0 : time() + $duration,
            'ip' => getRealIP()
        ];
        saveData($data);
        
        logUpload('文本内容 (' . mb_substr($text, 0, 20) . '...)', strlen($text), $duration);
        
        $_SESSION['message'] = '文本保存成功！';
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理删除
function handleDelete(&$data) {
    $index = intval($_GET['delete']);
    if (isset($data[$index])) {
        if ($data[$index]['type'] === 'file' && file_exists($data[$index]['path'])) {
            @unlink($data[$index]['path']);
        }
        unset($data[$index]);
        $data = array_values($data);
        saveData($data);
        $_SESSION['message'] = '删除成功！';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 处理下载
function handleDownload(&$data) {
    $index = intval($_GET['download']);
    if (isset($data[$index]) && $data[$index]['type'] === 'file') {
        $file = $data[$index];
        if (file_exists($file['path'])) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file['name']) . '"');
            header('Content-Length: ' . filesize($file['path']));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            readfile($file['path']);
            exit;
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}