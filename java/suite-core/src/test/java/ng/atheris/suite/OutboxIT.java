package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.time.Duration;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.transaction.support.TransactionTemplate;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import ng.atheris.suite.model.Envelope;
import ng.atheris.suite.outbox.OutboxDeliverer;
import ng.atheris.suite.outbox.SuitePublisher;
import ng.atheris.suite.testapp.TestApp;

/** Peer is unreachable (127.0.0.1:1), so every delivery attempt is a retryable network failure. */
@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
@AutoConfigureMockMvc
class OutboxIT extends AbstractIT {

    @Autowired SuitePublisher publisher;
    @Autowired OutboxDeliverer deliverer;
    @Autowired org.springframework.transaction.PlatformTransactionManager txm;
    @Autowired MockMvc mvc;

    UUID publish(String subject) {
        return new TransactionTemplate(txm).execute(s -> publisher.publish("test.recorded", 1, "kano-heritage-bank",
                subject, mapper.createObjectNode().put("n", 1), new Envelope.Actor("a@b.ng", "A B"), null));
    }

    Map<String, Object> row(UUID id) {
        return jdbc.queryForMap("select status, attempts, last_error, next_attempt_at, created_at from suite_outbox where id = ?", id);
    }

    @Test
    void publishWritesPendingRowWithFullEnvelope() throws Exception {
        UUID id = publish("itg:vendor:VEN-0042");
        assertThat(row(id)).containsEntry("status", "pending").containsEntry("attempts", 0);
        var payload = mapper.readTree(jdbc.queryForObject("select payload::text from suite_outbox where id = ?", String.class, id));
        assertThat(payload.path("id").asText()).isEqualTo(id.toString());
        assertThat(payload.path("source").asText()).isEqualTo("itg");
        assertThat(payload.path("occurred_at").asText()).isNotBlank();
        assertThat(payload.path("actor").path("email").asText()).isEqualTo("a@b.ng");
        assertThat(id.toString().charAt(14)).isEqualTo('7');
    }

    @Test
    void publishRequiresTransaction() {
        assertThatThrownBy(() -> publisher.publish("test.recorded", 1, "t", "itg:vendor:V1", null, null, null))
                .isInstanceOf(org.springframework.transaction.IllegalTransactionStateException.class);
    }

    @Test
    void rolledBackBusinessTransactionLeavesNoEvent() {
        new TransactionTemplate(txm).executeWithoutResult(s -> {
            publisher.publish("test.recorded", 1, "kano-heritage-bank", "itg:vendor:V1", null, null, null);
            s.setRollbackOnly();
        });
        assertThat(count("select count(*) from suite_outbox")).isZero();
    }

    @Test
    void backoffScheduleAndGiveUpAfter72h() {
        assertThat(List.of(1, 2, 3, 4, 5, 6, 40).stream().map(OutboxDeliverer::backoff).toList()).containsExactly(
                Duration.ofSeconds(30), Duration.ofMinutes(2), Duration.ofMinutes(10), Duration.ofMinutes(30),
                Duration.ofHours(1), Duration.ofHours(1), Duration.ofHours(1));

        UUID id = publish("itg:vendor:VEN-1");
        assertThat(deliverer.deliverDue()).isEqualTo(1);
        assertThat(row(id)).containsEntry("status", "pending").containsEntry("attempts", 1);
        assertThat(row(id).get("last_error").toString()).isNotBlank();
        Instant next = ((java.sql.Timestamp) row(id).get("next_attempt_at")).toInstant();
        assertThat(Duration.between(Instant.now(), next)).isBetween(Duration.ofSeconds(20), Duration.ofSeconds(31));
        assertThat(deliverer.deliverDue()).isZero(); // not due yet

        jdbc.update("update suite_outbox set next_attempt_at = now() where id = ?", id);
        deliverer.deliverDue();
        next = ((java.sql.Timestamp) row(id).get("next_attempt_at")).toInstant();
        assertThat(Duration.between(Instant.now(), next)).isBetween(Duration.ofSeconds(100), Duration.ofSeconds(121));

        jdbc.update("update suite_outbox set next_attempt_at = now(), created_at = now() - interval '73 hours' where id = ?", id);
        deliverer.deliverDue();
        assertThat(row(id)).containsEntry("status", "failed").containsEntry("attempts", 3);
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'send' and outcome = 'retry'", id)).isEqualTo(2);
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'send' and outcome = 'failed'", id)).isEqualTo(1);
    }

    @Test
    void perSubjectOrderingHoldsLaterEvents() {
        UUID first = publish("itg:vendor:VEN-A");
        UUID second = publish("itg:vendor:VEN-A");
        UUID other = publish("itg:vendor:VEN-B");
        assertThat(deliverer.deliverDue()).isEqualTo(2); // first and other attempted; second held
        assertThat(row(first)).containsEntry("attempts", 1);
        assertThat(row(second)).containsEntry("attempts", 0);
        assertThat(row(other)).containsEntry("attempts", 1);
    }

    @Test
    void adminListsFailuresAndRetryResetsRow() throws Exception {
        UUID id = publish("itg:vendor:VEN-1");
        jdbc.update("update suite_outbox set status='failed', attempts=7, last_error='HTTP 422: nope' where id = ?", id);

        mvc.perform(get("/suite/admin/failures")).andExpect(status().isOk())
                .andExpect(jsonPath("$[0].id").value(id.toString()))
                .andExpect(jsonPath("$[0].lastError").value("HTTP 422: nope"));
        mvc.perform(post("/suite/admin/failures/{id}/retry", id)).andExpect(status().isNoContent());
        assertThat(row(id)).containsEntry("status", "pending").containsEntry("attempts", 0);
        mvc.perform(post("/suite/admin/failures/{id}/retry", id)).andExpect(status().isNotFound()); // no longer failed
        mvc.perform(get("/suite/admin/failures")).andExpect(jsonPath("$").isEmpty());
    }

    @Test
    void deepLinkRedirectsOr404() throws Exception {
        mvc.perform(get("/suite/open").param("ref", "itg:change:CHG-2026-0042"))
                .andExpect(status().isFound()).andExpect(header().string("Location", "/change/CHG-2026-0042"));
        mvc.perform(get("/suite/open").param("ref", "isec:incident:INC-1")).andExpect(status().isNotFound());
        mvc.perform(get("/suite/open").param("ref", "garbage")).andExpect(status().isBadRequest());
    }
}
