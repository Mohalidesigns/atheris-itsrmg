package ng.atheris.suite;

import java.time.Clock;
import java.util.List;

import com.fasterxml.jackson.databind.ObjectMapper;

import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Import;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.scheduling.annotation.EnableScheduling;
import org.springframework.transaction.PlatformTransactionManager;

import ng.atheris.suite.client.SuiteClient;
import ng.atheris.suite.inbound.InboundEventProcessor;
import ng.atheris.suite.inbound.SuiteEventHandler;
import ng.atheris.suite.inbound.TenantDirectory;
import ng.atheris.suite.outbox.OutboxDeliverer;
import ng.atheris.suite.outbox.OutboxPublisher;
import ng.atheris.suite.outbox.SuitePublisher;
import ng.atheris.suite.security.SuiteSignatureFilter;
import ng.atheris.suite.security.SuiteSigner;
import ng.atheris.suite.web.SuiteAdminController;
import ng.atheris.suite.web.SuiteAdminService;
import ng.atheris.suite.web.SuiteEventController;
import ng.atheris.suite.web.SuiteHealthController;
import ng.atheris.suite.web.SuiteOpenController;

/** Everything is active only when {@code suite.enabled=true}. */
@AutoConfiguration(afterName = {
    "org.springframework.boot.autoconfigure.jackson.JacksonAutoConfiguration",
    "org.springframework.boot.autoconfigure.jdbc.DataSourceAutoConfiguration",
    "org.springframework.boot.autoconfigure.jdbc.JdbcClientAutoConfiguration",
    "org.springframework.boot.autoconfigure.transaction.TransactionAutoConfiguration",
    "org.springframework.boot.autoconfigure.flyway.FlywayAutoConfiguration"})
@ConditionalOnProperty(prefix = "suite", name = "enabled", havingValue = "true")
@EnableConfigurationProperties(SuiteProperties.class)
@EnableScheduling
@Import({SuiteEventController.class, SuiteHealthController.class, SuiteOpenController.class, SuiteAdminController.class})
public class SuiteAutoConfiguration {

    @Bean
    @ConditionalOnMissingBean
    Clock suiteClock() {
        return Clock.systemUTC();
    }

    @Bean
    @ConditionalOnMissingBean
    TenantDirectory tenantDirectory(SuiteProperties props) {
        return slug -> slug != null && props.getTenants().contains(slug);
    }

    @Bean
    SuiteSigner suiteSigner(SuiteProperties props, Clock clock) {
        return new SuiteSigner(props, clock);
    }

    @Bean
    FilterRegistrationBean<SuiteSignatureFilter> suiteSignatureFilter(SuiteProperties props, Clock clock) {
        var reg = new FilterRegistrationBean<>(new SuiteSignatureFilter(props, clock));
        reg.addUrlPatterns("/api/suite/v1/*");
        reg.setName("suiteSignatureFilter");
        reg.setOrder(Integer.MIN_VALUE + 10);
        return reg;
    }

    @Bean
    SuiteAudit suiteAudit(JdbcClient jdbc, Clock clock) {
        return new SuiteAudit(jdbc, clock);
    }

    @Bean
    SuitePublisher suitePublisher(JdbcClient jdbc, ObjectMapper mapper, SuiteProperties props, Clock clock) {
        return new OutboxPublisher(jdbc, mapper, props, clock);
    }

    @Bean
    SuiteClient suiteClient(SuiteProperties props, SuiteSigner signer, ObjectMapper mapper) {
        return new SuiteClient(props, signer, mapper);
    }

    @Bean
    OutboxDeliverer outboxDeliverer(JdbcClient jdbc, SuiteClient client, SuiteAudit audit, SuiteProperties props,
                                    Clock clock, PlatformTransactionManager txManager) {
        return new OutboxDeliverer(jdbc, client, audit, props, clock, txManager);
    }

    @Bean
    InboundEventProcessor inboundEventProcessor(List<SuiteEventHandler> handlers, JdbcClient jdbc,
                                                SuiteProperties props, Clock clock) {
        return new InboundEventProcessor(handlers, jdbc, props, clock);
    }

    @Bean
    SuiteAdminService suiteAdminService(JdbcClient jdbc, Clock clock) {
        return new SuiteAdminService(jdbc, clock);
    }
}
