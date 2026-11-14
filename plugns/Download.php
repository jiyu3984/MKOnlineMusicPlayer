<?php

namespace Mxue;

require_once __DIR__ . '/Security.php';

class Download
{
    public $source;
    private const MAX_BYTES = 64 * 1024 * 1024;
    private const QUOTA_BYTES = 1024 * 1024 * 1024;

    public function __construct($source)
    {
        if (!in_array($source, Security::SOURCES, true)) throw new \InvalidArgumentException('Invalid music source');
        $this->source = $source;
    }

    public function echojson($downpath, $msg = '')
    {
        return json_encode(['code' => $downpath ? 1 : 0, 'url' => $downpath ?: '',
            'msg' => $downpath ? '歌曲url获取成功' : $msg], JSON_UNESCAPED_UNICODE);
    }

    /** Download to a temporary file; redirects are validated and DNS-pinned individually. */
    public function curl($url, $destination)
    {
        $temporary = tempnam(dirname($destination), '.download-');
        if ($temporary === false) return false;
        $stream = fopen($temporary, 'w+b');
        if ($stream === false) {
            @unlink($temporary);
            return false;
        }
        $completed = false;
        $deadline = microtime(true) + 45;
        try {
            for ($redirects = 0; $redirects <= 5; $redirects++) {
                $target = Security::publicUrl($url);
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 1) return false;
                $location = null;
                $bytes = 0;
                $tooLarge = false;
                ftruncate($stream, 0);
                rewind($stream);
                $curl = curl_init();
                $options = [
                    CURLOPT_URL => $url,
                    CURLOPT_PROXY => '',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => min(25, $remaining),
                    CURLOPT_CONNECTTIMEOUT => min(8, $remaining),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'MKOnlineMusicPlayer/secure-download',
                    CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$location, &$tooLarge) {
                        if (preg_match('/\ALocation:\s*(.*?)\s*\r?\n\z/i', $line, $match)) $location = $match[1];
                        if (preg_match('/\AContent-Length:\s*([0-9]+)/i', $line, $match)
                            && (float) $match[1] > self::MAX_BYTES) {
                            $tooLarge = true;
                            return 0;
                        }
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, $data) use ($stream, &$bytes, &$tooLarge) {
                        $bytes += strlen($data);
                        if ($bytes > self::MAX_BYTES) {
                            $tooLarge = true;
                            return 0;
                        }
                        return fwrite($stream, $data);
                    },
                ];
                if (!filter_var(trim($target['host'], '[]'), FILTER_VALIDATE_IP)) {
                    $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
                    $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ip];
                }
                curl_setopt_array($curl, $options);
                $result = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $contentType = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
                curl_close($curl);
                if ($result === false || $tooLarge) return false;
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null) {
                    $url = Security::redirectUrl($url, $location);
                    continue;
                }
                if ($status !== 200 || $bytes === 0
                    || !(str_starts_with($contentType, 'audio/') || str_starts_with($contentType, 'application/octet-stream'))) return false;
                fflush($stream);
                fclose($stream);
                $stream = null;
                chmod($temporary, 0644);
                $completed = rename($temporary, $destination);
                return $completed;
            }
            return false;
        } catch (\Throwable $error) {
            return false;
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (!$completed) @unlink($temporary);
        }
    }

    public function download($url, $name, $artist)
    {
        if (!is_string($url) || !is_string($name) || !is_string($artist) || trim($name) === '') return $this->echojson(null, '歌曲信息有误');
        try {
            Security::publicUrl($url);
        } catch (\InvalidArgumentException $error) {
            return $this->echojson(null, '歌曲地址有误');
        }
        $directory = dirname(__DIR__) . '/temp/' . $this->source;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) return $this->echojson(null, '歌曲下载暂不可用');
        $filename = Security::downloadFilename($name, $artist, $url);
        $destination = $directory . '/' . $filename;
        $path = '/temp/' . $this->source . '/' . rawurlencode($filename);
        if (is_file($destination)) return $this->echojson($path);
        // One new transfer at a time gives the aggregate quota a race-free 64 MiB reservation.
        // Existing cached songs above do not wait for this lock.
        $lock = fopen(dirname(__DIR__) . '/temp/.download.lock', 'c');
        if ($lock === false) return $this->echojson(null, '歌曲下载暂不可用');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return $this->echojson(null, '当前下载较多，请稍后重试');
        }
        try {
            if (is_file($destination)) return $this->echojson($path);
            $used = 0;
            foreach (Security::SOURCES as $provider) {
                $providerDirectory = dirname(__DIR__) . '/temp/' . $provider;
                if (!is_dir($providerDirectory)) continue;
                foreach (new \DirectoryIterator($providerDirectory) as $file) {
                    if ($file->isFile()) $used += $file->getSize();
                    if ($used + self::MAX_BYTES > self::QUOTA_BYTES) {
                        return $this->echojson(null, '下载空间暂满，请稍后重试');
                    }
                }
            }
            if ($this->curl($url, $destination)) return $this->echojson($path);
            return $this->echojson(null, '歌曲url获取失败');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
