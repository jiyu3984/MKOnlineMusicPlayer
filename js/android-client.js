/* The Android app owns audio playback; browsers keep using HTMLAudioElement. */
(function(window, document) {
    'use strict';

    var bridge = window.KunMusicAndroid;
    var available = window.location.origin === 'https://yy.kunps.cn' &&
        bridge && typeof bridge.postMessage === 'function';
    var audio, current, pendingPlay, queueItems = [], queueOffset = 0;
    var sequence = 0, requestSequence = 0, session = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    var protocolInstalled = false, openedSongs = [], openSongGeneration = 0;
    var state = { trackId: '', queueId: '', src: '', paused: true, duration: NaN,
        currentTime: 0, volume: 1, error: null, readyState: 0 };

    function text(value, max) {
        return String(value == null ? '' : value).slice(0, max || 512);
    }

    function mediaUrl(value) {
        if(typeof value !== 'string' || !value || value.length > 8192 || value === 'err' || /[\u0000-\u001f\u007f]/.test(value)) return '';
        try {
            var parsed = new URL(value, window.location.href);
            if(parsed.protocol === 'http:') parsed.protocol = 'https:';
            if(parsed.protocol !== 'https:' || parsed.username || parsed.password ||
                (parsed.port && parsed.port !== '443')) return '';
            var host = parsed.hostname.toLowerCase();
            if(!host || host === 'localhost' || host.endsWith('.localhost') ||
                host.endsWith('.local') || host.indexOf(':') !== -1 || /^[\d.]+$/.test(host)) return '';
            return parsed.href;
        } catch(error) { return ''; }
    }

    function command(action, values) {
        if(!available) return false;
        var message = { action: action, trackId: state.trackId };
        Object.keys(values || {}).forEach(function(key) { message[key] = values[key]; });
        bridge.postMessage(JSON.stringify(message));
        return true;
    }

    function emit(type, handled) {
        if(!audio) return;
        var event = new Event(type);
        if(handled) event.nativeHandled = true;
        audio.dispatchEvent(event);
    }

    function installProtocol() {
        if(protocolInstalled || !available || !window.jQuery || !window.mkPlayer) return;
        var endpoint = 'https://yy.kunps.cn/client-api.php';
        window.mkPlayer.api = endpoint;
        window.mkPlayer.method = 'POST';
        window.mkPlayer.dataType = 'json';
        window.jQuery.ajaxPrefilter(function(options, original) {
            if(options.url !== endpoint) return;
            var originalData = original.data || {}, params = {};
            if(typeof originalData !== 'object' || Array.isArray(originalData)) throw new Error('Invalid client API parameters');
            Object.keys(originalData).forEach(function(key) { if(key !== 'types') params[key] = originalData[key]; });
            var requestId = 'web-' + session + '-' + (++requestSequence);
            options.type = 'POST';
            options.contentType = 'application/json; charset=UTF-8';
            options.processData = false;
            options.data = JSON.stringify({ protocol: 'kunmusic/1', requestId: requestId,
                method: text(originalData.types, 32), params: params });
            options.converters = options.converters || {};
            options.converters['text json'] = function(body) {
                var envelope = JSON.parse(body);
                if(!envelope || envelope.protocol !== 'kunmusic/1' || envelope.requestId !== requestId || envelope.ok !== true) {
                    throw new Error('The music server returned an invalid response');
                }
                return envelope.result;
            };
        });
        protocolInstalled = true;
    }

    function openSong(request) {
        if(!available || !request || typeof request !== 'object') return;
        var sources = ['netease', 'tencent', 'kugou', 'kuwo', 'baidu', 'xiami'];
        if(sources.indexOf(request.source) === -1 || typeof request.id !== 'string' ||
            !/^[A-Za-z0-9_-]{1,192}$/.test(request.id)) return;
        var requestId = typeof request.requestId === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(request.requestId)
            ? request.requestId : request.source + ':' + request.id;
        try {
            var stored = JSON.parse(window.sessionStorage.getItem('kunmusic-opened-links') || '[]');
            if(Array.isArray(stored) && stored.indexOf(requestId) !== -1) return;
            if(Array.isArray(stored)) openedSongs = stored.concat(openedSongs).filter(function(value, index, all) {
                return typeof value === 'string' && all.indexOf(value) === index;
            }).slice(-31);
        } catch(error) {}
        if(openedSongs.indexOf(requestId) !== -1) return;
        openedSongs.push(requestId);
        if(openedSongs.length > 32) openedSongs.shift();
        var generation = ++openSongGeneration;
        window.jQuery(function() {
            installProtocol();
            window.jQuery.ajax({ url: window.mkPlayer.api, type: 'POST', dataType: 'json',
                data: { types: 'song', source: request.source, id: request.id },
                success: function(result) {
                    if(generation !== openSongGeneration) return;
                    var music = Array.isArray(result) ? result[0] : null;
                    if(!music || !music.id || String(music.id) !== request.id) return;
                    var item = { id: String(music.id), source: request.source, name: text(music.name),
                        artist: text(Array.isArray(music.artist) ? music.artist.join(' / ') : music.artist),
                        album: text(music.album), url_id: text(music.url_id || music.id, 192),
                        pic_id: text(music.pic_id || music.id, 192), lyric_id: text(music.lyric_id || music.id, 192),
                        pic: null, url: '' };
                    var list = window.musicList[1].item;
                    var index = -1;
                    for(var i = 0; i < list.length; i++) if(sameMusic(list[i], item)) { index = i; break; }
                    if(index < 0) { index = list.length; list.push(item); }
                    window.rem.playlist = 1;
                    window.playList(index);
                    window.dataBox('player');
                    try { window.sessionStorage.setItem('kunmusic-opened-links', JSON.stringify(openedSongs)); } catch(error) {}
                },
                error: function() {
                    var index = openedSongs.indexOf(requestId);
                    if(index !== -1) openedSongs.splice(index, 1);
                    if(generation === openSongGeneration && window.layer) window.layer.msg('歌曲读取失败，请稍后重试');
                }
            });
        });
    }

    function rejectPlay(name) {
        if(!pendingPlay) return;
        var pending = pendingPlay;
        pendingPlay = null;
        var error = new Error(name === 'AbortError' ? 'Playback was interrupted' : 'Native playback failed');
        error.name = name;
        pending.reject(error);
    }

    function resolvePlay() {
        if(!pendingPlay) return;
        var pending = pendingPlay;
        pendingPlay = null;
        pending.resolve();
    }

    function queueMusic(music) {
        return { id: text(music.id, 192), source: text(music.source, 32),
            url_id: text(music.url_id, 192), pic_id: text(music.pic_id, 192),
            lyric_id: text(music.lyric_id, 192), name: text(music.name),
            artist: text(music.artist), album: text(music.album), pic: mediaUrl(music.pic) };
    }

    function sameMusic(a, b) {
        return a && b && String(a.id) === String(b.id) && a.source === b.source;
    }

    function setMusic(music) {
        var url = mediaUrl(music && music.url);
        if(!url) throw new Error('The Android player requires a public HTTPS audio URL');
        rejectPlay('AbortError');
        current = music;
        state.trackId = session + '-' + (++sequence);
        state.queueId = state.trackId;
        state.src = url;
        state.currentTime = 0;
        state.duration = NaN;
        state.paused = true;
        state.error = null;
        state.readyState = 0;
        var all = window.musicList && window.musicList[1] && window.musicList[1].item;
        var index = window.rem && Number.isInteger(window.rem.playid) ? window.rem.playid : 0;
        if(!Array.isArray(all) || !sameMusic(all[index], music)) { all = [music]; index = 0; }
        queueItems = all;
        queueOffset = Math.max(0, Math.min(index - 499, all.length - 1000));
        var items = all.slice(queueOffset, queueOffset + 1000).map(queueMusic);
        var queueIndex = index - queueOffset;
        // Keep UTF-8 JSON below the native bridge limit, including unusually long metadata.
        while(items.length > 1 && JSON.stringify(items).length > 180000) {
            var count = Math.ceil(items.length / 2);
            var start = Math.max(0, Math.min(queueIndex - Math.floor(count / 2), items.length - count));
            items = items.slice(start, start + count);
            queueOffset += start;
            queueIndex -= start;
        }
        command('queue', { items: items, index: queueIndex,
            order: window.rem && [1, 2, 3].indexOf(window.rem.order) !== -1 ? window.rem.order : 2 });
        command('load', { url: url, title: text(music.name), artist: text(music.artist),
            album: text(music.album), artwork: mediaUrl(music.pic),
            id: text(music.id, 192), source: text(music.source, 32) });
    }

    function nativeMusic(event) {
        var music = event.music;
        if(!music || typeof music !== 'object' || !music.id || !music.source) return null;
        var copy = queueMusic(music);
        copy.url = mediaUrl(event.src || music.url);
        copy.pic = mediaUrl(music.pic || music.artwork);
        return copy;
    }

    function synchronizeTrack(event, restoring) {
        var music = nativeMusic(event);
        if(!music || !window.rem || !window.musicList || !window.musicList[1]) return false;
        var nativeIndex = Number.isInteger(event.index) ? event.index : event.queueIndex;
        var index = Number.isInteger(nativeIndex) ? nativeIndex + queueOffset : -1;
        if(restoring) {
            var stored = event.queue && Array.isArray(event.queue.items) ? event.queue.items : event.items;
            queueItems = Array.isArray(stored) ? stored.map(queueMusic) : [music];
            queueOffset = 0;
            index = Number.isInteger(nativeIndex) ? nativeIndex : 0;
            window.musicList[1].item = queueItems;
            window.rem.playlist = 1;
        }
        if(!sameMusic(queueItems[index], music)) {
            index = -1;
            for(var i = 0; i < queueItems.length; i++) {
                if(sameMusic(queueItems[i], music)) { index = i; break; }
            }
            if(index === -1) { index = queueItems.length; queueItems.push(music); }
        }
        Object.keys(music).forEach(function(key) { queueItems[index][key] = music[key]; });
        current = queueItems[index];
        window.musicList[1].item = queueItems;
        window.rem.playid = index;
        if(!window.musicList[window.rem.playlist] ||
            !sameMusic(window.musicList[window.rem.playlist].item[index], current)) window.rem.playlist = 1;
        var order = event.queue && event.queue.order || event.order;
        if([1, 2, 3].indexOf(order) !== -1) window.rem.order = order;
        var attempt = window.newPlaybackAttempt();
        attempt.source = state.src;
        attempt.wanted = !state.paused;
        window.rem.paused = state.paused;
        if(typeof window.playerSavedata === 'function') window.playerSavedata('playing', queueItems);
        if(typeof window.addHis === 'function') window.addHis(current);
        if(typeof window.changeCover === 'function') window.changeCover(current);
        if(typeof window.ajaxLyric === 'function') window.ajaxLyric(current, window.lyricCallback);
        if(typeof window.refreshList === 'function') window.refreshList();
        if(window.music_bar) window.music_bar.lock(false);
        return true;
    }

    function onEvent(event) {
        if(!audio || !available) return;
        if(typeof event === 'string') {
            if(event.length > 1500000) return;
            try { event = JSON.parse(event); } catch(error) { return; }
        }
        if(!event || typeof event !== 'object' || typeof event.type !== 'string') return;
        var restoring = event.type === 'state' && !state.trackId && typeof event.trackId === 'string' && event.trackId;
        if(typeof event.trackId !== 'string' || !event.trackId) return;
        var changed = (event.type === 'trackchange' || event.type === 'state') && event.trackId !== state.trackId;
        if(changed && !restoring && (!state.queueId || event.queueId !== state.queueId)) return;
        if(!restoring && !changed && event.trackId !== state.trackId) return;
        if(restoring || changed) {
            if(!nativeMusic(event)) return;
            rejectPlay('AbortError');
            state.trackId = event.trackId;
            state.queueId = event.queueId || event.trackId;
            state.src = mediaUrl(event.src) || 'kunmusic:' + event.trackId;
            state.currentTime = 0;
            state.duration = NaN;
            state.paused = true;
            state.readyState = 0;
            state.error = null;
        }
        var oldPaused = state.paused;
        if(typeof event.src === 'string') {
            var beforeSource = state.src;
            state.src = mediaUrl(event.src) || state.src;
            if(state.src !== beforeSource && window.rem && window.rem.playbackAttempt &&
                window.rem.playbackAttempt.source === beforeSource) window.rem.playbackAttempt.source = state.src;
        }
        if(Number.isFinite(event.currentTime) && event.currentTime >= 0) state.currentTime = event.currentTime;
        if(Number.isFinite(event.duration) && event.duration >= 0) state.duration = event.duration;
        if(Number.isFinite(event.volume)) state.volume = Math.max(0, Math.min(1, event.volume));
        if(typeof event.paused === 'boolean') state.paused = event.paused;
        if(Number.isFinite(state.duration)) state.readyState = 1;
        if((restoring || changed) && !synchronizeTrack(event, !!restoring)) return;
        if(event.type === 'error') {
            state.error = { code: Number(event.errorCode) || 4 };
            state.paused = true;
            rejectPlay(event.handled === true ? 'AbortError' : 'NotSupportedError');
            emit('pause');
            emit('error', event.handled === true);
        } else if(event.type === 'playing') {
            state.paused = false;
            state.readyState = 4;
            state.error = null;
            resolvePlay();
            emit('play');
            emit('playing');
        } else if(event.type === 'play') {
            state.paused = false;
            emit('play');
        } else if(event.type === 'pause') {
            state.paused = true;
            rejectPlay('AbortError');
            emit('pause');
        } else if(event.type === 'state' || event.type === 'trackchange') {
            if(!state.paused && (oldPaused || restoring || changed)) { emit('play'); emit('playing'); resolvePlay(); }
            else if(state.paused && (!oldPaused || restoring || changed)) emit('pause');
            emit('loadedmetadata');
            emit('durationchange');
            emit('timeupdate');
        } else if(['timeupdate', 'durationchange', 'loadedmetadata', 'ended', 'waiting', 'seeked', 'volumechange'].indexOf(event.type) !== -1) {
            emit(event.type);
        }
    }

    function createAudio() {
        if(!available) return null;
        if(audio) return audio;
        installProtocol();
        audio = document.createElement('audio');
        audio.setAttribute('data-native-playback', 'true');
        audio.isNativeAudio = true;
        audio.setMusic = setMusic;
        audio.setPlaybackOrder = function(order) { command('order', { order: order }); };
        audio.next = function() { return state.trackId ? command('next') : false; };
        audio.previous = function() { return state.trackId ? command('previous') : false; };
        audio.play = function() {
            if(!state.trackId) return Promise.reject(new Error('No selected music'));
            if(!state.paused && state.readyState >= 3 && !state.error) { command('play'); return Promise.resolve(); }
            if(pendingPlay) return pendingPlay.promise;
            var pending = {};
            pending.promise = new Promise(function(resolve, reject) { pending.resolve = resolve; pending.reject = reject; });
            pendingPlay = pending;
            try { command('play'); } catch(error) { rejectPlay('NotSupportedError'); }
            return pending.promise;
        };
        audio.pause = function() {
            rejectPlay('AbortError');
            if(state.trackId) command('pause');
        };
        ['src', 'currentSrc', 'paused', 'duration', 'error', 'readyState'].forEach(function(key) {
            Object.defineProperty(audio, key, { configurable: true, get: function() { return state[key === 'currentSrc' ? 'src' : key]; } });
        });
        Object.defineProperty(audio, 'currentTime', { configurable: true, get: function() { return state.currentTime; },
            set: function(value) {
                if(!Number.isFinite(value) || value < 0) return;
                state.currentTime = Number.isFinite(state.duration) ? Math.min(value, state.duration) : value;
                command('seek', { currentTime: state.currentTime });
            } });
        Object.defineProperty(audio, 'volume', { configurable: true, get: function() { return state.volume; },
            set: function(value) {
                if(!Number.isFinite(value)) return;
                state.volume = Math.max(0, Math.min(1, value));
                command('volume', { volume: state.volume });
            } });
        var cover = document.getElementById('music-cover');
        if(cover) cover.addEventListener('load', function() {
            if(!current || !state.trackId) return;
            var artwork = mediaUrl(cover.currentSrc || cover.src);
            if(artwork && artwork === mediaUrl(current.pic)) command('metadata', { title: text(current.name), artist: text(current.artist),
                album: text(current.album), artwork: artwork });
        });
        window.setTimeout(requestState, 0);
        document.addEventListener('visibilitychange', function() {
            if(!document.hidden) requestState();
        });
        return audio;
    }

    function requestState() {
        return audio ? command('state') : false;
    }

    window.KunMusicNative = { isAvailable: !!available, createAudio: createAudio, onEvent: onEvent,
        openSong: openSong, requestState: requestState };
})(window, document);
