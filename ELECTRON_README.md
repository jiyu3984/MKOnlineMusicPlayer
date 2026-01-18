# MK在线音乐播放器 - Electron 桌面版

## 简介

这是一个基于 Electron 的桌面音乐播放器，支持自定义 URL 协议 (`mkmusic://`)，可以制作成 Windows、macOS 和 Linux 的安装包。

## 功能特性

- ✨ 支持网易云、QQ音乐、酷狗等多平台音乐搜索和播放
- 🎵 支持自定义 URL 协议 `mkmusic://`
- 📦 支持打包成桌面应用（Windows/macOS/Linux）
- 🔄 自动启动 PHP 内置服务器（需要系统安装 PHP）
- 💾 本地数据持久化

## 系统要求

### 开发环境
- Node.js 16.x 或更高版本
- npm 或 yarn
- PHP 5.4+ （运行时需要，用于后端 API）

### 运行时（可选）
- PHP 5.4+ - 如果系统未安装 PHP，应用将以静态模式运行，部分功能可能不可用

## 安装依赖

```bash
npm install
```

或

```bash
yarn install
```

## 开发和测试

### 启动开发模式

```bash
npm start
# 或
npm run dev  # 带开发者工具
```

### 自定义协议测试

安装后，可以通过以下 URL 格式测试协议功能：

**播放指定歌曲**
```
mkmusic://play?id=436514312&source=netease
```

**搜索歌曲**
```
mkmusic://search?keyword=周杰伦
```

**打开歌单**
```
mkmusic://playlist?id=123456&source=netease
```

在浏览器地址栏输入上述 URL，或创建一个 HTML 链接：

```html
<a href="mkmusic://play?id=436514312&source=netease">播放歌曲</a>
```

## 构建安装包

### Windows 安装包

```bash
npm run build:win
```

生成的文件：
- `dist/MK音乐播放器 Setup x.x.x.exe` - NSIS 安装程序
- `dist/MK音乐播放器 x.x.x.exe` - 便携版（免安装）

### macOS 安装包

```bash
npm run build:mac
```

生成的文件：
- `dist/MK音乐播放器-x.x.x.dmg` - DMG 镜像
- `dist/MK音乐播放器-x.x.x-mac.zip` - ZIP 压缩包

### Linux 安装包

```bash
npm run build:linux
```

生成的文件：
- `dist/MK音乐播放器-x.x.x.AppImage` - AppImage 格式
- `dist/mk-music-player_x.x.x_amd64.deb` - Debian/Ubuntu 包
- `dist/mk-music-player-x.x.x.x86_64.rpm` - RedHat/Fedora 包

### 构建所有平台

```bash
npm run build:all
```

注意：在 macOS 上可以构建所有平台，在 Windows 上只能构建 Windows 版本，在 Linux 上可以构建 Windows 和 Linux 版本。

## 项目结构

```
MKOnlineMusicPlayer/
├── electron/                   # Electron 相关文件
│   ├── main.js                # 主进程
│   ├── preload.js             # 预加载脚本
│   └── protocol-handler.js    # 协议处理器
├── build/                      # 构建资源
│   ├── icons/                 # 应用图标
│   ├── installer.nsh          # Windows NSIS 配置
│   └── entitlements.mac.plist # macOS 权限配置
├── dist/                       # 构建输出目录
├── js/                         # JavaScript 源码
├── css/                        # 样式文件
├── images/                     # 图片资源
├── plugns/                     # 插件
├── index.html                  # 主页面
├── api.php                     # 后端 API
├── package.json                # 项目配置
└── ELECTRON_README.md          # 本文档
```

## 协议 URL 格式说明

### 1. 播放歌曲

```
mkmusic://play?id={歌曲ID}&source={音乐源}
```

参数：
- `id`: 歌曲 ID（必填）
- `source`: 音乐源，可选值：`netease`（网易云）、`tencent`（QQ音乐）、`kugou`（酷狗）、`baidu`（百度音乐）（默认：`netease`）

