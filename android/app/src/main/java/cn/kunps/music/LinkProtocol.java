package cn.kunps.music;

import java.net.URI;
import java.net.URLDecoder;
import java.io.UnsupportedEncodingException;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.Map;
import java.util.Set;

/** External links carry identifiers only; media locations always come from the server. */
public final class LinkProtocol {
    private static final Set<String> SOURCES = Set.of("netease", "kugou", "tencent", "kuwo", "baidu", "xiami");

    public record Song(String source, String id) {}

    public static Song parse(String value) {
        if (value == null || value.length() > 512) return null;
        try {
            URI uri = URI.create(value);
            if (!"kunmusic".equals(uri.getScheme()) || !"song".equals(uri.getHost())
                    || uri.getUserInfo() != null || uri.getPort() != -1 || uri.getFragment() != null
                    || (uri.getPath() != null && !uri.getPath().isEmpty() && !"/".equals(uri.getPath()))) return null;
            Map<String, String> parameters = new HashMap<>();
            String query = uri.getRawQuery();
            if (query == null) return null;
            for (String pair : query.split("&", -1)) {
                String[] parts = pair.split("=", 2);
                if (parts.length != 2) return null;
                String key = URLDecoder.decode(parts[0], StandardCharsets.UTF_8.name());
                String decoded = URLDecoder.decode(parts[1], StandardCharsets.UTF_8.name());
                if ((!"source".equals(key) && !"id".equals(key)) || parameters.put(key, decoded) != null) return null;
            }
            String source = parameters.get("source"), id = parameters.get("id");
            if (source == null || !SOURCES.contains(source) || id == null || !id.matches("[A-Za-z0-9_-]{1,192}")) return null;
            return new Song(source, id);
        } catch (IllegalArgumentException | UnsupportedEncodingException error) {
            return null;
        }
    }

    private LinkProtocol() {}
}
