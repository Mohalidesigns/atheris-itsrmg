package ng.atheris.suite.inbound;

/** Knows which tenant slugs exist in this product. Override the default (config-list based) bean as needed. */
@FunctionalInterface
public interface TenantDirectory {
    boolean exists(String slug);
}
