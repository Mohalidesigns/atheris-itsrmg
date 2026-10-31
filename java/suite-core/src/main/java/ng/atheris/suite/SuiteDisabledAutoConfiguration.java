package ng.atheris.suite;

import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.context.annotation.Bean;

import ng.atheris.suite.outbox.NoOpSuitePublisher;
import ng.atheris.suite.outbox.SuitePublisher;

/** When suite is off, products can still inject {@link SuitePublisher}; it does nothing. */
@AutoConfiguration
@ConditionalOnProperty(prefix = "suite", name = "enabled", havingValue = "false", matchIfMissing = true)
public class SuiteDisabledAutoConfiguration {

    @Bean
    @ConditionalOnMissingBean(SuitePublisher.class)
    SuitePublisher suitePublisher() {
        return new NoOpSuitePublisher();
    }
}
