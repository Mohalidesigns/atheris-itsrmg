package ng.atheris.platform.user;

/** Bad user data (maps to 422 on REST, {@code SuiteValidationException} on events). */
public class InvalidUserException extends RuntimeException {
    public InvalidUserException(String message) {
        super(message);
    }
}
