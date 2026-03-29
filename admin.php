<?php
/**
 * 管理员后台 - 酷狗扫码登录
 * 扫码后自动保存Cookie，api.php自动读取
 */

// ============ 管理员密码（请修改） ============
$admin_password = 'admin123';
// =============================================

session_start();

// 登录验证
if (isset($_POST['password'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin_logged'] = true;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// API 请求处理
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['admin_logged']) || !$_SESSION['admin_logged']) {
        die(json_encode(array('error' => '未登录')));
    }

    $action = $_GET['action'];

    // 获取酷狗登录二维码
    if ($action === 'qrcode') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login-user.kugou.com/v1/autologin/qrcode/get?appid=1014&clientver=11440&plat=4&_=' . time());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer: https://www.kugou.com/',
        ));
        $result = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        die($result);
    }

    // 检查扫码状态
    if ($action === 'check') {
        $qrcode = isset($_GET['qrcode']) ? $_GET['qrcode'] : '';
        if (!$qrcode) die(json_encode(array('error' => '缺少qrcode参数')));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login-user.kugou.com/v1/autologin/qrcode/state?appid=1014&qrcode=' . urlencode($qrcode) . '&_=' . time());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer: https://www.kugou.com/',
        ));
        $response = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($ch);

        $data = json_decode($body, true);

        // 登录成功，从响应中提取 token 信息并保存
        if (isset($data['data']['status']) && $data['data']['status'] == 2) {
            // 从响应头提取 Set-Cookie
            preg_match_all('/Set-Cookie:\s*([^\r\n]+)/i', $header, $cookie_matches);
            $cookies = array();
            foreach ($cookie_matches[1] as $cookie_line) {
                $parts = explode(';', $cookie_line);
                $kv = trim($parts[0]);
                if ($kv && strpos($kv, '=') !== false) {
                    $cookies[] = $kv;
                }
            }

            // 从响应体提取 token 和 userid
            $token = isset($data['data']['token']) ? $data['data']['token'] : '';
            $userid = isset($data['data']['userid']) ? $data['data']['userid'] : '';

            // 构建完整 Cookie
            $cookie_parts = $cookies;
            if ($token) $cookie_parts[] = 't=' . $token;
            if ($userid) $cookie_parts[] = 'KugooID=' . $userid;

            // 读取现有 Cookie 补充 mid 和 dfid（如果响应中没有）
            $full_cookie = implode('; ', $cookie_parts);

            // 如果缺少 kg_mid，生成一个
            if (strpos($full_cookie, 'kg_mid') === false && strpos($full_cookie, 'mid=') === false) {
                $mid = md5(uniqid(mt_rand(), true));
                $full_cookie .= '; kg_mid=' . $mid . '; mid=' . $mid;
            }

            // 保存 Cookie
            if ($token) {
                file_put_contents('config/kugou_cookie.txt', $full_cookie);
                $data['cookie_saved'] = true;
                $data['cookie_preview'] = substr($full_cookie, 0, 100) . '...';
            }
        }

        die(json_encode($data));
    }

    // 查看当前 Cookie 状态
    if ($action === 'status') {
        $cookie_file = 'config/kugou_cookie.txt';
        $result = array('has_cookie' => false);
        if (file_exists($cookie_file)) {
            $cookie = file_get_contents($cookie_file);
            $result['has_cookie'] = !empty(trim($cookie));
            $result['update_time'] = date('Y-m-d H:i:s', filemtime($cookie_file));
            // 解析显示关键信息
            preg_match('/KugooID=(\d+)/', $cookie, $m);
            $result['userid'] = isset($m[1]) ? $m[1] : '未知';
            preg_match('/t=([a-f0-9]+)/', $cookie, $m);
            $result['token_preview'] = isset($m[1]) ? substr($m[1], 0, 16) . '...' : '无';
        }
        die(json_encode($result));
    }

    // 清除 Cookie
    if ($action === 'clear') {
        @unlink('config/kugou_cookie.txt');
        die(json_encode(array('success' => true)));
    }

    die(json_encode(array('error' => '未知操作')));
}

