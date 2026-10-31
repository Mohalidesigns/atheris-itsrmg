package ng.atheris.suite.web;

import java.util.Map;

import com.fasterxml.jackson.databind.ObjectMapper;

import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RestController;

import ng.atheris.suite.SuiteAudit;
import ng.atheris.suite.inbound.InboundEventProcessor;
import ng.atheris.suite.inbound.SuiteEventHandler;
import ng.atheris.suite.inbound.SuiteValidationException;
import ng.atheris.suite.inbound.TenantDirectory;
import ng.atheris.suite.model.Envelope;

/** {@code POST /api/suite/v1/events} (ATH-SUI-001 section 3.3). Signature is verified by the servlet filter. */
@RestController
public class SuiteEventController {

    private final ObjectMapper mapper;
    private final TenantDirectory tenants;
    private final InboundEventProcessor processor;
    private final SuiteAudit audit;

    public SuiteEventController(ObjectMapper mapper, TenantDirectory tenants, InboundEventProcessor processor, SuiteAudit audit) {
        this.mapper = mapper;
        this.tenants = tenants;
        this.processor = processor;
        this.audit = audit;
    }

    @PostMapping("/api/suite/v1/events")
    public ResponseEntity<Map<String, Object>> receive(@RequestBody byte[] body) {
        Envelope e;
        try {
            e = Envelope.parse(mapper, body);
        } catch (IllegalArgumentException ex) {
            audit.record(null, null, null, "receive", "rejected_malformed", ex.getMessage());
            return error(400, "bad_envelope", ex.getMessage());
        }
        audit.record(e, "receive", "received", null);
        if (!tenants.exists(e.tenant())) {
            audit.record(e, "receive", "rejected_tenant", e.tenant());
            return error(409, "unknown_tenant", "Unknown tenant: " + e.tenant());
        }
        SuiteEventHandler handler = processor.handlerFor(e.type());
        if (handler == null) {
            audit.record(e, "receive", "rejected_type", e.type());
            return error(422, "unknown_type", "No handler for event type " + e.type());
        }
        try {
            String result = processor.process(e, handler);
            audit.record(e, "receive", result, null);
        } catch (SuiteValidationException ex) {
            audit.record(e, "receive", "rejected_validation", ex.getMessage());
            return error(422, "validation_failed", ex.getMessage());
        } catch (RuntimeException ex) {
            audit.record(e, "receive", "error", ex.toString());
            throw ex;
        }
        return ResponseEntity.accepted().body(Map.of("received", e.id().toString()));
    }

    private static ResponseEntity<Map<String, Object>> error(int status, String code, String message) {
        return ResponseEntity.status(status).body(Map.of("error", code, "message", String.valueOf(message)));
    }
}
