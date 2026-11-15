package cn.kunps.music;

import android.content.ComponentName;
import android.content.Context;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import androidx.media3.common.C;
import androidx.media3.common.MediaItem;
import androidx.media3.common.MediaMetadata;
import androidx.media3.common.PlaybackException;
import androidx.media3.common.Player;
import androidx.media3.common.util.UnstableApi;
import androidx.media3.session.MediaController;
import androidx.media3.session.SessionToken;
import com.google.common.util.concurrent.ListenableFuture;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

/** Activity-owned connection to the service. Releasing it never stops background music. */
@UnstableApi
public final class NativePlayback implements Player.Listener {
    public interface EventSink { void onEvent(JSONObject event); }
    private final Context context;
    private final EventSink sink;
    private final Handler main = new Handler(Looper.getMainLooper());
    private ListenableFuture<MediaController> connection;
    private MediaController controller;
    private boolean released;
    private boolean desiredPlay;
    private float desiredVolume = 0.75f;
    private JSONObject waitingLoad;
    private JSONObject pendingQueue;
    private String currentQueueId = "";
    private int order = 2;
    private final Runnable ticker = new Runnable() {
        @Override public void run() {
            if (released) return;
            if (controller != null && controller.getMediaItemCount() > 0) emit("timeupdate", 0);
            main.postDelayed(this, 500);
        }
    };

    public NativePlayback(Context context, EventSink sink) {
        this.context = context.getApplicationContext(); this.sink = sink;
    }

    public void connect() { main.post(this::connectOnMain); }
    private void connectOnMain() {
        if (released || connection != null) return;
        connection = new MediaController.Builder(context,
                new SessionToken(context, new ComponentName(context, PlaybackService.class))).buildAsync();
        connection.addListener(() -> {
            if (released) return;
            try {
                controller = connection.get();
                controller.addListener(this);
                main.post(ticker);
                if (waitingLoad != null) { JSONObject load = waitingLoad; waitingLoad = null; load(load); }
                else emit("state", 0);
            } catch (Exception error) {
                String token = waitingTrackId();
                String src = waitingLoad == null ? "" : waitingLoad.optString("url");
                connection = null; desiredPlay = false;
                emitFailure(token, src, 1);
            }
        }, main::post);
    }

    public void command(JSONObject command) { dispatch(command); }
    public void dispatch(JSONObject command) {
        if (command == null) return;
        final JSONObject copy;
        try { copy = new JSONObject(command.toString()); } catch (JSONException ignored) { return; }
        main.post(() -> {
            if (released) return;
            connectOnMain();
            String action = copy.optString("action");
            if ("queue".equals(action)) { pendingQueue = copy; return; }
            if ("load".equals(action)) { load(copy); return; }
            String token = copy.optString("trackId", "");
            if (!token.isEmpty() && !token.equals(trackId()) && !token.equals(waitingTrackId())) return;
            if ("play".equals(action)) {
                desiredPlay = true;
                if (controller != null) {
                    if (controller.getMediaItemCount() > 0 && (controller.getPlayerError() != null
                            || controller.getPlaybackState() == Player.STATE_IDLE)) controller.prepare();
                    controller.play();
                }
            }
            else if ("pause".equals(action)) { desiredPlay = false; if (controller != null) controller.pause(); }
            else if ("seek".equals(action) && controller != null) {
                double value = copy.optDouble("currentTime", 0);
                if (Double.isFinite(value)) controller.seekTo(Math.max(0L, (long) (value * 1000)));
            } else if ("volume".equals(action)) {
                double value = copy.optDouble("volume", 0.75);
                if (Double.isFinite(value)) {
                    desiredVolume = (float) Math.max(0, Math.min(1, value));
                    if (controller != null) controller.setVolume(desiredVolume);
                }
            } else if ("metadata".equals(action)) updateMetadata(copy);
            else if ("order".equals(action)) { order = copy.optInt("order", 2); applyOrder(); }
            else if ("next".equals(action) && controller != null) controller.seekToNextMediaItem();
            else if ("previous".equals(action) && controller != null) controller.seekToPreviousMediaItem();
            else if ("state".equals(action)) emit("state", 0);
        });
    }

