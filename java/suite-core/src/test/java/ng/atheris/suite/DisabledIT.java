package ng.atheris.suite;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.context.ApplicationContext;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

import ng.atheris.suite.client.SuiteClient;
import ng.atheris.suite.outbox.NoOpSuitePublisher;
import ng.atheris.suite.outbox.OutboxDeliverer;
import ng.atheris.suite.outbox.SuitePublisher;
import ng.atheris.suite.testapp.TestApp;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT,
        properties = "suite.enabled=false")
class DisabledIT extends AbstractIT {

    @Autowired SuitePublisher publisher;
    @Autowired ApplicationContext ctx;
    @Autowired PlatformTransactionManager txm;

    @Test
    void publisherIsNoOpAndWritesNothing() {
        assertThat(publisher).isInstanceOf(NoOpSuitePublisher.class);
        Object id = new TransactionTemplate(txm).execute(s -> publisher.publish("test.recorded", 1, "kano-heritage-bank",
                "itg:vendor:VEN-1", null, null, null));
        assertThat(id).isNull();
        assertThat(count("select count(*) from suite_outbox")).isZero();
        assertThat(count("select count(*) from suite_audit_log")).isZero();
    }

    @Test
    void noSuiteBeansOrEndpoints() throws Exception {
        assertThat(ctx.getBeanNamesForType(SuiteClient.class)).isEmpty();
        assertThat(ctx.getBeanNamesForType(OutboxDeliverer.class)).isEmpty();
        assertThat(send("GET", "/api/suite/v1/health", null, "isec-client", "current-secret", java.time.Instant.now().getEpochSecond()).status())
                .isEqualTo(404);
        assertThat(send("POST", "/api/suite/v1/events", envelope("test.recorded"), "isec-client", "current-secret",
                java.time.Instant.now().getEpochSecond()).status()).isIn(404, 405);
        assertThat(send("GET", "/suite/open?ref=itg:vendor:V1", null, null, null, 0).status()).isEqualTo(404);
    }
}
