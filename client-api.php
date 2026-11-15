<?php
/** Versioned, data-only transport for the packaged Android client. */

require_once __DIR__ . '/plugns/Security.php';

final class KunMusicProtocolException extends InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

final class KunMusicClientProtocol
{
    public const VERSION = 'kunmusic/1';
    public const MAX_REQUEST_BYTES = 16384;
    private const PARAMETERS = [
        'song' => ['source', 'id'],
        'url' => ['source', 'id', 'url_id'],
        'pic' => ['source', 'id'],
        'lyric' => ['source', 'id'],
        'search' => ['source', 'name', 'count', 'pages'],
        'playlist' => ['source', 'id'],
        'userlist' => ['source', 'uid'],
        'comments' => ['source', 'id', 'count', 'pages'],
    ];

    /** Reject unknown actions and fields before entering the existing music API. */
    public static function decode(string $body, string $contentType, string $httpMethod): array
    {
        if ($httpMethod !== 'POST') {
            throw new KunMusicProtocolException('METHOD_NOT_ALLOWED', '仅支持 POST 请求', 405);
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            throw new KunMusicProtocolException('UNSUPPORTED_CONTENT_TYPE', '请使用 application/json', 415);
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new KunMusicProtocolException('REQUEST_TOO_LARGE', '请求内容过长', 413);
        }
        try {
            $request = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new KunMusicProtocolException('INVALID_JSON', 'JSON 格式有误');
        }
        if (!$request instanceof stdClass
            || array_diff(array_keys(get_object_vars($request)), ['protocol', 'requestId', 'method', 'params'])) {
            throw new KunMusicProtocolException('INVALID_REQUEST', '请求格式有误');
        }
        if (($request->protocol ?? null) !== self::VERSION) {
            throw new KunMusicProtocolException('UNSUPPORTED_PROTOCOL', '不支持的客户端协议', 426);
        }
        $requestId = $request->requestId ?? null;
        if (!is_string($requestId) || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId)) {
            throw new KunMusicProtocolException('INVALID_REQUEST_ID', '请求编号有误');
        }
        $method = $request->method ?? null;
        if (!is_string($method) || !array_key_exists($method, self::PARAMETERS)) {
            throw new KunMusicProtocolException('UNSUPPORTED_METHOD', '不支持的音乐操作');
        }
        if (!isset($request->params) || !$request->params instanceof stdClass) {
            throw new KunMusicProtocolException('INVALID_PARAMS', '参数必须为对象');
        }
        $params = get_object_vars($request->params);
        if (array_diff(array_keys($params), self::PARAMETERS[$method])) {
            throw new KunMusicProtocolException('INVALID_PARAMS', '包含不支持的参数');
        }
        foreach ($params as $key => $value) {
            if ((!is_string($value) && !is_int($value)) || strlen((string) $value) > 8192
                || str_contains((string) $value, "\0")) {
                throw new KunMusicProtocolException('INVALID_PARAMS', '参数格式有误');
            }
            $params[$key] = trim((string) $value);
        }
        $params['source'] = $params['source'] ?? 'netease';
        if (!in_array($params['source'], \Mxue\Security::SOURCES, true)) {
            throw new KunMusicProtocolException('INVALID_SOURCE', '不支持的音乐源');
        }
        if (in_array($method, ['song', 'url', 'pic', 'lyric', 'playlist', 'comments'], true)) {
            $id = $params['id'] ?? '';
            $urlId = $params['url_id'] ?? '';
            if (($id === '' && $urlId === '')
                || ($id !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,192}\z/D', $id))
                || ($urlId !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,192}\z/D', $urlId))) {
                throw new KunMusicProtocolException('INVALID_ID', '歌曲或歌单编号有误');
            }
        }
        if ($method === 'userlist' && !preg_match('/\A[0-9]{1,20}\z/D', $params['uid'] ?? '')) {
            throw new KunMusicProtocolException('INVALID_ID', '用户编号有误');
        }
        if ($method === 'search') {
            $name = $params['name'] ?? '';
            if ($name === '' || strlen($name) > 800 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new KunMusicProtocolException('INVALID_SEARCH', '搜索内容有误');
            }
        }
        if ($method === 'search' || $method === 'comments') {
            $count = $params['count'] ?? ($method === 'comments' ? '50' : '20');
            $pages = $params['pages'] ?? '1';
            if (!ctype_digit($count) || strlen($count) > 3 || (int) $count < 1 || (int) $count > 100
                || !ctype_digit($pages) || strlen($pages) > 4 || (int) $pages < 1 || (int) $pages > 1000) {
                throw new KunMusicProtocolException('INVALID_PAGINATION', '页码或数量有误');
            }
        }
        $params['types'] = $method;
        return ['requestId' => $requestId, 'params' => $params];
    }

    public static function error(?string $requestId, string $code, string $message): string
    {
        return json_encode(['protocol' => self::VERSION, 'requestId' => $requestId, 'ok' => false,
            'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function requestIdFromBody(string $body): ?string
    {
        if (strlen($body) > self::MAX_REQUEST_BYTES) return null;
        $request = json_decode($body, false, 16);
        $id = $request instanceof stdClass ? ($request->requestId ?? null) : null;
        return is_string($id) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $id) ? $id : null;
    }

    /** Share the existing server-local credentials, never return them to the client. */
    public static function configuredSongApi(string $source, string $neteaseCookie, string $kugouCookie): \Metowolf\Meting
    {
        require_once __DIR__ . '/plugns/Meting.php';
        $api = (new \Metowolf\Meting($source))->format(true);
        if ($source === 'netease' && $neteaseCookie !== '') $api->cookie($neteaseCookie);
        if ($source === 'kugou' && $kugouCookie !== '') {
            // The legacy Kugou metadata endpoint is HTTP; it must not receive a login Cookie.
            // Preserve the VIP client fields for API parity without attaching credentials to it.
            $fields = [];
            foreach (explode(';', $kugouCookie) as $field) {
                $pair = explode('=', trim($field), 2);
                if (count($pair) === 2) $fields[trim($pair[0])] = trim($pair[1]);
            }
            if (($fields['t'] ?? '') !== '') {
                $api->kugouVip($fields['KugooID'] ?? '0', $fields['t'],
                    $fields['kg_mid'] ?? ($fields['mid'] ?? ''), $fields['dfid'] ?? ($fields['kg_dfid'] ?? ''));
            }
        }
        return $api;
    }

    private static function song(array $params): string
    {
        $runtime = \Mxue\Security::runtimeConfig();
        $neteaseCookie = getenv('MUSIC_NETEASE_COOKIE') ?: ($runtime['netease_cookie'] ?? '');
        $kugouCookie = getenv('MUSIC_KUGOU_COOKIE') ?: ($runtime['kugou_cookie'] ?? '');
        if (!$kugouCookie && is_file(__DIR__ . '/cache/kugou_cookie.txt')) {
            $kugouCookie = trim(file_get_contents(__DIR__ . '/cache/kugou_cookie.txt'));
        }
        $api = self::configuredSongApi($params['source'], (string) $neteaseCookie, (string) $kugouCookie);
        return $api->song($params['id']);
    }

    /** The legacy API exits after output; its final buffer is wrapped without an HTTP loop. */
    public static function encodeApiResponse(string $body, string $requestId, int $status): array
    {
        if ($status >= 400) {
            return ['status' => $status, 'body' => self::error($requestId,
                $status < 500 ? 'INVALID_PARAMS' : 'SERVICE_UNAVAILABLE',
                $status < 500 ? '音乐请求参数有误' : '音乐服务暂时不可用')];
        }
        try {
            $result = strlen($body) <= 16777216 ? json_decode($body, false, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $error) {
            $result = null;
        }
        if (!is_array($result) && !$result instanceof stdClass) {
            return ['status' => 502, 'body' => self::error($requestId, 'UPSTREAM_UNAVAILABLE', '暂时无法获取音乐数据')];
        }
        return ['status' => 200, 'body' => json_encode(['protocol' => self::VERSION,
            'requestId' => $requestId, 'ok' => true, 'result' => $result],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
    }

    public static function serve(): never
    {
        error_reporting(0);
        ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('X-KunMusic-Protocol: ' . self::VERSION);
        $input = '';
        try {
            $input = file_get_contents('php://input', false, null, 0, self::MAX_REQUEST_BYTES + 1);
            $request = self::decode(is_string($input) ? $input : '', $_SERVER['CONTENT_TYPE'] ?? '',
                $_SERVER['REQUEST_METHOD'] ?? 'GET');
        } catch (KunMusicProtocolException $error) {
            http_response_code($error->status);
            if ($error->status === 405) header('Allow: POST');
            echo self::error(self::requestIdFromBody(is_string($input) ? $input : ''), $error->errorCode, $error->getMessage());
            exit;
        }
        // Query strings, cookies and JSON properties cannot override the selected API operation.
        $_GET = [];
        $_POST = $request['params'];
        $_REQUEST = $_POST;
        http_response_code(200);
        ob_start(static function (string $body) use ($request): string {
            $response = self::encodeApiResponse($body, $request['requestId'], http_response_code() ?: 200);
            http_response_code($response['status']);
            header('Content-Type: application/json; charset=utf-8');
            return $response['body'];
        });
        try {
            if ($request['params']['types'] === 'song') {
                echo self::song($request['params']);
                exit;
            }
            require __DIR__ . '/api.php';
        } catch (Throwable $error) {
            http_response_code(500);
            echo '{}';
        }
        exit;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    KunMusicClientProtocol::serve();
}
