package ng.atheris.platform.user;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Collection;
import java.util.List;
import java.util.Map;
import java.util.Set;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;

import org.springframework.beans.factory.ObjectProvider;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.transaction.annotation.Transactional;

import ng.atheris.platform.PlatformRepository;
import ng.atheris.platform.PlatformRepository.Organization;
import ng.atheris.platform.PlatformRepository.UserRow;
import ng.atheris.platform.security.CurrentUser;
import ng.atheris.suite.SuiteProperties;
import ng.atheris.suite.model.Envelope;
import ng.atheris.suite.outbox.SuitePublisher;

/**
 * Local user administration. Each change is written together with a {@code user.provisioned} /
 * {@code user.deactivated} outbox event when suite is enabled and contract c5 is on. Changes arriving from the
 * peer go through {@link UserSyncService} instead, which does not publish.
 */
public class UserService {

    public static final String PROVISIONED = "user.provisioned";
    public static final String DEACTIVATED = "user.deactivated";
    public static final String CONTRACT = "c5";

    private final PlatformRepository repo;
    private final PasswordEncoder encoder;
    private final SuitePublisher publisher;
    private final ObjectProvider<SuiteProperties> props;
    private final ObjectMapper mapper;
    private final Clock clock;
    private final CurrentUser current;

    public UserService(PlatformRepository repo, PasswordEncoder encoder, SuitePublisher publisher,
                       ObjectProvider<SuiteProperties> props, ObjectMapper mapper, Clock clock, CurrentUser current) {
        this.repo = repo;
        this.encoder = encoder;
        this.publisher = publisher;
        this.props = props;
        this.mapper = mapper;
        this.clock = clock;
        this.current = current;
    }

    @Transactional(readOnly = true)
    public List<UserRow> list(long orgId) {
        return repo.listUsers(orgId);
    }

    @Transactional(readOnly = true)
    public Map<Long, Set<String>> rolesByUser(long orgId) {
        return repo.rolesByUserInOrg(orgId);
    }

    @Transactional(readOnly = true)
    public Set<String> rolesOf(long userId) {
        return repo.rolesOf(userId);
    }

    @Transactional
    public UserRow provision(String orgSlug, String email, String name, String jobTitle, String department,
                             String password, Collection<String> roles) {
        Organization org = repo.findOrg(orgSlug).filter(Organization::active)
                .orElseThrow(() -> new InvalidUserException("unknown tenant: " + orgSlug));
        String e = UserValidator.normalizeEmail(email);
        if (repo.findUser(org.id(), e).isPresent()) {
            throw new DuplicateUserException(e);
        }
        String hash = null;
        if (password != null && !password.isEmpty()) {
            if (password.length() < 8) {
                throw new InvalidUserException("password must be at least 8 characters");
            }
            hash = encoder.encode(password);
        }
        UserRow u = repo.insertUser(org.id(), e, UserValidator.nameOrDefault(name, e),
                UserValidator.blankToNull(jobTitle), UserValidator.blankToNull(department), hash, true, "local", now());
        if (roles != null && !roles.isEmpty()) {
            repo.setRoles(u.id(), roles);
        }
        publish(PROVISIONED, u);
        return u;
    }

    @Transactional
    public UserRow update(long orgId, long id, String name, String jobTitle, String department) {
        UserRow u = repo.findUserById(orgId, id).orElseThrow(() -> new UserNotFoundException(String.valueOf(id)));
        repo.updateProfile(orgId, id, UserValidator.nameOrDefault(name, u.email()),
                UserValidator.blankToNull(jobTitle), UserValidator.blankToNull(department), now());
        UserRow updated = repo.findUserById(orgId, id).orElseThrow();
        publish(PROVISIONED, updated);
        return updated;
    }

    /** Idempotent: an already inactive user is returned untouched and nothing is published. */
    @Transactional
    public UserRow deactivate(long orgId, long id) {
        UserRow u = repo.findUserById(orgId, id).orElseThrow(() -> new UserNotFoundException(String.valueOf(id)));
        if (u.active()) {
            repo.setActive(orgId, u.email(), false, now());
            u = repo.findUserById(orgId, id).orElseThrow();
            publish(DEACTIVATED, u);
        }
        return u;
    }

    /** Roles are local to this product and are never synced. */
    @Transactional
    public UserRow setRoles(long orgId, long id, Collection<String> roles) {
        UserRow u = repo.findUserById(orgId, id).orElseThrow(() -> new UserNotFoundException(String.valueOf(id)));
        repo.setRoles(u.id(), roles == null ? List.of() : roles);
        return u;
    }

    private void publish(String type, UserRow u) {
        SuiteProperties p = props.getIfAvailable();
        if (p == null || !p.isEnabled() || !p.isContractEnabled(CONTRACT)) {
            return;
        }
        ObjectNode data = mapper.createObjectNode();
        data.put("email", u.email());
        data.put("name", u.name());
        data.put("job_title", u.jobTitle());
        data.put("department", u.department());
        data.put("active", u.active());
        Envelope.Actor actor = current.optional().map(c -> new Envelope.Actor(c.getEmail(), c.getName())).orElse(null);
        publisher.publish(type, 1, u.orgSlug(), p.getSource() + ":user:" + u.email(), data, actor, null);
    }

    private OffsetDateTime now() {
        return OffsetDateTime.ofInstant(clock.instant(), ZoneOffset.UTC);
    }
}
