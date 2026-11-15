package cn.kunps.music;

import android.Manifest;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.ComponentActivity;
import androidx.activity.OnBackPressedCallback;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.webkit.WebViewAssetLoader;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;
import androidx.annotation.OptIn;
import androidx.media3.common.util.UnstableApi;

import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.util.Set;
import java.util.UUID;

/** The UI is bundled in assets; the server supplies structured data and media links only. */
@OptIn(markerClass = UnstableApi.class)
public final class MainActivity extends ComponentActivity {
    private static final String HOME = "https://yy.kunps.cn/__app__/index.html";
    private WebView web;
    private NativePlayback playback;
    private FrameLayout root;
    private LinearLayout failure;
    private boolean pageReady;
    private JSONObject pendingSong;
    private boolean notificationRequested;

    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        root = new FrameLayout(this);
        root.setBackgroundColor(Color.rgb(25, 28, 32));
        setContentView(root);
        ViewCompat.setOnApplyWindowInsetsListener(root, (view, insets) -> {
            Insets safe = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            view.setPadding(safe.left, safe.top, safe.right, safe.bottom);
            return insets;
        });
        WindowCompat.getInsetsController(getWindow(), root).setAppearanceLightStatusBars(false);
        WindowCompat.getInsetsController(getWindow(), root).setAppearanceLightNavigationBars(false);
        createFailureView();

        if (!WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            showFailure("请先更新 Android System WebView，再打开坤音乐。", false);
            return;
        }
        web = new WebView(this);
        web.setBackgroundColor(Color.rgb(25, 28, 32));
        root.addView(web, 0, new FrameLayout.LayoutParams(-1, -1));
        WebSettings settings = web.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setSupportMultipleWindows(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(false);
        settings.setUserAgentString(settings.getUserAgentString() + " KunMusicAndroid/1.0");
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);

