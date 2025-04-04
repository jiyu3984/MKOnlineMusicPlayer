/**************************************************
 * MKOnlinePlayer v2.31
 * 歌词解析及滚动模块（修复翻译高亮 & 滚动错位）
 * 编写：mengkun(http://mkblog.cn) | 修改：ChatGPT
 * 时间：2017-9-13 / 2025-04 修复
 *************************************************/

var lyricArea = $("#lyric"); // 歌词显示容器

// 显示提示语（如歌词加载中、无歌词等）
function lyricTip(str) {
    lyricArea.html("<li class='lyric-tip'>" + str + "</li>");
}

// 歌曲加载完后的回调函数
function lyricCallback(str, id, tstr) {
    if (id !== musicList[rem.playlist].item[rem.playid].id) return;

    if (str === "") {
        lyricTip("暂时没有歌词");
        return false;
    }

    rem.lyric = parseLyric(str);  // 原歌词
    rem.tlyric = parseLyric(tstr); // 翻译歌词

    lyricArea.html('');
    lyricArea.scrollTop(0);
    rem.lastLyric = -1;

    var i = 0;
    for (var k in rem.lyric) {
        var txt = rem.lyric[k];
        if (!txt) txt = "&nbsp;";
        var li;
        if (!rem.tlyric[k] || rem.tlyric[k] === '') {
            li = $("<li data-no='" + i + "' class='lrc-item'><span class='shell'>" + txt + "</span></li>");
        } else {
            li = $("<li data-no='" + i + "' class='lrc-item'><span class='shell'>" + txt + "<br><span class='trans-lyric'>" + rem.tlyric[k] + "</span></span></li>");
        }
        lyricArea.append(li);
        i++;
    }
}

// 强制刷新当前时间点的歌词
function refreshLyric(time) {
    if (rem.lyric === '') return false;

    time = parseInt(time);
    var i = 0;
    for (var k in rem.lyric) {
        if (k >= time) break;
        i = k;
    }

    scrollLyric(i);
}

// 滚动歌词到指定句（修复翻译影响滚动的问题）
function scrollLyric(time) {
    if (rem.lyric === '') return false;

    time = parseInt(time);
    if (rem.lyric === undefined || rem.lyric[time] === undefined) return false;
    if (rem.lastLyric == time) return true;

    var i = 0;
    for (var k in rem.lyric) {
        if (k == time) break;
        i++;
    }

    rem.lastLyric = time;

    // 修复：只高亮 shell（避免翻译高亮）
    $(".lrc-item .shell.lplaying").removeClass("lplaying");
    $(".lrc-item[data-no='" + i + "'] .shell").addClass("lplaying");

    // 修复：使用元素位置定位滚动
    var currentItem = $(".lrc-item[data-no='" + i + "']");
    if (currentItem.length > 0) {
        var scroll = currentItem.position().top + lyricArea.scrollTop() - ($(".lyric").height() / 2);
        lyricArea.stop().animate({ scrollTop: scroll }, 1000);
    }
}

// 解析歌词
function parseLyric(lrc) {
    if (lrc === '') return '';
    var lyrics = lrc.split("\n");
    var lrcObj = {};
    for (var i = 0; i < lyrics.length; i++) {
        var lyric = decodeURIComponent(lyrics[i]);
        var timeReg = /\[\d*:\d*((\.|\:)\d*)*\]/g;
        var timeRegExpArr = lyric.match(timeReg);
        if (!timeRegExpArr) continue;
        var clause = lyric.replace(timeReg, '');
        for (var k = 0, h = timeRegExpArr.length; k < h; k++) {
            var t = timeRegExpArr[k];
            var min = Number(String(t.match(/\[\d*/i)).slice(1));
            var sec = Number(String(t.match(/\:\d*/i)).slice(1));
            var time = min * 60 + sec;
            lrcObj[time] = clause;
        }
    }
    return lrcObj;
}