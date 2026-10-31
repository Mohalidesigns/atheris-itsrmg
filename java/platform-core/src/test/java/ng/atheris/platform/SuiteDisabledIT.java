package ng.atheris.platform;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.platform.testapp.TestApp;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT,
        properties = "suite.enabled=false")
class SuiteDisabledIT extends AbstractIT {

    @Test
    void userAdminWorksAndPublishesNothing() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        assertThat(call("POST", "/platform/users", "{\"email\":\"n@a.ng\",\"password\":\"password1\"}", "admin@a.ng", "password").status()).isEqualTo(201);
        assertThat(count("select count(*) from suite_outbox")).isZero();
        assertThat(count("select count(*) from users")).isEqualTo(2);
    }
}
