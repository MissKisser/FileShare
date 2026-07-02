# FileShare

> 轻量级文件上传与文本存储系统 - A lightweight file upload and text storage system

[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## 简介

FileShare 是一个基于 PHP 的轻量级文件上传与文本存储系统。采用 SQLite 数据库存储元数据，支持分享链接、密码保护、RESTful API、管理后台等丰富功能。适用于临时文件分享、剪贴板文本云端保存、团队文件共享等场景。

## 特性

- **多文件上传** - 支持同时上传多个文件，最大 2GB
- **大文件支持** - 密码验证后支持大文件上传
- **文本剪贴板** - 保存文本内容到云端，支持一键复制
- **分享链接** - 每个文件/文本自动生成唯一分享码，支持独立分享页
- **密码保护** - 文件和文本可设置访问密码（bcrypt 加密）
- **二维码** - 分享页自动生成二维码，方便移动端扫码访问
- **文件预览** - 支持图片/视频/音频/PDF 在线预览，视频支持 Range 流式播放
- **搜索与过滤** - 按文件名/内容搜索，按类型/分类过滤，多种排序方式
- **批量操作** - 多选项目批量删除、批量复制分享链接
- **存储统计** - 可视化图表展示类型分布、每日上传趋势
- **管理后台** - 独立管理员面板，查看统计、管理项目、查看日志、系统设置
- **RESTful API** - 完整的 Token 认证 API，支持文件上传、文本保存、项目管理
- **文件去重** - SHA-256 哈希检测重复文件，节省存储空间
- **下载统计** - 记录文件下载/预览次数
- **智能过期** - 可设置保存时长（10分钟/1小时/1天/永久）
- **自动清理** - 过期文件自动删除，去重文件引用计数安全释放
- **响应式设计** - 适配桌面端和移动端，深色/浅色主题
- **隐私保护** - 上传者 IP 地址部分隐藏

## 技术栈

- **后端**: PHP 7.4+
- **数据库**: SQLite（WAL 模式，自动从 JSON 迁移）
- **前端**: 原生 HTML + CSS + JavaScript
- **图表**: Canvas 原生绘制（饼图、柱状图）
- **二维码**: qrcodejs

## 快速开始

### 环境要求

- PHP 7.4 或更高版本（需启用 SQLite3 扩展）
- Web 服务器（Nginx/Apache）
- 浏览器支持 HTML5

### 部署

1. **克隆项目**
```bash
git clone https://github.com/MissKisser/FileShare.git
cd FileShare
```

2. **配置环境变量**

复制 `.env.example` 为 `.env` 并修改：
```bash
cp .env.example .env
```

关键配置项：
```ini
LARGE_FILE_PASSWORD=your-secure-password
ADMIN_PASSWORD=your-admin-password
API_ENABLED=1
SITE_TITLE=文件上传与文本存储系统
```

3. **配置 Web 服务器**

**Nginx** (参考配置):
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/FileShare;
    index index.php;

    client_max_body_size 2048M;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Apache**:
将项目放在网站根目录，确保 `.htaccess` 可用（需要 `mod_rewrite`）。

4. **设置权限**
```bash
chmod -R 755 .
chmod -R 777 uploads/
chmod -R 777 storage/
```

5. **访问网站**

打开浏览器访问 `http://your-domain.com`

首次访问时，如果存在旧的 `data.json` 数据，系统会自动迁移到 SQLite 数据库。

### PHP 配置

如需上传大文件，配置 `.user.ini`:
```ini
upload_max_filesize = 2048M
post_max_size = 2048M
max_execution_time = 7200
memory_limit = 512M
```

## 目录结构

```
FileShare/
├── index.php                  # 应用入口
├── src/
│   ├── config.php             # 后端配置 + 环境变量
│   ├── database.php           # SQLite 连接单例 + 建表 + 迁移
│   ├── functions.php          # 核心数据操作函数
│   ├── handlers.php           # 请求路由与处理器
│   ├── api.php                # RESTful API
│   ├── admin.php              # 管理后台后端
│   └── migrate.php            # JSON→SQLite 迁移脚本
├── templates/
│   ├── main.php               # 主页面模板
│   ├── share.php              # 分享页模板
│   └── admin/
│       ├── layout.php         # 管理后台布局
│       └── login.php          # 管理员登录页
├── assets/
│   ├── css/
│   │   ├── style.css          # 主样式
│   │   ├── components.css     # 组件样式
│   │   └── admin.css          # 管理后台样式
│   └── js/
│       ├── upload.js          # 上传交互脚本
│       ├── charts.js          # Canvas 图表库
│       └── qrcode.min.js      # 二维码生成库
├── storage/                   # 数据存储（需写权限）
│   └── fileshare.db           # SQLite 数据库（自动创建）
├── uploads/                   # 文件存储（需写权限）
├── .env                       # 运行时环境变量（不入库）
├── .env.example               # 环境变量模板
├── .user.ini                  # PHP 运行时配置
└── API.md                     # API 文档
```

## 配置说明

### 环境变量（.env）

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `LARGE_FILE_PASSWORD` | 大文件上传密码（>200MB） | - |
| `ADMIN_PASSWORD` | 管理员密码（后台+API认证） | - |
| `API_ENABLED` | API 开关（1=启用，0=禁用） | `1` |
| `SITE_TITLE` | 站点标题 | `文件上传与文本存储系统` |
| `TRUSTED_PROXIES` | 可信代理 CIDR（逗号分隔） | 空 |

### 管理后台

访问 `/admin`，使用 `.env` 中配置的 `ADMIN_PASSWORD` 登录。

管理后台功能：
- 仪表盘：统计概览、最近上传/下载记录
- 项目管理：搜索、查看、删除文件/文本
- 日志查看：上传日志、下载日志
- 系统设置：站点标题、文件大小限制、IP 黑名单等

## 使用说明

### 文件上传

1. 选择文件（可多选，支持拖拽）
2. 可选：设置访问密码
3. 设置访问时效
4. 点击上传
5. 上传完成后可点击「分享」获取分享链接和二维码

### 文本存储

1. 输入文本内容
2. 可选：设置访问密码
3. 设置留存时间
4. 点击保存
5. 支持一键复制和分享

### 分享

- 每个文件/文本自动生成 8 位分享码
- 分享链接格式：`?s=xxxxxxxx`
- 分享页支持密码保护、二维码、在线预览

### 文件管理

| 操作 | 说明 |
|------|------|
| 拉取 | 下载文件 |
| 预览 | 在线预览（图片/视频/音频/PDF） |
| 分享 | 获取分享链接和二维码 |
| 移除 | 删除文件或文本 |

### 搜索与过滤

- 搜索框：按文件名或文本内容搜索
- 分类过滤：全部/文件/文本/图片/视频/音频/文档/代码/压缩包
- 排序：按时间/名称/大小，升序/降序

### 批量操作

- 勾选多个项目
- 批量删除
- 批量复制分享链接

## API

完整的 RESTful API 文档请参见 [API.md](API.md)。

### 快速示例

```bash
# 获取 API Token
curl -X POST 'https://your-domain.com/index.php?api=auth/token' \
  -H 'Content-Type: application/json' \
  -d '{"password": "your-admin-password"}'

# 上传文件
curl -X POST 'https://your-domain.com/index.php?api=upload' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -F 'file=@/path/to/file.pdf' \
  -F 'duration=86400'

# 保存文本
curl -X POST 'https://your-domain.com/index.php?api=text' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"content": "Hello World", "duration": 3600}'
```

## 安全机制

- 所有 PHP 核心文件通过 `ACCESS_ALLOWED` 常量防止直接访问
- CSRF Token 保护所有状态变更操作
- 管理员密码使用 `hash_equals()` 时序安全比较
- 文件/文本访问密码使用 `password_hash()` bcrypt 加密
- API Token 存储为 SHA-256 哈希值
- SQL 注入防护：PDO 参数化查询
- IP 黑名单支持 CIDR 格式
- 文件下载/预览需验证数据库 ID 有效性
- IP 地址在日志中部分隐藏

## 数据迁移

从旧版 JSON 存储升级到 SQLite：

- **自动迁移**：首次访问时自动检测 `data.json` 并迁移
- **手动迁移**：命令行运行 `php src/migrate.php`
- **备份**：迁移后原 JSON 文件自动备份为 `.bak`
- **零停机**：迁移过程无需停机

## 贡献

欢迎提交 Issue 和 Pull Request！

1. Fork 本仓库
2. 创建特性分支：`git checkout -b feature/amazing-feature`
3. 提交更改：`git commit -m 'Add amazing feature'`
4. 推送分支：`git push origin feature/amazing-feature`
5. 开启 Pull Request

## 许可证

本项目采用 [MIT 许可证](LICENSE)。

---

**作者**: Hackerdallas
