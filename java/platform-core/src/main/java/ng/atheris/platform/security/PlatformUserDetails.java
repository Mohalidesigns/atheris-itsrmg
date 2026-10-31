package ng.atheris.platform.security;

import java.util.Collection;
import java.util.List;
import java.util.Set;

import org.springframework.security.core.GrantedAuthority;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.userdetails.UserDetails;

/** Authenticated principal: carries the user's organization so products can scope every query by tenant. */
public class PlatformUserDetails implements UserDetails {

    private final long id;
    private final long organizationId;
    private final String organizationSlug;
    private final String email;
    private final String name;
    private final String passwordHash;
    private final boolean active;
    private final List<GrantedAuthority> authorities;

    public PlatformUserDetails(long id, long organizationId, String organizationSlug, String email, String name,
                               String passwordHash, boolean active, Set<String> roles) {
        this.id = id;
        this.organizationId = organizationId;
        this.organizationSlug = organizationSlug;
        this.email = email;
        this.name = name;
        this.passwordHash = passwordHash;
        this.active = active;
        this.authorities = roles.stream().<GrantedAuthority>map(r -> new SimpleGrantedAuthority("ROLE_" + r)).toList();
    }

    public long getId() { return id; }
    public long getOrganizationId() { return organizationId; }
    public String getOrganizationSlug() { return organizationSlug; }
    public String getEmail() { return email; }
    public String getName() { return name; }

    @Override public Collection<? extends GrantedAuthority> getAuthorities() { return authorities; }
    @Override public String getPassword() { return passwordHash; }
    @Override public String getUsername() { return email; }
    @Override public boolean isEnabled() { return active; }
}
