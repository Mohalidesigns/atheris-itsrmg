package ng.atheris.platform.demo;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.List;

import org.springframework.boot.ApplicationArguments;
import org.springframework.boot.ApplicationRunner;
import org.springframework.context.annotation.Profile;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

import ng.atheris.platform.PlatformRepository;
import ng.atheris.platform.PlatformRepository.Organization;

/**
 * Demo tenants and users (profile {@code demo}). Idempotent; never publishes suite events because each product
 * seeds the same demo estate itself.
 */
@Component
@Profile("demo")
public class DemoDataLoader implements ApplicationRunner {

    private record Demo(String slug, String orgName, String adminEmail, List<String[]> staff) {}

    private static final List<Demo> DEMOS = List.of(
            new Demo("kano-heritage-bank", "Kano Heritage Bank", "admin@kanoheritage.ng", List.of(
                    new String[] {"ciso@kanoheritage.ng", "Amina Bello", "CISO", "Information Security"},
                    new String[] {"cio@kanoheritage.ng", "Musa Danjuma", "CIO", "Technology"},
                    new String[] {"risk@kanoheritage.ng", "Hauwa Sani", "Head of IT Risk", "Risk"})),
            new Demo("first-bank-nigeria", "First Bank Nigeria", "admin@firstbanknigeria.ng", List.of(
                    new String[] {"cio@firstbanknigeria.ng", "Chidi Okafor", "CIO", "Technology"},
                    new String[] {"ciso@firstbanknigeria.ng", "Ngozi Eze", "CISO", "Information Security"})));

    private final PlatformRepository repo;
    private final PasswordEncoder encoder;
    private final Clock clock;

    public DemoDataLoader(PlatformRepository repo, PasswordEncoder encoder, Clock clock) {
        this.repo = repo;
        this.encoder = encoder;
        this.clock = clock;
    }

    @Override
    @Transactional
    public void run(ApplicationArguments args) {
        OffsetDateTime now = OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
        for (Demo d : DEMOS) {
            Organization org = repo.ensureOrg(d.slug(), d.orgName());
            ensureUser(org, d.adminEmail(), "Platform Administrator", "Administrator", "Technology",
                    List.of("ADMIN", "SUITE_ADMIN"), now);
            for (String[] s : d.staff()) {
                ensureUser(org, s[0], s[1], s[2], s[3], List.of("USER"), now);
            }
        }
    }

    private void ensureUser(Organization org, String email, String name, String title, String dept,
                            List<String> roles, OffsetDateTime now) {
        if (repo.findUser(org.id(), email).isEmpty()) {
            long id = repo.insertUser(org.id(), email, name, title, dept, encoder.encode("password"), true, "local", now).id();
            repo.setRoles(id, roles);
        }
    }
}
