package ng.atheris.platform.testapp;

import static org.springframework.security.config.Customizer.withDefaults;

import java.util.Map;

import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.annotation.Bean;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.web.SecurityFilterChain;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

import ng.atheris.platform.security.CurrentUser;
import ng.atheris.platform.security.PlatformSecurity;

/** Test-only product host. Lives in a sub-package so scanning never sees library classes. */
@SpringBootApplication
@RestController
public class TestApp {

    private final CurrentUser current;

    TestApp(CurrentUser current) {
        this.current = current;
    }

    @GetMapping("/whoami")
    Map<String, Object> whoami() {
        return Map.of("email", current.require().getEmail(), "org", current.organizationSlug());
    }

    @Bean
    SecurityFilterChain chain(HttpSecurity http) throws Exception {
        http.csrf(c -> c.disable())
            .httpBasic(withDefaults())
            .authorizeHttpRequests(a -> {
                a.requestMatchers("/api/suite/v1/**").permitAll();
                PlatformSecurity.adminRules(a);
                a.anyRequest().authenticated();
            });
        return http.build();
    }
}
