const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../js/player.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function setup() {
    const listeners = new Map(), plays = [], lookups = [], messages = [];
    const audio = { src: '', currentSrc: '', error: null, paused: true,
        addEventListener(type, handler) { listeners.set(type, handler); },
        pause() { this.paused = true; listeners.get('pause')?.(); },
        play() {
            this.paused = false;
            listeners.get('play')?.();
            let resolve, reject;
            const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
            plays.push({ resolve, reject, src: this.src });
            return promise;
        }
    };
    const chain = { 0: audio, appendTo() { return this; }, on(type, handler) { listeners.set(type, handler); return this; },
        attr(name, value) { if(name === 'src') { audio.src = value; audio.currentSrc = ''; audio.error = null; } return this; },
        addClass() { return this; }, removeClass() { return this; } };
    const context = { $() { return chain; }, navigator: { userAgent: 'Test' },
        document: { title: 'Music' }, window: {}, console,
        setInterval: () => 1, clearInterval() {},
        layer: { msg: message => messages.push(message) },
        volume_bar: { percent: 0.5 }, music_bar: { goto() {}, lock() {} },
        musicList: [{ item: [] }, { item: Array.from({ length: 15 }, (_, id) => ({ id, source: 'netease', name: 'Song ' + id, artist: 'Artist', url: '' })) }],
        refreshList() {}, refreshSheet() {}, addHis() {}, loadList() {}, changeCover() {},
        ajaxLyric() {}, lyricCallback() {}, comments() {}, playerSavedata() {},
        ajaxUrl(music, callback) { lookups.push({ music, callback }); }
    };
    vm.createContext(context);
    vm.runInContext(source, context, { filename: 'player.js' });
    Object.assign(context.rem, { playlist: 1, dislist: 1, playid: 0, paused: true,
        isMobile: false, webTitle: 'Music', errCount: 0 });
    context.initAudio();
    function answer(index = lookups.length - 1, url) {
        const lookup = lookups[index];
        lookup.music.url = url || 'https://audio.example/' + lookup.music.id + '.mp3';
        lookup.callback(lookup.music);
    }
    function emitError() {
        audio.currentSrc = audio.src;
        audio.error = { code: 4 };
        listeners.get('error')({ type: 'error' });
    }
    return { context, audio, plays, lookups, messages, listeners, answer, emitError };
}

test('asynchronous rejection and media error count once and skip only one track', async () => {
    const h = setup();
    h.context.playList(0); h.answer();
    h.emitError();
    assert.equal(h.context.rem.errCount, 1);
    assert.equal(h.context.rem.playid, 1);
    h.plays[0].reject({ name: 'NotSupportedError' });
    await tick();
    assert.equal(h.context.rem.errCount, 1);
    assert.equal(h.lookups.length, 2);
    assert.equal(h.context.rem.playid, 1);
});

test('a media error following a rejected promise does not fail the pending next track', async () => {
    const h = setup();
    h.context.playList(0); h.answer();
    h.plays[0].reject({ name: 'NotSupportedError' }); await tick();
    h.emitError();
    assert.equal(h.context.rem.errCount, 1);
    assert.equal(h.context.rem.playid, 1);
    assert.equal(h.lookups.length, 2);
    h.answer();
    h.audio.currentSrc = h.audio.src;
    h.listeners.get('playing')();
    assert.equal(h.context.rem.errCount, 0);
});

test('failed sources stop after ten attempts instead of resetting the streak on src assignment', async () => {
    const h = setup();
    h.context.playList(0);
    for(let failure = 0; failure < 10; failure++) {
        h.answer();
        h.plays[failure].reject({ name: 'NotSupportedError' });
        await tick();
    }
    assert.equal(h.plays.length, 10);
    assert.equal(h.lookups.length, 10);
    assert.equal(h.context.rem.errCount, 10);
    assert.equal(h.context.rem.paused, true);
    h.emitError();
    assert.equal(h.lookups.length, 10);
    assert(h.messages.at(-1).includes('播放已停止'));
});

test('play event and resolved promise do not reset failures until actual playing', async () => {
    const h = setup();
    h.context.rem.errCount = 4;
    h.context.playList(0); h.answer();
    assert.equal(h.context.rem.errCount, 4);
    h.plays[0].resolve(); await tick();
    assert.equal(h.context.rem.errCount, 4);
    h.audio.currentSrc = h.audio.src;
    h.listeners.get('playing')();
    assert.equal(h.context.rem.errCount, 0);
});

test('autoplay denial keeps the selected song and offers manual resume', async () => {
    const h = setup();
    h.context.playList(0); h.answer();
    h.plays[0].reject({ name: 'NotAllowedError' }); await tick();
    assert.equal(h.context.rem.playid, 0);
    assert.equal(h.context.rem.errCount, 0);
    assert.equal(h.context.rem.paused, true);
    assert.equal(h.lookups.length, 1);
    assert(h.messages.at(-1).includes('点击播放'));
    h.context.pause();
    assert.equal(h.plays.length, 2);
    assert.equal(h.context.rem.playid, 0);
});

test('pause and track changes discard late lookup results and play rejections', async () => {
    const h = setup();
    h.context.playList(0);
    h.context.pause();
    h.answer(0);
    assert.equal(h.plays.length, 0);
    h.context.playList(1); h.answer(1);
    h.context.playList(2); h.answer(2);
    h.plays[0].reject({ name: 'NotSupportedError' }); await tick();
    assert.equal(h.context.rem.playid, 2);
    assert.equal(h.lookups.length, 3);
    assert.equal(h.context.rem.errCount, 0);
    h.context.pause();
    h.plays[1].reject({ name: 'NotSupportedError' }); await tick();
    assert.equal(h.context.rem.errCount, 0);
    assert.equal(h.context.rem.paused, true);
});

test('resume during a pending track lookup reloads the selected track instead of the old audio', () => {
    const h = setup();
    h.context.playList(0); h.answer();
    const previousSource = h.audio.src;
    h.context.playList(1);
    h.context.pause();
    h.context.pause();
    assert.equal(h.lookups.length, 3);
    assert.equal(h.plays.length, 1);
    assert.equal(h.context.rem.playid, 1);
    h.answer(1);
    assert.equal(h.plays.length, 1);
    assert.equal(h.audio.src, previousSource);
    h.answer(2);
    assert.equal(h.plays.length, 2);
    assert.equal(h.audio.src, 'https://audio.example/1.mp3');
    assert.equal(h.context.rem.playid, 1);
});

test('AbortError is ignored and first manual play starts only one playback', async () => {
    const h = setup();
    h.context.rem.playlist = undefined;
    h.context.pause();
    assert.equal(h.lookups.length, 1);
    assert.equal(h.plays.length, 0);
    h.answer();
    assert.equal(h.plays.length, 1);
    h.plays[0].reject({ name: 'AbortError' }); await tick();
    assert.equal(h.context.rem.errCount, 0);
    assert.equal(h.lookups.length, 1);
});
