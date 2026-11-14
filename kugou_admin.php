<?php
/** 管理员后台：密码散列只读取服务器本地配置，不向浏览器返回登录凭据。 */
$config_path = getenv('MUSIC_CONFIG_FILE') ?: __DIR__ . '/config.local.php';
$admin_config = is_file($config_path) ? require $config_path : array();
$admin_hash = is_array($admin_config) && isset($admin_config['admin_password_hash'])
    && is_string($admin_config['admin_password_hash']) ? $admin_config['admin_password_hash'] : '';
$admin_configured = $admin_hash !== '' && password_get_info($admin_hash)['algoName'] !== 'unknown';
unset($admin_config);

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('MKMusicAdmin');
session_set_cookie_params(array('lifetime' => 0, 'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, 'samesite' => 'Strict'));
session_start();
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!empty($_SESSION['admin_logged']) &&
    (!isset($_SESSION['admin_auth_version'], $_SESSION['admin_authenticated_at'])
    || !hash_equals(hash('sha256', $admin_hash), (string) $_SESSION['admin_auth_version'])
    || time() - (int) $_SESSION['admin_authenticated_at'] > 3600)) {
    unset($_SESSION['admin_logged'], $_SESSION['admin_qr']);
}

function admin_json($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function admin_csrf_valid() {
    return isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/** One locked counter per client IP also applies when cookies are deleted. */
function admin_login_rate($reset = false, $failure = false) {
    $directory = __DIR__ . '/cache/admin-login-ratelimit';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        admin_json(503, array('error' => '登录服务暂不可用'));
    }
    $path = $directory . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) {
        if ($file) fclose($file);
        admin_json(503, array('error' => '登录服务暂不可用'));
    }
    chmod($path, 0600);
    $now = time();
    $state = json_decode(stream_get_contents($file, 4096), true);
    if (!is_array($state) || !isset($state['since'], $state['count'])
        || !is_int($state['since']) || !is_int($state['count'])
        || $state['since'] > $now || $now - $state['since'] >= 900 || $reset) {
        $state = array('since' => $now, 'count' => 0);
    }
    $blocked = $state['count'] >= 5;
    if ($failure && !$blocked) $state['count']++;
    rewind($file);
    ftruncate($file, 0);
    fwrite($file, json_encode($state));
    fflush($file);
    flock($file, LOCK_UN);
    fclose($file);
    if ($blocked) header('Retry-After: ' . max(1, 900 - ($now - $state['since'])));
    return $blocked;
}

function kugou_sign($params) {
    $key = 'NVPh5oo715z5DIWAeQlhMDsWXXQV4hwt';
    ksort($params);
    $str = $key;
    foreach ($params as $k => $v) $str .= $k . '=' . $v;
    return md5($str . $key);
}

/** Endpoints are fixed HTTPS hosts; never forward raw upstream responses to the UI. */
function kugou_curl($url) {
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'Referer: https://www.kugou.com/'),
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > 524288) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }
    ));
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $ok && $status === 200 ? json_decode($body, true) : null;
}

