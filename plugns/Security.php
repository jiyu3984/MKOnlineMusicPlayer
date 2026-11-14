<?php

namespace Mxue;

/** Validation shared by the public music API and its download transport. */
final class Security
{
    public const SOURCES = ['netease', 'tencent', 'kugou', 'kuwo', 'baidu', 'xiami'];

    public static function runtimeConfig(): array
    {
        static $configuration;
        if ($configuration === null) {
            $path = getenv('MUSIC_CONFIG_FILE') ?: dirname(__DIR__) . '/config.local.php';
            $loaded = is_file($path) ? require $path : [];
            $configuration = is_array($loaded) ? $loaded : [];
        }
        return $configuration;
    }

    public static function badRequest(string $message, int $status = 400): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['code' => 0, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function requestParam(string $key, $default = ''): string
    {
        $value = array_key_exists($key, $_POST) ? $_POST[$key]
            : (array_key_exists($key, $_GET) ? $_GET[$key] : $default);
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            self::badRequest('参数格式有误');
        }
        $value = trim((string) $value);
        if (strlen($value) > 8192 || str_contains($value, "\0")) {
            self::badRequest('参数格式有误');
        }
        return $value;
    }

    public static function validateApiRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            self::badRequest('请求方法有误', 405);
        }
        $source = self::requestParam('source', 'netease');
        if (!in_array($source, self::SOURCES, true)) {
            self::badRequest('不支持的音乐源');
        }
        $type = self::requestParam('types');
        if ($type === 'cache') {
            self::badRequest('缓存清理由服务器定时执行', 403);
        }
        if (!in_array($type, ['', 'url', 'pic', 'lyric', 'userlist', 'playlist', 'search', 'comments', 'download'], true)) {
            self::badRequest('不支持的操作');
        }
        if (in_array($type, ['url', 'pic', 'lyric', 'playlist', 'comments'], true)) {
            $id = self::requestParam('id');
            $urlId = $type === 'url' ? self::requestParam('url_id') : '';
            if (($id === '' && $urlId === '')
                || ($id !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,192}\z/D', $id))
                || ($urlId !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,192}\z/D', $urlId))) {
                self::badRequest('歌曲编号有误');
            }
        }
        if ($type === 'userlist' && !preg_match('/\A[0-9]{1,20}\z/D', self::requestParam('uid'))) {
            self::badRequest('用户编号有误');
        }
        if ($type === 'search') {
            $name = self::requestParam('name');
            if ($name === '' || strlen($name) > 800 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                self::badRequest('搜索内容有误');
            }
        }
        if (in_array($type, ['search', 'comments'], true)) {
            $count = self::requestParam('count', $type === 'comments' ? 50 : 20);
            $page = self::requestParam('pages', 1);
            if (!ctype_digit($count) || (int) $count < 1 || (int) $count > 100
                || !ctype_digit($page) || (int) $page < 1 || (int) $page > 1000
                || strlen($count) > 3 || strlen($page) > 4) {
                self::badRequest('页码或数量有误');
            }
        }
        if ($type === 'download') {
            foreach (['name', 'artist'] as $key) {
                if (strlen(self::requestParam($key)) > 800) self::badRequest('歌曲名称有误');
            }
            if (self::requestParam('name') === '') self::badRequest('歌曲名称有误');
            try {
                self::publicUrl(self::requestParam('url'));
            } catch (\InvalidArgumentException $error) {
                self::badRequest('歌曲地址有误');
            }
        }
    }

    private static function inNetwork(string $packed, string $network, int $bits): bool
    {
        $range = inet_pton($network);
        if ($range === false || strlen($packed) !== strlen($range)) return false;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        return substr($packed, 0, $bytes) === substr($range, 0, $bytes)
            && ($remainder === 0 || (ord($packed[$bytes]) & (255 << (8 - $remainder)))
                === (ord($range[$bytes]) & (255 << (8 - $remainder))));
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        if (strlen($packed) === 4) {
            $blocked = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
                ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
        } else {
            // Only ordinary global unicast, excluding special-use and transition ranges.
            if (!self::inNetwork($packed, '2000::', 3)) return false;
            $blocked = [['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]];
        }
        foreach ($blocked as [$network, $bits]) {
            if (self::inNetwork($packed, $network, $bits)) return false;
        }
        return true;
    }

    /** Reject mixed public/private DNS answers and pin the chosen IP in cURL. */
    public static function publicUrl(string $url, ?callable $resolver = null): array
    {
        if ($url === '' || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid URL');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid URL');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== ($scheme === 'https' ? 443 : 80)) throw new \InvalidArgumentException('Invalid port');
        $host = strtolower($parts['host']);
        $hostIp = trim($host, '[]');
        if (filter_var($hostIp, FILTER_VALIDATE_IP)) {
            $addresses = [$hostIp];
        } else {
            if (!preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
                throw new \InvalidArgumentException('Invalid host');
            }
            if ($resolver !== null) {
                $addresses = $resolver($host);
            } else {
                $addresses = [];
                foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ip'])) $addresses[] = $record['ip'];
                    if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
                }
            }
        }
        if (!is_array($addresses) || !$addresses) throw new \InvalidArgumentException('Unresolved host');
        foreach ($addresses as $ip) {
            if (!is_string($ip) || !self::isPublicIp($ip)) throw new \InvalidArgumentException('Non-public address');
        }
        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function redirectUrl(string $base, string $location): string
    {
        if ($location === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $location)) throw new \InvalidArgumentException('Invalid redirect');
        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $location)) return $location;
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        if ($location[0] === '/') return $origin . $location;
        $path = $parts['path'] ?? '/';
        if ($location[0] === '?') return $origin . $path . $location;
        return $origin . substr($path, 0, strrpos($path, '/') + 1) . $location;
    }

    public static function downloadFilename(string $name, string $artist, string $url): string
    {
        $label = $name . ($artist === '' ? '' : ' - ' . $artist);
        $label = preg_replace('/[\/\\\\\x00-\x1f\x7f<>:"|?*]+/u', '_', $label) ?? 'track';
        $label = trim($label, " .\t\n\r\0\x0B");
        $label = function_exists('mb_strcut') ? mb_strcut($label, 0, 160, 'UTF-8') : substr($label, 0, 160);
        if ($label === '') $label = 'track';
        return $label . '-' . substr(hash('sha256', $url), 0, 16) . '.mp3';
    }
}
