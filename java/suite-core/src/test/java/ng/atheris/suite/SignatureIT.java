package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;

import java.time.Instant;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.suite.testapp.TestApp;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
class SignatureIT extends AbstractIT {

    @Autowired SuiteProperties props;

    @AfterEach
    void resetRotation() {
        props.setPreviousSecret(null);
        props.setPreviousSecretExpiresAt(null);
    }

    long now() { return Instant.now().getEpochSecond(); }

    @Test
    void goodSignatureIsAccepted() throws Exception {
        assertThat(postEvent(envelope("test.recorded")).status()).isEqualTo(202);
    }

    @Test
    void healthIsSignedAndReturnsStatus() throws Exception {
        Resp ok = send("GET", "/api/suite/v1/health", null, "isec-client", "current-secret", now());
        assertThat(ok.status()).isEqualTo(200);
        assertThat(mapper.readTree(ok.body()).path("status").asText()).isEqualTo("ok");
        assertThat(mapper.readTree(ok.body()).path("source").asText()).isEqualTo("itg");
        assertThat(send("GET", "/api/suite/v1/health", null, "isec-client", null, now()).status()).isEqualTo(401);
    }

    @Test
    void badSignatureIsRejected() throws Exception {
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "wrong-secret", now())
                .status()).isEqualTo(401);
    }

    @Test
    void unknownClientIsRejected() throws Exception {
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "someone-else", "current-secret", now())
                .status()).isEqualTo(401);
    }

    @Test
    void expiredTimestampIsRejected() throws Exception {
        Resp r = send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "current-secret", now() - 301);
        assertThat(r.status()).isEqualTo(401);
        assertThat(r.body()).contains("unauthorized");
        // just inside the skew is fine
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "current-secret", now() - 290)
                .status()).isEqualTo(202);
    }

    @Test
    void tamperedBodyIsRejected() throws Exception {
        long ts = now();
        String signedFor = envelope("test.recorded");
        // sign one body, send another
        var req = java.net.http.HttpRequest.newBuilder(java.net.URI.create("http://127.0.0.1:" + port + "/api/suite/v1/events"))
                .header("X-Suite-Client", "isec-client").header("X-Suite-Timestamp", Long.toString(ts))
                .header("X-Suite-Signature", ng.atheris.suite.security.SuiteSigner.sign("current-secret", ts, signedFor.getBytes()))
                .POST(java.net.http.HttpRequest.BodyPublishers.ofString(envelope("test.recorded"))).build();
        assertThat(http.send(req, java.net.http.HttpResponse.BodyHandlers.ofString()).statusCode()).isEqualTo(401);
    }

    @Test
    void previousSecretAcceptedWithinWindow() throws Exception {
        props.setPreviousSecret("old-secret");
        props.setPreviousSecretExpiresAt(Instant.now().plusSeconds(3600));
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "old-secret", now())
                .status()).isEqualTo(202);
        assertThat(postEvent(envelope("test.recorded")).status()).isEqualTo(202); // current still works
    }

    @Test
    void previousSecretRejectedAfterWindow() throws Exception {
        props.setPreviousSecret("old-secret");
        props.setPreviousSecretExpiresAt(Instant.now().minusSeconds(1));
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "old-secret", now())
                .status()).isEqualTo(401);
    }
}
