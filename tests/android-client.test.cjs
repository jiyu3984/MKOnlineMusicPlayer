const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../js/android-client.js'), 'utf8');

function setup({ origin = 'https://yy.kunps.cn/__app__/', native = true } = {}) {
    const commands = [], timers = [], prefilters = [], requests = [], cover = new EventTarget();
    const seen = [], played = [], storage = new Map();
    const document = new EventTarget();
    document.createElement = () => Object.assign(new EventTarget(), { attributes: {},
        setAttribute(key, value) { this.attributes[key] = value; } });
    document.getElementById = () => cover;
    const $ = callback => { if(typeof callback === 'function') callback(); };
    $.ajaxPrefilter = callback => prefilters.push(callback);
    $.ajax = value => requests.push(value);
    const music = [0, 1, 2].map(id => ({ id: String(id + 10), source: 'netease', name: 'Song ' + id,
        artist: 'Artist', album: 'Album', pic_id: 'pic-' + id, lyric_id: 'lyric-' + id,
        url: 'https://cdn.example/' + id + '.mp3', pic: 'https://cdn.example/' + id + '.jpg' }));
    const context = { document, Event, URL, location: new URL(origin), console,
        jQuery: $, mkPlayer: { api: 'api.php' }, musicList: [{ item: [] }, { item: music }, { item: [] }],
        rem: { playlist: 1, playid: 0, order: 2, paused: true },
        newPlaybackAttempt() { return context.rem.playbackAttempt = { wanted: true, failed: false }; },
        playerSavedata() {}, addHis() {}, refreshList() {},
        changeCover(value) { seen.push(value); }, ajaxLyric() {}, lyricCallback() {},
        music_bar: { lock() {} }, setTimeout(callback) { timers.push(callback); },
        sessionStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) },
        playList(index) { played.push(index); }, dataBox() {},
        layer: { msg() {} }
    };
    context.window = context;
    if(native) context.KunMusicAndroid = { postMessage: body => commands.push(JSON.parse(body)) };
    vm.createContext(context);
    vm.runInContext(source, context, { filename: 'android-client.js' });
    const audio = context.KunMusicNative.createAudio();
    return { context, audio, commands, timers, prefilters, requests, seen, played, cover, music,
        emit: event => context.KunMusicNative.onEvent(event),
        load() { audio.setMusic(music[0]); return commands.at(-1); } };
}

test('ordinary browsers and untrusted origins do not enable native audio or protocol hooks', () => {
    for(const options of [{ native: false }, { origin: 'https://attacker.example/' }, { origin: 'http://yy.kunps.cn/' }]) {
        const h = setup(options);
        assert.equal(h.audio, null);
        assert.equal(h.context.KunMusicNative.isAvailable, false);
        assert.equal(h.prefilters.length, 0);
        assert.equal(h.commands.length, 0);
        assert.equal(h.context.KunMusicNative.requestState(), false);
    }
});

test('activity readiness can request the current native playback state', () => {
    const h = setup(), load = h.load();
    const count = h.commands.length;
    assert.equal(h.context.KunMusicNative.requestState(), true);
    assert.equal(h.commands.length, count + 1);
    assert.equal(h.commands.at(-1).action, 'state');
    assert.equal(h.commands.at(-1).trackId, load.trackId);
});

test('native audio sends a bounded queue before load and never starts an HTML audio source', () => {
    const h = setup(), load = h.load();
    assert.equal(h.commands.at(-2).action, 'queue');
    assert.equal(h.commands.at(-2).index, 0);
    assert.equal(h.commands.at(-2).items.length, 3);
    assert.equal(load.action, 'load');
    assert.equal(load.trackId, h.commands.at(-2).trackId);
    assert.equal(h.audio.src, h.music[0].url);
    assert.equal(h.audio.attributes.src, undefined);
    for(const url of ['javascript:alert(1)', 'https://127.0.0.1/song', 'https://localhost/song', 'https://user:pass@cdn.example/a', 'https://cdn.example:8080/a']) {
        assert.throws(() => h.audio.setMusic({ ...h.music[0], url }));
    }
});

test('old track events and transitions from another queue cannot alter newly selected playback', async () => {
    const h = setup(), old = h.load();
    const pending = h.audio.play().catch(error => error.name);
    h.audio.setMusic(h.music[1]);
    const current = h.commands.at(-1);
    assert.equal(await pending, 'AbortError');
    h.emit({ type: 'error', trackId: old.trackId, errorCode: 4 });
    h.emit({ type: 'trackchange', trackId: 'native-old', queueId: old.trackId, music: h.music[2], index: 2,
        src: h.music[2].url, paused: false });
    assert.equal(h.audio.src, h.music[1].url);
    assert.equal(h.audio.error, null);
    assert.equal(h.seen.length, 0);
    const next = h.audio.play();
    h.emit({ type: 'playing', trackId: current.trackId, queueId: current.trackId, currentTime: 3,
        duration: 100, paused: false, src: h.music[1].url });
    await next;
    assert.equal(h.audio.currentTime, 3);
    assert.equal(h.audio.duration, 100);
    assert.equal(h.audio.paused, false);
});

