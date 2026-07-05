<?php
/**
 * 压缩包在线预览后端
 * 作者：FileShare Contributors
 *
 * 支持 .zip / .tar / .tar.gz / .tgz 格式
 * 提供两个操作：
 *   - list: 列出压缩包内文件树
 *   - read: 读取压缩包内单个文件内容（文本类）
 */
if (!defined('ACCESS_ALLOWED')) exit('Access Denied');

// 最大可读取的压缩包大小（50MB）
define('ARCHIVE_MAX_SIZE', 50 * 1024 * 1024);
// 单文件读取最大字节数（2MB）
define('ARCHIVE_READ_LIMIT', 2 * 1024 * 1024);
// 允许直接读取的文本扩展名
define('ARCHIVE_TEXT_EXTS', array(
    'txt','md','markdown','json','xml','csv','log','ini','cfg','conf','yaml','yml',
    'js','ts','jsx','tsx','vue','svelte',
    'py','rb','php','java','c','cpp','h','hpp','cs','go','rs','swift','kt',
    'sh','bash','zsh','fish','ps1','bat','cmd',
    'sql','html','htm','css','scss','sass','less','styl',
    'toml','env','gitignore','dockerfile','makefile','cmake'
));

/**
 * 列出压缩包内文件列表
 *
 * @param array $item 项目记录
 * @return array ['entries' => [...], 'error' => string|null]
 */
function archiveList($item) {
    $path = $item['path'];
    if (!file_exists($path)) {
        return array('entries' => array(), 'error' => '文件不存在');
    }
    if (filesize($path) > ARCHIVE_MAX_SIZE) {
        return array('entries' => array(), 'error' => '压缩包过大，无法在线预览');
    }

    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    $entries = array();

    if ($ext === 'zip') {
        $entries = archiveListZip($path);
    } elseif (in_array($ext, array('tar', 'gz', 'tgz'))) {
        $entries = archiveListTar($path);
    } else {
        return array('entries' => array(), 'error' => '不支持的压缩格式');
    }

    return array('entries' => $entries, 'error' => null);
}

/**
 * 读取压缩包内单个文件
 *
 * @param array $item   项目记录
 * @param string $innerPath 压缩包内文件路径
 * @return array ['content' => string, 'mime' => string, 'error' => string|null]
 */
function archiveRead($item, $innerPath) {
    $path = $item['path'];
    if (!file_exists($path)) {
        return array('content' => '', 'mime' => 'text/plain', 'error' => '文件不存在');
    }

    // 安全：禁止路径遍历
    $innerPath = str_replace(array('../', '..\\'), '', $innerPath);
    if ($innerPath === '' || $innerPath[0] === '/') {
        return array('content' => '', 'mime' => 'text/plain', 'error' => '无效的文件路径');
    }

    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    $result = array('content' => '', 'mime' => 'text/plain', 'error' => null);

    if ($ext === 'zip') {
        $result = archiveReadZip($path, $innerPath);
    } elseif (in_array($ext, array('tar', 'gz', 'tgz'))) {
        $result = archiveReadTar($path, $innerPath);
    } else {
        $result['error'] = '不支持的压缩格式';
    }

    return $result;
}

// ============================================================
// ZIP 实现
// ============================================================

/**
 * 列出 ZIP 文件内容
 */
function archiveListZip($path) {
    $entries = array();
    if (!class_exists('ZipArchive')) {
        return array(array('name' => '(ZipArchive 不可用)', 'size' => 0, 'is_dir' => false));
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return array(array('name' => '(无法打开压缩包)', 'size' => 0, 'is_dir' => false));
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        $isDir = substr($name, -1) === '/';
        $entries[] = array(
            'name' => $name,
            'size' => $isDir ? 0 : $stat['size'],
            'is_dir' => $isDir,
            'compressed_size' => isset($stat['comp_size']) ? $stat['comp_size'] : 0,
        );
    }
    $zip->close();
    return $entries;
}

/**
 * 读取 ZIP 内单个文件
 */
