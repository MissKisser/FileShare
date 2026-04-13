# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

基于PHP的轻量级文件上传与文本存储系统，无需数据库，采用JSON文件存储元数据。

## 技术栈

- **后端**: PHP 7.4+
- **数据存储**: JSON文件（storage/data.json）
- **前端**: 原生HTML + CSS + JavaScript
- **构建工具**: Gulp + Sass（frontend目录）

## 常用命令

```powershell
# 启动开发服务器（Windows）
php -S localhost:9000

# 前端构建（SCSS 编译）
cd frontend
npm install
gulp

# 使用phpStudy（Windows）
# 1. 将项目复制到 C:\phpstudy_pro\WWW\copy.viaxv.top
# 2. 启动Apache + PHP
# 3. 访问 http://copy.viaxv.top
```

## 核心架构

```
index.php              # 应用入口
├── src/config.php     # 目录和PHP配置
├── src/functions.php  # 数据读写、过期清理、格式化函数
├── src/handlers.php   # 请求处理（上传/下载/删除/文本）
├── templates/main.php # 页面模板
├── frontend/          # 前端资源（CSS/JS/图片）
├── storage/           # 数据存储（data.json, upload_log.json）
└── uploads/           # 上传文件存储
```

## 请求处理流程

1. `index.php` 引入配置、函数库和处理器后调用 `handleRequest()`
2. `handleRequest()` 根据请求类型分发到相应处理函数
3. 数据通过 `loadData()` / `saveData()` 持久化到 `storage/data.json`
4. 每次页面加载时 `cleanExpired()` 自动清理过期项目

## 关键逻辑

- **真实IP获取**: `HTTP_CLIENT_IP` → `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR`
- **上传处理**: 多文件同时上传，支持最大5GB单文件
- **日志记录**: 上传日志保存在 `storage/upload_log.json`，最多保留500条
- **过期清理**: `expire=0` 表示永久保存，非零值表示Unix时间戳

## 配置说明

| 文件 | 用途 |
|------|------|
| `.user.ini` | PHP运行时配置（项目根目录创建，上传大小、超时等） |
| `src/config.php` | 后端目录配置（UPLOAD_DIR, STORAGE_DIR, DATA_FILE） |
| `frontend/config.php` | 站点标题、Logo、reCAPTCHA等前端配置 |

## 目录权限

```
/uploads/   # 存储上传文件，需要写权限
/storage/   # 存储数据文件和日志，需要写权限
```

## 前端构建说明

- `frontend/assets/sass/` → SCSS源文件
- `frontend/dist/styles.css` → 编译输出
- `gulp sass` 任务编译SCSS，watch任务监听文件变化自动编译

## 安全机制

- 所有PHP核心文件通过 `ACCESS_ALLOWED` 常量防止直接访问
- 文件下载通过 `handleDownload()` 验证索引有效性
- IP地址在日志中部分隐藏（`maskIP()` 函数）
