package ng.atheris.suite.inbound;

import ng.atheris.suite.model.Envelope;

/**
 * Handles one event {@link #type()}. Implement as a Spring bean; they are collected by type. Handlers run
 * in the same transaction as the inbox insert, so an exception rolls the inbox row back (the producer
 * will retry). They must be idempotent on business keys as well. Throw {@link SuiteValidationException}
 * for bad {@code data} (answered with 422, not retried).
 */
public interface SuiteEventHandler {

    String type();

    void handle(Envelope event);

    /** Contract key ("c1".."c15") gating this handler; if toggled off the event is acknowledged and ignored. */
    default String contract() {
        return null;
    }
}