test('native background track changes update the web selection without issuing a second load', () => {
    const h = setup(), load = h.load();
    const count = h.commands.filter(item => item.action === 'load').length;
    h.emit({ type: 'trackchange', trackId: 'native-next', queueId: load.trackId, index: 1,
        music: h.music[1], src: h.music[1].url, currentTime: 0, duration: 180, paused: false });
    assert.equal(h.context.rem.playid, 1);
    assert.equal(h.context.rem.playbackAttempt.source, h.music[1].url);
    assert.equal(h.seen.at(-1).id, h.music[1].id);
    assert.equal(h.commands.filter(item => item.action === 'load').length, count);
    h.audio.next();
    assert.equal(h.commands.at(-1).action, 'next');
    assert.equal(h.commands.at(-1).trackId, 'native-next');
});

test('recreated activity restores the native service queue without restarting audio', () => {
    const h = setup();
    h.emit({ type: 'state', trackId: 'native-restored', queueId: 'old-session', index: 2,
        queue: { items: h.music, index: 2, order: 3 }, music: h.music[2], src: h.music[2].url,
        currentTime: 74, duration: 240, paused: false, volume: .7 });
    assert.equal(h.context.rem.playid, 2);
    assert.equal(h.context.rem.order, 3);
    assert.equal(h.audio.currentTime, 74);
    assert.equal(h.audio.paused, false);
    assert.equal(h.context.musicList[1].item.length, 3);
    assert.equal(h.commands.some(item => item.action === 'load'), false);
});

test('foreground snapshots recover a missed background transition and resolved URL', () => {
    const h = setup(), load = h.load();
    const resolvedUrl = h.music[2].url;
    h.emit({ type: 'playing', trackId: load.trackId, currentTime: 70, duration: 300, paused: false });
    h.emit({ type: 'state', trackId: 'native-background', queueId: load.trackId, index: 2,
        music: { ...h.music[2], url: '' }, src: '', paused: false, currentTime: 4 });
    assert.equal(h.context.rem.playid, 2);
    assert.equal(h.audio.currentTime, 4);
    assert(Number.isNaN(h.audio.duration));
    assert.equal(h.audio.readyState, 0);
    assert.equal(h.audio.src, 'kunmusic:native-background');
    h.emit({ type: 'state', trackId: 'native-background', queueId: load.trackId, src: resolvedUrl,
        currentTime: 5, duration: 150, paused: false });
    assert.equal(h.context.rem.playbackAttempt.source, resolvedUrl);
    assert.equal(h.audio.currentSrc, resolvedUrl);
    assert.equal(h.audio.duration, 150);
});

test('playing an already active native track resolves without waiting for a duplicate event', async () => {
    const h = setup(), load = h.load();
    h.emit({ type: 'playing', trackId: load.trackId, duration: 100, paused: false });
    await h.audio.play();
    assert.equal(h.commands.at(-1).action, 'play');
});

test('long queue metadata stays below the native limit while retaining the selected item', () => {
    const h = setup();
    h.context.musicList[1].item = Array.from({ length: 1200 }, (_, index) => ({
        ...h.music[0], id: String(index), name: '音'.repeat(512), artist: '乐'.repeat(512), album: '歌'.repeat(512)
    }));
    h.context.rem.playid = 950;
    h.audio.setMusic(h.context.musicList[1].item[950]);
    const queue = h.commands.at(-2);
    assert(Buffer.byteLength(JSON.stringify(queue), 'utf8') < 1000000);
    assert.equal(queue.items[queue.index].id, '950');
    const next = queue.items[queue.index + 1];
    h.emit({ type: 'trackchange', trackId: 'native-window-next', queueId: queue.trackId,
        index: queue.index + 1, music: next, src: 'https://cdn.example/next.mp3', paused: false });
    assert.equal(h.context.rem.playid, 951);
});

test('handled native errors do not reject play as a second queue failure', async () => {
    const h = setup(), load = h.load(), events = [];
    h.audio.addEventListener('error', event => events.push(event.nativeHandled));
    const result = h.audio.play().catch(error => error.name);
    h.emit({ type: 'error', trackId: load.trackId, handled: true, errorCode: 4, paused: true });
    assert.equal(await result, 'AbortError');
    assert.deepEqual(events, [true]);
    assert.equal(h.audio.paused, true);
});

