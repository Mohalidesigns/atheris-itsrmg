package ng.atheris.suite.model;

import java.util.regex.Pattern;

/** Suite reference {@code <product>:<entity>:<code>} (ATH-SUI-001 section 3.4), at most 96 characters. */
public record SuiteRef(String product, String entity, String code) {

    public static final int MAX_LENGTH = 96;
    private static final Pattern SEGMENT = Pattern.compile("[a-z0-9][a-z0-9-]*");

    public SuiteRef {
        if (product == null || !SEGMENT.matcher(product).matches()
                || entity == null || !SEGMENT.matcher(entity).matches()) {
            throw new IllegalArgumentException("invalid suite reference product/entity");
        }
        if (code == null || code.isBlank() || code.chars().anyMatch(Character::isWhitespace)) {
            throw new IllegalArgumentException("invalid suite reference code");
        }
        if (toString(product, entity, code).length() > MAX_LENGTH) {
            throw new IllegalArgumentException("suite reference longer than " + MAX_LENGTH);
        }
    }

    public static SuiteRef parse(String text) {
        if (text == null) {
            throw new IllegalArgumentException("suite reference is null");
        }
        String[] p = text.split(":", 3);
        if (p.length != 3) {
            throw new IllegalArgumentException("suite reference must be <product>:<entity>:<code>");
        }
        return new SuiteRef(p[0], p[1], p[2]);
    }

    private static String toString(String product, String entity, String code) {
        return product + ":" + entity + ":" + code;
    }

    @Override
    public String toString() {
        return toString(product, entity, code);
    }
}
