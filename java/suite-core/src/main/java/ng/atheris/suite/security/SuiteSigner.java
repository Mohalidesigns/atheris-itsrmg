package ng.atheris.suite.security;

import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.Map;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

import ng.atheris.suite.SuiteProperties;

/** Produces and checks the {@code X-Suite-*} authentication headers (ATH-SUI-001 section 3.2). */
public class SuiteSigner {

    public static final String CLIENT_HEADER = "X-Suite-Client";
    public static final String TIMESTAMP_HEADER = "X-Suite-Timestamp";
    public static final String SIGNATURE_HEADER = "X-Suite-Signature";

    private final SuiteProperties props;
    private final Clock clock;

    public SuiteSigner(SuiteProperties props, Clock clock) {
        this.props = props;
        this.clock = clock;
    }

    /** {@code sha256=<hex HMAC-SHA256 of "<timestamp>.<body>">}. */
    public static String sign(String secret, long timestamp, byte[] body) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            mac.update((timestamp + ".").getBytes(StandardCharsets.UTF_8));
            mac.update(body);
            return "sha256=" + HexFormat.of().formatHex(mac.doFinal());
        } catch (NoSuchAlgorithmException | InvalidKeyException e) {
            throw new IllegalStateException(e);
        }
    }

    /** Headers for an outbound request signed with this product's client id and current secret. */
    public Map<String, String> headers(byte[] body) {
        long ts = clock.instant().getEpochSecond();
        Map<String, String> h = new LinkedHashMap<>();
        h.put(CLIENT_HEADER, props.getClientId());
        h.put(TIMESTAMP_HEADER, Long.toString(ts));
        h.put(SIGNATURE_HEADER, sign(props.getSecret(), ts, body));
        return h;
    }

    /** Constant-time check of a presented signature against one secret. */
    public static boolean matches(String secret, long timestamp, byte[] body, String presented) {
        if (secret == null || secret.isEmpty() || presented == null) {
            return false;
        }
        return MessageDigest.isEqual(
                sign(secret, timestamp, body).getBytes(StandardCharsets.UTF_8),
                presented.getBytes(StandardCharsets.UTF_8));
    }
}
