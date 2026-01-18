/**
 * Capacitor 移动端适配脚本
 * 处理深度链接、存储、网络等移动端特定功能
 */

(function() {
  'use strict';

  // 检查是否在 Capacitor 环境中
  if (typeof window.Capacitor === 'undefined') {
    console.log('不在 Capacitor 环境中');
    return;
  }

  console.log('Capacitor 环境已检测到');
  console.log('平台:', window.Capacitor.getPlatform());

  const { App, Browser, Network } = window.Capacitor.Plugins;

  // 处理深度链接（App Links）
  if (App) {
    App.addListener('appUrlOpen', (event) => {
      console.log('接收到 App URL:', event.url);
      handleDeepLink(event.url);
    });

    // 监听应用状态变化
    App.addListener('appStateChange', (state) => {
      console.log('应用状态变化:', state.isActive ? '前台' : '后台');
    });

    // 处理返回按钮
    App.addListener('backButton', (event) => {
      console.log('返回按钮被按下');
      // 可以在这里添加自定义返回逻辑
    });
  }

  // 监听网络状态
  if (Network) {
    Network.addListener('networkStatusChange', (status) => {
      console.log('网络状态变化:', status.connected ? '已连接' : '已断开');
      if (!status.connected) {
        if (typeof layer !== 'undefined') {
          layer.msg('网络连接已断开');
        }
      }
    });
  }

  // 处理深度链接
  function handleDeepLink(url) {
    try {
      // 支持多种 URL 格式
      // mkmusic://play?id=123&source=netease
      // https://music.example.com/play?id=123

      let finalUrl = url;

      // 如果是 https 链接，转换为 mkmusic 协议
      if (url.startsWith('https://') || url.startsWith('http://')) {
        // 提取路径和参数
        const urlObj = new URL(url);
        const path = urlObj.pathname.replace(/^\//, '');
        const params = urlObj.search;
        finalUrl = `mkmusic://${path}${params}`;
      }

      // 使用协议处理器
      if (typeof window.handleProtocolUrl === 'function') {
        window.handleProtocolUrl(finalUrl);
      } else {
        console.error('协议处理器未加载');
        // 延迟重试
        setTimeout(() => {
          if (typeof window.handleProtocolUrl === 'function') {
            window.handleProtocolUrl(finalUrl);
          }
        }, 1000);
      }
    } catch (error) {
      console.error('处理深度链接失败:', error);
    }
  }

  // 优化移动端体验
  function optimizeMobileExperience() {
    // 禁用长按选择
    document.addEventListener('contextmenu', (e) => {
      e.preventDefault();
    });

    // 防止双击缩放
    let lastTouchEnd = 0;
    document.addEventListener('touchend', (e) => {
      const now = Date.now();
      if (now - lastTouchEnd <= 300) {
        e.preventDefault();
      }
      lastTouchEnd = now;
    }, { passive: false });

    // 优化滚动
    document.addEventListener('touchmove', (e) => {
      // 允许滚动
    }, { passive: true });

    console.log('移动端优化已应用');
  }

  // 初始化
  function init() {
    console.log('Capacitor 应用初始化');

    // 检查网络状态
    if (Network) {
      Network.getStatus().then(status => {
        console.log('当前网络状态:', status);
      });
    }

    // 应用移动端优化
    optimizeMobileExperience();

    // 隐藏启动屏幕
    if (window.Capacitor.Plugins.SplashScreen) {
      setTimeout(() => {
        window.Capacitor.Plugins.SplashScreen.hide();
      }, 1000);
    }
  }

  // 页面加载完成后初始化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // 导出到全局
  window.CapacitorApp = {
    handleDeepLink: handleDeepLink
  };

  console.log('Capacitor 应用脚本已加载');
})();
