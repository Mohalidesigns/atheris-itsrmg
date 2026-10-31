package ng.atheris.platform.c5;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;

/** Data of {@code user.provisioned} (ATH-SUI-001 C5). {@code active} defaults to true when absent. */
@JsonIgnoreProperties(ignoreUnknown = true)
public record UserProvisionedData(
        String email,
        String name,
        @JsonProperty("job_title") String jobTitle,
        String department,
        Boolean active) {}
