package ng.atheris.suite.outbox;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.UUID;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.transaction.annotation.Propagation;
import org.springframework.transaction.annotation.Transactional;

import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.model.Envelope;
import ng.atheris.suite.model.SuiteRef;
import ng.atheris.suite.model.UuidV7;

public class OutboxPublisher implements SuitePublisher {

    private final JdbcClient jdbc;
    private final ObjectMapper mapper;
    private final SuiteProperties props;
    private final Clock clock;

    public OutboxPublisher(JdbcClient jdbc, ObjectMapper mapper, SuiteProperties props, Clock clock) {
        this.jdbc = jdbc;
        this.mapper = mapper;
        this.props = props;
        this.clock = clock;
    }

    @Override
    @Transactional(propagation = Propagation.MANDATORY)
    public UUID publish(String type, int version, String tenant, String subject, JsonNode data,
                        Envelope.Actor actor, String correlationId) {
        SuiteRef.parse(subject); // fail fast on a bad reference
        OffsetDateTime now = OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
        UUID id = UuidV7.next();
        Envelope env = new Envelope(id, type, version, now, props.getSource(), tenant, subject,
                correlationId, actor, data == null ? mapper.createObjectNode() : data);
        String json;
        try {
            json = mapper.writeValueAsString(env);
        } catch (JsonProcessingException e) {
            throw new IllegalArgumentException("event data cannot be serialized", e);
        }
        jdbc.sql("""
                insert into suite_outbox (id, type, version, tenant, subject, payload, status, attempts, next_attempt_at, created_at)
                values (:id, :type, :version, :tenant, :subject, cast(:payload as jsonb), 'pending', 0, :now, :now)""")
                .param("id", id).param("type", type).param("version", version).param("tenant", tenant)
                .param("subject", subject).param("payload", json).param("now", now)
                .update();
        return id;
    }
}
