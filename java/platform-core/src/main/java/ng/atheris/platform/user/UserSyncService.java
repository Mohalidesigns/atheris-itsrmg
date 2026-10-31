package ng.atheris.platform.user;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;

import org.springframework.transaction.annotation.Transactional;

import ng.atheris.platform.PlatformRepository;
import ng.atheris.platform.c5.UserDeactivatedData;
import ng.atheris.platform.c5.UserProvisionedData;

/**
 * Applies changes that came from the peer product. Never publishes (avoids ping-pong), never sets a password
 * and never grants roles. Idempotent on (organization slug, lower-cased e-mail).
 */
public class UserSyncService {

    private final PlatformRepository repo;
    private final Clock clock;

    public UserSyncService(PlatformRepository repo, Clock clock) {
        this.repo = repo;
        this.clock = clock;
    }

    @Transactional
    public void applyProvisioned(String orgSlug, UserProvisionedData d) {
        String email = UserValidator.normalizeEmail(d == null ? null : d.email());
        long orgId = org(orgSlug);
        repo.upsertSuiteUser(orgId, email, UserValidator.nameOrDefault(d.name(), email),
                UserValidator.blankToNull(d.jobTitle()), UserValidator.blankToNull(d.department()),
                d.active() == null || d.active(), now());
    }

    @Transactional
    public void applyDeactivated(String orgSlug, UserDeactivatedData d) {
        String email = UserValidator.normalizeEmail(d == null ? null : d.email());
        repo.setActive(org(orgSlug), email, false, now());
    }

    private long org(String slug) {
        return repo.findOrg(slug).orElseThrow(() -> new InvalidUserException("unknown tenant: " + slug)).id();
    }

    private OffsetDateTime now() {
        return OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
    }
}
