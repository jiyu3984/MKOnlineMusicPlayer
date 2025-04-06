/**************************************************
 * MKOnlinePlayer v2.31
 * 歌词解析及滚动模块（修复翻译高亮 & 滚动错位 + 动画 + 渐变模糊 + 3D倾斜）
 * 编写：mengkun | 修改：季雨
 * 时间：2017-9-13 / 2025-04
 *************************************************/

var lyricArea = $("#lyric");    // 歌词显示容器

// 判断是否为移动设备（安卓/苹果）
var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

// 模糊等级参数（安卓弱，PC强）
var maxBlur = isMobile ? 2 : 2;  // 安卓2，PC模糊减半为2

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

// 滚动歌词到指定句（保留模糊 + 高亮 + 平滑滚动 + 翻译处理）
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

    // 移除之前高亮
    $(".lrc-item .shell.lplaying").removeClass("lplaying");
    $(".lrc-item .trans-lyric.lplaying").removeClass("lplaying");
    $(".lrc-item").removeClass("lrc-item-playing");

    // 高亮当前行
    let $current = $(".lrc-item[data-no='" + i + "']");
    $current.find(".shell").addClass("lplaying");
    $current.find(".trans-lyric").addClass("lplaying");
    $current.addClass("lrc-item-playing");

    // 滚动平滑
    if ($current.length > 0) {
        let scrollPosition = $current.position().top + lyricArea.scrollTop() - ($(".lyric").height() / 2);
        lyricArea.stop().animate({ scrollTop: scrollPosition }, 1000);
    }

    // 应用模糊 + 倾斜
    applyBlur(i);
}

// 渐变模糊 + 3D 倾斜动画（含安卓加强版）
function applyBlur(currentIndex) {
    const items = $(".lrc-item");
    const centerIndex = currentIndex;
    const maxDistance = 6;  // 上下各模糊范围

    const maxTilt = isMobile ? 45 : 15;  // 安卓倾斜更大，PC小一点

    items.each(function () {
        const index = parseInt($(this).data("no"));
        const distance = Math.abs(index - centerIndex);

        if (distance === 0) {
            $(this).css({
                filter: "none",
                transform: "none"
            });
        } else if (distance <= maxDistance) {
            const blur = Math.min(maxBlur, (distance / maxDistance) * maxBlur);
            const tilt = (distance / maxDistance) * maxTilt;
            const direction = index < centerIndex ? -1 : 1;  // 上面向左倾，下面向右倾
            $(this).css({
                filter: `blur(${blur}px)`,
                transform: `rotateY(${direction * tilt}deg)`
            });
        } else {
            const direction = index < centerIndex ? -1 : 1;
            $(this).css({
                filter: `blur(${maxBlur}px)`,
                transform: `rotateY(${direction * maxTilt}deg)`
            });
        }
    });
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
