<?php
// Exercise disk-budget and concurrent-download behavior in an isolated filesystem.
$sandbox = sys_get_temp_dir() . '/mkmusic-quota-' . bin2hex(random_bytes(8));
mkdir($sandbox . '/plugns', 0700, true);
mkdir($sandbox . '/temp/netease', 0700, true);
foreach (['Security.php', 'Download.php'] as $file) {
    copy(dirname(__DIR__) . '/plugns/' . $file, $sandbox . '/plugns/' . $file);
}
require $sandbox . '/plugns/Download.php';
class FixtureDownload extends Mxue\Download {
    public $transfers = 0;
    public function curl($url, $destination) {
        $this->transfers++;
        file_put_contents($destination, 'audio fixture');
        return true;
    }
}
function quotaCheck($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
}
$lock = null;
try {
    $download = new FixtureDownload('netease');
    $result = json_decode($download->download('https://8.8.8.8/audio', '../../track', '../artist'), true);
    quotaCheck($result['code'] === 1 && $download->transfers === 1, 'Ordinary new download must work');
    $decoded = rawurldecode($result['url']);
    quotaCheck(dirname($decoded) === '/temp/netease' && !in_array(basename($decoded), ['.', '..'], true), 'Filename must remain in download directory');
    $sparse = fopen($sandbox . '/temp/netease/occupied.mp3', 'w+b');
    ftruncate($sparse, 1024 * 1024 * 1024 - 64 * 1024 * 1024 + 1);
    fclose($sparse);
    $result = json_decode($download->download('https://8.8.8.8/other', 'other', ''), true);
    quotaCheck($result['code'] === 0 && $download->transfers === 1, 'Quota must reject transfer before writing');
    unlink($sandbox . '/temp/netease/occupied.mp3');
    $lock = fopen($sandbox . '/temp/.download.lock', 'c');
    flock($lock, LOCK_EX);
    $result = json_decode($download->download('https://8.8.8.8/other', 'other', ''), true);
    quotaCheck($result['code'] === 0 && $download->transfers === 1, 'Concurrent new transfer must not bypass reservation');
    $result = json_decode($download->download('https://8.8.8.8/audio', '../../track', '../artist'), true);
    quotaCheck($result['code'] === 1 && $download->transfers === 1, 'Cached audio remains available while transfer lock is held');
    echo json_encode(['downloadQuotaChecks' => 5, 'passed' => true]) . PHP_EOL;
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($sandbox);
}
