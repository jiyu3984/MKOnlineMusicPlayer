<?php
// Offline protocol contract checks. No upstream API calls or runtime credentials.
require_once dirname(__DIR__) . '/client-api.php';

$checks = 0;
function protocolCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function protocolRequest(array $overrides = []): string
{
    return json_encode(array_replace(['protocol' => 'kunmusic/1', 'requestId' => 'fixture-1',
        'method' => 'url', 'params' => (object) ['id' => '287398']], $overrides), JSON_THROW_ON_ERROR);
}
function protocolRejects(string $body, string $expected, string $contentType = 'application/json', string $method = 'POST'): void
{
    try {
        KunMusicClientProtocol::decode($body, $contentType, $method);
        protocolCheck(false, 'Expected rejection ' . $expected);
    } catch (KunMusicProtocolException $error) {
        protocolCheck($error->errorCode === $expected, 'Rejection code ' . $expected);
    }
}

$decoded = KunMusicClientProtocol::decode(protocolRequest(), 'application/json; charset=utf-8', 'POST');
protocolCheck($decoded['requestId'] === 'fixture-1' && $decoded['params'] === [
    'id' => '287398', 'source' => 'netease', 'types' => 'url'], 'Valid request maps to existing music API');
foreach (['download', 'cache', 'admin', '', 'URL'] as $method) {
    protocolRejects(protocolRequest(['method' => $method]), 'UNSUPPORTED_METHOD');
}
foreach ([['url' => 'https://example.test/song'], ['callback' => 'steal'], ['types' => 'download'],
    ['id' => ['nested']], ['id' => true], ['id' => 1.5], ['id' => "bad\0id"]] as $params) {
    protocolRejects(protocolRequest(['params' => (object) $params]), 'INVALID_PARAMS');
}
protocolRejects(protocolRequest(['params' => (object) ['source' => 'unsupported', 'id' => '1']]), 'INVALID_SOURCE');
protocolRejects(protocolRequest(['protocol' => 'kunmusic/2']), 'UNSUPPORTED_PROTOCOL');
protocolRejects(protocolRequest(['requestId' => '<script>']), 'INVALID_REQUEST_ID');
protocolRejects(protocolRequest(['extra' => 1]), 'INVALID_REQUEST');
protocolRejects(protocolRequest(['params' => []]), 'INVALID_PARAMS');
protocolRejects('[]', 'INVALID_REQUEST');
protocolRejects('{', 'INVALID_JSON');
protocolRejects(str_repeat(' ', KunMusicClientProtocol::MAX_REQUEST_BYTES + 1), 'REQUEST_TOO_LARGE');
protocolRejects(protocolRequest(), 'METHOD_NOT_ALLOWED', 'application/json', 'GET');
protocolRejects(protocolRequest(), 'UNSUPPORTED_CONTENT_TYPE', 'text/plain');
foreach (['../etc/passwd', 'https://example.test/', '', str_repeat('a', 193)] as $id) {
    protocolRejects(protocolRequest(['params' => (object) ['id' => $id]]), 'INVALID_ID');
}
$urlId = KunMusicClientProtocol::decode(protocolRequest(['params' => (object) [
    'source' => 'kugou', 'url_id' => 'abc_encoded-id']]), 'application/json', 'POST');
protocolCheck($urlId['params']['url_id'] === 'abc_encoded-id', 'Encoded music identifier supported');
protocolRejects(protocolRequest(['method' => 'search', 'params' => (object) ['name' => '']]), 'INVALID_SEARCH');
protocolRejects(protocolRequest(['method' => 'search', 'params' => (object) ['name' => "a\nb"]]), 'INVALID_SEARCH');
foreach ([['count' => 101], ['pages' => 0], ['pages' => '1e3'], ['count' => '0010']] as $page) {
    protocolRejects(protocolRequest(['method' => 'search', 'params' => (object) array_merge(['name' => '孙燕姿'], $page)]), 'INVALID_PAGINATION');
}
foreach (['song', 'pic', 'lyric', 'playlist', 'comments'] as $method) {
    $request = KunMusicClientProtocol::decode(protocolRequest(['method' => $method]), 'application/json', 'POST');
    protocolCheck($request['params']['types'] === $method, $method . ' data operation accepted');
}
protocolRejects(protocolRequest(['method' => 'userlist', 'params' => (object) ['uid' => '../id']]), 'INVALID_ID');
$user = KunMusicClientProtocol::decode(protocolRequest(['method' => 'userlist', 'params' => (object) ['uid' => '123']]), 'application/json', 'POST');
protocolCheck($user['params']['uid'] === '123', 'User playlist request accepted');
protocolCheck(KunMusicClientProtocol::requestIdFromBody(protocolRequest()) === 'fixture-1', 'Safe request ID survives validation failure');
protocolCheck(KunMusicClientProtocol::requestIdFromBody(protocolRequest(['requestId' => '<secret>'])) === null, 'Invalid request ID not reflected');
protocolCheck(KunMusicClientProtocol::requestIdFromBody('{') === null, 'Malformed JSON has no reflected request ID');

$netease = KunMusicClientProtocol::configuredSongApi('netease', 'MUSIC_U=fixture-session; __csrf=fixture-csrf', '');
protocolCheck(str_contains($netease->header['Cookie'], 'MUSIC_U=fixture-session')
    && str_contains($netease->header['Cookie'], 'appver=8.2.30'), 'Song metadata preserves NetEase client metadata and credentials');
$kugou = KunMusicClientProtocol::configuredSongApi('kugou', '', 't=fixture-token; KugooID=123; kg_mid=fixture-mid; kg_dfid=fixture-dfid');
protocolCheck($kugou->kugou_userid === '123' && $kugou->kugou_token === 'fixture-token'
    && $kugou->kugou_mid === 'fixture-mid' && $kugou->kugou_dfid === 'fixture-dfid', 'Song metadata preserves Kugou VIP configuration');
protocolCheck(!str_contains($kugou->header['Cookie'] ?? '', 'fixture-token'), 'Legacy metadata requests never send Kugou login Cookie');

$success = KunMusicClientProtocol::encodeApiResponse('{"url":"https://audio.example.test/track.mp3"}', 'response-1', 200);
$response = json_decode($success['body'], true, 16, JSON_THROW_ON_ERROR);
protocolCheck($success['status'] === 200 && $response['protocol'] === 'kunmusic/1' && $response['ok'] === true
    && $response['requestId'] === 'response-1' && $response['result']['url'] === 'https://audio.example.test/track.mp3', 'Successful API response envelope');
$empty = KunMusicClientProtocol::encodeApiResponse('[]', 'empty', 200);
protocolCheck(json_decode($empty['body'], true)['result'] === [], 'Empty search results remain valid');
foreach (['', 'null', '<html>upstream internal diagnostic</html>', '"scalar"'] as $invalid) {
    $response = KunMusicClientProtocol::encodeApiResponse($invalid, 'bad-upstream', 200);
    protocolCheck($response['status'] === 502 && !str_contains($response['body'], 'diagnostic'), 'Malformed upstream response hidden');
}
foreach ([400, 403, 500, 503] as $status) {
    $response = KunMusicClientProtocol::encodeApiResponse('{"error":"secret credential detail"}', 'error-1', $status);
    $decoded = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
    protocolCheck($response['status'] === $status && $decoded['ok'] === false
        && $decoded['requestId'] === 'error-1' && !str_contains($response['body'], 'secret'), 'Error is bounded and carries request ID');
}
echo json_encode(['clientProtocolChecks' => $checks, 'passed' => true]) . PHP_EOL;
