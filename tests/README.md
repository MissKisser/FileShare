# 测试

本项目支持两种测试方式。

## 1. PHPUnit 单元测试（推荐新代码使用）

需要先安装 Composer 依赖：

```bash
composer install
```

运行所有 PHPUnit 测试：

```bash
composer test
# 或
vendor/bin/phpunit
```

示范用例在 `tests/Unit/ExampleTest.php`，测试纯函数（不依赖 HTTP 服务器）。

### 添加新 PHPUnit 测试

- 纯函数测试（formatSize、maskIP 等）放 `tests/Unit/`
- 需要数据库/HTTP 的集成测试放 `tests/Integration/`，参考 `tests/helpers/HttpHelper.php`

## 2. 手动 CLI 测试脚本（已存在的端到端测试）

`tests/test_*.php` 是一组手写的 CLI 脚本，启动内置 PHP 服务器后跑完整流程：

```bash
# 启动测试服务器（独立端口，避免影响开发环境）
php -S 127.0.0.1:8767 &

# 运行单个测试
BASE_URL=http://127.0.0.1:8767 php tests/test_owner_delete.php
BASE_URL=http://127.0.0.1:8767 php tests/test_delete_auth.php
BASE_URL=http://127.0.0.1:8767 php tests/test_password_protection_leak.php
BASE_URL=http://127.0.0.1:8767 php tests/test_share_page_redesign.php
```

这些脚本相互独立，各自管理 cookie jar 与数据库探针。

## 测试覆盖的功能

| 测试文件 | 覆盖范围 |
|---------|---------|
| `test_owner_delete.php` | owner 自删除流程（C4 后行为已更新） |
| `test_delete_auth.php` | admin 删除认证门 + CSRF + atomic delete |
| `test_password_protection_leak.php` | 密码保护项不在公开响应泄露 |
| `test_share_page_redesign.php` | 分享页重构布局 |
| `test_copy_buttons.php` | 复制按钮功能（前端） |
| `test_copy_buttons_browser.js` | 浏览器端复制按钮（Playwright） |

## Backlog

- 把手动脚本逐步迁移到 PHPUnit（M6 仅搭框架 + 示范）
- 引入 GitHub Actions CI 自动跑 PHPUnit
