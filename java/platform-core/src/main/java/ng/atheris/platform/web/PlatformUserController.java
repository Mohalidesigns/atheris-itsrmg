package ng.atheris.platform.web;

import java.time.OffsetDateTime;
import java.util.List;
import java.util.Map;
import java.util.Set;

import com.fasterxml.jackson.annotation.JsonProperty;

import org.springframework.boot.autoconfigure.condition.ConditionalOnWebApplication;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import ng.atheris.platform.PlatformRepository.UserRow;
import ng.atheris.platform.security.CurrentUser;
import ng.atheris.platform.user.DuplicateUserException;
import ng.atheris.platform.user.InvalidUserException;
import ng.atheris.platform.user.UserNotFoundException;
import ng.atheris.platform.user.UserService;

/** Admin API. Products protect {@code /platform/**} with ROLE_ADMIN. Always scoped to the caller's organization. */
@RestController
@RequestMapping("/platform/users")
@ConditionalOnWebApplication(type = ConditionalOnWebApplication.Type.SERVLET)
public class PlatformUserController {

    public record UserView(long id, String email, String name, @JsonProperty("job_title") String jobTitle,
                           String department, boolean active, String source, Set<String> roles,
                           @JsonProperty("created_at") OffsetDateTime createdAt) {}

    public record CreateUser(String email, String name, @JsonProperty("job_title") String jobTitle,
                             String department, String password, List<String> roles) {}

    public record SetRoles(List<String> roles) {}

    private final UserService users;
    private final CurrentUser current;

    public PlatformUserController(UserService users, CurrentUser current) {
        this.users = users;
        this.current = current;
    }

    @GetMapping
    public List<UserView> list() {
        long org = current.organizationId();
        Map<Long, Set<String>> roles = users.rolesByUser(org);
        return users.list(org).stream().map(u -> view(u, roles.getOrDefault(u.id(), Set.of()))).toList();
    }

    @PostMapping
    public ResponseEntity<UserView> create(@RequestBody CreateUser req) {
        UserRow u = users.provision(current.organizationSlug(), req.email(), req.name(), req.jobTitle(),
                req.department(), req.password(), req.roles());
        return ResponseEntity.status(HttpStatus.CREATED).body(view(u, users.rolesOf(u.id())));
    }

    @PostMapping("/{id}/deactivate")
    public UserView deactivate(@PathVariable long id) {
        if (id == current.require().getId()) {
            throw new InvalidUserException("you cannot deactivate yourself");
        }
        UserRow u = users.deactivate(current.organizationId(), id);
        return view(u, users.rolesOf(u.id()));
    }

    @PostMapping("/{id}/roles")
    public UserView roles(@PathVariable long id, @RequestBody SetRoles req) {
        UserRow u = users.setRoles(current.organizationId(), id, req.roles());
        return view(u, users.rolesOf(u.id()));
    }

    private static UserView view(UserRow u, Set<String> roles) {
        return new UserView(u.id(), u.email(), u.name(), u.jobTitle(), u.department(), u.active(), u.source(), roles,
                u.createdAt());
    }

    @ExceptionHandler(UserNotFoundException.class)
    ResponseEntity<Map<String, String>> notFound(UserNotFoundException e) {
        return ResponseEntity.status(HttpStatus.NOT_FOUND).body(Map.of("error", e.getMessage()));
    }

    @ExceptionHandler(DuplicateUserException.class)
    ResponseEntity<Map<String, String>> conflict(DuplicateUserException e) {
        return ResponseEntity.status(HttpStatus.CONFLICT).body(Map.of("error", e.getMessage()));
    }

    @ExceptionHandler(InvalidUserException.class)
    ResponseEntity<Map<String, String>> invalid(InvalidUserException e) {
        return ResponseEntity.status(HttpStatus.UNPROCESSABLE_ENTITY).body(Map.of("error", e.getMessage()));
    }
}
