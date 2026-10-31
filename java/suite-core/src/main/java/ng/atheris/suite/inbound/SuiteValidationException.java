package ng.atheris.suite.inbound;

/** Thrown by a handler when the event {@code data} is invalid; answered with HTTP 422. */
public class SuiteValidationException extends RuntimeException {
    public SuiteValidationException(String message) {
        super(message);
    }
}
