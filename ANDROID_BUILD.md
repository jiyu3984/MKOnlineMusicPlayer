# Android 应用构建指南

本指南将帮助您将 MK在线音乐播放器打包成 Android 应用（APK）。

## 前置要求

### 必需软件

1. **Node.js** (16.x 或更高)
   - 下载: https://nodejs.org/

2. **Android Studio**
   - 下载: https://developer.android.com/studio
   - 用于构建 Android 应用

3. **Java JDK** (11 或更高)
   - Android Studio 通常会自带
   - 或下载: https://www.oracle.com/java/technologies/downloads/

### 可选软件

- **Git** - 版本控制
- **Gradle** - Android Studio 自带

## 第一步：安装依赖

在项目根目录运行：

```bash
npm install
```

这将安装所有必需的依赖，包括 Capacitor。

## 第二步：配置 API 服务器

由于 Android 应用无法运行 PHP，您需要配置一个远程 API 服务器。

### 选项 A: 使用现有服务器（推荐）

编辑 `mobile-config.js` 文件：

```javascript
window.mobileConfig = {
  // 替换为您的服务器地址
  apiBaseUrl: 'https://your-domain.com',
  // 或使用演示服务器
  // apiBaseUrl: 'https://music.itmxue.cn',
};
```

### 选项 B: 本地测试

用于开发测试，手机和电脑需在同一 WiFi：

1. 启动本地 PHP 服务器：
```bash
php -S 0.0.0.0:8080
```

2. 获取电脑 IP 地址（例如 192.168.1.100）

3. 修改 `mobile-config.js`：
```javascript
window.mobileConfig = {
  apiBaseUrl: 'http://192.168.1.100:8080',
};
```

详细配置请参考 [MOBILE_API_SETUP.md](MOBILE_API_SETUP.md)

## 第三步：初始化 Capacitor

首次使用需要初始化 Capacitor：

```bash
npx cap init
```

按提示输入：
- App name: `MK音乐播放器`
- App ID: `com.mkmusic.player`
- Web asset directory: `.` (当前目录)

## 第四步：添加 Android 平台

```bash
npx cap add android
```

这将创建 `android/` 目录并生成 Android 项目文件。

## 第五步：配置深度链接

编辑 `android/app/src/main/AndroidManifest.xml`，在 `<activity>` 标签内添加：

```xml
<!-- 在现有的 intent-filter 之后添加 -->
<intent-filter android:autoVerify="true">
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="mkmusic" />
</intent-filter>
```

完整示例请参考 `android-config/AndroidManifest-DeepLink.xml`

## 第六步：同步项目文件

每次修改 Web 代码后，需要同步到 Android 项目：

```bash
npx cap sync
```

或使用快捷命令：

```bash
npm run cap:sync
```

## 第七步：打开 Android Studio

```bash
npx cap open android
```

或：

```bash
npm run cap:open:android
```

## 第八步：构建 APK

### 方法 A: 使用 Android Studio（推荐）

1. Android Studio 打开后，等待 Gradle 同步完成

2. 点击菜单：`Build` → `Build Bundle(s) / APK(s)` → `Build APK(s)`

3. 等待构建完成，成功后会弹出通知

4. 点击 `locate` 查看生成的 APK 文件

5. APK 位置：`android/app/build/outputs/apk/debug/app-debug.apk`

### 方法 B: 使用命令行

```bash
cd android
./gradlew assembleDebug
cd ..
```

生成的 APK 位于：`android/app/build/outputs/apk/debug/app-debug.apk`

### 方法 C: 构建发布版本

发布版本需要签名：

```bash
cd android
./gradlew assembleRelease
cd ..
```

## 第九步：安装到手机

### 方法 A: USB 连接

1. 启用手机的开发者选项和 USB 调试

2. 使用 USB 线连接手机到电脑

3. 在 Android Studio 中点击 `Run` (绿色播放按钮)

### 方法 B: 手动安装

1. 将 APK 文件传输到手机

2. 在手机上打开文件管理器

3. 找到 APK 文件并点击安装

4. 如果提示"未知来源"，需要在设置中允许

## 应用图标和启动画面

### 应用图标

1. 准备图标文件：
   - `android/app/src/main/res/mipmap-*` 目录
   - 需要多种尺寸：48x48, 72x72, 96x96, 144x144, 192x192

2. 使用在线工具生成：
   - https://icon.kitchen/
   - https://romannurik.github.io/AndroidAssetStudio/

