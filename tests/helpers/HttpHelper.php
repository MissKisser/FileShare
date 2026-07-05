<?php
/**
 * HTTP 测试辅助
 *
 * 抽取自 tests/test_owner_delete.php / test_delete_auth.php 等手动测试脚本，
 * 统一管理 curl + cookie jar 的样板代码，供 PHPUnit 集成测试和手动脚本共用。
 */

/**
 * 发起 GET 请求，维护 cookie jar
 *
 * @param string $base BASE_URL（如 http://127.0.0.1:8767）
 * @param string $jar  cookie jar 文件路径
 * @param string $path 路径（如 /?s=abc123）
 * @return string 响应体
 */
function http_get_jar($base, $jar, $path) {
    $ch = curl_init(rtrim($base, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp === false ? '' : $resp;
}

/**
 * 发起 POST 请求（application/x-www-form-urlencoded），维护 cookie jar
 *
 * @param string $base
 * @param string $jar
 * @param string $path
 * @param array $postFields
 * @param int &$status 输出 HTTP 状态码
 * @return string 响应体
 */
function http_post_jar($base, $jar, $path, $postFields, &$status = 0) {
    $ch = curl_init(rtrim($base, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $resp === false ? '' : $resp;
}

/**
 * 简单断言（手动测试脚本用；PHPUnit 用例用 TestCase::assertTrue）
 *
 * @param bool $cond
 * @param string $message
 */
function assert_true($cond, $message) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
