package ng.atheris.suite.testapp;

import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.function.Consumer;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.jdbc.core.JdbcTemplate;

import ng.atheris.suite.inbound.SuiteEventHandler;
import ng.atheris.suite.inbound.SuiteValidationException;
import ng.atheris.suite.model.Envelope;
import ng.atheris.suite.model.SuiteRef;
import ng.atheris.suite.web.SuiteRefResolver;

@Configuration
public class TestHandlers {

    /** Events seen by handlers, in arrival order. */
    public static final List<Envelope> SEEN = new CopyOnWriteArrayList<>();

    record H(String type, String contract, Consumer<Envelope> body) implements SuiteEventHandler {
        @Override public void handle(Envelope e) { body.accept(e); }
    }

    @Bean SuiteEventHandler recorded() { return new H("test.recorded", null, SEEN::add); }

    @Bean SuiteEventHandler invalid() {
        return new H("test.invalid", null, e -> { throw new SuiteValidationException("data.name is required"); });
    }

    @Bean SuiteEventHandler boom() {
        return new H("test.boom", null, e -> { throw new IllegalStateException("handler blew up"); });
    }

    @Bean SuiteEventHandler gated() { return new H("test.gated", "c9", SEEN::add); }

    @Bean SuiteRefResolver itgResolver() {
        return new SuiteRefResolver() {
            @Override public boolean supports(SuiteRef ref) { return ref.product().equals("itg"); }
            @Override public String urlFor(SuiteRef ref) { return "/" + ref.entity() + "/" + ref.code(); }
        };
    }
}
