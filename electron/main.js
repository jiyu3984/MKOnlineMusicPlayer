const { app, BrowserWindow, protocol, ipcMain, shell } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');

// 自定义协议名称
const PROTOCOL_NAME = 'mkmusic';

// 保持对窗口对象的全局引用，避免被垃圾回收
let mainWindow = null;
let phpServer = null;
let phpPort = 8888;

// 单实例锁
const gotTheLock = app.requestSingleInstanceLock();

if (!gotTheLock) {
  app.quit();
} else {
  app.on('second-instance', (event, commandLine, workingDirectory) => {
    // 当第二个实例启动时，聚焦到主窗口
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.focus();

      // 处理协议 URL
      const url = commandLine.find(arg => arg.startsWith(`${PROTOCOL_NAME}://`));
      if (url) {
        handleProtocolUrl(url);
      }
    }
  });

  // 在 app ready 之前注册协议
  if (process.defaultApp) {
    if (process.argv.length >= 2) {
      app.setAsDefaultProtocolClient(PROTOCOL_NAME, process.execPath, [path.resolve(process.argv[1])]);
    }
  } else {
    app.setAsDefaultProtocolClient(PROTOCOL_NAME);
  }
}

// 启动 PHP 内置服务器
function startPhpServer() {
  return new Promise((resolve, reject) => {
    // 检查是否安装了 PHP
    const phpCheck = spawn('php', ['--version']);

    phpCheck.on('error', (err) => {
      console.error('PHP 未安装或不在 PATH 中');
      resolve(false);
    });

    phpCheck.on('close', (code) => {
      if (code === 0) {
        console.log('检测到 PHP，启动内置服务器...');

        const appPath = app.isPackaged
          ? path.join(process.resourcesPath, 'app')
          : path.join(__dirname, '..');

        // 启动 PHP 内置服务器
        phpServer = spawn('php', [
          '-S', `127.0.0.1:${phpPort}`,
          '-t', appPath
        ], {
          cwd: appPath
        });

        phpServer.stdout.on('data', (data) => {
          console.log(`PHP Server: ${data}`);
        });

        phpServer.stderr.on('data', (data) => {
          console.error(`PHP Server Error: ${data}`);
        });

        phpServer.on('close', (code) => {
          console.log(`PHP Server 已退出，代码: ${code}`);
        });

        setTimeout(() => resolve(true), 1000);
      } else {
        resolve(false);
      }
    });
  });
}

// 创建主窗口
async function createWindow() {
  // 尝试启动 PHP 服务器
  const phpStarted = await startPhpServer();

  mainWindow = new BrowserWindow({
    width: 1200,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    icon: path.join(__dirname, '../images/icon.png'),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
      webSecurity: true,
      allowRunningInsecureContent: false
    },
    backgroundColor: '#1a1a1a',
    show: false,
    frame: true,
    titleBarStyle: 'default',
    autoHideMenuBar: true
  });

  // 加载应用
  if (phpStarted) {
    // 使用 PHP 服务器
    mainWindow.loadURL(`http://127.0.0.1:${phpPort}/index.html`);
    console.log(`加载 URL: http://127.0.0.1:${phpPort}/index.html`);
  } else {
    // 降级到静态文件（需要处理 PHP API）
    const indexPath = app.isPackaged
      ? path.join(process.resourcesPath, 'app', 'index.html')
      : path.join(__dirname, '../index.html');

    mainWindow.loadFile(indexPath);
    console.log('加载静态文件:', indexPath);

    // 提示用户需要安装 PHP
    mainWindow.webContents.on('did-finish-load', () => {
      mainWindow.webContents.executeJavaScript(`
        console.warn('PHP 服务器未启动，部分功能可能不可用。请安装 PHP 以获得完整功能。');
      `);
    });
  }

  // 窗口准备好后显示
  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
  });

  // 窗口关闭时的处理
  mainWindow.on('closed', () => {
    mainWindow = null;
  });

  // 打开外部链接
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  // 开发工具（开发模式）
  if (process.argv.includes('--debug')) {
    mainWindow.webContents.openDevTools();
  }
}

// 处理协议 URL
function handleProtocolUrl(url) {
  console.log('接收到协议 URL:', url);

  if (mainWindow && mainWindow.webContents) {
    // 解析协议 URL
    // 格式: mkmusic://play?id=123456&source=netease
    // 或: mkmusic://search?keyword=周杰伦
    mainWindow.webContents.send('protocol-url', url);
  }
}

// 当 Electron 完成初始化时
app.whenReady().then(() => {
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

// 当所有窗口关闭时
app.on('window-all-closed', () => {
  // 停止 PHP 服务器
  if (phpServer) {
    phpServer.kill();
  }

  if (process.platform !== 'darwin') {
    app.quit();
  }
});

// macOS 处理协议 URL
app.on('open-url', (event, url) => {
  event.preventDefault();
  handleProtocolUrl(url);
});

// Windows/Linux 处理协议 URL
if (process.platform === 'win32' || process.platform === 'linux') {
  const url = process.argv.find(arg => arg.startsWith(`${PROTOCOL_NAME}://`));
  if (url) {
    app.whenReady().then(() => {
      setTimeout(() => handleProtocolUrl(url), 1000);
    });
  }
}

// 应用退出前清理
app.on('before-quit', () => {
  if (phpServer) {
    phpServer.kill();
  }
});

// IPC 通信处理
ipcMain.on('get-app-path', (event) => {
  const appPath = app.isPackaged
    ? path.join(process.resourcesPath, 'app')
    : path.join(__dirname, '..');
  event.reply('app-path', appPath);
});

ipcMain.on('get-php-port', (event) => {
  event.reply('php-port', phpPort);
});

// 全局错误处理
process.on('uncaughtException', (error) => {
  console.error('未捕获的异常:', error);
});

process.on('unhandledRejection', (error) => {
  console.error('未处理的 Promise 拒绝:', error);
});
