package ng.atheris.platform.user;

import java.util.Locale;
import java.util.regex.Pattern;

public final class UserValidator {

    private static final Pattern EMAIL = Pattern.compile("^[^@\\s,;<>]+@[^@\\s,;<>]+\\.[^@\\s,;<>]+$");

    private UserValidator() {}

    /** Trims and lower-cases; rejects blank or malformed addresses. */
    public static String normalizeEmail(String email) {
        if (email == null || email.isBlank()) {
            throw new InvalidUserException("email is required");
        }
        String e = email.trim().toLowerCase(Locale.ROOT);
        if (e.length() > 254 || !EMAIL.matcher(e).matches()) {
            throw new InvalidUserException("email is not well-formed: " + email);
        }
        return e;
    }

    /** Falls back to the e-mail local part when no name was supplied. */
    public static String nameOrDefault(String name, String email) {
        return name == null || name.isBlank() ? email.substring(0, email.indexOf('@')) : name.trim();
    }

    public static String blankToNull(String s) {
        return s == null || s.isBlank() ? null : s.trim();
    }
}
