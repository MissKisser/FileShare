# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

基于 PHP 的轻量级文件上传与文本存储系统，采用 SQLite 数据库存储元数据，支持分享链接、密码保护、RESTful API、管理后台等丰富功能。

## 技术栈

- **后端**: PHP 7.4+（需启用 SQLite3 扩展）
- **数据库**: SQLite（WAL 模式，自动从 JSON 迁移）
- **前端**: 原生 HTML + CSS + JavaScript
- **图表**: Canvas 原生绘制（饼图、柱状图）
- **二维码**: qrcodejs 库

## 常用命令

```powershell
# 启动开发服务器（Windows）
php -S localhost:9000

# 使用phpStudy（Windows）
# 1. 将项目复制到 C:\phpstudy_pro\WWW\copy.viaxv.top
# 2. 启动Apache + PHP
# 3. 访问 http://copy.viaxv.top

# 手动运行 JSON→SQLite 迁移
php src/migrate.php
```

## 核心架构

```
index.php                  # 应用入口
├── src/config.php         # 目录配置 + 环境变量加载
├── src/database.php       # SQLite 连接单例 + 建表 + 迁移检测
├── src/functions.php      # 数据操作（CRUD、搜索、统计、日志）
├── src/handlers.php       # 请求路由与处理器（上传/下载/删除/分享/预览/搜索/批量）
├── src/api.php            # RESTful API（Token 认证、CRUD 端点）
├── src/admin.php          # 管理后台后端（登录、仪表盘、日志、设置）
├── src/migrate.php        # JSON→SQLite 迁移脚本
├── templates/main.php     # 主页面模板
├── templates/share.php    # 独立分享页模板
├── templates/admin/       # 管理后台模板
├── assets/js/upload.js    # 前端交互（上传、搜索、批量、分享、统计）
├── assets/js/charts.js    # Canvas 图表库
├── assets/js/qrcode.min.js # 二维码生成
├── storage/               # SQLite 数据库（fileshare.db）
└── uploads/               # 上传文件存储
```

## 请求处理流程

1. `index.php` 引入配置、数据库、函数库和处理器
2. 检测是否需要 JSON→SQLite 迁移（`needsMigration()`）
3. IP 黑名单检查（`isIPBlacklisted()`）
4. `handleRequest()` 根据请求类型分发：
   - `?s=code` → 分享页
   - `?preview=code` → 文件预览（支持 Range 流式传输）
   - `?api=endpoint` → RESTful API
   - `/admin/action` → 管理后台
   - `?action=search` → 搜索
   - `?action=batch_delete` → 批量删除
   - POST 上传/文本/删除/下载
5. 数据通过 PDO 操作 SQLite 数据库
6. 每次页面加载时 `cleanExpired()` 自动清理过期项目

## 数据库结构

- **items** - 项目主表（文件+文本），含 share_code、file_hash、password、download_count、owner_token_hash（owner 删除凭证 sha256 哈希）
- **upload_logs** - 上传日志（含 chunk 上传 session_id、chunk_count、received_chunks 位图、status）
- **download_logs** - 下载日志
- **api_tokens** - API Token（存储 SHA-256 哈希）
- **admin_sessions** - 管理员会话
- **settings** - 系统设置（key-value，含元数据列 label/description/category/control_type/sort_order 用于后台分组渲染）

## 关键逻辑

- **真实IP获取**: 默认信任 REMOTE_ADDR；仅当配置 `TRUSTED_PROXIES` CIDR 时才读 X-Forwarded-For / X-Real-IP / Client-IP（防 IP 伪造）
- **分享码**: `bin2hex(random_bytes(4))` 生成 8 位十六进制码，碰撞重试
- **owner token**: `bin2hex(random_bytes(32))` 64 字符 256 位熵，DB 仅存 sha256(token)，明文仅返回给创建者一次（manage_url）
- **文件去重**: SHA-256 哈希检测重复，引用计数安全删除（事务化）
- **密码保护**: `password_hash()` bcrypt 加密，`password_verify()` 校验
- **API 认证**: Bearer Token（1小时）+ Refresh Token（7天），Token 存储为 SHA-256 哈希，**仅支持 Authorization Header**（不再支持 ?token=）
- **管理后台**: Session 认证，`hash_equals()` 时序安全比较
- **过期清理**: `expire=0` 表示永久保存，非零值表示 Unix 时间戳；5 分钟节流避免热路径阻塞
- **视频流**: HTTP Range 请求支持（单 range 严格解析 + 416 越界保护），断点续传

## 安全机制（速率限制）

为防止密码爆破，公开认证端点均加速率限制（session 维度计数）：

| 端点 | 限制 | key |
|------|------|-----|
| 分享密码验证 `?action=verify_share_password` | 10 次/60 秒 | `share_pwd_<sha1(code+ip)>` |
| 管理员登录 `/admin/login` | 10 次/60 秒 | `admin_login_<ip>` |
| API Token 申请 `?api=auth/token` | 10 次/60 秒 | `api_auth_<ip>` |
| 大文件密码验证 `?action=verify_large_file_password` | 5 次/60 秒 | `large_file_password_attempts` |

失败时计数，成功不计数。超限返回 429。

## 配置说明

| 文件 | 用途 |
|------|------|
| `.env` | 运行时环境变量（LARGE_FILE_PASSWORD, ADMIN_PASSWORD, API_ENABLED, SITE_TITLE） |
| `.env.example` | 环境变量模板 |
| `.user.ini` | PHP 运行时配置（上传大小、超时等） |
| `src/config.php` | 后端目录配置 + 环境变量加载 |

## 目录权限

```
/uploads/   # 存储上传文件，需要写权限
/storage/   # SQLite 数据库 + 日志，需要写权限
```

## 安全机制

- 所有 PHP 核心文件通过 `ACCESS_ALLOWED` 常量防止直接访问
- CSRF Token 保护所有状态变更 POST 请求
- 管理员密码使用 `hash_equals()` 时序安全比较
- 文件/文本访问密码使用 `password_hash()` bcrypt 加密
- API Token 存储为 SHA-256 哈希值
- SQL 注入防护：PDO 参数化查询
- IP 黑名单支持 CIDR 格式
- IP 地址在日志中部分隐藏（`maskIP()` 函数）

## PHP 7.4 兼容性

- 不使用箭头函数 `fn() =>`
- 不使用命名参数
- 不使用 `match` 表达式
- 不使用 `nullsafe` 运算符 `?->`

## 注释规范
1. 必须使用标准文档注释（如 JSDoc、Docstring 等），仅说明代码功能、入参及返回值。
2. 绝对禁止保留任何历史痕迹：不得出现日期、版本变动、需求/Bug单号、被注释的废弃代码或 TODO/FIXME。代码必须呈现为干净的最终版。