package ng.atheris.suite;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.UUID;

import com.fasterxml.jackson.databind.ObjectMapper;

import org.junit.jupiter.api.BeforeEach;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.web.server.LocalServerPort;
import org.springframework.boot.testcontainers.service.connection.ServiceConnection;
import org.springframework.jdbc.core.JdbcTemplate;
import org.testcontainers.containers.PostgreSQLContainer;

import ng.atheris.suite.model.UuidV7;
import ng.atheris.suite.security.SuiteSigner;
import ng.atheris.suite.testapp.TestHandlers;

/** Shared Postgres container (one per JVM) and HTTP helpers. */
abstract class AbstractIT {

    @ServiceConnection
    static final PostgreSQLContainer<?> PG = new PostgreSQLContainer<>("postgres:17");
    static { PG.start(); }

    record Resp(int status, String body) {}

    @LocalServerPort int port;
    @Autowired JdbcTemplate jdbc;
    @Autowired ObjectMapper mapper;

    final HttpClient http = HttpClient.newBuilder().version(HttpClient.Version.HTTP_1_1).build();

    @BeforeEach
    void cleanDatabase() {
        jdbc.execute("truncate suite_outbox, suite_inbox, suite_audit_log");
        TestHandlers.SEEN.clear();
    }

    static String envelope(UUID id, String type, String tenant, String subject) {
        return """
            {"id":"%s","type":"%s","version":1,"occurred_at":"2026-10-31T09:15:00+01:00","source":"isec",
             "tenant":"%s","subject":"%s","correlation_id":null,"actor":null,"data":{"k":"v"}}"""
            .formatted(id, type, tenant, subject);
    }

    static String envelope(String type) {
        return envelope(UuidV7.next(), type, "kano-heritage-bank", "isec:incident:INC-1");
    }

    Resp send(String method, String path, String body, String client, String secret, long ts) throws Exception {
        byte[] bytes = body == null ? new byte[0] : body.getBytes(StandardCharsets.UTF_8);
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + path))
                .header("Content-Type", "application/json")
                .method(method, bytes.length == 0 && method.equals("GET")
                        ? HttpRequest.BodyPublishers.noBody() : HttpRequest.BodyPublishers.ofByteArray(bytes));
        if (client != null) b.header("X-Suite-Client", client);
        b.header("X-Suite-Timestamp", Long.toString(ts));
        if (secret != null) b.header("X-Suite-Signature", SuiteSigner.sign(secret, ts, bytes));
        HttpResponse<String> r = http.send(b.build(), HttpResponse.BodyHandlers.ofString());
        return new Resp(r.statusCode(), r.body());
    }

    /** Correctly signed as the peer (isec-client). */
    Resp postEvent(String body) throws Exception {
        return send("POST", "/api/suite/v1/events", body, "isec-client", "current-secret", Instant.now().getEpochSecond());
    }

    int count(String sql, Object... args) {
        return jdbc.queryForObject(sql, Integer.class, args);
    }
}
