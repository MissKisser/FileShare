<?php
/**
 * 示范用 PHPUnit 测试用例
 *
 * 展示如何用 PHPUnit 写单元测试（不依赖真实 HTTP 服务器）。
 * 完整迁移 tests/test_*.php 到 PHPUnit 留作 backlog（M6 仅搭框架）。
 */
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 示范：formatSize 正确格式化字节数
     */
    public function testFormatSizeFormatsBytes()
    {
        $this->assertSame('0 B', formatSize(0));
        $this->assertSame('1 B', formatSize(1));
        $this->assertSame('1 KB', formatSize(1024));
        $this->assertSame('1 MB', formatSize(1024 * 1024));
        $this->assertSame('1 GB', formatSize(1024 * 1024 * 1024));
    }

    /**
     * 示范：formatExpire 处理永久/过期/未来时间
     */
    public function testFormatExpireHandlesEdgeCases()
    {
        $this->assertSame('永久', formatExpire(0));
        $this->assertSame('已过期', formatExpire(time() - 100));
    }

    /**
     * 示范：maskIP 正确掩码 IPv4
     */
    public function testMaskIPMasksLastTwoOctets()
    {
        $masked = maskIP('192.168.1.100');
        $this->assertSame('192.168.***.***', $masked);
        // 非 IPv4 原样返回
        $this->assertSame('unknown', maskIP('unknown'));
    }

    /**
     * 示范：sanitizeStoredFilename 净化文件名
     */
    public function testSanitizeFilenameRemovesUnsafeChars()
    {
        $this->assertSame('hello.txt', sanitizeStoredFilename('hello.txt'));
        $this->assertSame('shell.php', sanitizeStoredFilename('../../shell.php'));
        // 空格、中文等非 [a-zA-Z0-9._-] 字符转为下划线
        $this->assertSame('hello_world.txt', sanitizeStoredFilename('hello world.txt'));
    }
}
