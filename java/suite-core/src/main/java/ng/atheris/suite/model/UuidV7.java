package ng.atheris.suite.model;

import java.security.SecureRandom;
import java.util.UUID;

/** Minimal UUIDv7 (RFC 9562) generator: 48-bit unix-ms timestamp, version 7, random remainder. */
public final class UuidV7 {

    private static final SecureRandom RANDOM = new SecureRandom();

    private UuidV7() {}

    public static UUID next() {
        return next(System.currentTimeMillis());
    }

    public static UUID next(long epochMillis) {
        long msb = (epochMillis << 16) | 0x7000L | RANDOM.nextInt(1 << 12);
        long lsb = (RANDOM.nextLong() & 0x3FFFFFFFFFFFFFFFL) | 0x8000000000000000L;
        return new UUID(msb, lsb);
    }
}
