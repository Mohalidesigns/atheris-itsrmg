package ng.atheris.suite.web;

import java.util.List;
import java.util.UUID;

import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RestController;

/**
 * JSON endpoints for the "Delivery failures" admin page.
 *
 * <p><b>Security:</b> not signed and not authenticated here. The product MUST protect
 * {@code /suite/admin/**} with its own security (suite-core does not depend on Spring Security).
 */
@RestController
public class SuiteAdminController {

    private final SuiteAdminService service;

    public SuiteAdminController(SuiteAdminService service) {
        this.service = service;
    }

    @GetMapping("/suite/admin/failures")
    public List<SuiteAdminService.FailedDelivery> failures() {
        return service.failures();
    }

    @PostMapping("/suite/admin/failures/{id}/retry")
    public ResponseEntity<Void> retry(@PathVariable UUID id) {
        return service.retry(id) ? ResponseEntity.noContent().build() : ResponseEntity.notFound().build();
    }
}