3. 将生成的图标文件替换到对应目录

### 启动画面

编辑：`android/app/src/main/res/values/styles.xml`

```xml
<style name="AppTheme.NoActionBarLaunch" parent="AppTheme.NoActionBar">
    <item name="android:background">@drawable/splash</item>
</style>
```

## 签名和发布

### 生成密钥库

```bash
keytool -genkey -v -keystore my-release-key.keystore -alias my-key-alias -keyalg RSA -keysize 2048 -validity 10000
```

### 配置签名

编辑 `android/app/build.gradle`：

```gradle
android {
    ...
    signingConfigs {
        release {
            storeFile file("my-release-key.keystore")
            storePassword "your-password"
            keyAlias "my-key-alias"
            keyPassword "your-password"
        }
    }
    buildTypes {
        release {
            signingConfig signingConfigs.release
            ...
        }
    }
}
```

### 构建发布版本

```bash
cd android
./gradlew assembleRelease
```

生成的 APK：`android/app/build/outputs/apk/release/app-release.apk`

## 发布到 Google Play

1. 访问 https://play.google.com/console

2. 创建应用并填写信息

3. 上传 APK 或 AAB 文件

4. 填写应用商店信息（描述、截图等）

5. 提交审核

## 常见问题

### 1. Gradle 同步失败

**错误**: `Could not find gradle wrapper`

**解决**:
```bash
cd android
gradle wrapper
```

### 2. SDK 许可证未接受

**错误**: `You have not accepted the license agreements`

**解决**:
```bash
yes | sdkmanager --licenses
```

### 3. 应用无法联网

**错误**: `ERR_CLEARTEXT_NOT_PERMITTED`

**解决**: 在 `AndroidManifest.xml` 中添加：
```xml
<application
    android:usesCleartextTraffic="true"
    ...>
```

### 4. 构建超时

**解决**: 增加 Gradle 内存，编辑 `android/gradle.properties`：
```properties
org.gradle.jvmargs=-Xmx2048m
```

### 5. 深度链接不工作

**解决**:
- 检查 `AndroidManifest.xml` 配置
- 确认 URL scheme 为 `mkmusic`
- 重新安装应用

### 6. 打包后白屏

**解决**:
- 检查 `mobile-config.js` 中的 API 地址
- 查看 Logcat 日志排查错误
- 确认 CORS 配置正确

## 测试深度链接

在浏览器中打开测试页面 `protocol-test.html`，或使用 ADB：

```bash
adb shell am start -W -a android.intent.action.VIEW -d "mkmusic://play?id=436514312&source=netease" com.mkmusic.player
```

## 性能优化

### 1. 启用混淆

编辑 `android/app/build.gradle`：

```gradle
buildTypes {
    release {
        minifyEnabled true
        shrinkResources true
        proguardFiles getDefaultProguardFile('proguard-android-optimize.txt'), 'proguard-rules.pro'
    }
}
```

### 2. 减小 APK 大小

- 启用 APK 拆分（按架构）
- 使用 WebP 图片格式
- 删除未使用的资源

### 3. 构建 AAB（Android App Bundle）

```bash
cd android
./gradlew bundleRelease
```

AAB 文件更小，Google Play 推荐格式。

## 调试技巧

### 查看日志

```bash
adb logcat | grep Capacitor
```

### Chrome DevTools

1. 手机连接电脑（USB 调试）
2. Chrome 浏览器打开 `chrome://inspect`
3. 选择您的应用
4. 点击 `inspect`

### Android Studio Logcat

在 Android Studio 底部点击 `Logcat` 标签查看实时日志。

## 下一步

- 阅读 [Capacitor 官方文档](https://capacitorjs.com/)
- 添加更多插件（推送通知、分享等）
- 优化应用性能
- 发布到应用商店

## 有用的命令

```bash
# 同步代码到 Android
npm run cap:sync

# 打开 Android Studio
npm run cap:open:android

# 构建和打开（一键操作）
npm run build:android

# 清理构建
cd android && ./gradlew clean && cd ..

# 查看已连接设备
adb devices

# 卸载应用
adb uninstall com.mkmusic.player

# 安装 APK
adb install android/app/build/outputs/apk/debug/app-debug.apk
```

## 获取帮助

如果遇到问题：

1. 查看 [Capacitor 文档](https://capacitorjs.com/docs)
2. 搜索 Stack Overflow
3. 提交 Issue 到项目仓库

祝您构建顺利！📱🎵
