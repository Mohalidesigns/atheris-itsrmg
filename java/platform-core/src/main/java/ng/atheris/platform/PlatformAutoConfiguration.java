package ng.atheris.platform;

import java.time.Clock;

import com.fasterxml.jackson.databind.ObjectMapper;

import org.springframework.beans.factory.ObjectProvider;
import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Import;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.security.core.userdetails.UserDetailsService;

import ng.atheris.platform.c5.UserDeactivatedHandler;
import ng.atheris.platform.c5.UserProvisionedHandler;
import ng.atheris.platform.demo.DemoDataLoader;
import ng.atheris.platform.security.CurrentUser;
import ng.atheris.platform.security.PlatformUserDetailsService;
import ng.atheris.platform.user.UserService;
import ng.atheris.platform.user.UserSyncService;
import ng.atheris.platform.web.PlatformUserController;
import ng.atheris.suite.SuiteAutoConfiguration;
import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.inbound.TenantDirectory;
import ng.atheris.suite.outbox.SuitePublisher;

/**
 * Runs before {@link SuiteAutoConfiguration} so {@link JdbcTenantDirectory} is registered first and suite-core's
 * {@code @ConditionalOnMissingBean} default TenantDirectory backs off. Disable with {@code platform.enabled=false}.
 */
@AutoConfiguration(before = SuiteAutoConfiguration.class, afterName = {
    "org.springframework.boot.autoconfigure.jackson.JacksonAutoConfiguration",
    "org.springframework.boot.autoconfigure.jdbc.DataSourceAutoConfiguration",
    "org.springframework.boot.autoconfigure.jdbc.JdbcClientAutoConfiguration",
    "org.springframework.boot.autoconfigure.transaction.TransactionAutoConfiguration",
    "org.springframework.boot.autoconfigure.flyway.FlywayAutoConfiguration"})
@ConditionalOnProperty(prefix = "platform", name = "enabled", havingValue = "true", matchIfMissing = true)
@Import({PlatformUserController.class, DemoDataLoader.class})
public class PlatformAutoConfiguration {

    @Bean
    @ConditionalOnMissingBean
    Clock platformClock() {
        return Clock.systemUTC();
    }

    @Bean
    @ConditionalOnMissingBean
    PlatformRepository platformRepository(JdbcClient jdbc) {
        return new PlatformRepository(jdbc);
    }

    @Bean
    @ConditionalOnMissingBean(TenantDirectory.class)
    TenantDirectory tenantDirectory(PlatformRepository repo) {
        return new JdbcTenantDirectory(repo);
    }

    @Bean
    @ConditionalOnMissingBean
    PasswordEncoder passwordEncoder() {
        return new BCryptPasswordEncoder();
    }

    @Bean
    @ConditionalOnMissingBean(UserDetailsService.class)
    UserDetailsService platformUserDetailsService(PlatformRepository repo) {
        return new PlatformUserDetailsService(repo);
    }

    @Bean
    CurrentUser currentUser() {
        return new CurrentUser();
    }

    @Bean
    UserService platformUserService(PlatformRepository repo, PasswordEncoder encoder, SuitePublisher publisher,
                                    ObjectProvider<SuiteProperties> props, ObjectMapper mapper, Clock clock,
                                    CurrentUser current) {
        return new UserService(repo, encoder, publisher, props, mapper, clock, current);
    }

    @Bean
    UserSyncService platformUserSyncService(PlatformRepository repo, Clock clock) {
        return new UserSyncService(repo, clock);
    }

    @Bean
    UserProvisionedHandler userProvisionedHandler(UserSyncService sync, ObjectMapper mapper) {
        return new UserProvisionedHandler(sync, mapper);
    }

    @Bean
    UserDeactivatedHandler userDeactivatedHandler(UserSyncService sync, ObjectMapper mapper) {
        return new UserDeactivatedHandler(sync, mapper);
    }
}
