package ng.atheris.platform.security;

import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configurers.AuthorizeHttpRequestsConfigurer;

/**
 * Helper for the product's {@code SecurityFilterChain}:
 * <pre>{@code
 * http.authorizeHttpRequests(a -> {
 *     a.requestMatchers("/api/suite/v1/**").permitAll();   // HMAC-signed by suite-core
 *     PlatformSecurity.adminRules(a);                       // /platform/** and /suite/admin/** need ROLE_ADMIN
 *     a.anyRequest().authenticated();
 * }).httpBasic(withDefaults()).csrf(c -> c.ignoringRequestMatchers("/api/suite/v1/**"));
 * }</pre>
 */
public final class PlatformSecurity {

    public static final String ADMIN = "ADMIN";

    private PlatformSecurity() {}

    public static void adminRules(AuthorizeHttpRequestsConfigurer<HttpSecurity>.AuthorizationManagerRequestMatcherRegistry registry) {
        registry.requestMatchers("/platform/**", "/suite/admin/**").hasRole(ADMIN);
    }
}