        WebViewAssetLoader.AssetsPathHandler assets = new WebViewAssetLoader.AssetsPathHandler(this);
        WebViewAssetLoader loader = new WebViewAssetLoader.Builder()
                .setDomain("yy.kunps.cn")
                .addPathHandler("/__app__/", path -> assets.handle("player/" + path))
                .build();
        playback = new NativePlayback(this, event -> runOnUiThread(() -> {
            if (pageReady && isLocalDocument(web.getUrl())) {
                web.evaluateJavascript("window.KunMusicNative && window.KunMusicNative.onEvent(" + event + ");", null);
            }
        }));
        playback.connect();
        WebViewCompat.addWebMessageListener(web, "KunMusicAndroid", Set.of("https://yy.kunps.cn"),
                (view, message, origin, isMainFrame, reply) -> {
                    if (!isMainFrame || !isLocalDocument(view.getUrl())) return;
                    String value = message.getData();
                    if (value == null || value.length() > 1_500_000) return;
                    try {
                        JSONObject command = new JSONObject(value);
                        String action = command.optString("action");
                        if ("load".equals(action) || "play".equals(action)) requestMediaNotifications();
                        playback.command(command);
                    } catch (JSONException ignored) {
                        // The bridge accepts JSON commands only.
                    }
                });
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) return true;
                Uri target = request.getUrl();
                if (isLocalDocument(target.toString())) return false;
                if ("kunmusic".equals(target.getScheme())) {
                    receiveSong(target);
                } else if ("https".equals(target.getScheme()) && target.getUserInfo() == null) {
                    openExternal(target);
                }
                return true;
            }

            @Override public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                WebResourceResponse bundled = loader.shouldInterceptRequest(uri);
                if (bundled != null) return bundled;
                if (!"https".equals(uri.getScheme()) || uri.getUserInfo() != null) return denied();
                if ("yy.kunps.cn".equals(uri.getHost())) {
                    // No remote HTML, CSS, scripts or configuration can replace the installed interface.
                    if (!"/client-api.php".equals(uri.getPath()) || !"POST".equals(request.getMethod())) return denied();
                }
                return null;
            }

            @Override public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                pageReady = false;
            }

            @Override public void onPageFinished(WebView view, String url) {
                if (!isLocalDocument(url)) return;
                pageReady = true;
                failure.setVisibility(View.GONE);
                web.evaluateJavascript("window.KunMusicNative && window.KunMusicNative.requestState && window.KunMusicNative.requestState();", null);
                sendPendingSong();
            }

            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) showFailure("暂时无法打开播放器，请重试。", true);
            }
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                // Leaving the interface does not stop its foreground media service.
                moveTaskToBack(true);
            }
        });
        receiveSong(getIntent().getData());
        web.loadUrl(HOME);
    }

    private static boolean isLocalDocument(String value) {
        if (value == null) return false;
        Uri uri = Uri.parse(value);
        return "https".equals(uri.getScheme()) && "yy.kunps.cn".equals(uri.getHost())
                && uri.getUserInfo() == null && uri.getPort() == -1
                && "/__app__/index.html".equals(uri.getPath());
    }

    private static WebResourceResponse denied() {
        return new WebResourceResponse("text/plain", "UTF-8", 403, "Blocked", java.util.Map.of(),
                new ByteArrayInputStream("Blocked".getBytes(StandardCharsets.UTF_8)));
    }

    private void requestMediaNotifications() {
        if (!notificationRequested && Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            notificationRequested = true;
            requestPermissions(new String[] {Manifest.permission.POST_NOTIFICATIONS}, 1);
        }
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        receiveSong(intent.getData());
    }

    private void receiveSong(Uri uri) {
        if (uri == null) return;
        LinkProtocol.Song song = LinkProtocol.parse(uri.toString());
        if (song == null) {
            Toast.makeText(this, "歌曲链接格式有误", Toast.LENGTH_SHORT).show();
            return;
        }
        pendingSong = new JSONObject();
        try {
            pendingSong.put("source", song.source()).put("id", song.id()).put("requestId", UUID.randomUUID().toString());
            sendPendingSong();
        } catch (JSONException ignored) { pendingSong = null; }
    }

    private void sendPendingSong() {
        if (!pageReady || pendingSong == null || !isLocalDocument(web.getUrl())) return;
        web.evaluateJavascript("window.KunMusicNative && window.KunMusicNative.openSong(" + pendingSong + ");", null);
        pendingSong = null;
    }

    private void openExternal(Uri target) {
        try { startActivity(new Intent(Intent.ACTION_VIEW, target).addCategory(Intent.CATEGORY_BROWSABLE)); }
        catch (ActivityNotFoundException ignored) { Toast.makeText(this, "没有可打开此链接的应用", Toast.LENGTH_SHORT).show(); }
    }

    private void createFailureView() {
        failure = new LinearLayout(this);
        failure.setOrientation(LinearLayout.VERTICAL);
        failure.setGravity(Gravity.CENTER);
        failure.setPadding(36, 36, 36, 36);
        failure.setBackgroundColor(Color.rgb(25, 28, 32));
        failure.setVisibility(View.GONE);
        root.addView(failure, new FrameLayout.LayoutParams(-1, -1));
    }

    private void showFailure(String message, boolean retry) {
        failure.removeAllViews();
        TextView text = new TextView(this);
        text.setText(message);
        text.setTextColor(Color.WHITE);
        text.setTextSize(17);
        text.setGravity(Gravity.CENTER);
        failure.addView(text);
        if (retry) {
            Button button = new Button(this);
            button.setText("重新打开");
            button.setOnClickListener(view -> web.loadUrl(HOME));
            failure.addView(button);
        }
        failure.setVisibility(View.VISIBLE);
    }

    @Override protected void onDestroy() {
        pageReady = false;
        if (playback != null) playback.release();
        if (web != null) {
            if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
                WebViewCompat.removeWebMessageListener(web, "KunMusicAndroid");
            }
            root.removeView(web);
            web.destroy();
        }
        super.onDestroy();
    }
}
