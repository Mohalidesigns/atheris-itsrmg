package ng.atheris.platform.c5;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/** Data of {@code user.deactivated} (ATH-SUI-001 C5); only {@code email} is required. */
@JsonIgnoreProperties(ignoreUnknown = true)
public record UserDeactivatedData(
        String email,
        String name,
        @JsonProperty("job_title") String jobTitle,
        String department,
        Boolean active) {}
