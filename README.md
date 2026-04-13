# FileShare

> 轻量级文件上传与文本存储系统 - A lightweight file upload and text storage system

[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## 简介

FileShare 是一个基于 PHP 的轻量级文件上传与文本存储系统。无需数据库，采用 JSON 文件存储元数据，部署简单快捷。适用于临时文件分享、剪贴板文本云端保存等场景。

## 特性

- **多文件上传** - 支持同时上传多个文件
- **大文件支持** - 最大支持 5GB 单文件上传（受服务器配置限制）
- **文本剪贴板** - 保存文本内容到云端，支持一键复制
- **智能过期** - 可设置保存时长（10分钟/1小时/1天/永久）
- **自动清理** - 过期文件自动删除，释放存储空间
- **上传日志** - 记录最近上传历史
- **进度显示** - 实时显示上传进度和预计剩余时间
- **响应式设计** - 适配桌面端和移动端
- **隐私保护** - 上传者 IP 地址部分隐藏

## 技术栈

- **后端**: PHP 7.4+
- **存储**: JSON 文件（无需数据库）
- **前端**: 原生 HTML + CSS + JavaScript
- **构建**: Gulp + Sass

## 快速开始

### 环境要求

- PHP 7.4 或更高版本
- Web 服务器（Nginx/Apache）
- 浏览器支持 HTML5

### 部署

1. **克隆项目**
```bash
git clone https://github.com/MissKisser/FileShare.git
cd FileShare
```

2. **配置 Web 服务器**

**Nginx** (参考配置):
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/FileShare;
    index index.php;

    client_max_body_size 5000M;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Apache**:
将项目放在网站根目录，确保 `.htaccess` 可用（需要 `mod_rewrite`）。

3. **设置权限**
```bash
chmod -R 755 .
chmod -R 777 uploads/
chmod -R 777 storage/
```

4. **访问网站**
打开浏览器访问 `http://your-domain.com`

### PHP 配置

如需上传大文件，配置 `.user.ini`:
```ini
upload_max_filesize = 5000M
post_max_size = 5000M
max_execution_time = 6000
memory_limit = 512M
```

## 目录结构

```
FileShare/
├── index.php              # 应用入口
├── src/
│   ├── config.php         # 后端配置
│   ├── functions.php      # 核心函数
│   └── handlers.php       # 请求处理器
├── templates/
│   └── main.php           # 页面模板
├── frontend/
│   ├── config.php         # 前端配置
│   ├── header.php         # HTML 头部
│   ├── footer.php         # HTML 底部
│   └── assets/            # 前端资源
├── assets/
│   ├── css/               # 样式文件
│   └── js/
│       └── upload.js      # 上传交互脚本
├── storage/               # 数据存储（需写权限）
│   ├── data.json
│   └── upload_log.json
└── uploads/               # 文件存储（需写权限）
```

## 配置说明

### 核心配置

编辑 `src/config.php`:
```php
define('UPLOAD_DIR', __DIR__ . '/../uploads/');   // 上传文件目录
define('STORAGE_DIR', __DIR__ . '/../storage/');  // 数据存储目录
define('DATA_FILE', STORAGE_DIR . 'data.json');   // 元数据文件
```

### 前端配置

编辑 `frontend/config.php`:
```php
define('SITE_TITLE', 'FileShare');           // 网站标题
define('SITE_LOGO', 'assets/img/logo.png');  // Logo 路径
```

## 使用说明

### 文件上传

1. 选择文件（可多选）
2. 设置访问时效
3. 点击上传
4. 上传完成后在列表中管理文件

### 文本存储

1. 输入文本内容
2. 设置留存时间
3. 点击保存
4. 支持一键复制

### 文件管理

| 操作 | 说明 |
|------|------|
| 下载 | 点击"拉取"下载文件 |
| 删除 | 点击"移除"删除文件或文本 |

## API

系统支持通过 URL 参数调用：

- `?delete=<index>` - 删除指定索引的文件/文本
- `?download=<index>` - 下载指定索引的文件

## 安全机制

- 敏感文件访问保护（`.user.ini`、`.htaccess` 等）
- 文件下载索引验证
- IP 地址部分隐藏
- 目录访问限制

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