示例：
```
mkmusic://play?id=436514312&source=netease
```

### 2. 搜索歌曲

```
mkmusic://search?keyword={关键词}
```

参数：
- `keyword`: 搜索关键词（必填）

示例：
```
mkmusic://search?keyword=%E5%91%A8%E6%9D%B0%E4%BC%A6
```
（URL 编码的"周杰伦"）

### 3. 打开歌单

```
mkmusic://playlist?id={歌单ID}&source={音乐源}
```

参数：
- `id`: 歌单 ID（必填）
- `source`: 音乐源（默认：`netease`）

示例：
```
mkmusic://playlist?id=123456&source=netease
```

## PHP 服务器说明

### 自动启动

应用启动时会自动检测系统是否安装了 PHP。如果检测到 PHP，会自动启动 PHP 内置服务器（端口：8888）。

### 手动安装 PHP

**Windows:**
1. 下载 PHP：https://windows.php.net/download/
2. 解压到 `C:\php`
3. 添加 `C:\php` 到系统环境变量 PATH

**macOS:**
```bash
brew install php
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt-get install php
```

**Linux (CentOS/RHEL):**
```bash
sudo yum install php
```

### 降级模式

如果 PHP 未安装，应用将以静态模式运行。此模式下：
- ✅ 可以正常播放本地播放列表
- ❌ 无法搜索歌曲
- ❌ 无法获取在线歌曲信息
- ❌ 无法下载歌曲

## 图标设置

### 准备图标文件

需要为不同平台准备图标：

**Windows:**
- `build/icon.ico` - 256x256 像素的 ICO 文件

**macOS:**
- `build/icon.icns` - ICNS 格式图标（包含多种尺寸）

**Linux:**
- `build/icons/` 目录下放置多种尺寸的 PNG 图标：
  - `16x16.png`
  - `32x32.png`
  - `48x48.png`
  - `64x64.png`
  - `128x128.png`
  - `256x256.png`
  - `512x512.png`
  - `1024x1024.png`

### 图标转换工具

- **PNG 转 ICO**: https://convertico.com/
- **PNG 转 ICNS**: 使用 `png2icns` 工具（macOS）或在线工具

## 常见问题

### 1. 构建失败

**问题**: `Cannot find module 'electron'`

**解决**:
```bash
npm install
```

### 2. PHP 服务器未启动

**问题**: 应用启动后提示"PHP 服务器未启动"

**解决**:
1. 检查是否安装了 PHP：`php --version`
2. 如果未安装，参考上面的 PHP 安装说明
3. 确保 PHP 在系统 PATH 中

### 3. 协议无法打开应用

**问题**: 点击 `mkmusic://` 链接没有反应

**解决**:
- **Windows**: 重新安装应用，或手动运行安装程序中的注册表脚本
- **macOS**: 首次打开应用时，系统会自动注册协议
- **Linux**: 某些桌面环境需要手动配置协议处理器

### 4. 构建 macOS 应用时提示签名失败

**问题**: Code signing failed

**解决**:
在 `package.json` 中设置：
```json
"mac": {
  "identity": null
}
```

## 开发注意事项

1. **跨平台兼容性**: 测试时注意路径分隔符差异（Windows 使用 `\`，Unix 使用 `/`）
2. **PHP 依赖**: 考虑将 PHP 后端改写为 Node.js 以避免 PHP 依赖
3. **安全性**: 自定义协议可能被恶意网站利用，建议添加确认对话框
4. **更新机制**: 可以集成 `electron-updater` 实现自动更新

## 后续优化建议

- [ ] 将 PHP 后端改写为 Node.js，消除 PHP 依赖
- [ ] 添加自动更新功能
- [ ] 添加协议调用确认对话框（安全性）
- [ ] 添加托盘图标和最小化到托盘功能
- [ ] 添加全局快捷键
- [ ] 优化首次启动体验
- [ ] 添加应用内设置页面

## 许可证

MIT

## 贡献

欢迎提交 Issue 和 Pull Request！
