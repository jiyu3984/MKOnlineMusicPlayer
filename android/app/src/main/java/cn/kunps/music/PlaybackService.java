package cn.kunps.music;

import android.app.PendingIntent;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Intent;
import android.net.Uri;
import android.os.Handler;
import android.os.Bundle;
import android.os.Looper;
import android.os.Process;
import androidx.media3.common.AudioAttributes;
import androidx.media3.common.C;
import androidx.media3.common.MediaItem;
import androidx.media3.common.MediaMetadata;
import androidx.media3.common.Player;
import androidx.media3.common.util.UnstableApi;
import androidx.media3.datasource.DataSpec;
import androidx.media3.datasource.DefaultDataSource;
import androidx.media3.datasource.ResolvingDataSource;
import androidx.media3.datasource.okhttp.OkHttpDataSource;
import androidx.media3.exoplayer.ExoPlayer;
import androidx.media3.session.MediaSession;
import androidx.media3.session.MediaSessionService;
import androidx.media3.session.DefaultMediaNotificationProvider;
import androidx.media3.session.SessionCommands;
import com.google.common.util.concurrent.Futures;
import com.google.common.util.concurrent.ListenableFuture;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.IDN;
import java.net.InetAddress;
import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.Locale;
import java.util.List;
import java.util.Set;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import javax.net.ssl.HttpsURLConnection;
import okhttp3.OkHttpClient;
import org.json.JSONObject;

/** Owns audio independently of the activity and its WebView. */
@UnstableApi
public final class PlaybackService extends MediaSessionService {
    static final String EXTRA_MUSIC = "kun.music";
    static final String EXTRA_TRACK = "kun.track";
    static final String EXTRA_QUEUE = "kun.queue";
    static final String EXTRA_STREAM = "kun.stream";
    private final Handler main = new Handler(Looper.getMainLooper());
    private final ConcurrentHashMap<String, Resolved> resolved = new ConcurrentHashMap<>();
    private final Set<String> refreshStreams = ConcurrentHashMap.newKeySet();
    private final ExecutorService artworkWorker = Executors.newSingleThreadExecutor();
    private ExoPlayer player;
    private MediaSession session;
    private OkHttpClient network;
    private volatile boolean destroyed;
    private int consecutiveFailures;

