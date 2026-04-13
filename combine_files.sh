#!/bin/bash

# 设置输出文件名
OUTPUT_FILE="combined_contents.txt"

# 清空或创建输出文件
> "$OUTPUT_FILE"

# 使用 find 命令只查找 .php, .js 和 .css 文件
find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) | while read -r file; do
    # 打印文件路径作为分隔符
    echo "=== $file ===" >> "$OUTPUT_FILE"
    # 将文件内容追加到输出文件
    cat "$file" >> "$OUTPUT_FILE"
    # 在每个文件内容后添加一个空行，方便阅读
    echo "" >> "$OUTPUT_FILE"
done

echo "所有 PHP、JS 和 CSS 文件内容已合并到: $OUTPUT_FILE"
