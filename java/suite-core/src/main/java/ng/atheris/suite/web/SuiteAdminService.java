package ng.atheris.suite.web;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.List;
import java.util.UUID;

import org.springframework.jdbc.core.simple.JdbcClient;

/** Backs the "Suite -> Delivery failures" admin page. */
public class SuiteAdminService {

    public record FailedDelivery(UUID id, String type, String tenant, String subject, int attempts,
                                 String lastError, OffsetDateTime createdAt) {}

    private final JdbcClient jdbc;
    private final Clock clock;

    public SuiteAdminService(JdbcClient jdbc, Clock clock) {
        this.jdbc = jdbc;
        this.clock = clock;
    }

    public List<FailedDelivery> failures() {
        return jdbc.sql("select id, type, tenant, subject, attempts, last_error, created_at from suite_outbox where status = 'failed' order by seq desc")
                .query((rs, i) -> new FailedDelivery(rs.getObject("id", UUID.class), rs.getString("type"),
                        rs.getString("tenant"), rs.getString("subject"), rs.getInt("attempts"),
                        rs.getString("last_error"), rs.getObject("created_at", OffsetDateTime.class)))
                .list();
    }

    /** Resets a failed row to pending with zero attempts, due now. @return false if there is no such failed row */
    public boolean retry(UUID id) {
        return jdbc.sql("update suite_outbox set status='pending', attempts=0, next_attempt_at=:now where id=:id and status='failed'")
                .param("now", OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC)).param("id", id).update() > 0;
    }
}