if (isset($_POST['password'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !admin_csrf_valid()) {
        admin_json(403, array('error' => '页面已失效，请刷新后重试'));
    }
    if (!$admin_configured) admin_json(503, array('error' => '管理员登录尚未配置'));
    if (admin_login_rate(false, true)) admin_json(429, array('error' => '尝试过于频繁，请稍后重试'));
    if (!is_string($_POST['password']) || strlen($_POST['password']) > 1024
        || !password_verify($_POST['password'], $admin_hash)) {
        admin_json(401, array('error' => '密码不正确'));
    }
    admin_login_rate(true);
    session_regenerate_id(true);
    $_SESSION['admin_logged'] = true;
    $_SESSION['admin_authenticated_at'] = time();
    $_SESSION['admin_auth_version'] = hash('sha256', $admin_hash);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: kugou_admin.php', true, 303);
    exit;
}

if (isset($_GET['logout'])) admin_json(405, array('error' => '请使用页面上的退出按钮'));
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!is_string($action)) admin_json(400, array('error' => '操作参数有误'));
if ($action !== '') {
    if (empty($_SESSION['admin_logged'])) admin_json(401, array('error' => '未登录'));
    if ($action !== 'status' && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')) {
        header('Allow: POST');
        admin_json(405, array('error' => '此操作需要 POST 请求'));
    }
    if ($action !== 'status' && !admin_csrf_valid()) {
        admin_json(403, array('error' => '页面已失效，请刷新后重试'));
    }
    if ($action === 'logout') {
        $_SESSION = array();
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', array('expires' => time() - 3600, 'path' => $parameters['path'],
            'secure' => $parameters['secure'], 'httponly' => true, 'samesite' => 'Strict'));
        session_destroy();
        header('Location: kugou_admin.php', true, 303);
        exit;
    }
    if ($action === 'qrcode') {
        $mid = bin2hex(random_bytes(16));
        $params = array('appid' => '1014', 'clienttime' => (string) round(microtime(true) * 1000),
            'clientver' => '20000', 'mid' => $mid, 'plat' => '4', 'srcappid' => '2919',
            'type' => '1', 'uuid' => $mid);
        $params['signature'] = kugou_sign($params);
        $result = kugou_curl('https://login-user.kugou.com/v2/qrcode?' . http_build_query($params));
        $qrcode = is_array($result) ? ($result['data']['qrcode'] ?? null) : null;
        if (!is_string($qrcode) || !preg_match('/^[A-Za-z0-9._~+\/=-]{1,512}$/D', $qrcode)) {
            admin_json(502, array('error' => '暂时无法获取二维码，请稍后重试'));
        }
        $_SESSION['admin_qr'] = array('mid' => $mid, 'qrcode' => $qrcode, 'expires' => time() + 300);
        admin_json(200, array('data' => array('qrcode' => $qrcode)));
    }
    if ($action === 'check') {
        $qr = $_SESSION['admin_qr'] ?? null;
        $qrcode = $_POST['qrcode'] ?? null;
        if (!is_array($qr) || $qr['expires'] < time() || !is_string($qrcode)
            || !hash_equals($qr['qrcode'], $qrcode)) {
            admin_json(400, array('error' => '二维码已失效，请重新获取'));
        }
        $params = array('appid' => '1014', 'clienttime' => (string) round(microtime(true) * 1000),
            'clientver' => '20000', 'mid' => $qr['mid'], 'plat' => '4',
            'qrcode' => $qrcode, 'srcappid' => '2919', 'uuid' => $qr['mid']);
        $params['signature'] = kugou_sign($params);
        $data = kugou_curl('https://login-user.kugou.com/v2/get_userinfo_qrcode?' . http_build_query($params));
        if (!is_array($data)) admin_json(502, array('error' => '暂时无法查询扫码状态，请稍后重试'));
        $status = isset($data['data']['status']) && is_numeric($data['data']['status']) ? (int) $data['data']['status'] : -1;
        $token = $data['data']['token'] ?? '';
        $userid = $data['data']['userid'] ?? '';
        $saved = false;
        if (is_string($token) && preg_match('/^[A-Za-z0-9._~+\/=-]{1,2048}$/D', $token)
            && (is_string($userid) || is_int($userid)) && preg_match('/^[0-9]{1,24}$/D', (string) $userid)) {
            $cookie = 't=' . $token . '; KugooID=' . $userid . '; kg_mid=' . $qr['mid']
                . '; mid=' . $qr['mid'] . '; dfid=' . md5($qr['mid'] . $token) . '; userid=' . $userid;
            $temporary = tempnam(__DIR__ . '/cache', 'kugou-login-');
            if ($temporary === false || file_put_contents($temporary, $cookie, LOCK_EX) !== strlen($cookie)
                || !chmod($temporary, 0600) || !rename($temporary, __DIR__ . '/cache/kugou_cookie.txt')) {
                if ($temporary !== false) @unlink($temporary);
                admin_json(503, array('error' => '登录信息暂时无法保存，请稍后重试'));
            }
            $saved = true;
            $status = 4;
            unset($_SESSION['admin_qr']);
        }
        admin_json(200, array('data' => array('status' => $status), 'cookie_saved' => $saved));
    }
    if ($action === 'status') {
        $cookie_file = __DIR__ . '/cache/kugou_cookie.txt';
        $result = array('has_cookie' => false);
        if (is_file($cookie_file)) {
            $cookie = file_get_contents($cookie_file);
            $result['has_cookie'] = is_string($cookie) && trim($cookie) !== '';
            $result['update_time'] = date('Y-m-d H:i:s', filemtime($cookie_file));
            preg_match('/(?:^|;\s*)KugooID=([0-9]{1,24})(?:;|$)/', (string) $cookie, $matches);
            $result['userid'] = $matches[1] ?? '未知';
        }
        admin_json(200, $result);
    }
    if ($action === 'clear') {
        $cookie_file = __DIR__ . '/cache/kugou_cookie.txt';
        if (file_exists($cookie_file) && !unlink($cookie_file)) {
            admin_json(503, array('error' => '登录信息暂时无法清除，请稍后重试'));
        }
        admin_json(200, array('success' => true));
    }
    admin_json(400, array('error' => '未知操作'));
}
$is_logged = !empty($_SESSION['admin_logged']);
$csrf_html = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
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
        .logout button { border: 0; background: none; color: #888; font-size: 13px; cursor: pointer; }
        .logout button:hover { color: #e94560; }
        #refreshBtn { display: none; }
    </style>
</head>
<body>
<div class="container">
    <?php if (!$is_logged): ?>
        <h2>管理员登录</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_html; ?>">
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

        <form method="POST" class="logout">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_html; ?>">
            <button type="submit">退出管理</button>
        </form>

        <script>
        var pollTimer = null;
        var currentQrcode = '';
        var isPolling = false;
        var csrfToken = <?php echo json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function adminRequest(action, values) {
            var body = new URLSearchParams(values || {});
            body.set('action', action);
            body.set('csrf_token', csrfToken);
            return fetch('kugou_admin.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || data.error) throw new Error(data.error || '请求暂时失败');
                        return data;
                    });
                });
        }

        // 检查 Cookie 状态
        function checkStatus() {
            adminRequest('status').then(data => {
                var el = document.getElementById('cookieStatus');
                el.replaceChildren();
                var status = document.createElement('div');
                status.className = 'status ' + (data.has_cookie ? 'status-ok' : 'status-warn');
                status.textContent = data.has_cookie ? 'Cookie 已保存' : '暂无Cookie，请扫码登录';
                el.appendChild(status);
                if (data.has_cookie) {
                    [['用户ID', data.userid], ['更新时间', data.update_time]].forEach(function (item) {
                        var row = document.createElement('div');
                        row.className = 'info-row';
                        var label = document.createElement('span');
                        label.className = 'info-label';
                        label.textContent = item[0];
                        var value = document.createElement('span');
                        value.textContent = item[1] || '';
                        row.append(label, value);
                        el.appendChild(row);
                    });
                }
            }).catch(function (error) {
                document.getElementById('cookieStatus').textContent = error.message;
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

            adminRequest('qrcode').then(data => {
                if (data.data && data.data.qrcode) {
                    currentQrcode = data.data.qrcode;
                    // 用酷狗扫码URL生成二维码图片
                    var scanUrl = 'https://h5.kugou.com/apps/loginQRCode/html/index.html?qrcode=' + currentQrcode + '&appid=1014';
                    document.getElementById('qrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(scanUrl);
                    document.getElementById('scanStatus').textContent = '请用酷狗APP扫描二维码';
                    // 开始轮询
                    pollTimer = setInterval(checkScan, 2000);
                } else {
                    document.getElementById('scanStatus').className = 'status status-err';
                    document.getElementById('scanStatus').textContent = '暂时无法获取二维码，请稍后重试';
                }
            }).catch(e => {
                document.getElementById('scanStatus').className = 'status status-err';
                document.getElementById('scanStatus').textContent = '请求失败：' + e.message;
            });
        }

        // 检查扫码状态
        function checkScan() {
            if (!currentQrcode || isPolling) return;
            isPolling = true;
            adminRequest('check', { qrcode: currentQrcode }).then(data => {
                var statusEl = document.getElementById('scanStatus');
                var state = data.data ? data.data.status : -1;
                if (data.cookie_saved) { state = 4; }

                var errCode = data.error_code || 0;
                if (state === 4) {
                    statusEl.className = 'status status-ok';
                    statusEl.textContent = '登录成功，Cookie 已保存！';
                    clearInterval(pollTimer);
                    checkStatus();
                } else if (state === 2) {
                    statusEl.className = 'status status-info';
                    statusEl.textContent = '已扫码，请在手机上确认登录';
                } else if (state === 0 || state === 1) {
                    // v2 API: status=0或1都表示等待扫码
                    statusEl.className = 'status status-info';
                    statusEl.textContent = '等待扫码... (' + state + ')';
                } else if (state === 3) {
                    statusEl.className = 'status status-warn';
                    statusEl.textContent = '已取消或已过期，请刷新';
                    clearInterval(pollTimer);
                } else {
                    statusEl.className = 'status status-info';
                    statusEl.textContent = '等待中... (status=' + state + ', err=' + errCode + ')';
                }
            }).catch(function (error) {
                var statusEl = document.getElementById('scanStatus');
                statusEl.className = 'status status-err';
                statusEl.textContent = error.message;
                clearInterval(pollTimer);
            }).then(function () { isPolling = false; });
        }

        // 清除 Cookie
        function clearCookie() {
            if (!confirm('确定清除Cookie？')) return;
            adminRequest('clear').then(() => {
                checkStatus();
            }).catch(function (error) {
                document.getElementById('cookieStatus').textContent = error.message;
            });
        }

        checkStatus();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
