package ng.atheris.suite.outbox;

import java.time.Clock;
import java.time.Duration;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.UUID;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

import ng.atheris.suite.SuiteAudit;
import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.client.SuiteClient;
import ng.atheris.suite.client.SuitePeerUnavailableException;

/**
 * Delivers pending outbox rows to the peer. One row per transaction, claimed with
 * {@code FOR UPDATE SKIP LOCKED} so several instances can run. A row is held back while an older
 * pending row exists for the same subject (per-subject ordering); {@code failed} rows do not block.
 */
public class OutboxDeliverer {

    private static final Logger log = LoggerFactory.getLogger(OutboxDeliverer.class);
    private static final Duration[] BACKOFF = {
        Duration.ofSeconds(30), Duration.ofMinutes(2), Duration.ofMinutes(10), Duration.ofMinutes(30), Duration.ofHours(1)
    };

    private final JdbcClient jdbc;
    private final SuiteClient client;
    private final SuiteAudit audit;
    private final SuiteProperties props;
    private final Clock clock;
    private final TransactionTemplate tx;

    public OutboxDeliverer(JdbcClient jdbc, SuiteClient client, SuiteAudit audit, SuiteProperties props,
                           Clock clock, PlatformTransactionManager txManager) {
        this.jdbc = jdbc;
        this.client = client;
        this.audit = audit;
        this.props = props;
        this.clock = clock;
        this.tx = new TransactionTemplate(txManager);
    }

    /** Delay before the next attempt after {@code attempts} failed attempts: 30s, 2m, 10m, 30m, 1h, then hourly. */
    public static Duration backoff(int attempts) {
        return BACKOFF[Math.min(Math.max(attempts, 1), BACKOFF.length) - 1];
    }

    @Scheduled(fixedDelayString = "${suite.outbox.poll-interval:5s}", initialDelayString = "${suite.outbox.poll-interval:5s}")
    public void poll() {
        try {
            deliverDue();
        } catch (RuntimeException e) {
            log.error("Suite outbox delivery run failed", e);
        }
    }

    /** Processes due rows (up to the batch size); returns the number of rows attempted. */
    public int deliverDue() {
        int n = 0;
        while (n < props.getOutbox().getBatchSize() && Boolean.TRUE.equals(tx.execute(s -> deliverOne()))) {
            n++;
        }
        return n;
    }

    private boolean deliverOne() {
        OffsetDateTime now = OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
        var row = jdbc.sql("""
                select o.id, o.type, o.subject, o.payload::text as payload, o.attempts, o.created_at
                from suite_outbox o
                where o.status = 'pending' and o.next_attempt_at <= :now
                  and not exists (select 1 from suite_outbox p
                                  where p.subject = o.subject and p.status = 'pending' and p.seq < o.seq)
                order by o.seq limit 1 for update of o skip locked""")
                .param("now", now)
                .query((rs, i) -> new Row(rs.getObject("id", UUID.class), rs.getString("type"), rs.getString("subject"),
                        rs.getString("payload"), rs.getInt("attempts"), rs.getObject("created_at", OffsetDateTime.class)))
                .optional();
        if (row.isEmpty()) {
            return false;
        }
        Row r = row.get();
        int attempts = r.attempts() + 1;
        try {
            SuiteClient.Response res = client.postEvent(r.payload());
            if (res.ok()) {
                jdbc.sql("update suite_outbox set status='delivered', attempts=:a, delivered_at=:now, last_error=null where id=:id")
                        .param("a", attempts).param("now", now).param("id", r.id()).update();
                audit.record(r.id(), r.type(), r.subject(), "send", "delivered", "HTTP " + res.status());
            } else if (res.status() >= 500) {
                retryOrFail(r, attempts, now, "HTTP " + res.status());
            } else {
                fail(r, attempts, "HTTP " + res.status() + ": " + res.body());
            }
        } catch (SuitePeerUnavailableException e) {
            retryOrFail(r, attempts, now, e.getMessage());
        }
        return true;
    }

    private void retryOrFail(Row r, int attempts, OffsetDateTime now, String error) {
        if (!now.isBefore(r.createdAt().plus(props.getOutbox().getMaxAge()))) {
            fail(r, attempts, "gave up after " + props.getOutbox().getMaxAge() + ": " + error);
            return;
        }
        OffsetDateTime next = now.plus(backoff(attempts));
        jdbc.sql("update suite_outbox set attempts=:a, next_attempt_at=:next, last_error=:err where id=:id")
                .param("a", attempts).param("next", next).param("err", error).param("id", r.id()).update();
        audit.record(r.id(), r.type(), r.subject(), "send", "retry", error);
    }

    private void fail(Row r, int attempts, String error) {
        jdbc.sql("update suite_outbox set status='failed', attempts=:a, last_error=:err where id=:id")
                .param("a", attempts).param("err", error).param("id", r.id()).update();
        audit.record(r.id(), r.type(), r.subject(), "send", "failed", error);
    }

    private record Row(UUID id, String type, String subject, String payload, int attempts, OffsetDateTime createdAt) {}
}
