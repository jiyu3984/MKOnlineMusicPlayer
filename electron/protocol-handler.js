/**
 * 协议 URL 处理器
 * 处理 mkmusic:// 协议的 URL
 *
 * 支持的 URL 格式：
 * - mkmusic://play?id=123456&source=netease - 播放指定歌曲
 * - mkmusic://search?keyword=周杰伦 - 搜索歌曲
 * - mkmusic://playlist?id=123456&source=netease - 打开歌单
 */

(function() {
  'use strict';

  // 检查是否在 Electron 环境中
  if (typeof window.electronAPI === 'undefined') {
    console.log('不在 Electron 环境中，协议处理器未启用');
    return;
  }

  console.log('协议处理器已加载');

  // 解析协议 URL
  function parseProtocolUrl(url) {
    try {
      // mkmusic://action?param1=value1&param2=value2
      const match = url.match(/^mkmusic:\/\/([^?]+)(\?(.+))?$/);
      if (!match) {
        console.error('无效的协议 URL:', url);
        return null;
      }

      const action = match[1];
      const paramsStr = match[3] || '';
      const params = {};

      // 解析参数
      if (paramsStr) {
        paramsStr.split('&').forEach(pair => {
          const [key, value] = pair.split('=');
          params[decodeURIComponent(key)] = decodeURIComponent(value);
        });
      }

      return { action, params };
    } catch (error) {
      console.error('解析协议 URL 失败:', error);
      return null;
    }
  }

  // 处理播放动作
  function handlePlay(params) {
    const { id, source = 'netease' } = params;

    if (!id) {
      console.error('播放动作缺少歌曲 ID');
      return;
    }

    console.log(`播放歌曲: ID=${id}, 来源=${source}`);

    // 等待播放器加载
    const checkPlayer = setInterval(() => {
      if (typeof rem !== 'undefined' && typeof playList === 'function') {
        clearInterval(checkPlayer);

        // 创建歌曲对象
        const music = {
          id: id,
          name: '正在加载...',
          artist: '',
          album: '',
          source: source,
          url_id: id,
          pic_id: id,
          lyric_id: id
        };

        // 添加到播放列表并播放
        if (typeof rem.paused !== 'undefined') {
          rem.paused.push(music);
          const index = rem.paused.length - 1;
          playList(index);

          // 显示提示
          if (typeof layer !== 'undefined') {
            layer.msg('正在播放来自协议的歌曲');
          }
        }
      }
    }, 100);

    // 超时处理
    setTimeout(() => {
      clearInterval(checkPlayer);
    }, 5000);
  }

  // 处理搜索动作
  function handleSearch(params) {
    const { keyword } = params;

    if (!keyword) {
      console.error('搜索动作缺少关键词');
      return;
    }

    console.log(`搜索: ${keyword}`);

    // 等待页面加载
    const checkSearch = setInterval(() => {
      const searchInput = document.querySelector('.flex input[name="search"]');
      if (searchInput && typeof ajaxSearch === 'function') {
        clearInterval(checkSearch);

        // 设置搜索关键词
        searchInput.value = keyword;

        // 触发搜索
        ajaxSearch(keyword, mkPlayer.method);

        // 显示提示
        if (typeof layer !== 'undefined') {
          layer.msg(`搜索: ${keyword}`);
        }
      }
    }, 100);

    // 超时处理
    setTimeout(() => {
      clearInterval(checkSearch);
    }, 5000);
  }

  // 处理歌单动作
  function handlePlaylist(params) {
    const { id, source = 'netease' } = params;

    if (!id) {
      console.error('歌单动作缺少歌单 ID');
      return;
    }

    console.log(`打开歌单: ID=${id}, 来源=${source}`);

    // 等待页面加载
    const checkPlaylist = setInterval(() => {
      if (typeof ajaxPlayList === 'function') {
        clearInterval(checkPlaylist);

        // 加载歌单
        ajaxPlayList(id, source);

        // 显示提示
        if (typeof layer !== 'undefined') {
          layer.msg(`正在加载歌单: ${id}`);
        }
      }
    }, 100);

    // 超时处理
    setTimeout(() => {
      clearInterval(checkPlaylist);
    }, 5000);
  }

  // 处理协议 URL
  function handleProtocolUrl(url) {
    console.log('处理协议 URL:', url);

    const parsed = parseProtocolUrl(url);
    if (!parsed) {
      return;
    }

    const { action, params } = parsed;

    switch (action) {
      case 'play':
        handlePlay(params);
        break;
      case 'search':
        handleSearch(params);
        break;
      case 'playlist':
        handlePlaylist(params);
        break;
      default:
        console.error('未知的协议动作:', action);
    }
  }

  // 监听协议 URL
  window.electronAPI.onProtocolUrl((url) => {
    handleProtocolUrl(url);
  });

  // 导出到全局
  window.handleProtocolUrl = handleProtocolUrl;

  console.log('协议处理器已就绪');
})();
