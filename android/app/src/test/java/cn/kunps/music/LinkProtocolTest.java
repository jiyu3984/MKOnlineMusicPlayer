package cn.kunps.music;

import static org.junit.Assert.*;
import org.junit.Test;

public class LinkProtocolTest {
    @Test public void acceptsSongIdentifiers() {
        assertEquals(new LinkProtocol.Song("netease", "287398"),
                LinkProtocol.parse("kunmusic://song?source=netease&id=287398"));
        assertNotNull(LinkProtocol.parse("kunmusic://song/?id=ABC_12-3&source=kugou"));
    }

    @Test public void rejectsExecutableOrAmbiguousLinks() {
        for (String link : new String[] {
                "https://song?source=netease&id=1", "kunmusic://song@evil.com?source=netease&id=1",
                "kunmusic://song:443?source=netease&id=1", "kunmusic://song?source=netease&id=1&id=2",
                "kunmusic://song?source=netease&id=1&url=https://evil.com/a.mp3",
                "kunmusic://song?source=unsupported&id=1", "kunmusic://song?source=netease&id=%0A1",
                "kunmusic://song?source=netease&id=1#extra", "kunmusic://song/other?source=netease&id=1",
                "kunmusic://song?source=netease&id=%ZZ", "kunmusic://song?source=netease",
                "kunmusic://song?id=1", "kunmusic://song?"
        }) assertNull(link, LinkProtocol.parse(link));
    }
}