    private void load(JSONObject load) {
        waitingLoad = load;
        if (controller == null) return;
        String token = load.optString("trackId", "");
        try {
            if (token.isEmpty() || token.length() > 256) throw new IllegalArgumentException("Invalid track token");
            String source = load.optString("source", "");
            String id = load.optString("id", "");
            if (!PlaybackService.supportedSource(source) || id.isEmpty()) throw new IllegalArgumentException("Invalid track");
            String stream = PlaybackService.publicHttps(load.optString("url", ""), false).toString();
            JSONObject queued = pendingQueue != null && token.equals(pendingQueue.optString("trackId")) ? pendingQueue : null;
            if (load.optJSONObject("queue") != null) queued = load.optJSONObject("queue");
            JSONArray entries = queued == null ? new JSONArray() : queued.optJSONArray("items");
            int index = queued == null ? 0 : queued.optInt("index", 0);
            if (entries == null || entries.length() == 0 || entries.length() > 1000) { entries = new JSONArray(); index = 0; }
            JSONObject selected = music(load);
            if (entries.length() == 0) entries.put(selected);
            if (index < 0 || index >= entries.length()) index = 0;
            JSONObject queueSelected = entries.optJSONObject(index);
            if (queueSelected == null || !id.equals(queueSelected.optString("id")) || !source.equals(queueSelected.optString("source"))) {
                entries = new JSONArray().put(selected); index = 0;
            } else {
                selected = new JSONObject(queueSelected.toString());
                selected.put("url", stream);
                selected.put("name", load.optString("title", selected.optString("name")));
                selected.put("artist", load.optString("artist", selected.optString("artist")));
                selected.put("album", load.optString("album", selected.optString("album")));
                if (!load.optString("artwork").isEmpty()) selected.put("pic", load.optString("artwork"));
                entries.put(index, selected);
            }
            currentQueueId = token;
            order = queued == null ? order : queued.optInt("order", 2);
            List<MediaItem> items = new ArrayList<>();
            for (int i = 0; i < entries.length(); i++) {
                JSONObject entry = entries.getJSONObject(i);
                items.add(item(entry, i == index ? token : "native-" + UUID.randomUUID(), token, i == index ? stream : ""));
            }
            controller.setMediaItems(items, index, 0);
            controller.setVolume(desiredVolume);
            applyOrder();
            controller.prepare();
            if (desiredPlay) controller.play();
            waitingLoad = null; pendingQueue = null;
            emit("loadstart", 0);
        } catch (Exception error) {
            waitingLoad = null;
            emitFailure(token, load.optString("url", ""), 4);
        }
    }

    private MediaItem item(JSONObject music, String token, String queueId, String stream) throws Exception {
        String source = music.optString("source");
        String id = music.optString("id");
        if (!PlaybackService.supportedSource(source) || id.isEmpty() || id.length() > 512) throw new IllegalArgumentException("Invalid queue track");
        String art = music.optString("pic", "");
        Uri artwork = null;
        try { if (!art.isEmpty()) artwork = PlaybackService.publicHttps(art, false); } catch (Exception ignored) { }
        Uri.Builder location = new Uri.Builder().scheme("kunmusic").authority(source).appendPath(id)
                .appendQueryParameter("url_id", music.optString("url_id", ""))
                .appendQueryParameter("pic_id", music.optString("pic_id", ""))
                .appendQueryParameter("token", token);
        if (!stream.isEmpty()) location.appendQueryParameter("stream", stream);
        if (artwork == null) location.appendQueryParameter("art", "1");
        Bundle extras = new Bundle();
        extras.putString(PlaybackService.EXTRA_TRACK, token);
        extras.putString(PlaybackService.EXTRA_QUEUE, queueId);
        extras.putString(PlaybackService.EXTRA_MUSIC, music.toString());
        MediaMetadata metadata = new MediaMetadata.Builder().setTitle(music.optString("name", "未知歌曲"))
                .setArtist(music.optString("artist", "未知歌手")).setAlbumTitle(music.optString("album", ""))
                .setArtworkUri(artwork).setIsPlayable(true).setMediaType(MediaMetadata.MEDIA_TYPE_MUSIC).setExtras(extras).build();
        return new MediaItem.Builder().setMediaId(source + ":" + id).setUri(location.build()).setMediaMetadata(metadata).build();
    }

    private static JSONObject music(JSONObject load) throws JSONException {
        JSONObject music = new JSONObject();
        music.put("id", load.optString("id")); music.put("source", load.optString("source"));
        music.put("name", load.optString("title")); music.put("artist", load.optString("artist"));
        music.put("album", load.optString("album")); music.put("pic", load.optString("artwork"));
        music.put("url", load.optString("url"));
        return music;
    }

    private void updateMetadata(JSONObject command) {
        if (controller == null || controller.getCurrentMediaItem() == null) return;
        MediaItem current = controller.getCurrentMediaItem();
        MediaMetadata.Builder metadata = current.mediaMetadata.buildUpon();
        if (command.has("title")) metadata.setTitle(command.optString("title"));
        if (command.has("artist")) metadata.setArtist(command.optString("artist"));
        if (command.has("album")) metadata.setAlbumTitle(command.optString("album"));
        try { if (!command.optString("artwork").isEmpty()) metadata.setArtworkUri(PlaybackService.publicHttps(command.optString("artwork"), false)); }
        catch (Exception ignored) { }
        controller.replaceMediaItem(controller.getCurrentMediaItemIndex(), current.buildUpon().setMediaMetadata(metadata.build()).build());
    }

