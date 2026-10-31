package ng.atheris.suite.outbox;

import java.util.UUID;

import com.fasterxml.jackson.databind.JsonNode;

import ng.atheris.suite.model.Envelope;

/** Used when {@code suite.enabled} is not true: nothing is written. */
public class NoOpSuitePublisher implements SuitePublisher {
    @Override
    public UUID publish(String type, int version, String tenant, String subject, JsonNode data,
                        Envelope.Actor actor, String correlationId) {
        return null;
    }
}
