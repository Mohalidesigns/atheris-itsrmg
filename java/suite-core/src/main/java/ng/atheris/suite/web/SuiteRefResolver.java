package ng.atheris.suite.web;

import ng.atheris.suite.model.SuiteRef;

/** Maps a suite reference to the URL of the record's page in this product. Implement as a Spring bean. */
public interface SuiteRefResolver {

    boolean supports(SuiteRef ref);

    String urlFor(SuiteRef ref);
}