$is_logged = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 酷狗登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #1a1a2e; color: #eee; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: #16213e; border-radius: 16px; padding: 40px; max-width: 420px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        h2 { text-align: center; margin-bottom: 24px; color: #e94560; }
        .form-group { margin-bottom: 16px; }
        input[type="password"] { width: 100%; padding: 12px 16px; border: 1px solid #333; border-radius: 8px; background: #0f3460; color: #fff; font-size: 16px; outline: none; }
        input[type="password"]:focus { border-color: #e94560; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; transition: all 0.3s; }
        .btn-primary { background: #e94560; color: #fff; }
        .btn-primary:hover { background: #c73e54; }
        .btn-danger { background: #666; color: #fff; margin-top: 12px; }
        .btn-danger:hover { background: #888; }
        .qr-area { text-align: center; padding: 20px 0; }
        .qr-area img { max-width: 200px; border-radius: 8px; background: #fff; padding: 8px; }
        .status { text-align: center; padding: 12px; border-radius: 8px; margin: 16px 0; font-size: 14px; }
        .status-ok { background: #1b4332; color: #95d5b2; }
        .status-warn { background: #6b3a00; color: #ffc857; }
        .status-info { background: #0f3460; color: #a8dadc; }
        .status-err { background: #641220; color: #f4a261; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #333; font-size: 14px; }
        .info-label { color: #888; }
        .logout { text-align: center; margin-top: 16px; }
        .logout a { color: #888; font-size: 13px; text-decoration: none; }
        .logout a:hover { color: #e94560; }
        #refreshBtn { display: none; }
    </style>
</head>
<body>
<div class="container">
    <?php if (!$is_logged): ?>
        <h2>管理员登录</h2>
        <form method="POST">
            <div class="form-group">
                <input type="password" name="password" placeholder="请输入管理员密码" autofocus>
            </div>
            <button type="submit" class="btn btn-primary">登 录</button>
        </form>
    <?php else: ?>
        <h2>酷狗扫码登录</h2>

        <div id="cookieStatus"></div>

        <div id="qrArea" class="qr-area" style="display:none;">
            <p style="margin-bottom:12px; color:#aaa;">用酷狗APP扫码登录</p>
            <img id="qrImg" src="" alt="二维码">
            <div id="scanStatus" class="status status-info" style="margin-top:12px;">等待扫码...</div>
        </div>

        <button id="getQrBtn" class="btn btn-primary" onclick="getQRCode()">获取登录二维码</button>
        <button id="refreshBtn" class="btn btn-primary" onclick="getQRCode()">刷新二维码</button>
        <button class="btn btn-danger" onclick="clearCookie()">清除Cookie</button>

        <div class="logout"><a href="?logout=1">退出管理</a></div>

        <script>
        var pollTimer = null;
        var currentQrcode = '';

        // 检查 Cookie 状态
        function checkStatus() {
            fetch('admin.php?action=status').then(r => r.json()).then(data => {
                var el = document.getElementById('cookieStatus');
                if (data.has_cookie) {
                    el.innerHTML = '<div class="status status-ok">Cookie 有效</div>' +
                        '<div class="info-row"><span class="info-label">用户ID</span><span>' + data.userid + '</span></div>' +
                        '<div class="info-row"><span class="info-label">Token</span><span>' + data.token_preview + '</span></div>' +
                        '<div class="info-row"><span class="info-label">更新时间</span><span>' + data.update_time + '</span></div>';
                } else {
                    el.innerHTML = '<div class="status status-warn">暂无Cookie，请扫码登录</div>';
                }
            });
        }

        // 获取二维码
        function getQRCode() {
            if (pollTimer) clearInterval(pollTimer);
            document.getElementById('qrArea').style.display = 'block';
            document.getElementById('getQrBtn').style.display = 'none';
            document.getElementById('refreshBtn').style.display = 'block';
            document.getElementById('scanStatus').className = 'status status-info';
            document.getElementById('scanStatus').textContent = '正在获取二维码...';

            fetch('admin.php?action=qrcode').then(r => r.json()).then(data => {
                if (data.data && data.data.qrcode) {
                    currentQrcode = data.data.qrcode;
                    // 生成二维码图片 URL
                    var qrUrl = data.data.url || ('https://login-user.kugou.com/v1/autologin/qrcode/qr?qrcode=' + currentQrcode + '&appid=1014');
                    document.getElementById('qrImg').src = qrUrl;
                    document.getElementById('scanStatus').textContent = '请用酷狗APP扫描二维码';
                    // 开始轮询
                    pollTimer = setInterval(checkScan, 2000);
                } else {
                    document.getElementById('scanStatus').className = 'status status-err';
                    document.getElementById('scanStatus').textContent = '获取二维码失败：' + JSON.stringify(data);
                }
            }).catch(e => {
                document.getElementById('scanStatus').className = 'status status-err';
                document.getElementById('scanStatus').textContent = '请求失败：' + e.message;
            });
        }

        // 检查扫码状态
        function checkScan() {
            if (!currentQrcode) return;
            fetch('admin.php?action=check&qrcode=' + encodeURIComponent(currentQrcode)).then(r => r.json()).then(data => {
                var statusEl = document.getElementById('scanStatus');
                var state = data.data ? data.data.status : -1;

                if (state === 0) {
                    statusEl.className = 'status status-info';
                    statusEl.textContent = '等待扫码...';
                } else if (state === 1) {
                    statusEl.className = 'status status-info';
                    statusEl.textContent = '已扫码，请在手机上确认登录';
                } else if (state === 2) {
                    statusEl.className = 'status status-ok';
                    statusEl.textContent = 'Cookie 已保存！';
                    clearInterval(pollTimer);
                    checkStatus();
                } else if (state === 3 || state === -1) {
                    statusEl.className = 'status status-warn';
                    statusEl.textContent = '二维码已过期，请刷新';
                    clearInterval(pollTimer);
                }
            });
        }

        // 清除 Cookie
        function clearCookie() {
            if (!confirm('确定清除Cookie？')) return;
            fetch('admin.php?action=clear').then(r => r.json()).then(() => {
                checkStatus();
            });
        }

        checkStatus();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
