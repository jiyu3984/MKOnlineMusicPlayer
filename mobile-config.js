/**
 * 移动端配置文件
 * 配置 API 地址等移动端特定设置
 */

window.mobileConfig = {
  // API 服务器地址
  // 选项1: 使用公共 API（推荐用于测试）
  // apiBaseUrl: 'https://your-api-server.com',

  // 选项2: 使用本地服务器（需要在同一网络）
  // apiBaseUrl: 'http://192.168.1.100:8080',

  // 选项3: 使用在线部署的版本
  // apiBaseUrl: 'https://music.itmxue.cn',

  // 默认使用相对路径（适用于打包后的应用）
  apiBaseUrl: '',

  // 是否启用调试模式
  debug: true,

  // 缓存设置
  cache: {
    enabled: true,
    maxAge: 30 * 60 * 1000 // 30 分钟
  },

  // 播放器设置
  player: {
    autoplay: false,
    volume: 0.75,
    defaultSource: 'netease'
  }
};

// 检测环境并应用配置
(function() {
  const isMobileApp = window.location.protocol === 'capacitor:' ||
                      window.location.protocol === 'ionic:' ||
                      window.location.protocol === 'file:';

  if (isMobileApp) {
    console.log('移动应用环境检测到');
    console.log('配置:', window.mobileConfig);

    // 如果需要使用远程 API，可以在这里修改 mkPlayer.api
    if (window.mobileConfig.apiBaseUrl) {
      // 等待 mkPlayer 加载
      const checkMkPlayer = setInterval(() => {
        if (typeof mkPlayer !== 'undefined') {
          clearInterval(checkMkPlayer);
          mkPlayer.api = window.mobileConfig.apiBaseUrl + '/api.php';
          console.log('API 地址已设置为:', mkPlayer.api);
        }
      }, 100);

      setTimeout(() => clearInterval(checkMkPlayer), 5000);
    }
  }
})();
