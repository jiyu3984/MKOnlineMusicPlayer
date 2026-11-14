<?php

require_once dirname(__DIR__) . '/plugns/Security.php';
require_once dirname(__DIR__) . '/plugns/Download.php';

use Mxue\Security;
use Mxue\Download;

$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function rejects($url, $resolver = null) {
    try {
        Security::publicUrl($url, $resolver);
        return false;
    } catch (InvalidArgumentException $error) {
        return true;
    }
}

foreach (['127.0.0.1', '10.2.3.4', '172.16.0.1', '192.168.0.1', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.2.1', '198.18.0.1',
    '::1', '::', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '2002:7f00:1::',
    '2001:db8::1', 'ff00::1', '3fff::1'] as $ip) {
    check(!Security::isPublicIp($ip), 'Blocked address ' . $ip);
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
    check(Security::isPublicIp($ip), 'Public address ' . $ip);
}
foreach (['file:///etc/passwd', 'gopher://8.8.8.8/', 'http://localhost/', 'http://127.1/',
    'http://2130706433/', 'http://0177.0.0.1/', 'http://0x7f000001/', 'http://127.0.0.1/',
    'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://8.8.8.8:8765/',
    'https://8.8.8.8:80/', 'http://user:pass@8.8.8.8/', 'http://8.8.8.8/#hidden',
    "http://8.8.8.8/\r\nX-Test:1", 'http://8.8.8.8\\@127.0.0.1/'] as $url) {
    check(rejects($url), 'Blocked URL ' . $url);
}
check(rejects('https://audio.example.test/song', fn($host) => ['8.8.8.8', '127.0.0.1']), 'Mixed DNS rejected');
check(rejects('https://audio.example.test/song', fn($host) => ['::ffff:127.0.0.1']), 'Mapped DNS rejected');
check(rejects('https://audio.example.test/song', fn($host) => []), 'Empty DNS rejected');
$target = Security::publicUrl('https://audio.example.test/song', fn($host) => ['8.8.8.8']);
check($target['ip'] === '8.8.8.8' && $target['port'] === 443, 'Public DNS pin target');
check(Security::redirectUrl('https://audio.example.test/a/song', '/next') === 'https://audio.example.test/next', 'Root-relative redirect');
check(Security::redirectUrl('https://audio.example.test/a/song', '?v=2') === 'https://audio.example.test/a/song?v=2', 'Query redirect');
check(rejects(Security::redirectUrl('https://audio.example.test/a/song', '//127.0.0.1/private')), 'Redirect to loopback rejected');
check(rejects(Security::redirectUrl('https://audio.example.test/a/song', 'file:///etc/passwd')), 'Redirect protocol rejected');
foreach ([['../../escape', '../writer'], ["a\0b", "artist\nname"], ['歌曲/名字', 'AC/DC'], ['.', '..']] as [$name, $artist]) {
    $filename = Security::downloadFilename($name, $artist, 'https://8.8.8.8/song');
    check(basename($filename) === $filename && !preg_match('/[\/\\\\\x00-\x1f]/', $filename), 'Safe filename');
    check(str_ends_with($filename, '.mp3'), 'Audio extension');
}
check(Security::downloadFilename('song', '', 'https://8.8.8.8/a') !== Security::downloadFilename('song', '', 'https://8.8.8.8/b'), 'Distinct source files');
try {
    new Download('../../cache');
    check(false, 'Invalid source constructor');
} catch (InvalidArgumentException $error) { check(true, 'Invalid source constructor'); }
check(json_decode((new Download('netease'))->download('file:///etc/passwd', 'song', ''), true)['code'] === 0, 'Invalid download fails before I/O');
echo json_encode(['checks' => $checks, 'passed' => true]) . PHP_EOL;
