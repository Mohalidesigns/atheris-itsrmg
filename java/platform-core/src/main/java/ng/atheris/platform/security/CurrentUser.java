package ng.atheris.platform.security;

import java.util.Optional;

import org.springframework.security.access.AccessDeniedException;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;

/** Resolves the authenticated user and its organization. Products use this to scope every tenant query. */
public class CurrentUser {

    public Optional<PlatformUserDetails> optional() {
        Authentication a = SecurityContextHolder.getContext().getAuthentication();
        return a != null && a.isAuthenticated() && a.getPrincipal() instanceof PlatformUserDetails p
                ? Optional.of(p) : Optional.empty();
    }

    public PlatformUserDetails require() {
        return optional().orElseThrow(() -> new AccessDeniedException("no authenticated platform user"));
    }

    public long organizationId() { return require().getOrganizationId(); }

    public String organizationSlug() { return require().getOrganizationSlug(); }
}
