# 移动端 API 服务器配置指南

由于 Android/iOS 应用无法直接运行 PHP 代码，您需要配置一个 API 服务器来提供后端服务。

## 方案一：使用现有的在线服务器（推荐）

如果您已经有一个运行中的 MKOnlineMusicPlayer 网站，可以直接使用它作为 API 服务器。

### 步骤

1. 打开 `mobile-config.js` 文件

2. 修改 `apiBaseUrl` 为您的服务器地址：

```javascript
window.mobileConfig = {
  // 使用您的在线服务器
  apiBaseUrl: 'https://your-domain.com',
  // 或者使用演示服务器
  // apiBaseUrl: 'https://music.itmxue.cn',
  // ...
};
```

3. 确保您的服务器已配置 CORS（跨域资源共享）

在 Apache 配置中添加：
```apache
Header set Access-Control-Allow-Origin "*"
Header set Access-Control-Allow-Headers "X-Requested-With"
Header set Access-Control-Allow-Methods "GET,POST,OPTIONS"
```

或在 Nginx 配置中添加：
```nginx
add_header Access-Control-Allow-Origin *;
add_header Access-Control-Allow-Headers X-Requested-With;
add_header Access-Control-Allow-Methods GET,POST,OPTIONS;
```

## 方案二：本地测试服务器

在开发和测试阶段，可以使用本地服务器。

### 步骤

1. 确保您的电脑和手机在同一个 WiFi 网络中

2. 启动 PHP 内置服务器：

```bash
cd /path/to/MKOnlineMusicPlayer
php -S 0.0.0.0:8080
```

3. 获取您的电脑 IP 地址：

**Windows**:
```cmd
ipconfig
```
查找 "IPv4 地址"

**macOS/Linux**:
```bash
ifconfig
# 或
ip addr
```
查找类似 `192.168.x.x` 的地址

4. 修改 `mobile-config.js`：

```javascript
window.mobileConfig = {
  // 使用您的本地 IP
  apiBaseUrl: 'http://192.168.1.100:8080',  // 替换为您的 IP
  // ...
};
```

5. 构建并安装应用到手机测试

## 方案三：部署到云服务器

将项目部署到云服务器（如阿里云、腾讯云等）。

### 推荐服务

- **Vercel**: 免费，支持 PHP（通过 Serverless）
- **Heroku**: 免费套餐，支持 PHP
- **Digital Ocean**: 按需付费
- **阿里云/腾讯云**: 国内服务器

### 部署步骤（以 Vercel 为例）

1. 安装 Vercel CLI：
```bash
npm install -g vercel
```

2. 登录 Vercel：
```bash
vercel login
```

3. 部署项目：
```bash
cd /path/to/MKOnlineMusicPlayer
vercel
```

4. 获取部署后的 URL，更新 `mobile-config.js`

## 方案四：使用 Docker 部署

使用 Docker Compose 快速部署服务器。

### 步骤

1. 确保安装了 Docker 和 Docker Compose

2. 在项目目录运行：
```bash
docker-compose up -d
```

3. 获取服务器 IP 地址，更新 `mobile-config.js`

## 配置文件说明

### mobile-config.js

```javascript
window.mobileConfig = {
  // API 服务器地址（必填）
  apiBaseUrl: 'https://your-api-server.com',

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
```

## 测试 API 连接

在移动应用中测试 API 是否正常工作：

1. 打开应用
2. 打开开发者工具（如果启用了调试模式）
3. 尝试搜索歌曲
4. 检查控制台是否有错误信息

## 常见问题

### 1. CORS 错误

**错误**: `Access to XMLHttpRequest at 'http://...' from origin 'capacitor://localhost' has been blocked by CORS policy`

**解决**: 在服务器配置中添加 CORS 头（见方案一）

### 2. 连接超时

**错误**: `Failed to fetch` 或 `Network Error`

**解决**:
- 检查手机和服务器是否在同一网络
- 检查防火墙设置
- 确认服务器正在运行

### 3. 404 错误

**错误**: `404 Not Found`

**解决**:
- 检查 `apiBaseUrl` 是否正确
- 确认 API 路径是否正确（应该是 `/api.php`）

### 4. 证书错误（HTTPS）

**错误**: `SSL certificate problem`

**解决**:
- 使用有效的 SSL 证书
- 在开发环境可以临时使用 HTTP

## 安全建议

1. **生产环境使用 HTTPS**
   - 保护用户数据安全
   - 防止中间人攻击

2. **限制 CORS**
   - 不要使用 `*`，而是指定具体的域名
   ```apache
   Header set Access-Control-Allow-Origin "https://your-app-domain.com"
   ```

3. **API 限流**
   - 防止滥用
   - 可以使用 Nginx 或 API 网关实现

4. **缓存优化**
   - 启用服务器端缓存
   - 减少 API 调用次数

## 下一步

配置好 API 服务器后：

1. 阅读 [Android 构建指南](ANDROID_BUILD.md)
2. 构建并测试应用
3. 发布到 Google Play 或其他应用商店