function archiveReadZip($path, $innerPath) {
    $result = array('content' => '', 'mime' => 'text/plain', 'error' => null);

    if (!class_exists('ZipArchive')) {
        $result['error'] = 'ZipArchive 不可用';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $result['error'] = '无法打开压缩包';
        return $result;
    }

    // 查找文件索引
    $index = $zip->locateName($innerPath);
    if ($index === false) {
        // 尝试不同编码（中文文件名常见问题）
        $index = $zip->locateName($innerPath, ZipArchive::FL_NOCASE);
    }
    if ($index === false) {
        $zip->close();
        $result['error'] = '文件不存在于压缩包内';
        return $result;
    }

    $stat = $zip->statIndex($index);
    if ($stat['size'] > ARCHIVE_READ_LIMIT) {
        $zip->close();
        $result['error'] = '文件过大，无法在线预览（上限 2MB）';
        return $result;
    }

    $content = $zip->getFromIndex($index);
    $zip->close();

    if ($content === false) {
        $result['error'] = '读取文件失败';
        return $result;
    }

    // 检测是否为文本
    $innerExt = strtolower(pathinfo($innerPath, PATHINFO_EXTENSION));
    if (!in_array($innerExt, ARCHIVE_TEXT_EXTS)) {
        // 非 whitelist 扩展名，检查是否为文本内容
        if (isBinaryContent($content)) {
            $result['error'] = '该文件为二进制文件，不支持在线预览';
            return $result;
        }
    }

    // 尝试 UTF-8 解码，否则尝试 GBK
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = mb_convert_encoding($content, 'UTF-8', 'GBK,GB2312,GB18030');
        if ($converted !== false) {
            $content = $converted;
        }
    }

    $result['content'] = $content;
    $result['mime'] = guessTextMime($innerExt);
    return $result;
}

// ============================================================
// TAR 实现（使用 PharData）
// ============================================================

/**
 * 列出 TAR 文件内容
 */
function archiveListTar($path) {
    $entries = array();
    if (!class_exists('PharData')) {
        return array(array('name' => '(PharData 不可用)', 'size' => 0, 'is_dir' => false));
    }

    try {
        $phar = new PharData($path);
        foreach (new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $name = $file->getFilename();
            // 获取相对路径
            $relPath = substr($file->getPathname(), strlen($phar->getPath()));
            $relPath = ltrim($relPath, '/\\');
            if (empty($relPath)) continue;

            $isDir = $file->isDir();
            $entries[] = array(
                'name' => $relPath,
                'size' => $isDir ? 0 : $file->getSize(),
                'is_dir' => $isDir,
                'compressed_size' => 0,
            );
        }
    } catch (Exception $e) {
        // I8 加固：异常 message 含 PharData 内部细节，对用户模糊化
        error_log('archiveListTar failed: ' . $e->getMessage());
        $entries[] = array('name' => '(无法读取压缩包内容)', 'size' => 0, 'is_dir' => false);
    }
    return $entries;
}

/**
 * 读取 TAR 内单个文件
 */
function archiveReadTar($path, $innerPath) {
    $result = array('content' => '', 'mime' => 'text/plain', 'error' => null);

    if (!class_exists('PharData')) {
        $result['error'] = 'PharData 不可用';
        return $result;
    }

    try {
        $phar = new PharData($path);
        // PharData 使用 phar:// 路径
        $fullPath = 'phar://' . $path . '/' . $innerPath;
        if (!file_exists($fullPath)) {
            $result['error'] = '文件不存在于压缩包内';
            return $result;
        }

        $fileSize = filesize($fullPath);
        if ($fileSize > ARCHIVE_READ_LIMIT) {
            $result['error'] = '文件过大，无法在线预览（上限 2MB）';
            return $result;
        }

        $content = file_get_contents($fullPath);

        // 检测是否为文本
        $innerExt = strtolower(pathinfo($innerPath, PATHINFO_EXTENSION));
        if (!in_array($innerExt, ARCHIVE_TEXT_EXTS)) {
            if (isBinaryContent($content)) {
                $result['error'] = '该文件为二进制文件，不支持在线预览';
                return $result;
            }
        }

        // 编码转换
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'GBK,GB2312,GB18030');
            if ($converted !== false) {
                $content = $converted;
            }
        }

        $result['content'] = $content;
        $result['mime'] = guessTextMime($innerExt);
    } catch (Exception $e) {
        // I8 加固：异常 message 含 phar:// 路径细节，对用户模糊化
        error_log('archiveReadTar failed: ' . $e->getMessage());
        $result['error'] = '读取压缩包内容失败';
    }

    return $result;
}

// ============================================================
// 辅助函数
// ============================================================

/**
 * 简单检测二进制内容
 */
function isBinaryContent($str) {
    // 取前 4KB 检测
    $sample = substr($str, 0, 4096);
    // NULL 字节或大量控制字符 → 二进制
    if (strpos($sample, "\0") !== false) {
        return true;
    }
    $controlCount = 0;
    $len = strlen($sample);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($sample[$i]);
        if ($c < 32 && $c !== 9 && $c !== 10 && $c !== 13) {
            $controlCount++;
        }
    }
    // 控制字符占比超过 1% → 二进制
    return ($controlCount / max($len, 1)) > 0.01;
}

/**
 * 根据扩展名猜测文本 MIME
 */
function guessTextMime($ext) {
    $map = array(
        'json' => 'application/json',
        'xml' => 'application/xml',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'text/javascript',
        'yaml' => 'text/yaml',
        'yml' => 'text/yaml',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'csv' => 'text/csv',
        'sql' => 'application/sql',
    );
    return isset($map[$ext]) ? $map[$ext] : 'text/plain';
}
