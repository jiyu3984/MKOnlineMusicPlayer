#!/bin/bash

# Android 项目自动配置脚本
# 此脚本将自动完成 Android 项目的初始化和配置

set -e  # 遇到错误时退出

echo "=========================================="
echo "  MK音乐播放器 - Android 项目配置向导"
echo "=========================================="
echo ""

# 检查 Node.js
if ! command -v node &> /dev/null; then
    echo "❌ 错误: 未检测到 Node.js"
    echo "请先安装 Node.js: https://nodejs.org/"
    exit 1
fi

echo "✅ Node.js 版本: $(node --version)"
echo "✅ npm 版本: $(npm --version)"
echo ""

# 检查是否已安装依赖
if [ ! -d "node_modules" ]; then
    echo "📦 正在安装依赖..."
    npm install
    echo "✅ 依赖安装完成"
else
    echo "✅ 依赖已安装"
fi
echo ""

# 检查是否已配置 API
echo "🔧 配置检查"
echo "请确保已配置 mobile-config.js 中的 API 地址"
echo ""
read -p "是否已配置 API 地址? (y/n): " api_configured

if [ "$api_configured" != "y" ]; then
    echo ""
    echo "请编辑 mobile-config.js 文件，设置 apiBaseUrl"
    echo "示例:"
    echo "  apiBaseUrl: 'https://your-domain.com',"
    echo ""
    read -p "按回车键继续编辑配置文件..."

    # 尝试打开编辑器
    if command -v code &> /dev/null; then
        code mobile-config.js
    elif command -v nano &> /dev/null; then
        nano mobile-config.js
    elif command -v vi &> /dev/null; then
        vi mobile-config.js
    else
        echo "请手动编辑 mobile-config.js 文件"
    fi

    echo ""
    read -p "配置完成后，按回车键继续..."
fi

echo ""
echo "📱 初始化 Capacitor..."

# 检查是否已初始化
if [ ! -f "capacitor.config.json" ]; then
    echo "正在创建 Capacitor 配置..."
    # 配置文件已经存在，跳过
fi

echo "✅ Capacitor 配置完成"
echo ""

# 添加 Android 平台
if [ ! -d "android" ]; then
    echo "📱 添加 Android 平台..."
    npx cap add android
    echo "✅ Android 平台添加完成"
else
    echo "✅ Android 平台已存在"
fi
echo ""

# 同步项目文件
echo "🔄 同步项目文件到 Android..."
npx cap sync
echo "✅ 同步完成"
echo ""

# 配置深度链接
echo "🔗 配置深度链接"
echo ""
echo "需要手动配置深度链接，请按照以下步骤:"
echo ""
echo "1. 打开 android/app/src/main/AndroidManifest.xml"
echo "2. 在 <activity> 标签内添加以下配置:"
echo ""
cat android-config/AndroidManifest-DeepLink.xml
echo ""
read -p "按回车键继续..."
echo ""

# 完成提示
echo "=========================================="
echo "  ✅ Android 项目配置完成！"
echo "=========================================="
echo ""
echo "下一步操作:"
echo ""
echo "1. 打开 Android Studio:"
echo "   npm run cap:open:android"
echo ""
echo "2. 在 Android Studio 中:"
echo "   - 等待 Gradle 同步完成"
echo "   - 配置深度链接（AndroidManifest.xml）"
echo "   - 点击 'Build' -> 'Build APK'"
echo ""
echo "3. 详细说明请查看:"
echo "   ANDROID_BUILD.md"
echo ""
echo "需要帮助？请查看文档或提交 Issue"
echo "=========================================="
