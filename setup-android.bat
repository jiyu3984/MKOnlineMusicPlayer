@echo off
REM Android 项目自动配置脚本（Windows 版本）
REM 此脚本将自动完成 Android 项目的初始化和配置

echo ==========================================
echo   MK音乐播放器 - Android 项目配置向导
echo ==========================================
echo.

REM 检查 Node.js
where node >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo X 错误: 未检测到 Node.js
    echo 请先安装 Node.js: https://nodejs.org/
    pause
    exit /b 1
)

echo V Node.js 已安装
node --version
echo V npm 已安装
npm --version
echo.

REM 检查是否已安装依赖
if not exist "node_modules" (
    echo 正在安装依赖...
    call npm install
    echo V 依赖安装完成
) else (
    echo V 依赖已安装
)
echo.

REM 配置提示
echo 配置检查
echo 请确保已配置 mobile-config.js 中的 API 地址
echo.
set /p api_configured="是否已配置 API 地址? (y/n): "

if /i not "%api_configured%"=="y" (
    echo.
    echo 请编辑 mobile-config.js 文件，设置 apiBaseUrl
    echo 示例:
    echo   apiBaseUrl: 'https://your-domain.com',
    echo.
    echo 按任意键打开配置文件...
    pause >nul

    REM 尝试打开编辑器
    if exist "C:\Program Files\Microsoft VS Code\Code.exe" (
        start "" "C:\Program Files\Microsoft VS Code\Code.exe" mobile-config.js
    ) else (
        notepad mobile-config.js
    )

    echo.
    echo 配置完成后，按任意键继续...
    pause >nul
)

echo.
echo 初始化 Capacitor...
echo V Capacitor 配置完成
echo.

REM 添加 Android 平台
if not exist "android" (
    echo 添加 Android 平台...
    call npx cap add android
    echo V Android 平台添加完成
) else (
    echo V Android 平台已存在
)
echo.

REM 同步项目文件
echo 同步项目文件到 Android...
call npx cap sync
echo V 同步完成
echo.

REM 配置深度链接
echo 配置深度链接
echo.
echo 需要手动配置深度链接，请按照以下步骤:
echo.
echo 1. 打开 android\app\src\main\AndroidManifest.xml
echo 2. 在 activity 标签内添加深度链接配置
echo 3. 参考 android-config\AndroidManifest-DeepLink.xml
echo.
pause
echo.

REM 完成提示
echo ==========================================
echo   V Android 项目配置完成！
echo ==========================================
echo.
echo 下一步操作:
echo.
echo 1. 打开 Android Studio:
echo    npm run cap:open:android
echo.
echo 2. 在 Android Studio 中:
echo    - 等待 Gradle 同步完成
echo    - 配置深度链接 (AndroidManifest.xml)
echo    - 点击 Build -^> Build APK
echo.
echo 3. 详细说明请查看:
echo    ANDROID_BUILD.md
echo.
echo 需要帮助？请查看文档或提交 Issue
echo ==========================================
echo.
pause
