package ng.atheris.suite.testapp;

import java.util.List;
import java.util.Map;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

/** A peer query for the loopback tests: echoes repeated params, or answers 204 when asked for nothing. */
@RestController
public class TestQueryController {

    @GetMapping("/api/suite/v1/test-echo")
    public ResponseEntity<Map<String, Object>> echo(@RequestParam(name = "system", required = false) List<String> systems) {
        if (systems == null || systems.isEmpty()) {
            return ResponseEntity.noContent().build();
        }
        return ResponseEntity.ok(Map.of("systems", systems));
    }
}
