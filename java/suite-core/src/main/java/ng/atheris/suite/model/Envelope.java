package ng.atheris.suite.model;

import java.io.IOException;
import java.time.OffsetDateTime;
import java.util.UUID;

import com.fasterxml.jackson.annotation.JsonProperty;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

/** Event envelope, ATH-SUI-001 section 3.3. JSON uses snake_case field names. */
public record Envelope(
        UUID id,
        String type,
        int version,
        @JsonProperty("occurred_at") OffsetDateTime occurredAt,
        String source,
        String tenant,
        String subject,
        @JsonProperty("correlation_id") String correlationId,
        Actor actor,
        JsonNode data) {

    public record Actor(String email, String name) {}

    /**
     * Parses and validates an envelope.
     *
     * @throws IllegalArgumentException when the body is not a well-formed envelope (mapped to HTTP 400)
     */
    public static Envelope parse(ObjectMapper mapper, byte[] body) {
        try {
            JsonNode n = mapper.readTree(body);
            if (n == null || !n.isObject()) {
                throw new IllegalArgumentException("envelope must be a JSON object");
            }
            for (String f : new String[] {"id", "type", "occurred_at", "source", "tenant", "subject"}) {
                if (!n.path(f).isTextual() || n.path(f).asText().isBlank()) {
                    throw new IllegalArgumentException("missing or invalid field: " + f);
                }
            }
            if (!n.path("version").isInt() || n.path("version").asInt() < 1) {
                throw new IllegalArgumentException("missing or invalid field: version");
            }
            if (!n.path("data").isObject()) {
                throw new IllegalArgumentException("missing or invalid field: data");
            }
            JsonNode actor = n.get("actor");
            if (actor != null && !actor.isNull() && !actor.isObject()) {
                throw new IllegalArgumentException("invalid field: actor");
            }
            SuiteRef.parse(n.get("subject").asText());
            return mapper.treeToValue(n, Envelope.class);
        } catch (JsonProcessingException e) {
            throw new IllegalArgumentException("malformed envelope: " + e.getOriginalMessage(), e);
        } catch (IOException e) {
            throw new IllegalArgumentException("unreadable envelope", e);
        }
    }
}
