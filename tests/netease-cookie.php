<?php
// Offline regression: login credentials must not remove required client metadata.
require_once dirname(__DIR__) . '/plugns/Meting.php';

use Metowolf\Meting;

$checks = 0;
function cookieCheck($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function cookieFields($header) {
    $fields = array();
    foreach (explode(';', $header) as $item) {
        $pair = explode('=', trim($item), 2);
        if (count($pair) === 2) $fields[$pair[0]] = $pair[1];
    }
    return $fields;
}

$api = new Meting('netease');
$defaults = cookieFields($api->header['Cookie']);
cookieCheck($defaults['appver'] === '8.2.30' && $defaults['os'] === 'iPhone OS', 'Expected client identity defaults');
cookieCheck(!isset($defaults['MUSIC_U']) && !isset($defaults['__csrf']), 'Defaults contain no login credentials');
cookieCheck($api->cookie('MUSIC_U=first-account; __csrf=first-csrf') === $api, 'Cookie setter remains chainable');
$loggedIn = cookieFields($api->header['Cookie']);
cookieCheck(array_intersect_key($loggedIn, $defaults) === $defaults, 'All required metadata survives login');
cookieCheck($loggedIn['MUSIC_U'] === 'first-account' && $loggedIn['__csrf'] === 'first-csrf', 'Login credential and CSRF preserved');

$api->cookie('MUSIC_U=second-account; os=pc; appver=custom; session=encoded==; extra=value');
$second = cookieFields($api->header['Cookie']);
cookieCheck($second['os'] === 'pc' && $second['appver'] === 'custom', 'Explicit client metadata overrides defaults');
cookieCheck($second['MUSIC_U'] === 'second-account' && !isset($second['__csrf']), 'A new login never inherits the previous account CSRF');
cookieCheck($second['session'] === 'encoded==' && $second['extra'] === 'value', 'Cookie values with equals and additional fields preserved');

$api->cookie('MUSIC_U=third-account; MUSIC_U=last-account');
$third = cookieFields($api->header['Cookie']);
cookieCheck($third['MUSIC_U'] === 'last-account', 'Later explicit duplicate wins');
cookieCheck($third['os'] === $defaults['os'] && $third['appver'] === $defaults['appver'], 'A later call restores defaults rather than old overrides');
cookieCheck(!isset($third['session']) && !isset($third['extra']), 'Arbitrary previous account state is removed');
$api->cookie('');
cookieCheck(cookieFields($api->header['Cookie']) === $defaults, 'Empty input resets to anonymous defaults');

foreach (array('tencent', 'kugou', 'baidu', 'xiami', 'kuwo') as $source) {
    $other = new Meting($source);
    $header = 'token=fixture; os=pc; repeated=one; repeated=two';
    $other->cookie($header);
    cookieCheck($other->header['Cookie'] === $header, $source . ' preserves replacement behavior');
    $other->cookie('');
    cookieCheck($other->header['Cookie'] === '', $source . ' can clear its Cookie');
}

echo json_encode(array('neteaseCookieChecks' => $checks, 'passed' => true)) . PHP_EOL;
