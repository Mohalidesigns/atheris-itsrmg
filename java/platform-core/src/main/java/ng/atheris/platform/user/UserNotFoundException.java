package ng.atheris.platform.user;

public class UserNotFoundException extends RuntimeException {
    public UserNotFoundException(String what) {
        super("user not found: " + what);
    }
}
