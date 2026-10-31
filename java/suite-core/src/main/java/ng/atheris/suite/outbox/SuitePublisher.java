package ng.atheris.suite.outbox;

import java.util.UUID;

import com.fasterxml.jackson.databind.JsonNode;

import ng.atheris.suite.model.Envelope;

/** Entry point for products to emit suite events. */
public interface SuitePublisher {

    /**
     * Writes an event to the outbox. Must be called inside the caller's business transaction so the
     * change and the event commit together; throws if no transaction is active. A no-op when
     * {@code suite.enabled=false}.
     *
     * @return the new event id (UUIDv7), or {@code null} when suite is disabled
     */
    UUID publish(String type, int version, String tenant, String subject, JsonNode data,
                 Envelope.Actor actor, String correlationId);
}
