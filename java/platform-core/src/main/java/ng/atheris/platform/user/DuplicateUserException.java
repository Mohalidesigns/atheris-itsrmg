package ng.atheris.platform.user;

public class DuplicateUserException extends RuntimeException {
    public DuplicateUserException(String email) {
        super("user already exists: " + email);
    }
}
