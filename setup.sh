#!/bin/bash

# 创建目录结构
mkdir -p project/{public/{assets/{css,js},uploads},src,storage,templates}

# 创建文件
touch project/public/index.php
touch project/public/assets/css/style.css
touch project/public/assets/js/upload.js
touch project/src/config.php
touch project/src/functions.php
touch project/src/handlers.php
touch project/storage/data.json
touch project/templates/main.php

# 设置权限
chmod 755 project/public/uploads
chmod 755 project/storage
chmod 644 project/storage/data.json

echo "✅ 目录结构创建完成！"
tree project
