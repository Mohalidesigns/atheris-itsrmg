package ng.atheris.suite.client;

/** The peer answered with a non-2xx status that is not an availability problem (typically 4xx). */
public class SuitePeerException extends RuntimeException {
    private final int status;

    public SuitePeerException(int status, String body) {
        super("Suite peer answered HTTP " + status + (body == null || body.isBlank() ? "" : ": " + body));
        this.status = status;
    }

    protected SuitePeerException(String message, Throwable cause) {
        super(message, cause);
        this.status = 0;
    }

    public int status() { return status; }
}
