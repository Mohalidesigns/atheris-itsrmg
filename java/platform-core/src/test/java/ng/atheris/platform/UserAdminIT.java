package ng.atheris.platform;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import ng.atheris.platform.testapp.TestApp;

@SpringBootTest(classes = TestApp.class, webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT)
class UserAdminIT extends AbstractIT {

    @Test
    void listIsTenantIsolated() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        seedUser("bank-a", "staff@a.ng", "password", "USER");
        seedUser("bank-b", "admin@b.ng", "password", "ADMIN");
        seedUser("bank-b", "secret@b.ng", "password", "USER");

        Resp a = call("GET", "/platform/users", null, "admin@a.ng", "password");
        assertThat(a.status()).isEqualTo(200);
        assertThat(a.body()).contains("admin@a.ng", "staff@a.ng").doesNotContain("b.ng").doesNotContain("password_hash");
        Resp b = call("GET", "/platform/users", null, "admin@b.ng", "password");
        assertThat(b.body()).contains("secret@b.ng").doesNotContain("a.ng\"");
    }

    @Test
    void cannotTouchAnotherOrganizationsUser() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        long other = seedUser("bank-b", "victim@b.ng", "password", "USER");
        assertThat(call("POST", "/platform/users/" + other + "/deactivate", null, "admin@a.ng", "password").status()).isEqualTo(404);
        assertThat(call("POST", "/platform/users/" + other + "/roles", "{\"roles\":[\"ADMIN\"]}", "admin@a.ng", "password").status()).isEqualTo(404);
        assertThat(count("select count(*) from users where email = 'victim@b.ng' and active")).isEqualTo(1);
    }

    @Test
    void adminRoleRequired() throws Exception {
        seedUser("bank-a", "staff@a.ng", "password", "USER");
        assertThat(call("GET", "/platform/users", null, "staff@a.ng", "password").status()).isEqualTo(403);
        assertThat(call("GET", "/platform/users", null, null, null).status()).isEqualTo(401);
    }

    @Test
    void createDuplicateInvalidAndRoles() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        String req = "{\"email\":\"n@a.ng\",\"password\":\"password1\",\"roles\":[\"USER\"]}";
        Resp c = call("POST", "/platform/users", req, "admin@a.ng", "password");
        assertThat(c.status()).isEqualTo(201);
        assertThat(c.body()).contains("\"roles\":[\"USER\"]");
        assertThat(call("POST", "/platform/users", req, "admin@a.ng", "password").status()).isEqualTo(409);
        assertThat(call("POST", "/platform/users", "{\"email\":\"bad\"}", "admin@a.ng", "password").status()).isEqualTo(422);
        assertThat(call("POST", "/platform/users", "{\"email\":\"s@a.ng\",\"password\":\"short\"}", "admin@a.ng", "password").status()).isEqualTo(422);
        long id = mapper.readTree(c.body()).path("id").asLong();
        Resp r = call("POST", "/platform/users/" + id + "/roles", "{\"roles\":[\"ADMIN\",\"SUITE_ADMIN\"]}", "admin@a.ng", "password");
        assertThat(r.body()).contains("\"roles\":[\"ADMIN\",\"SUITE_ADMIN\"]");
        assertThat(call("POST", "/platform/users/" + id + "/roles", "{\"roles\":[\"NOPE\"]}", "admin@a.ng", "password").status()).isEqualTo(422);
        // roles are never published
        assertThat(count("select count(*) from suite_outbox where type = 'user.provisioned'")).isEqualTo(1);
    }

    @Test
    void deactivatedAndPasswordlessUsersCannotAuthenticate() throws Exception {
        seedUser("bank-a", "admin@a.ng", "password", "ADMIN");
        long id = seedUser("bank-a", "staff@a.ng", "password", "USER");
        seedUser("bank-a", "nopass@a.ng", null, "USER");
        assertThat(call("GET", "/whoami", null, "staff@a.ng", "password").status()).isEqualTo(200);
        assertThat(call("GET", "/whoami", null, "nopass@a.ng", "anything").status()).isEqualTo(401);
        assertThat(call("POST", "/platform/users/" + id + "/deactivate", null, "admin@a.ng", "password").status()).isEqualTo(200);
        assertThat(call("GET", "/whoami", null, "staff@a.ng", "password").status()).isEqualTo(401);
        assertThat(call("GET", "/whoami", null, "admin@a.ng", "wrong").status()).isEqualTo(401);
    }

    @Test
    void sameEmailInTwoOrganizationsNeedsSlugPrefix() throws Exception {
        seedUser("bank-a", "shared@x.ng", "password", "USER");
        seedUser("bank-b", "shared@x.ng", "password", "USER");
        assertThat(call("GET", "/whoami", null, "shared@x.ng", "password").status()).isEqualTo(401);
        Resp r = call("GET", "/whoami", null, "bank-b|shared@x.ng", "password");
        assertThat(r.status()).isEqualTo(200);
        assertThat(r.body()).contains("bank-b");
    }
}
