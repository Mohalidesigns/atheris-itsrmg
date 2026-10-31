package ng.atheris.platform;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.ActiveProfiles;

import ng.atheris.platform.demo.DemoDataLoader;
import ng.atheris.platform.testapp.TestApp;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
@ActiveProfiles("demo")
class DemoDataLoaderIT extends AbstractIT {

    @Autowired DemoDataLoader loader;

    @Test
    void loadsDemoEstateIdempotently() throws Exception {
        loader.run(null);
        int users = count("select count(*) from users");
        assertThat(count("select count(*) from organizations where slug in ('kano-heritage-bank','first-bank-nigeria')")).isEqualTo(2);
        assertThat(users).isGreaterThanOrEqualTo(6);
        loader.run(null);
        loader.run(null);
        assertThat(count("select count(*) from users")).isEqualTo(users);
        assertThat(count("select count(*) from organizations")).isEqualTo(2);
        assertThat(count("select count(*) from user_roles ur join users u on u.id = ur.user_id join roles r on r.id = ur.role_id where u.email = 'admin@kanoheritage.ng' and r.name in ('ADMIN','SUITE_ADMIN')")).isEqualTo(2);
        assertThat(count("select count(*) from suite_outbox")).isZero();

        assertThat(call("GET", "/platform/users", null, "admin@firstbanknigeria.ng", "password").body())
                .contains("cio@firstbanknigeria.ng").doesNotContain("kanoheritage");
        assertThat(call("GET", "/platform/users", null, "admin@kanoheritage.ng", "password").body())
                .contains("Amina Bello", "CISO");
    }
}
