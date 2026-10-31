package ng.atheris.suite.web;

import java.util.Map;

import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

import ng.atheris.suite.SuiteProperties;

/** {@code GET /api/suite/v1/health} - signed like every other suite endpoint. */
@RestController
public class SuiteHealthController {

    private final SuiteProperties props;

    public SuiteHealthController(SuiteProperties props) {
        this.props = props;
    }

    @GetMapping("/api/suite/v1/health")
    public Map<String, String> health() {
        String v = SuiteHealthController.class.getPackage().getImplementationVersion();
        return Map.of("status", "ok", "source", String.valueOf(props.getSource()), "version", v == null ? "dev" : v);
    }
}