    @Override public void onCreate() {
        super.onCreate();
        String channelId = "kun_music_playback";
        NotificationChannel channel = new NotificationChannel(channelId,
                getString(R.string.media_notification_channel), NotificationManager.IMPORTANCE_LOW);
        channel.setDescription(getString(R.string.media_notification_channel_description));
        channel.setShowBadge(false);
        NotificationManager notifications = getSystemService(NotificationManager.class);
        if (notifications != null) notifications.createNotificationChannel(channel);
        DefaultMediaNotificationProvider notificationProvider = new DefaultMediaNotificationProvider.Builder(this)
                .setChannelId(channelId).setChannelName(R.string.media_notification_channel).build();
        notificationProvider.setSmallIcon(R.drawable.ic_music_note);
        setMediaNotificationProvider(notificationProvider);
        network = new OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS).readTimeout(25, TimeUnit.SECONDS)
                .followSslRedirects(false)
                .dns(host -> Arrays.asList(publicAddresses(host)))
                .addNetworkInterceptor(chain -> {
                    publicHttps(chain.request().url().toString(), false);
                    return chain.proceed(chain.request());
                }).build();
        OkHttpDataSource.Factory http = new OkHttpDataSource.Factory(network).setUserAgent("KunMusic/1.0 (Android)");
        ResolvingDataSource.Factory sources = new ResolvingDataSource.Factory(
                new DefaultDataSource.Factory(this, http), this::resolve);
        player = new ExoPlayer.Builder(this)
                .setMediaSourceFactory(new androidx.media3.exoplayer.source.DefaultMediaSourceFactory(sources))
                .setAudioAttributes(new AudioAttributes.Builder().setUsage(C.USAGE_MEDIA)
                        .setContentType(C.AUDIO_CONTENT_TYPE_MUSIC).build(), true)
                .setHandleAudioBecomingNoisy(true).setWakeMode(C.WAKE_MODE_LOCAL).build();
        player.setRepeatMode(Player.REPEAT_MODE_ALL);
        player.addListener(new Player.Listener() {
            @Override public void onIsPlayingChanged(boolean isPlaying) { if (isPlaying) consecutiveFailures = 0; }
            @Override public void onPlayerError(androidx.media3.common.PlaybackException error) {
                MediaItem failed = player.getCurrentMediaItem();
                String failedToken = failed == null || failed.mediaMetadata.extras == null ? ""
                        : failed.mediaMetadata.extras.getString(EXTRA_TRACK, "");
                boolean requested = player.getPlayWhenReady();
                boolean renew = isExpiredStream(error) && refreshStreams.add(failedToken);
                if (failed != null && failed.localConfiguration != null) resolved.remove(failed.localConfiguration.uri.toString());
                if (!renew) consecutiveFailures++;
                if (consecutiveFailures >= 10 || player.getMediaItemCount() == 0) { player.pause(); return; }
                main.post(() -> {
                    MediaItem current = player == null ? null : player.getCurrentMediaItem();
                    String currentToken = current == null || current.mediaMetadata.extras == null ? ""
                            : current.mediaMetadata.extras.getString(EXTRA_TRACK, "");
                    if (player == null || !failedToken.equals(currentToken) || player.getPlayerError() == null
                            || !requested || !player.getPlayWhenReady()) return;
                    if (!renew) player.seekToNextMediaItem();
                    player.prepare(); player.play();
                });
            }
        });
        MediaSession.Builder builder = new MediaSession.Builder(this, player).setCallback(new MediaSession.Callback() {
            @Override public MediaSession.ConnectionResult onConnect(MediaSession mediaSession, MediaSession.ControllerInfo info) {
                if (info.getUid() == Process.myUid()) return MediaSession.Callback.super.onConnect(mediaSession, info);
                if (!info.isTrusted() && !mediaSession.isMediaNotificationController(info)) return MediaSession.ConnectionResult.reject();
                Player.Commands controls = new Player.Commands.Builder().addAll(
                        Player.COMMAND_PLAY_PAUSE, Player.COMMAND_PREPARE, Player.COMMAND_STOP,
                        Player.COMMAND_SEEK_IN_CURRENT_MEDIA_ITEM, Player.COMMAND_SEEK_TO_DEFAULT_POSITION,
                        Player.COMMAND_SEEK_TO_PREVIOUS, Player.COMMAND_SEEK_TO_PREVIOUS_MEDIA_ITEM,
                        Player.COMMAND_SEEK_TO_NEXT, Player.COMMAND_SEEK_TO_NEXT_MEDIA_ITEM,
                        Player.COMMAND_SEEK_BACK, Player.COMMAND_SEEK_FORWARD,
                        Player.COMMAND_GET_CURRENT_MEDIA_ITEM, Player.COMMAND_GET_TIMELINE,
                        Player.COMMAND_GET_MEDIA_ITEMS_METADATA, Player.COMMAND_GET_AUDIO_ATTRIBUTES,
                        Player.COMMAND_GET_VOLUME).build();
                return MediaSession.ConnectionResult.accept(SessionCommands.EMPTY, controls);
            }
            @Override public ListenableFuture<List<MediaItem>> onAddMediaItems(MediaSession mediaSession,
                    MediaSession.ControllerInfo info, List<MediaItem> mediaItems) {
                if (info.getUid() != Process.myUid()) return Futures.immediateFailedFuture(new SecurityException("Queue changes require the app"));
                return MediaSession.Callback.super.onAddMediaItems(mediaSession, info, mediaItems);
            }
            @Override public ListenableFuture<MediaSession.MediaItemsWithStartPosition> onSetMediaItems(MediaSession mediaSession,
                    MediaSession.ControllerInfo info, List<MediaItem> mediaItems, int startIndex, long startPositionMs) {
                if (info.getUid() != Process.myUid()) return Futures.immediateFailedFuture(new SecurityException("Queue changes require the app"));
                return MediaSession.Callback.super.onSetMediaItems(mediaSession, info, mediaItems, startIndex, startPositionMs);
            }
        });
        Intent launch = getPackageManager().getLaunchIntentForPackage(getPackageName());
        if (launch != null) builder.setSessionActivity(PendingIntent.getActivity(this, 0, launch,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
        session = builder.build();
    }

    @Override public MediaSession onGetSession(MediaSession.ControllerInfo controllerInfo) {
        return session;
    }

    @Override public void onTaskRemoved(Intent rootIntent) {
        // Swiping the activity away keeps requested playback and its media notification alive.
        if (player == null || !player.getPlayWhenReady() || player.getMediaItemCount() == 0) stopSelf();
    }

    @Override public void onDestroy() {
        destroyed = true;
        main.removeCallbacksAndMessages(null);
        if (session != null) { session.release(); session = null; }
        if (player != null) { player.release(); player = null; }
        resolved.clear();
        refreshStreams.clear();
        artworkWorker.shutdownNow();
        if (network != null) { network.dispatcher().executorService().shutdown(); network.connectionPool().evictAll(); }
        super.onDestroy();
    }

    private DataSpec resolve(DataSpec request) throws IOException {
        if (destroyed) throw new IOException("Player closed");
        Uri uri = request.uri;
        if (!"kunmusic".equals(uri.getScheme())) return request.withUri(publicHttps(uri.toString(), true));
        String key = uri.toString();
        Resolved cached = resolved.get(key);
        if (cached != null && System.currentTimeMillis() - cached.created < 240000L)
            return request.withUri(publicHttps(cached.url, true));
        String source = uri.getHost();
        String id = uri.getLastPathSegment();
        if (!supportedSource(source) || id == null || id.length() > 512) throw new IOException("Invalid track");
        String token = uri.getQueryParameter("token");
        String stream = refreshStreams.contains(token == null ? "" : token) ? null : uri.getQueryParameter("stream");
        if (stream == null || stream.isEmpty()) {
            JSONObject response = api("url", source, id, "url_id", uri.getQueryParameter("url_id"));
            stream = response.optString("url", "");
        }
        Uri audio = publicHttps(stream, true);
        resolved.put(key, new Resolved(audio.toString()));
        if (resolved.size() > 1200) resolved.clear();
        main.post(() -> updateResolvedStream(token, audio));
        if ("1".equals(uri.getQueryParameter("art"))) {
            try { artworkWorker.execute(() -> {
                try {
                    String pictureId = uri.getQueryParameter("pic_id");
                    JSONObject picture = api("pic", source, pictureId == null || pictureId.isEmpty() ? id : pictureId, null, null);
                    Uri artwork = publicHttps(picture.optString("url", ""), true);
                    main.post(() -> updateArtwork(token, artwork));
                } catch (IOException ignored) { /* Artwork failure does not stop audio. */ }
            }); } catch (java.util.concurrent.RejectedExecutionException ignored) { }
        }
        return request.withUri(audio);
    }

    private static boolean isExpiredStream(Throwable error) {
        for (Throwable cause = error; cause != null; cause = cause.getCause()) {
            if (cause instanceof androidx.media3.datasource.HttpDataSource.InvalidResponseCodeException) {
                int status = ((androidx.media3.datasource.HttpDataSource.InvalidResponseCodeException) cause).responseCode;
                return status == 401 || status == 403 || status == 410;
            }
        }
        return false;
    }

    private void updateArtwork(String token, Uri artwork) {
        if (player == null || token == null) return;
        for (int i = 0; i < player.getMediaItemCount(); i++) {
            MediaItem item = player.getMediaItemAt(i);
            if (item.mediaMetadata.extras == null || !token.equals(item.mediaMetadata.extras.getString(EXTRA_TRACK))) continue;
            MediaMetadata metadata = item.mediaMetadata.buildUpon().setArtworkUri(artwork).build();
            player.replaceMediaItem(i, item.buildUpon().setMediaMetadata(metadata).build());
            break;
        }
    }

    private void updateResolvedStream(String token, Uri audio) {
        if (player == null || token == null) return;
        for (int i = 0; i < player.getMediaItemCount(); i++) {
            MediaItem item = player.getMediaItemAt(i);
            Bundle extras = item.mediaMetadata.extras;
            if (extras == null || !token.equals(extras.getString(EXTRA_TRACK))) continue;
            Bundle updated = new Bundle(extras);
            updated.putString(EXTRA_STREAM, audio.toString());
            try {
                JSONObject music = new JSONObject(extras.getString(EXTRA_MUSIC, "{}"));
                music.put("url", audio.toString()); updated.putString(EXTRA_MUSIC, music.toString());
            } catch (Exception ignored) { }
            MediaMetadata metadata = item.mediaMetadata.buildUpon().setExtras(updated).build();
            player.replaceMediaItem(i, item.buildUpon().setMediaMetadata(metadata).build());
            break;
        }
    }

    static boolean supportedSource(String source) {
        return "netease".equals(source) || "kugou".equals(source) || "kuwo".equals(source)
                || "tencent".equals(source) || "xiami".equals(source) || "baidu".equals(source);
    }

    /** Public HTTPS only. DNS validation is performed on the player's loading thread. */
    static Uri publicHttps(String value, boolean checkDns) throws IOException {
        try {
            URI parsed = new URI(value);
            String scheme = parsed.getScheme();
            if (parsed.getUserInfo() != null || parsed.getFragment() != null) throw new IOException("Invalid media URL");
            // Some music providers return an HTTP spelling of the same HTTPS CDN resource.
            if ("http".equalsIgnoreCase(scheme) && (parsed.getPort() == -1 || parsed.getPort() == 80)) {
                if (parsed.getHost() == null) throw new IOException("Invalid media URL");
                parsed = new URI("https://" + parsed.getHost() + (parsed.getRawPath() == null ? "" : parsed.getRawPath())
                        + (parsed.getRawQuery() == null ? "" : "?" + parsed.getRawQuery()));
            }
            if (!"https".equalsIgnoreCase(parsed.getScheme()) || parsed.getUserInfo() != null
                    || parsed.getHost() == null || (parsed.getPort() != -1 && parsed.getPort() != 443)
                    || parsed.getFragment() != null || value.length() > 16384) throw new IOException("Invalid media URL");
            String host = IDN.toASCII(parsed.getHost()).toLowerCase(Locale.ROOT);
            if (host.equals("localhost") || host.endsWith(".localhost") || host.endsWith(".local")
                    || host.endsWith(".internal") || !host.contains(".") || host.contains(":")) throw new IOException("Private media host");
            if (checkDns) publicAddresses(host);
            else if (host.matches("[0-9.]+")) {
                throw new IOException("Literal media addresses are not permitted");
            }
            return Uri.parse(parsed.toASCIIString());
        } catch (IOException error) { throw error; }
        catch (Exception error) { throw new IOException("Invalid media URL", error); }
    }

    private static InetAddress[] publicAddresses(String host) throws java.net.UnknownHostException {
        InetAddress[] addresses = InetAddress.getAllByName(host);
        if (addresses.length == 0) throw new java.net.UnknownHostException("Unresolved media host");
        for (InetAddress address : addresses) {
            byte[] bytes = address.getAddress();
            int first = bytes[0] & 255, second = bytes.length > 1 ? bytes[1] & 255 : 0;
            if (address.isAnyLocalAddress() || address.isLoopbackAddress() || address.isLinkLocalAddress()
                    || address.isSiteLocalAddress() || address.isMulticastAddress()
                    || (bytes.length == 16 && (first & 0xfe) == 0xfc)
                    || (bytes.length == 4 && (first == 0 || first >= 224
                    || (first == 100 && second >= 64 && second <= 127)
                    || (first == 192 && (second == 0 || second == 2))
                    || (first == 198 && (second == 18 || second == 19 || second == 51))
                    || (first == 203 && second == 0)))) throw new java.net.UnknownHostException("Private media address");
        }
        return addresses;
    }

    private static JSONObject api(String type, String source, String id, String extra, String value) throws IOException {
        HttpsURLConnection connection = null;
        try {
            connection = (HttpsURLConnection) new java.net.URL("https://yy.kunps.cn/client-api.php").openConnection();
            connection.setConnectTimeout(12000); connection.setReadTimeout(15000);
            connection.setInstanceFollowRedirects(false); connection.setRequestMethod("POST");
            connection.setDoOutput(true); connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            JSONObject params = new JSONObject().put("source", source).put("id", id);
            if (extra != null) params.put(extra, value == null ? "" : value);
            String requestId = "media-" + java.util.UUID.randomUUID();
            JSONObject request = new JSONObject().put("protocol", "kunmusic/1").put("requestId", requestId)
                    .put("method", type).put("params", params);
            try (OutputStream output = connection.getOutputStream()) { output.write(request.toString().getBytes(StandardCharsets.UTF_8)); }
            if (connection.getResponseCode() != 200) throw new IOException("Music provider unavailable");
            try (InputStream input = connection.getInputStream(); ByteArrayOutputStream result = new ByteArrayOutputStream()) {
                byte[] chunk = new byte[8192]; int count;
                while ((count = input.read(chunk)) != -1) {
                    if (result.size() + count > 1048576) throw new IOException("Provider response too large");
                    result.write(chunk, 0, count);
                }
                JSONObject envelope = new JSONObject(result.toString(StandardCharsets.UTF_8.name()));
                if (!"kunmusic/1".equals(envelope.optString("protocol")) || !requestId.equals(envelope.optString("requestId"))
                        || !envelope.optBoolean("ok") || envelope.optJSONObject("result") == null)
                    throw new IOException("Music provider unavailable");
                return envelope.getJSONObject("result");
            }
        } catch (IOException error) { throw error; }
        catch (Exception error) { throw new IOException("Invalid music provider response", error); }
        finally { if (connection != null) connection.disconnect(); }
    }

    private static final class Resolved {
        final String url; final long created = System.currentTimeMillis();
        Resolved(String url) { this.url = url; }
    }
}
