package cn.kunps.music;

import android.app.Activity;
import android.app.Instrumentation;
import android.content.Intent;
import android.os.Bundle;
import android.os.SystemClock;
import android.webkit.WebView;

import androidx.annotation.OptIn;
import androidx.media3.common.util.UnstableApi;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.lang.reflect.Field;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;
import java.util.function.Predicate;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import javax.net.ssl.HttpsURLConnection;

/** Device smoke check. Uses public music data and never accesses platform login credentials. */
@OptIn(markerClass = UnstableApi.class)
public final class AppSmokeInstrumentation extends Instrumentation {
    private final List<JSONObject> events = new ArrayList<>();
    private NativePlayback playback;
    private Activity activity;
    private int checks;

    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }

    @Override public void onStart() {
        Bundle result = new Bundle();
        int code = Activity.RESULT_OK;
        try {
            checkAssets();
            checkBundledActivity();
            checkNativePlayback();
            result.putString("stream", "KunMusic device smoke passed: " + checks + " checks\n");
        } catch (Throwable error) {
            code = Activity.RESULT_CANCELED;
            result.putString("stream", "KunMusic device smoke failed: " + error.getClass().getSimpleName()
                    + ": " + error.getMessage() + "\n");
        } finally {
            if (playback != null) {
                try { playback.command(new JSONObject().put("action", "pause")); } catch (Exception ignored) { }
                runOnMainSync(() -> { });
                playback.release();
                runOnMainSync(() -> { });
            }
            if (activity != null) runOnMainSync(activity::finish);
            getTargetContext().stopService(new Intent(getTargetContext(), PlaybackService.class));
        }
        result.putInt("checks", checks);
        finish(code, result);
    }

    private void checkAssets() throws Exception {
        String html = readAsset("player/index.html");
        require(html.contains("Content-Security-Policy") && html.contains("script-src 'self'"), "bundled CSP");
        Matcher references = Pattern.compile("<(?:script|link|img)\\b[^>]*?(?:src|href)=\"([^\"]+)\"").matcher(html);
        int count = 0;
        while (references.find()) {
            String name = references.group(1).split("[?#]", 2)[0];
            if (name.isEmpty() || name.startsWith("/") || name.contains(":")) continue;
            require(!readAsset("player/" + name).isEmpty(), "bundled resource " + name);
            count++;
        }
        require(count >= 10, "HTML resource coverage");
        for (String secret : new String[] {"player/config.local.php", "player/api.php", "player/client-api.php"}) {
            try (InputStream ignored = getTargetContext().getAssets().open(secret)) {
                throw new AssertionError("server-only file packaged");
            } catch (IOException expected) { checks++; }
        }
        progress("Bundled interface resources verified");
    }

    private void checkBundledActivity() throws Exception {
        activity = startActivitySync(new Intent(getTargetContext(), MainActivity.class)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        Field field = MainActivity.class.getDeclaredField("web");
        field.setAccessible(true);
        WebView web = (WebView) field.get(activity);
        require(web != null, "WebView feature availability");
        long deadline = SystemClock.elapsedRealtime() + 60000;
        boolean ready = false;
        while (SystemClock.elapsedRealtime() < deadline) {
            String value = evaluate(web, "Boolean(window.KunMusicNative && window.KunMusicNative.isAvailable && window.jQuery && window.mkPlayer && document.readyState === 'complete')");
            if ("true".equals(value)) { ready = true; break; }
            SystemClock.sleep(300);
        }
        require(ready, "local JavaScript and native bridge initialized");
        require("true".equals(evaluate(web, "location.href === 'https://yy.kunps.cn/__app__/index.html'")), "local document URL");
        require("true".equals(evaluate(web, "mkPlayer.api === 'https://yy.kunps.cn/client-api.php'")), "client protocol endpoint");
        require("true".equals(evaluate(web, "document.styleSheets.length > 0 && getComputedStyle(document.body).fontFamily.length > 0")), "local stylesheet applied");
        progress("Local WebView and bridge verified");
    }

    private void checkNativePlayback() throws Exception {
        JSONObject first = api("song", new JSONObject().put("source", "netease").put("id", "287398")).getJSONArray("result").getJSONObject(0);
        JSONObject second = api("song", new JSONObject().put("source", "netease").put("id", "167691")).getJSONArray("result").getJSONObject(0);
        String stream = api("url", new JSONObject().put("source", "netease").put("id", "287398")).getJSONObject("result").getString("url");
        if (stream.startsWith("http://")) stream = "https://" + stream.substring(7);
        playback = new NativePlayback(getTargetContext(), event -> {
            synchronized (events) { events.add(event); events.notifyAll(); }
        });
        playback.connect();
        await(event -> "state".equals(event.optString("type")), 30000, "media service connection");
        String token = "smoke-" + UUID.randomUUID();
        playback.command(new JSONObject().put("action", "volume").put("volume", 0));
        playback.command(new JSONObject().put("action", "queue").put("trackId", token).put("index", 0)
                .put("order", 2).put("items", new JSONArray().put(first).put(second)));
        playback.command(new JSONObject().put("action", "load").put("trackId", token)
                .put("source", "netease").put("id", "287398").put("url", stream)
                .put("title", first.optString("name")).put("artist", first.optJSONArray("artist").join(" / ")));
        playback.command(new JSONObject().put("action", "play").put("trackId", token));
        await(event -> "playing".equals(event.optString("type")) && "287398".equals(event.optString("id")), 60000, "first native audio playing");
        await(event -> "287398".equals(event.optString("id")) && event.optDouble("currentTime") > 1, 30000, "native playback progress");
        clearEvents();
        playback.command(new JSONObject().put("action", "pause").put("trackId", token));
        JSONObject paused = await(event -> "287398".equals(event.optString("id")) && event.optBoolean("paused"), 10000, "native pause");
        double pausedAt = paused.optDouble("currentTime");
        SystemClock.sleep(1300);
        clearEvents();
        playback.command(new JSONObject().put("action", "state"));
        JSONObject snapshot = await(event -> "state".equals(event.optString("type")), 10000, "paused snapshot");
        require(snapshot.optBoolean("paused") && Math.abs(snapshot.optDouble("currentTime") - pausedAt) < 1, "pause keeps playback position");
        clearEvents();
        playback.command(new JSONObject().put("action", "seek").put("trackId", token).put("currentTime", 5));
        await(event -> Math.abs(event.optDouble("currentTime") - 5) < 1, 15000, "native seek");
        runOnMainSync(() -> activity.moveTaskToBack(true));
        clearEvents();
        playback.command(new JSONObject().put("action", "play").put("trackId", token));
        await(event -> "playing".equals(event.optString("type")), 30000, "background native playback");
        clearEvents();
        playback.command(new JSONObject().put("action", "next"));
        await(event -> "playing".equals(event.optString("type")) && "167691".equals(event.optString("id")), 60000, "background next track");
        playback.release();
        runOnMainSync(() -> { });
        clearEvents();
        playback = new NativePlayback(getTargetContext(), event -> {
            synchronized (events) { events.add(event); events.notifyAll(); }
        });
        playback.connect();
        JSONObject restored = await(event -> "state".equals(event.optString("type")) && "167691".equals(event.optString("id")), 30000, "controller reconnect");
        require(!restored.optBoolean("paused") && restored.getJSONObject("queue").getJSONArray("items").length() == 2,
                "release preserves service playback and queue");
        progress("Native play/pause/seek/next/background/reconnect verified");
    }

    private JSONObject await(Predicate<JSONObject> predicate, long timeout, String label) throws Exception {
        long deadline = SystemClock.elapsedRealtime() + timeout;
        synchronized (events) {
            while (SystemClock.elapsedRealtime() < deadline) {
                for (int i = events.size() - 1; i >= 0; i--) if (predicate.test(events.get(i))) { checks++; return events.get(i); }
                events.wait(Math.min(500, Math.max(1, deadline - SystemClock.elapsedRealtime())));
            }
        }
        throw new AssertionError(label + " timed out");
    }

    private void clearEvents() { synchronized (events) { events.clear(); } }
    private void require(boolean value, String label) { if (!value) throw new AssertionError(label); checks++; }
    private void progress(String value) { Bundle data = new Bundle(); data.putString("stream", value + "\n"); sendStatus(0, data); }
    private String evaluate(WebView web, String script) throws Exception {
        AtomicReference<String> value = new AtomicReference<>();
        CountDownLatch ready = new CountDownLatch(1);
        runOnMainSync(() -> web.evaluateJavascript(script, result -> { value.set(result); ready.countDown(); }));
        if (!ready.await(20, TimeUnit.SECONDS)) throw new AssertionError("JavaScript evaluation timed out");
        return value.get();
    }
    private String readAsset(String name) throws IOException {
        try (InputStream input = getTargetContext().getAssets().open(name); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[8192]; int count;
            while ((count = input.read(buffer)) != -1) output.write(buffer, 0, count);
            return output.toString(StandardCharsets.UTF_8.name());
        }
    }
    private JSONObject api(String method, JSONObject params) throws Exception {
        String requestId = "smoke-" + UUID.randomUUID();
        HttpsURLConnection connection = (HttpsURLConnection) new URL("https://yy.kunps.cn/client-api.php").openConnection();
        connection.setConnectTimeout(20000); connection.setReadTimeout(60000); connection.setRequestMethod("POST");
        connection.setDoOutput(true); connection.setRequestProperty("Content-Type", "application/json");
        try {
            byte[] body = new JSONObject().put("protocol", "kunmusic/1").put("requestId", requestId)
                    .put("method", method).put("params", params).toString().getBytes(StandardCharsets.UTF_8);
            try (OutputStream output = connection.getOutputStream()) { output.write(body); }
            require(connection.getResponseCode() == 200, method + " HTTPS response");
            try (InputStream input = connection.getInputStream(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
                byte[] buffer = new byte[8192]; int count;
                while ((count = input.read(buffer)) != -1) { if (output.size() > 1048576) throw new IOException("response too large"); output.write(buffer, 0, count); }
                JSONObject response = new JSONObject(output.toString(StandardCharsets.UTF_8.name()));
                require("kunmusic/1".equals(response.optString("protocol")) && requestId.equals(response.optString("requestId"))
                        && response.optBoolean("ok"), method + " protocol response");
                return response;
            }
        } finally { connection.disconnect(); }
    }
}
