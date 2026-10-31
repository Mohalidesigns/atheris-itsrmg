package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.net.ServerSocket;
import java.util.UUID;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.DynamicPropertyRegistry;
import org.springframework.test.context.DynamicPropertySource;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

import ng.atheris.suite.client.SuiteClient;
import ng.atheris.suite.client.SuitePeerException;
import ng.atheris.suite.model.Envelope;
import ng.atheris.suite.outbox.OutboxDeliverer;
import ng.atheris.suite.outbox.SuitePublisher;
import ng.atheris.suite.testapp.TestApp;
import ng.atheris.suite.testapp.TestHandlers;

/** One app talking to itself: it is its own peer (same client id and secret) on a pre-chosen port. */
@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.DEFINED_PORT)
class LoopbackIT extends AbstractIT {

    static final int PORT = freePort();

    static int freePort() {
        try (ServerSocket s = new ServerSocket(0)) {
            return s.getLocalPort();
        } catch (IOException e) {
            throw new IllegalStateException(e);
        }
    }

    @DynamicPropertySource
    static void loopback(DynamicPropertyRegistry r) {
        r.add("server.port", () -> PORT);
        r.add("suite.peer.url", () -> "http://127.0.0.1:" + PORT);
        r.add("suite.peer.client-id", () -> "itg-client"); // peer == self
    }

    @Autowired SuitePublisher publisher;
    @Autowired OutboxDeliverer deliverer;
    @Autowired PlatformTransactionManager txm;
    @Autowired SuiteClient client;

    UUID publish(String type, String subject) {
        return new TransactionTemplate(txm).execute(s -> publisher.publish(type, 1, "kano-heritage-bank", subject,
                mapper.createObjectNode().put("n", 1), new Envelope.Actor("ciso@kanoheritage.ng", "Amina Bello"), null));
    }

    @Test
    void publishDeliversToItselfAndAuditsBothDirections() {
        UUID id = publish("test.recorded", "itg:vendor:VEN-0042");
        assertThat(deliverer.deliverDue()).isEqualTo(1);

        assertThat(jdbc.queryForObject("select status from suite_outbox where id = ?", String.class, id)).isEqualTo("delivered");
        assertThat(count("select count(*) from suite_outbox where id = ? and delivered_at is not null", id)).isEqualTo(1);
        assertThat(TestHandlers.SEEN).hasSize(1);
        Envelope seen = TestHandlers.SEEN.get(0);
        assertThat(seen.id()).isEqualTo(id);
        assertThat(seen.subject()).isEqualTo("itg:vendor:VEN-0042");
        assertThat(seen.actor().email()).isEqualTo("ciso@kanoheritage.ng");
        assertThat(count("select count(*) from suite_inbox where id = ? and result = 'processed'", id)).isEqualTo(1);
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'send' and outcome = 'delivered'", id)).isEqualTo(1);
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'receive' and outcome = 'processed'", id)).isEqualTo(1);
    }

    @Test
    void sameSubjectEventsArriveInOrder() {
        UUID a = publish("test.recorded", "itg:vendor:VEN-1");
        UUID b = publish("test.recorded", "itg:vendor:VEN-1");
        assertThat(deliverer.deliverDue()).isEqualTo(2);
        assertThat(TestHandlers.SEEN).extracting(Envelope::id).containsExactly(a, b);
    }

    @Test
    void validationRejectionFailsImmediatelyWithoutRetry() {
        UUID id = publish("test.invalid", "itg:vendor:VEN-2");
        assertThat(deliverer.deliverDue()).isEqualTo(1);
        var row = jdbc.queryForMap("select status, attempts, last_error from suite_outbox where id = ?", id);
        assertThat(row).containsEntry("status", "failed").containsEntry("attempts", 1);
        assertThat(row.get("last_error").toString()).contains("422").contains("data.name is required");
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'send' and outcome = 'failed'", id)).isEqualTo(1);
    }

    @Test
    void unknownTenantFailsImmediately() {
        UUID id = new TransactionTemplate(txm).execute(s -> publisher.publish("test.recorded", 1, "ghost-bank",
                "itg:vendor:VEN-3", null, null, null));
        deliverer.deliverDue();
        assertThat(jdbc.queryForObject("select status from suite_outbox where id = ?", String.class, id)).isEqualTo("failed");
    }

    @Test
    void signedQueryRoundTripsAndFourXxIsPeerException() {
        var health = client.query("/api/suite/v1/health", java.util.Map.of(), com.fasterxml.jackson.databind.JsonNode.class);
        assertThat(health.path("status").asText()).isEqualTo("ok");
        org.assertj.core.api.Assertions.assertThatThrownBy(() -> client.query("/api/suite/v1/missing", java.util.Map.of(), String.class))
                .isInstanceOf(SuitePeerException.class).hasMessageContaining("404");
    }
}