test('seeking, volume and delayed artwork are forwarded to the current native track', () => {
    const h = setup(), load = h.load();
    h.emit({ type: 'loadedmetadata', trackId: load.trackId, duration: 100 });
    h.audio.currentTime = 300;
    assert.equal(h.commands.at(-1).currentTime, 100);
    h.audio.volume = -1;
    assert.equal(h.commands.at(-1).volume, 0);
    h.cover.src = 'https://cdn.example/new-cover.jpg';
    const before = h.commands.length;
    h.cover.dispatchEvent(new Event('load'));
    assert.equal(h.commands.length, before, 'a cover from the previous track must not update native metadata');
    h.music[0].pic = h.cover.src;
    h.cover.dispatchEvent(new Event('load'));
    assert.equal(h.commands.at(-1).action, 'metadata');
    assert.equal(h.commands.at(-1).artwork, h.cover.src);
});

test('client API envelope validates request correlation and leaves unrelated requests untouched', () => {
    const h = setup();
    assert.equal(h.context.mkPlayer.api, 'https://yy.kunps.cn/client-api.php');
    const options = { url: h.context.mkPlayer.api };
    h.prefilters[0](options, { data: { types: 'search', source: 'netease', name: 'song&source=kugou' } });
    const request = JSON.parse(options.data);
    assert.equal(request.protocol, 'kunmusic/1');
    assert.equal(request.method, 'search');
    assert.equal(request.params.name, 'song&source=kugou');
    const convert = options.converters['text json'];
    assert.deepEqual(Array.from(convert(JSON.stringify({ protocol: 'kunmusic/1', requestId: request.requestId, ok: true, result: [1, 2] }))), [1, 2]);
    assert.throws(() => convert(JSON.stringify({ protocol: 'kunmusic/1', requestId: 'wrong', ok: true, result: [] })));
    assert.throws(() => convert(JSON.stringify({ protocol: 'kunmusic/1', requestId: request.requestId, ok: false })));
    const other = { url: 'https://other.example/' };
    h.prefilters[0](other, { data: { a: 1 } });
    assert.equal(other.data, undefined);
});

test('deep links accept music IDs only and play one correlated song once', () => {
    const h = setup();
    h.context.KunMusicNative.openSong({ source: 'netease', id: 'https://attacker.example/' });
    h.context.KunMusicNative.openSong({ source: 'unsupported', id: '123' });
    assert.equal(h.requests.length, 0);
    h.context.KunMusicNative.openSong({ source: 'netease', id: '123', requestId: 'link-123' });
    h.context.KunMusicNative.openSong({ source: 'netease', id: '123', requestId: 'link-123' });
    assert.equal(h.requests.length, 1);
    h.requests[0].success([{ id: '123', name: 'Shared Song', artist: ['A', 'B'], album: 'Album' }]);
    assert.deepEqual(h.played, [3]);
    assert.equal(h.context.musicList[1].item[3].artist, 'A / B');
    assert.equal(h.context.musicList[1].item[3].source, 'netease');
});

test('Tencent shared songs preserve their alphanumeric ID and source', () => {
    const h = setup(), id = '003rJqfR1t31qZ';
    h.context.KunMusicNative.openSong({ source: 'tencent', id, requestId: 'qq-share' });
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].data.types, 'song');
    assert.equal(h.requests[0].data.source, 'tencent');
    assert.equal(h.requests[0].data.id, id);
    h.requests[0].success([{ id, name: 'Tencent shared song', artist: ['Artist'] }]);
    assert.deepEqual(h.played, [3]);
    assert.equal(h.context.musicList[1].item[3].id, id);
    assert.equal(h.context.musicList[1].item[3].source, 'tencent');
});

test('shared song IDs respect the 192-character client API boundary', () => {
    const h = setup(), id = 'a'.repeat(192);
    h.context.KunMusicNative.openSong({ source: 'tencent', id: id + 'a' });
    assert.equal(h.requests.length, 0);
    h.context.KunMusicNative.openSong({ source: 'tencent', id });
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].data.id, id);
    h.requests[0].success([{ id, name: 'Maximum ID', artist: ['Artist'] }]);
    assert.equal(h.context.musicList[1].item[3].id, id);
});

test('a late deep-link lookup cannot replace the newer requested song', () => {
    const h = setup();
    h.context.KunMusicNative.openSong({ source: 'netease', id: '123', requestId: 'link-a' });
    h.context.KunMusicNative.openSong({ source: 'netease', id: '456', requestId: 'link-b' });
    h.requests[1].success([{ id: '456', name: 'New intent', artist: ['Artist'] }]);
    h.requests[0].success([{ id: '123', name: 'Old intent', artist: ['Artist'] }]);
    assert.deepEqual(h.played, [3]);
    assert.equal(h.context.musicList[1].item[3].id, '456');
});
