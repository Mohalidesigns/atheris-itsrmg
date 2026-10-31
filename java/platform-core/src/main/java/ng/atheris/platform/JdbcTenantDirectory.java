package ng.atheris.platform;

import ng.atheris.suite.inbound.TenantDirectory;

/** A tenant is known when an active organization has that slug. Replaces suite-core's config-list directory. */
public class JdbcTenantDirectory implements TenantDirectory {

    private final PlatformRepository repo;

    public JdbcTenantDirectory(PlatformRepository repo) {
        this.repo = repo;
    }

    @Override
    public boolean exists(String slug) {
        return repo.activeOrgExists(slug);
    }
}
