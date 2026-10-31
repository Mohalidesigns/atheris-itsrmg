package ng.atheris.suite.testapp;

import org.springframework.boot.autoconfigure.SpringBootApplication;

/** Test-only host application. Lives in a sub-package so component scanning never sees library classes. */
@SpringBootApplication
public class TestApp {
}
