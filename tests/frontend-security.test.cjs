const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function setup() {
    const elements = new Map(), requests = [], dialogs = [], messages = [];
    class Element {
        constructor(markup = '') { this.markup = markup; this.children = []; this[0] = {}; this.length = 1; }
        html(value) { if(value === undefined) return this.markup; this.markup = String(value); this.children = []; return this; }
        append(value) { this.children.push(value instanceof Element ? value.markup : value); return this; }
        text(value) { this.textValue = value; return this; }
        attr() { return this; }
        scrollTop() { return this; }
        remove() { return this; }
    }
    const $ = value => {
        if(typeof value === 'function') return;
        if(typeof value === 'string' && value.startsWith('<')) return new Element(value);
        if(!elements.has(value)) elements.set(value, new Element());
        return elements.get(value);
    };
    $.ajax = request => requests.push(request);
    const context = { $, layui: { use() {} }, URL,
        navigator: { userAgent: 'Test' }, document: { documentElement: { clientWidth: 1200 } },
        window: { location: new URL('http://localhost:520/') },
        mkPlayer: { method: 'POST', api: 'api.php', dataType: 'json', loadcount: 20 },
        rem: { mainList: new Element(), sheetList: new Element(), source: 'netease', loadPage: 1,
            wd: '', playlist: 1, playid: 0, audio: [{ duration: 10 }] },
        musicList: [{ item: [] }, { item: [] }],
        console, setTimeout: () => 1, clearInterval() {}, clearTimeout() {}
    };
    vm.createContext(context);
    for(const filename of ['functions.js', 'ajax.js', 'lyric.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../js', filename), 'utf8'), context, { filename });
    }
    context.layer = { msg: text => messages.push(text), open: value => dialogs.push(value), load: () => 1,
        close() {}, closeAll() {} };
    return { context, requests, dialogs, messages, elements };
}

const attack = '<img src=x onerror="window.injected=true">';

test('song metadata, detail dialogs, and shared titles remain text', () => {
    const { context: c, dialogs } = setup();
    c.addItem(1, attack, attack, attack);
    const row = c.rem.mainList.children[0];
    assert(!row.includes('<img'));
    assert.match(row, /&lt;img/);
    const song = { name: attack, artist: attack, album: attack, url: 'https://audio.example/song.mp3?x="&y=2' };
    c.musicList[0].item = [song];
    c.musicInfo(0, 0);
    assert(!dialogs[0].content.includes('<img'));
    c.ajaxShare(song);
    assert(!dialogs[1].content.includes('<img'));
    assert.match(dialogs[1].content, /https:\/\/audio\.example\/song\.mp3/);
    assert(!dialogs[1].content.includes('value="https://audio.example/song.mp3?x="'));
});

test('playlist names and image attributes cannot introduce markup', () => {
    const { context: c } = setup();
    c.addSheet(3, attack, 'javascript:alert(1)');
    const sheet = c.rem.sheetList.children[0];
    assert.equal((sheet.match(/<img/g) || []).length, 1);
    assert.match(sheet, /src="images\/player_cover\.png"/);
    assert.match(sheet, /&lt;img/);
    assert(!sheet.includes('src="javascript:'));
    c.addSheet(4, 'Rock & Roll', 'https://images.example/artwork.jpg?name="onerror="alert(1)');
    assert.match(c.rem.sheetList.children[1], /Rock &amp; Roll/);
    assert(!c.rem.sheetList.children[1].includes('"onerror="'));
});

test('lyrics and translations preserve literal text and malformed percent sequences', () => {
    const { context: c, elements } = setup();
    c.musicList[1].item = [{ id: 'track' }];
    c.lyricCallback('[00:00]' + attack + '\n[00:01]100% love', 'track', '[00:00]' + attack);
    const lines = elements.get('#lyric').children.join('');
    assert(!lines.includes('<img'));
    assert.equal((lines.match(/&lt;img/g) || []).length, 2);
    assert.match(lines, /100% love/);
    c.lyricTip(attack);
    assert(!elements.get('#lyric').markup.includes('<img'));
});

test('media URL validation blocks executable schemes and credential confusion', () => {
    const { context: c } = setup();
    for(const value of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
        'file:///etc/passwd', 'https://user:pass@audio.example/song.mp3', 'java\nscript:alert(1)']) {
        assert.equal(c.safeMediaUrl(value), '');
    }
    assert.equal(c.safeMediaUrl('//cdn.example/song.mp3'), 'http://cdn.example/song.mp3');
    assert.equal(c.safeMediaUrl('/images/cover.png'), 'http://localhost:520/images/cover.png');
    assert.equal(c.safeMediaUrl('https://cdn.example/a song.mp3'), 'https://cdn.example/a%20song.mp3');
});

test('search and download metadata are sent as values instead of query fragments', () => {
    const { context: c, requests } = setup();
    c.rem.wd = 'song&source=kugou';
    c.ajaxSearch();
    assert.equal(requests[0].data.name, 'song&source=kugou');
    assert.equal(requests[0].data.source, 'netease');
    const song = { name: 'a&source=kugou', artist: 'x&url=http://127.0.0.1/', source: 'netease',
        url: 'https://audio.example/song.mp3' };
    c.download(song);
    assert.equal(requests[1].data.name, song.name);
    assert.equal(requests[1].data.artist, song.artist);
    assert.equal(requests[1].data.source, 'netease');
});

test('download responses cannot navigate an iframe to an external page or script', () => {
    const { context: c, requests, elements } = setup();
    c.download({ name: 'song', artist: 'artist', source: 'netease', url: 'https://audio.example/song.mp3' });
    for(const url of ['javascript:alert(1)', 'https://attacker.example/temp/song.mp3']) {
        requests[0].success({ code: 1, url });
        assert(!elements.has('body'));
    }
    requests[0].success({ code: 1, url: 'http://localhost:520/temp/netease/song.mp3' });
    assert.equal(elements.get('body').children.length, 1);
});
