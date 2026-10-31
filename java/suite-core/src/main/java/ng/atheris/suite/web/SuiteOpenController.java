package ng.atheris.suite.web;

import java.net.URI;
import java.util.List;

import org.springframework.http.HttpHeaders;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

import ng.atheris.suite.model.SuiteRef;

/**
 * {@code GET /suite/open?ref=<suite ref>} deep link: 302 to the URL given by the first matching
 * {@link SuiteRefResolver}, 404 if none, 400 for a malformed reference.
 *
 * <p><b>Security:</b> this endpoint is not signed. The product must keep it inside its authenticated
 * area (suite-core deliberately does not depend on Spring Security). Resolvers should return
 * app-relative URLs.
 */
@RestController
public class SuiteOpenController {

    private final List<SuiteRefResolver> resolvers;

    public SuiteOpenController(List<SuiteRefResolver> resolvers) {
        this.resolvers = resolvers;
    }

    @GetMapping("/suite/open")
    public ResponseEntity<Void> open(@RequestParam("ref") String ref) {
        SuiteRef parsed;
        try {
            parsed = SuiteRef.parse(ref);
        } catch (IllegalArgumentException e) {
            return ResponseEntity.badRequest().build();
        }
        return resolvers.stream().filter(r -> r.supports(parsed)).findFirst()
                .map(r -> ResponseEntity.status(302).header(HttpHeaders.LOCATION, URI.create(r.urlFor(parsed)).toString()).<Void>build())
                .orElseGet(() -> ResponseEntity.notFound().build());
    }
}
