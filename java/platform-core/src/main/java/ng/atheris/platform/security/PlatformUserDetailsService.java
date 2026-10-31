package ng.atheris.platform.security;

import java.util.List;
import java.util.Locale;

import org.springframework.security.core.userdetails.UserDetails;
import org.springframework.security.core.userdetails.UserDetailsService;
import org.springframework.security.core.userdetails.UsernameNotFoundException;

import ng.atheris.platform.PlatformRepository;
import ng.atheris.platform.PlatformRepository.UserRow;

/**
 * Loads active users that have a local password. The username is the e-mail. If the same address exists in
 * several organizations the login must be written {@code <org-slug>|<email>}.
 * Suite-synced users have no password and cannot log in locally (SSO comes later).
 */
public class PlatformUserDetailsService implements UserDetailsService {

    private final PlatformRepository repo;

    public PlatformUserDetailsService(PlatformRepository repo) {
        this.repo = repo;
    }

    @Override
    public UserDetails loadUserByUsername(String username) throws UsernameNotFoundException {
        if (username == null || username.isBlank()) {
            throw new UsernameNotFoundException("blank username");
        }
        String slug = null;
        String email = username.trim().toLowerCase(Locale.ROOT);
        int colon = email.indexOf('|');
        if (colon > 0) {
            slug = email.substring(0, colon);
            email = email.substring(colon + 1);
        }
        List<UserRow> found = repo.findLoginCandidates(email, slug);
        if (found.size() != 1) {
            throw new UsernameNotFoundException("no unique active user for " + username);
        }
        UserRow u = found.get(0);
        return new PlatformUserDetails(u.id(), u.organizationId(), u.orgSlug(), u.email(), u.name(),
                u.passwordHash(), u.active(), repo.rolesOf(u.id()));
    }
}
