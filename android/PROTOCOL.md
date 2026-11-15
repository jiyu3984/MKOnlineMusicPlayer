# 坤音乐客户端协议

界面、CSS、JavaScript、图标和默认歌单定义随 APK 打包。客户端只通过 HTTPS 请求音乐数据和媒体链接，服务端不会下发可执行界面代码。音乐平台 Cookie 仍保存在服务端。

## 音乐数据请求

地址：`POST https://yy.kunps.cn/client-api.php`。

请求头：`Content-Type: application/json; charset=utf-8`。

```json
{
  "protocol": "kunmusic/1",
  "requestId": "android-1",
  "method": "url",
  "params": { "source": "netease", "id": "287398" }
}
```

`requestId` 为 1–128 个字母、数字、句点、下划线、冒号或连字符。客户端使用它匹配请求与响应；服务端不将其视为身份凭据。请求体上限 16 KiB。

| method | params | result |
| --- | --- | --- |
| `song` | `source`, `id` | 标准歌曲元数据数组，含 `id/name/artist/album/pic_id/url_id/lyric_id/source`；`artist` 为字符串数组 |
| `url` | `source`, `id`, 可选 `url_id` | 可播放媒体链接及码率等数据 |
| `pic` | `source`, `id`（通常为 `pic_id`） | 封面链接 |
| `lyric` | `source`, `id`（通常为 `lyric_id`） | 原文及翻译歌词 |
| `search` | `source`, `name`, 可选 `count/pages` | 标准歌曲元数据数组 |
| `playlist` | `source`, `id` | 与网页 API 相同的歌单数据 |
| `userlist` | `source`, `uid` | 与网页 API 相同的用户歌单数据 |
| `comments` | `source`, `id`, 可选 `count/pages` | 评论数据 |

`source` 默认 `netease`，其余取值与原音乐 API 保持一致。每页 `count` 为 1–100，`pages` 为 1–1000。未列出的参数及方法被拒绝；客户端协议不提供缓存清除、下载到服务端、后台管理或 Cookie 操作。

成功响应：

```json
{
  "protocol": "kunmusic/1",
  "requestId": "android-1",
  "ok": true,
  "result": { "url": "https://audio.example.test/song.mp3" }
}
```

失败响应：

```json
{
  "protocol": "kunmusic/1",
  "requestId": "android-1",
  "ok": false,
  "error": { "code": "INVALID_ID", "message": "歌曲或歌单编号有误" }
}
```

HTTP 状态码包括：`400` 请求错误，`405` 方法错误，`413` 体积超限，`415` 内容类型错误，`426` 协议版本不支持，`500/502` 服务或上游暂时不可用。无有效请求编号时 `requestId` 为 `null`。错误不包含上游原始响应、内部路径或凭据。

媒体链接由原生播放器请求和播放；链接可能过期，切歌时应重新解析。HTTPS JSON 请求用于客户端与服务端交换数据，不承载音乐平台登录凭据。

## 网站唤起客户端

歌曲链接使用 `kunmusic://song?source=netease&id=287398`。链接只传音乐源和歌曲编号；客户端验证后调用 `song` 获取元数据，再调用 `url/pic/lyric`。不得将任意媒体地址、JavaScript、文件路径或服务端地址当作协议参数。

## 验证

```sh
php -l client-api.php
php tests/client-protocol.php
```

离线检查不会请求音乐上游，也不会读取生产 Cookie。
