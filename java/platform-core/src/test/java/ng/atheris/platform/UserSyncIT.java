package ng.atheris.platform;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.UUID;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.platform.testapp.TestApp;
import ng.atheris.suite.model.UuidV7;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
class UserSyncIT extends AbstractIT {

    static final String DATA = "{\"email\":\"Ada.Obi@Kano.ng\",\"name\":\"Ada Obi\",\"job_title\":\"Analyst\",\"department\":\"Risk\",\"active\":true}";

    @Test
    void provisioningPublishesOutboxRow() throws Exception {
        seedUser("kano-heritage-bank", "admin@kano.ng", "password", "ADMIN");
        Resp r = call("POST", "/platform/users", "{\"email\":\"New.User@Kano.ng\",\"name\":\"New User\",\"job_title\":\"Dev\",\"department\":\"IT\",\"password\":\"password1\"}",
                "admin@kano.ng", "password");
        assertThat(r.status()).isEqualTo(201);
        var row = jdbc.queryForMap("select type, tenant, subject, payload::text as payload from suite_outbox");
        assertThat(row.get("type")).isEqualTo("user.provisioned");
        assertThat(row.get("tenant")).isEqualTo("kano-heritage-bank");
        assertThat(row.get("subject")).isEqualTo("itg:user:new.user@kano.ng");
        var data = mapper.readTree((String) row.get("payload")).path("data");
        assertThat(data.path("email").asText()).isEqualTo("new.user@kano.ng");
        assertThat(data.path("job_title").asText()).isEqualTo("Dev");
        assertThat(data.path("active").asBoolean()).isTrue();
        assertThat(mapper.readTree((String) row.get("payload")).path("actor").path("email").asText()).isEqualTo("admin@kano.ng");
    }

    @Test
    void deactivationPublishesAndIsIdempotent() throws Exception {
        seedUser("kano-heritage-bank", "admin@kano.ng", "password", "ADMIN");
        long id = seedUser("kano-heritage-bank", "staff@kano.ng", "password", "USER");
        assertThat(call("POST", "/platform/users/" + id + "/deactivate", null, "admin@kano.ng", "password").status()).isEqualTo(200);
        assertThat(call("POST", "/platform/users/" + id + "/deactivate", null, "admin@kano.ng", "password").status()).isEqualTo(200);
        assertThat(count("select count(*) from suite_outbox where type = 'user.deactivated'")).isEqualTo(1);
        assertThat(count("select count(*) from users where email = 'staff@kano.ng' and not active and deactivated_at is not null")).isEqualTo(1);
    }

    @Test
    void inboundProvisionedCreatesSuiteUserAndIsIdempotent() throws Exception {
        repo.ensureOrg("kano-heritage-bank", "Kano");
        UUID id = UuidV7.next();
        String body = userEvent(id, "user.provisioned", "kano-heritage-bank", DATA);
        assertThat(postEvent(body).status()).isEqualTo(202);
        assertThat(postEvent(body).status()).isEqualTo(202); // replay
        assertThat(postEvent(userEvent("user.provisioned", "kano-heritage-bank", DATA)).status()).isEqualTo(202); // new id, same business key
        assertThat(count("select count(*) from users")).isEqualTo(1);
        var u = jdbc.queryForMap("select email, name, job_title, department, source, password_hash, active from users");
        assertThat(u.get("email")).isEqualTo("ada.obi@kano.ng");
        assertThat(u.get("source")).isEqualTo("suite");
        assertThat(u.get("password_hash")).isNull();
        assertThat(u.get("job_title")).isEqualTo("Analyst");
        assertThat(count("select count(*) from user_roles")).isZero();
        assertThat(count("select count(*) from suite_outbox")).isZero();
    }

    @Test
    void inboundInvalidEmailIs422() throws Exception {
        repo.ensureOrg("kano-heritage-bank", "Kano");
        assertThat(postEvent(userEvent("user.provisioned", "kano-heritage-bank", "{\"email\":\"not-an-email\",\"name\":\"X\"}")).status()).isEqualTo(422);
        assertThat(postEvent(userEvent("user.provisioned", "kano-heritage-bank", "{\"name\":\"X\"}")).status()).isEqualTo(422);
        assertThat(postEvent(userEvent("user.deactivated", "kano-heritage-bank", "{\"email\":\"\"}")).status()).isEqualTo(422);
        assertThat(count("select count(*) from users")).isZero();
    }

    @Test
    void inboundUnknownTenantIs409() throws Exception {
        repo.ensureOrg("kano-heritage-bank", "Kano");
        assertThat(postEvent(userEvent("user.provisioned", "nope-bank", DATA)).status()).isEqualTo(409);
        // suite.tenants=ghost-bank is configured but not in the database: the JDBC directory wins
        assertThat(postEvent(userEvent("user.provisioned", "ghost-bank", DATA)).status()).isEqualTo(409);
        assertThat(count("select count(*) from users")).isZero();
    }

    @Test
    void inboundDeactivateDeactivatesWithoutEmitting() throws Exception {
        seedUser("kano-heritage-bank", "staff@kano.ng", "password", "USER");
        assertThat(postEvent(userEvent("user.deactivated", "kano-heritage-bank", "{\"email\":\"STAFF@kano.ng\",\"active\":false}")).status()).isEqualTo(202);
        assertThat(count("select count(*) from users where email = 'staff@kano.ng' and not active")).isEqualTo(1);
        assertThat(count("select count(*) from suite_outbox")).isZero();
        // unknown user: acknowledged, nothing created
        assertThat(postEvent(userEvent("user.deactivated", "kano-heritage-bank", "{\"email\":\"ghost@kano.ng\"}")).status()).isEqualTo(202);
        assertThat(count("select count(*) from users")).isEqualTo(1);
    }

    @Test
    void inboundReprovisionReactivates() throws Exception {
        repo.ensureOrg("kano-heritage-bank", "Kano");
        postEvent(userEvent("user.provisioned", "kano-heritage-bank", DATA));
        postEvent(userEvent("user.deactivated", "kano-heritage-bank", "{\"email\":\"ada.obi@kano.ng\"}"));
        assertThat(count("select count(*) from users where not active")).isEqualTo(1);
        postEvent(userEvent("user.provisioned", "kano-heritage-bank", DATA));
        assertThat(count("select count(*) from users where active and deactivated_at is null")).isEqualTo(1);
    }
}
