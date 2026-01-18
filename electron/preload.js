const { contextBridge, ipcRenderer } = require('electron');

// 向渲染进程暴露安全的 API
contextBridge.exposeInMainWorld('electronAPI', {
  // 监听协议 URL
  onProtocolUrl: (callback) => {
    ipcRenderer.on('protocol-url', (event, url) => {
      callback(url);
    });
  },

  // 获取应用路径
  getAppPath: () => {
    return new Promise((resolve) => {
      ipcRenderer.send('get-app-path');
      ipcRenderer.once('app-path', (event, path) => {
        resolve(path);
      });
    });
  },

  // 获取 PHP 端口
  getPhpPort: () => {
    return new Promise((resolve) => {
      ipcRenderer.send('get-php-port');
      ipcRenderer.once('php-port', (event, port) => {
        resolve(port);
      });
    });
  },

  // 平台信息
  platform: process.platform,

  // 版本信息
  versions: {
    node: process.versions.node,
    chrome: process.versions.chrome,
    electron: process.versions.electron
  }
});

// 在页面加载完成后注入协议处理脚本
window.addEventListener('DOMContentLoaded', () => {
  console.log('Electron 预加载脚本已加载');
  console.log('平台:', process.platform);
  console.log('Electron 版本:', process.versions.electron);
});
