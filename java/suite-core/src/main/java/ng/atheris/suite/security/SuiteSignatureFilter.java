package ng.atheris.suite.security;

import java.io.BufferedReader;
import java.io.ByteArrayInputStream;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.charset.Charset;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Instant;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ReadListener;
import jakarta.servlet.ServletException;
import jakarta.servlet.ServletInputStream;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;
import jakarta.servlet.http.HttpServletResponse;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.web.filter.OncePerRequestFilter;

import ng.atheris.suite.SuiteProperties;

/**
 * Verifies the HMAC signature of every request under {@code /api/suite/v1/**}. Registered for that
 * pattern by the auto-configuration. The raw body is cached so controllers can still read it.
 */
public class SuiteSignatureFilter extends OncePerRequestFilter {

    private static final Logger log = LoggerFactory.getLogger(SuiteSignatureFilter.class);

    private final SuiteProperties props;
    private final Clock clock;

    public SuiteSignatureFilter(SuiteProperties props, Clock clock) {
        this.props = props;
        this.clock = clock;
    }

    /** Suite payloads are small JSON; refuse anything larger before verifying the signature. */
    static final int MAX_BODY_BYTES = 1024 * 1024;

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        byte[] body = request.getInputStream().readNBytes(MAX_BODY_BYTES + 1);
        if (body.length > MAX_BODY_BYTES) {
            response.setStatus(413);
            response.setContentType("application/json;charset=UTF-8");
            response.getWriter().write("{\"error\":\"payload_too_large\"}");
            return;
        }
        String problem = verify(request, body);
        if (problem != null) {
            log.warn("Rejected suite request {} {}: {}", request.getMethod(), request.getRequestURI(), problem);
            response.setStatus(HttpServletResponse.SC_UNAUTHORIZED);
            response.setContentType("application/json;charset=UTF-8");
            response.getWriter().write("{\"error\":\"unauthorized\",\"message\":\"" + problem + "\"}");
            return;
        }
        chain.doFilter(new CachedBodyRequest(request, body), response);
    }

    private String verify(HttpServletRequest req, byte[] body) {
        String peerClient = props.getPeer().getClientId();
        String client = req.getHeader(SuiteSigner.CLIENT_HEADER);
        if (peerClient == null || peerClient.isBlank() || client == null || !client.equals(peerClient)) {
            return "unknown client";
        }
        long ts;
        try {
            ts = Long.parseLong(req.getHeader(SuiteSigner.TIMESTAMP_HEADER));
        } catch (NumberFormatException e) {
            return "invalid timestamp";
        }
        Instant now = clock.instant();
        if (Math.abs(now.getEpochSecond() - ts) > props.getClockSkewSeconds()) {
            return "timestamp outside allowed skew";
        }
        String sig = req.getHeader(SuiteSigner.SIGNATURE_HEADER);
        boolean ok = SuiteSigner.matches(props.getSecret(), ts, body, sig);
        Instant until = props.getPreviousSecretExpiresAt();
        if (until != null && now.isBefore(until)) {
            ok |= SuiteSigner.matches(props.getPreviousSecret(), ts, body, sig); // non-short-circuit on purpose
        }
        return ok ? null : "invalid signature";
    }

    private static final class CachedBodyRequest extends HttpServletRequestWrapper {
        private final byte[] body;

        CachedBodyRequest(HttpServletRequest request, byte[] body) {
            super(request);
            this.body = body;
        }

        @Override
        public ServletInputStream getInputStream() {
            ByteArrayInputStream in = new ByteArrayInputStream(body);
            return new ServletInputStream() {
                @Override public int read() { return in.read(); }
                @Override public int read(byte[] b, int off, int len) { return in.read(b, off, len); }
                @Override public boolean isFinished() { return in.available() == 0; }
                @Override public boolean isReady() { return true; }
                @Override public void setReadListener(ReadListener listener) { throw new UnsupportedOperationException(); }
            };
        }

        @Override
        public BufferedReader getReader() {
            String enc = getCharacterEncoding();
            Charset cs = enc != null ? Charset.forName(enc) : StandardCharsets.UTF_8;
            return new BufferedReader(new InputStreamReader(getInputStream(), cs));
        }
    }
}