    private void applyOrder() {
        if (controller == null) return;
        if (order != 1 && order != 2 && order != 3) order = 2;
        controller.setRepeatMode(order == 1 ? Player.REPEAT_MODE_ONE : Player.REPEAT_MODE_ALL);
        controller.setShuffleModeEnabled(order == 3);
    }

    private String trackId() {
        if (controller == null || controller.getCurrentMediaItem() == null || controller.getCurrentMediaItem().mediaMetadata.extras == null) return "";
        return controller.getCurrentMediaItem().mediaMetadata.extras.getString(PlaybackService.EXTRA_TRACK, "");
    }
    private String waitingTrackId() { return waitingLoad == null ? "" : waitingLoad.optString("trackId"); }

    private JSONObject state(String type, int errorCode) throws JSONException {
        JSONObject event = new JSONObject(); event.put("type", type); event.put("errorCode", errorCode);
        if ("error".equals(type)) event.put("handled", true);
        MediaItem current = controller == null ? null : controller.getCurrentMediaItem();
        Bundle extras = current == null ? null : current.mediaMetadata.extras;
        String queueId = extras == null ? currentQueueId : extras.getString(PlaybackService.EXTRA_QUEUE, currentQueueId);
        JSONObject music = extras == null ? new JSONObject() : new JSONObject(extras.getString(PlaybackService.EXTRA_MUSIC, "{}"));
        if (current != null && current.mediaMetadata.artworkUri != null) music.put("pic", current.mediaMetadata.artworkUri.toString());
        String src = extras == null ? null : extras.getString(PlaybackService.EXTRA_STREAM);
        if (src == null) src = current != null && current.localConfiguration != null ? current.localConfiguration.uri.getQueryParameter("stream") : null;
        if (src == null) src = "";
        event.put("trackId", trackId()); event.put("queueId", queueId); event.put("id", music.optString("id"));
        event.put("source", music.optString("source")); event.put("src", src); event.put("music", music);
        event.put("currentTime", controller == null ? 0 : Math.max(0, controller.getCurrentPosition()) / 1000.0);
        long duration = controller == null ? 0 : controller.getDuration();
        event.put("duration", duration == C.TIME_UNSET ? 0 : Math.max(0, duration) / 1000.0);
        event.put("paused", controller == null || !controller.getPlayWhenReady());
        event.put("volume", controller == null ? desiredVolume : controller.getVolume());
        int index = controller == null ? 0 : controller.getCurrentMediaItemIndex();
        event.put("index", index); event.put("queueIndex", index);
        if ("state".equals(type) || "trackchange".equals(type)) {
            JSONArray entries = new JSONArray();
            if (controller != null) for (int i = 0; i < controller.getMediaItemCount(); i++) {
                Bundle entryExtras = controller.getMediaItemAt(i).mediaMetadata.extras;
                entries.put(new JSONObject(entryExtras == null ? "{}" : entryExtras.getString(PlaybackService.EXTRA_MUSIC, "{}")));
            }
            int repeat = controller == null ? Player.REPEAT_MODE_ALL : controller.getRepeatMode();
            int currentOrder = repeat == Player.REPEAT_MODE_ONE ? 1 : controller != null && controller.getShuffleModeEnabled() ? 3 : 2;
            event.put("queue", new JSONObject().put("items", entries).put("index", index).put("order", currentOrder));
        }
        return event;
    }

    private void emit(String type, int errorCode) {
        if (released) return;
        try { sink.onEvent(state(type, errorCode)); } catch (Exception ignored) { }
    }
    private void emitFailure(String token, String src, int code) {
        if (released) return;
        try { JSONObject error = state("error", code); error.put("trackId", token); error.put("queueId", token); error.put("src", src); error.put("handled", false); sink.onEvent(error); }
        catch (Exception ignored) { }
    }

    @Override public void onMediaItemTransition(MediaItem item, int reason) { emit("trackchange", 0); }
    @Override public void onPlayWhenReadyChanged(boolean playWhenReady, int reason) { desiredPlay = playWhenReady; emit(playWhenReady ? "play" : "pause", 0); }
    @Override public void onIsPlayingChanged(boolean playing) { if (playing) emit("playing", 0); }
    @Override public void onPlaybackStateChanged(int state) {
        if (state == Player.STATE_READY) { emit("loadedmetadata", 0); emit("durationchange", 0); }
        else if (state == Player.STATE_ENDED) emit("ended", 0);
    }
    @Override public void onMediaMetadataChanged(MediaMetadata metadata) { emit("metadata", 0); emit("state", 0); }
    @Override public void onPlayerError(PlaybackException error) { emit("error", error.errorCode); }
    @Override public void onVolumeChanged(float volume) { emit("volumechange", 0); }

    public void release() {
        main.post(() -> {
            if (released) return;
            released = true; main.removeCallbacksAndMessages(null);
            if (controller != null) { controller.removeListener(this); controller.release(); controller = null; }
            else if (connection != null) MediaController.releaseFuture(connection);
            connection = null; waitingLoad = null; pendingQueue = null;
        });
    }
}
