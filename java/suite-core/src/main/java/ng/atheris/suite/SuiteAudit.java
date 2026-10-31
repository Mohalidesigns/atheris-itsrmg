package ng.atheris.suite;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.UUID;

import org.springframework.jdbc.core.simple.JdbcClient;

import ng.atheris.suite.model.Envelope;

/** Writes the {@code suite_audit_log} rows (ATH-SUI-001 section 3.7): event id, type, subject, outcome. */
public class SuiteAudit {

    private final JdbcClient jdbc;
    private final Clock clock;

    public SuiteAudit(JdbcClient jdbc, Clock clock) {
        this.jdbc = jdbc;
        this.clock = clock;
    }

    public void record(UUID eventId, String type, String subject, String direction, String outcome, String detail) {
        jdbc.sql("""
                insert into suite_audit_log (event_id, type, subject, direction, outcome, detail, created_at)
                values (:event, :type, :subject, :direction, :outcome, :detail, :now)""")
                .param("event", eventId).param("type", type).param("subject", subject)
                .param("direction", direction).param("outcome", outcome).param("detail", detail)
                .param("now", OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC))
                .update();
    }

    public void record(Envelope e, String direction, String outcome, String detail) {
        record(e.id(), e.type(), e.subject(), direction, outcome, detail);
    }
}
