package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.UUID;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.suite.model.UuidV7;
import ng.atheris.suite.testapp.TestApp;
import ng.atheris.suite.testapp.TestHandlers;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
class InboundIT extends AbstractIT {

    @Test
    void acceptsAndInvokesHandler() throws Exception {
        UUID id = UuidV7.next();
        Resp r = postEvent(envelope(id, "test.recorded", "kano-heritage-bank", "isec:incident:INC-9"));
        assertThat(r.status()).isEqualTo(202);
        assertThat(mapper.readTree(r.body()).path("received").asText()).isEqualTo(id.toString());
        assertThat(TestHandlers.SEEN).hasSize(1);
        assertThat(TestHandlers.SEEN.get(0).data().path("k").asText()).isEqualTo("v");
        assertThat(count("select count(*) from suite_inbox where id = ? and result = 'processed' and processed_at is not null", id)).isEqualTo(1);
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and direction = 'receive'", id)).isEqualTo(2);
    }

    @Test
    void duplicateIdIsNoOp() throws Exception {
        String body = envelope(UuidV7.next(), "test.recorded", "kano-heritage-bank", "isec:incident:INC-9");
        assertThat(postEvent(body).status()).isEqualTo(202);
        assertThat(postEvent(body).status()).isEqualTo(202);
        assertThat(TestHandlers.SEEN).hasSize(1);
        assertThat(count("select count(*) from suite_inbox")).isEqualTo(1);
        assertThat(count("select count(*) from suite_audit_log where outcome = 'duplicate'")).isEqualTo(1);
    }

    @Test
    void unknownTenantIs409() throws Exception {
        assertThat(postEvent(envelope(UuidV7.next(), "test.recorded", "nope-bank", "isec:incident:INC-9")).status()).isEqualTo(409);
        assertThat(TestHandlers.SEEN).isEmpty();
    }

    @Test
    void malformedEnvelopeIs400() throws Exception {
        assertThat(postEvent("{not json").status()).isEqualTo(400);
        assertThat(postEvent("{\"id\":\"" + UuidV7.next() + "\",\"type\":\"test.recorded\"}").status()).isEqualTo(400);
        assertThat(postEvent(envelope(UuidV7.next(), "test.recorded", "kano-heritage-bank", "bad-ref")).status()).isEqualTo(400);
        assertThat(postEvent(envelope("test.recorded").replace(UUID.randomUUID().toString(), "x").replaceFirst("\"id\":\"[^\"]+\"", "\"id\":\"not-a-uuid\""))
                .status()).isEqualTo(400);
    }

    @Test
    void validationFailureIs422AndNotRecorded() throws Exception {
        Resp r = postEvent(envelope("test.invalid"));
        assertThat(r.status()).isEqualTo(422);
        assertThat(r.body()).contains("data.name is required");
        assertThat(count("select count(*) from suite_inbox")).isZero();
    }

    @Test
    void unknownTypeIs422() throws Exception {
        assertThat(postEvent(envelope("nothing.happened")).status()).isEqualTo(422);
        assertThat(count("select count(*) from suite_inbox")).isZero();
    }

    @Test
    void handlerExceptionRollsBackInboxRow() throws Exception {
        UUID id = UuidV7.next();
        String body = envelope(id, "test.boom", "kano-heritage-bank", "isec:incident:INC-9");
        assertThat(postEvent(body).status()).isEqualTo(500);
        assertThat(count("select count(*) from suite_inbox")).isZero();
        assertThat(count("select count(*) from suite_audit_log where event_id = ? and outcome = 'error'", id)).isEqualTo(1);
        // the producer's retry is processed from scratch, not treated as a duplicate
        assertThat(postEvent(body).status()).isEqualTo(500);
    }

    @Test
    void disabledContractIsAcknowledgedAndIgnored() throws Exception {
        UUID id = UuidV7.next();
        assertThat(postEvent(envelope(id, "test.gated", "kano-heritage-bank", "isec:incident:INC-9")).status()).isEqualTo(202);
        assertThat(TestHandlers.SEEN).isEmpty();
        assertThat(count("select count(*) from suite_inbox where id = ? and result = 'ignored'", id)).isEqualTo(1);
    }
}
