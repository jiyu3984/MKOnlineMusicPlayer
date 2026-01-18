# 快速开始指南

## 第一步：安装 Node.js

如果还没有安装 Node.js，请先安装：

**下载地址**: https://nodejs.org/

推荐安装 LTS 版本（长期支持版）。

安装完成后，打开终端/命令行，验证安装：

```bash
node --version
npm --version
```

## 第二步：安装项目依赖

在项目目录下运行：

```bash
npm install
```

这将安装 Electron 和其他必要的依赖包。

## 第三步：测试应用

启动开发模式：

```bash
npm start
```

或者带开发者工具启动：

```bash
npm run dev
```

应用窗口应该会自动打开。

## 第四步：测试自定义协议

1. 在浏览器中打开项目目录下的 `protocol-test.html` 文件
2. 点击页面上的任意测试链接
3. 系统应该会询问是否打开 MK音乐播放器
4. 确认后，应用会根据链接内容执行相应操作

## 第五步：构建安装包

### Windows 用户

```bash
npm run build:win
```

生成的安装包位于 `dist/` 目录：
- `MK音乐播放器 Setup x.x.x.exe` - 安装程序
- `MK音乐播放器 x.x.x.exe` - 便携版

### macOS 用户

```bash
npm run build:mac
```

生成的文件位于 `dist/` 目录：
- `MK音乐播放器-x.x.x.dmg` - DMG 镜像

### Linux 用户

```bash
npm run build:linux
```

生成的文件位于 `dist/` 目录：
- `MK音乐播放器-x.x.x.AppImage` - AppImage 格式
- `mk-music-player_x.x.x_amd64.deb` - Debian/Ubuntu 包
- `mk-music-player-x.x.x.x86_64.rpm` - RedHat/Fedora 包

## 可选：安装 PHP

为了获得完整功能，建议安装 PHP：

**Windows**:
- 下载 https://windows.php.net/download/
- 解压并添加到系统 PATH

**macOS**:
```bash
brew install php
```

**Linux (Ubuntu/Debian)**:
```bash
sudo apt-get install php
```

**Linux (CentOS/RHEL)**:
```bash
sudo yum install php
```

验证安装：
```bash
php --version
```

## 常见问题

### 1. npm install 失败

**问题**: 网络超时或下载失败

**解决**: 使用国内镜像源

```bash
npm config set registry https://registry.npmmirror.com
npm install
```

### 2. 构建失败

**问题**: electron-builder 下载失败

**解决**: 设置镜像

```bash
# Windows PowerShell
$env:ELECTRON_MIRROR="https://npmmirror.com/mirrors/electron/"
npm run build:win

# macOS/Linux
export ELECTRON_MIRROR=https://npmmirror.com/mirrors/electron/
npm run build:mac
```

### 3. 应用无法启动

**问题**: 点击启动没有反应

**解决**:
1. 检查控制台错误信息
2. 确保已正确安装依赖：`npm install`
3. 删除 `node_modules` 文件夹后重新安装

### 4. PHP 功能不可用

**问题**: 搜索等功能无法使用

**解决**:
1. 确保系统已安装 PHP
2. 运行 `php --version` 验证
3. 确保 PHP 在系统 PATH 中

## 下一步

- 阅读 `ELECTRON_README.md` 了解详细功能
- 查看 `protocol-test.html` 测试所有协议功能
- 根据需要修改配置和样式

## 获取帮助

如果遇到问题：

1. 查看 `ELECTRON_README.md` 的常见问题部分
2. 检查控制台错误信息
3. 提交 Issue 到项目仓库

祝使用愉快！🎵
