package ng.atheris.suite.client;

/**
 * The peer could not be reached in time (connect error, timeout or 5xx). UIs should catch this and show
 * the "peer is unavailable - showing last known data" banner instead of failing (ATH-SUI-001 section 3.6).
 */
public class SuitePeerUnavailableException extends SuitePeerException {
    public SuitePeerUnavailableException(String message, Throwable cause) {
        super(message, cause);
    }
}
