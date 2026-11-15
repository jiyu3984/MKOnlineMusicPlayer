# 坤音乐 Android

原生 Media3 播放服务配合 APK 内置界面，支持 Android 8.0 及以上。通知栏、锁屏和耳机控制通过系统 MediaSession 提供；播放队列由原生服务管理，后台切歌不依赖 WebView 定时器。

## 界面和数据

`bundlePlayerAssets` 将仓库中的 HTML、CSS、JavaScript、图标及 layui 复制到 APK assets。WebViewAssetLoader 拦截 `https://yy.kunps.cn/__app__/` 并返回本地文件；启动不会从网站下载界面。界面更新需要发布新 APK。

只有音乐数据、封面和音频使用网络。数据请求遵循 [PROTOCOL.md](PROTOCOL.md)，服务地址固定为 `https://yy.kunps.cn/client-api.php`。网站歌曲详情提供 `kunmusic://song?source=netease&id=287398` 链接，客户端验证歌曲标识后自行请求数据。平台登录 Cookie 保留在服务端。

首次播放会申请 Android 13 及以上的通知权限。返回键将界面移至后台；暂停后划掉应用会结束空闲服务。系统省电策略可能限制后台运行，可在系统设置中允许坤音乐后台播放。

## 构建

安装 JDK 21、Android SDK 36 和 Build Tools，配置 `ANDROID_HOME` 或本地 `local.properties` 的 `sdk.dir`，然后在本目录执行：

```sh
./gradlew :app:assembleDebug :app:testDebugUnitTest :app:lintDebug
```

正式版使用环境变量 `KUNMUSIC_KEYSTORE`、`KUNMUSIC_STORE_PASSWORD`、`KUNMUSIC_KEY_PASSWORD`，证书 alias 为 `kunmusic`：

```sh
./gradlew :app:assembleRelease :app:lintRelease
```

签名文件必须保存在仓库外并备份；后续更新必须继续使用同一证书。构建产物、签名文件及本地 SDK 路径不提交。

## 设备冒烟测试

项目包含无额外测试依赖的 `AppSmokeInstrumentation`。它验证 APK 本地资源、WebView 原生桥，以及真实 HTTPS 音乐数据驱动的播放、暂停、进度跳转、后台切歌和控制器重连；测试使用零音量，结束时停止播放。

```sh
./gradlew :app:assembleDebug :app:assembleDebugAndroidTest
adb install -r app/build/outputs/apk/debug/app-debug.apk
adb install -r app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk
adb shell am instrument -w cn.kunps.music.test/cn.kunps.music.AppSmokeInstrumentation
```

此测试需要联网并连接已启动的 Android 设备。当前已完成测试 APK 编译；本机无实机，无加速模拟器未成功启动，因此尚未运行设备冒烟测试，也未进行各厂商灵动岛实机验证。

## 服务部署

部署根目录的 `client-api.php`，将它与 `api.php` 一样交给 PHP-FPM，并沿用音乐接口的限流策略。若 Nginx 使用 PHP 白名单，需要新增精确路径规则；参见 [server-nginx.conf.example](server-nginx.conf.example)。Android 源码目录无需对外提供。保留现有服务端配置及平台 Cookie。

## 灵动岛支持范围

| 系统 | 官方接入方式 |
| --- | --- |
| 小米 HyperOS | [超级岛指南](https://dev.mi.com/xiaomihyperos/documentation/detail?pId=2131)第六节说明标准媒体通知可自动上岛；本应用使用该媒体路径。 |
| 荣耀 | [媒体胶囊指南](https://developer.honor.com/cn/doc/guides/101771)提供 MediaSession + MediaStyle 自动显示方案；本应用使用该媒体路径。 |
| OPPO／一加 | [流体云授权](https://open.oppomobile.com/documentation/page/info?id=12716)需要企业认证、合作白名单及签名绑定，尚未获得相关准入。 |
| vivo | [原子岛接入](https://dev.vivo.com.cn/documentCenter/doc/894)需要商店上架、场景准入和联调，尚未获得相关准入。 |
| 其他 Android 设备 | 提供系统标准媒体通知和锁屏控制；具体胶囊显示由系统版本决定。HarmonyOS NEXT 需要独立 HAP 应用。 |

目前不能保证所有厂商及机型的灵动岛效果；需要对应系统和实机验证。不会伪造厂商授权参数或把音乐伪装成导航通知。
