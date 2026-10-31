package ng.atheris.platform;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.Base64;
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

abstract class AbstractIT {

    @ServiceConnection
    static final PostgreSQLContainer<?> PG = new PostgreSQLContainer<>("postgres:17");
    static { PG.start(); }

    record Resp(int status, String body) {}

    @LocalServerPort int port;
    @Autowired JdbcTemplate jdbc;
    @Autowired ObjectMapper mapper;
    @Autowired PlatformRepository repo;
    @Autowired org.springframework.security.crypto.password.PasswordEncoder encoder;

    final HttpClient http = HttpClient.newBuilder().version(HttpClient.Version.HTTP_1_1).build();

    @BeforeEach
    void cleanDatabase() {
        jdbc.execute("truncate suite_outbox, suite_inbox, suite_audit_log, user_roles, users, organizations restart identity cascade");
    }

    /** Creates a login-capable user directly in the database. */
    long seedUser(String slug, String email, String password, String... roles) {
        var org = repo.ensureOrg(slug, slug);
        var u = repo.insertUser(org.id(), email, email, null, null, password == null ? null : encoder.encode(password),
                true, "local", java.time.OffsetDateTime.now());
        repo.setRoles(u.id(), java.util.List.of(roles));
        return u.id();
    }

    Resp call(String method, String path, String body, String user, String password) throws Exception {
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + path))
                .header("Content-Type", "application/json")
                .method(method, body == null ? HttpRequest.BodyPublishers.noBody() : HttpRequest.BodyPublishers.ofString(body));
        if (user != null) {
            b.header("Authorization", "Basic " + Base64.getEncoder().encodeToString((user + ":" + password).getBytes(StandardCharsets.UTF_8)));
        }
        HttpResponse<String> r = http.send(b.build(), HttpResponse.BodyHandlers.ofString());
        return new Resp(r.statusCode(), r.body());
    }

    /** Correctly signed as the peer (isec-client). */
    Resp postEvent(String body) throws Exception {
        long ts = Instant.now().getEpochSecond();
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        HttpRequest r = HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + "/api/suite/v1/events"))
                .header("Content-Type", "application/json")
                .header("X-Suite-Client", "isec-client")
                .header("X-Suite-Timestamp", Long.toString(ts))
                .header("X-Suite-Signature", SuiteSigner.sign("current-secret", ts, bytes))
                .POST(HttpRequest.BodyPublishers.ofByteArray(bytes)).build();
        HttpResponse<String> resp = http.send(r, HttpResponse.BodyHandlers.ofString());
        return new Resp(resp.statusCode(), resp.body());
    }

    static String userEvent(String type, String tenant, String dataJson) {
        return """
            {"id":"%s","type":"%s","version":1,"occurred_at":"2026-10-31T09:15:00+01:00","source":"isec",
             "tenant":"%s","subject":"isec:user:someone@x.ng","correlation_id":null,"actor":null,"data":%s}"""
            .formatted(UuidV7.next(), type, tenant, dataJson);
    }

    static String userEvent(UUID id, String type, String tenant, String dataJson) {
        return userEvent(type, tenant, dataJson).replaceFirst("\"id\":\"[^\"]+\"", "\"id\":\"" + id + "\"");
    }

    int count(String sql, Object... args) {
        return jdbc.queryForObject(sql, Integer.class, args);
    }
}
