package ng.atheris.platform;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.platform.testapp.TestApp;

/** Suite enabled but contract c5 off: users are managed locally, nothing is published. */
@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT,
        properties = "suite.contracts.c5=false")
class SyncOffIT extends AbstractIT {

    @Test
    void noOutboxRowWhenC5Off() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        assertThat(call("POST", "/platform/users", "{\"email\":\"n@a.ng\",\"password\":\"password1\"}", "admin@a.ng", "password").status()).isEqualTo(201);
        long id = seedUser("bank-a", "s@a.ng", "password", "USER");
        assertThat(call("POST", "/platform/users/" + id + "/deactivate", null, "admin@a.ng", "password").status()).isEqualTo(200);
        assertThat(count("select count(*) from suite_outbox")).isZero();
    }

    @Test
    void inboundUserEventsIgnoredWhenC5Off() throws Exception {
        repo.ensureOrg("bank-a", "A");
        assertThat(postEvent(userEvent("user.provisioned", "bank-a", "{\"email\":\"x@a.ng\",\"name\":\"X\"}")).status()).isEqualTo(202);
        assertThat(count("select count(*) from users")).isZero();
    }
}
