package ng.atheris.platform;

import java.time.OffsetDateTime;
import java.util.Collection;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;
import java.util.TreeSet;

import org.springframework.jdbc.core.simple.JdbcClient;

import ng.atheris.platform.user.InvalidUserException;

/** All SQL of the platform tables. Every user query is scoped by organization id. */
public class PlatformRepository {

    public record Organization(long id, String slug, String name, boolean active) {}

    public record UserRow(long id, long organizationId, String orgSlug, String email, String name, String jobTitle,
                          String department, String passwordHash, boolean active, String source,
                          OffsetDateTime createdAt) {}

    private static final String USER_SELECT = """
            select u.id, u.organization_id, o.slug, u.email, u.name, u.job_title, u.department, u.password_hash,
                   u.active, u.source, u.created_at
            from users u join organizations o on o.id = u.organization_id""";

    private final JdbcClient jdbc;

    public PlatformRepository(JdbcClient jdbc) {
        this.jdbc = jdbc;
    }

    private static UserRow mapUser(java.sql.ResultSet rs, int i) throws java.sql.SQLException {
        return new UserRow(rs.getLong(1), rs.getLong(2), rs.getString(3), rs.getString(4), rs.getString(5),
                rs.getString(6), rs.getString(7), rs.getString(8), rs.getBoolean(9), rs.getString(10),
                rs.getObject(11, OffsetDateTime.class));
    }

    public Optional<Organization> findOrg(String slug) {
        return jdbc.sql("select id, slug, name, active from organizations where slug = :slug").param("slug", slug)
                .query((rs, i) -> new Organization(rs.getLong(1), rs.getString(2), rs.getString(3), rs.getBoolean(4)))
                .optional();
    }

    public boolean activeOrgExists(String slug) {
        return slug != null && jdbc.sql("select count(*) from organizations where slug = :slug and active")
                .param("slug", slug).query(Integer.class).single() > 0;
    }

    public Organization ensureOrg(String slug, String name) {
        jdbc.sql("insert into organizations (slug, name) values (:slug, :name) on conflict (slug) do nothing")
                .param("slug", slug).param("name", name).update();
        return findOrg(slug).orElseThrow();
    }

    public Optional<UserRow> findUser(long orgId, String email) {
        return jdbc.sql(USER_SELECT + " where u.organization_id = :org and u.email = :email")
                .param("org", orgId).param("email", email).query(PlatformRepository::mapUser).optional();
    }

    public Optional<UserRow> findUserById(long orgId, long id) {
        return jdbc.sql(USER_SELECT + " where u.organization_id = :org and u.id = :id")
                .param("org", orgId).param("id", id).query(PlatformRepository::mapUser).optional();
    }

    public List<UserRow> listUsers(long orgId) {
        return jdbc.sql(USER_SELECT + " where u.organization_id = :org order by u.email")
                .param("org", orgId).query(PlatformRepository::mapUser).list();
    }

    /** Active, login-capable users matching a login email, across organizations. */
    public List<UserRow> findLoginCandidates(String email, String orgSlug) {
        String sql = USER_SELECT + " where u.email = :email and u.active and o.active and u.password_hash is not null"
                + (orgSlug != null ? " and o.slug = :slug" : "");
        var q = jdbc.sql(sql).param("email", email);
        if (orgSlug != null) {
            q = q.param("slug", orgSlug);
        }
        return q.query(PlatformRepository::mapUser).list();
    }

    public UserRow insertUser(long orgId, String email, String name, String jobTitle, String department,
                              String passwordHash, boolean active, String source, OffsetDateTime now) {
        long id = jdbc.sql("""
                insert into users (organization_id, email, name, job_title, department, password_hash, active, source,
                                   created_at, updated_at, deactivated_at)
                values (:org, :email, :name, cast(:jt as varchar), cast(:dep as varchar), cast(:pw as varchar),
                        :active, :source, :now, :now, case when :active then null else cast(:now as timestamptz) end)
                returning id""")
                .param("org", orgId).param("email", email).param("name", name).param("jt", jobTitle)
                .param("dep", department).param("pw", passwordHash).param("active", active).param("source", source)
                .param("now", now).query(Long.class).single();
        return findUserById(orgId, id).orElseThrow();
    }

    /** Insert or update by (org, email); keeps the existing source and password. */
    public void upsertSuiteUser(long orgId, String email, String name, String jobTitle, String department,
                                boolean active, OffsetDateTime now) {
        jdbc.sql("""
                insert into users (organization_id, email, name, job_title, department, active, source,
                                   created_at, updated_at, deactivated_at)
                values (:org, :email, :name, cast(:jt as varchar), cast(:dep as varchar), :active, 'suite',
                        :now, :now, case when :active then null else cast(:now as timestamptz) end)
                on conflict (organization_id, email) do update set
                    name = excluded.name, job_title = excluded.job_title, department = excluded.department,
                    active = excluded.active, updated_at = excluded.updated_at,
                    deactivated_at = case when excluded.active then null
                                          else coalesce(users.deactivated_at, excluded.updated_at) end""")
                .param("org", orgId).param("email", email).param("name", name).param("jt", jobTitle)
                .param("dep", department).param("active", active).param("now", now).update();
    }

    public void updateProfile(long orgId, long id, String name, String jobTitle, String department, OffsetDateTime now) {
        jdbc.sql("""
                update users set name = :name, job_title = cast(:jt as varchar), department = cast(:dep as varchar),
                                 updated_at = :now
                where organization_id = :org and id = :id""")
                .param("name", name).param("jt", jobTitle).param("dep", department).param("now", now)
                .param("org", orgId).param("id", id).update();
    }

    /** @return rows changed (0 when already in that state or absent) */
    public int setActive(long orgId, String email, boolean active, OffsetDateTime now) {
        return jdbc.sql("""
                update users set active = :active, updated_at = :now,
                       deactivated_at = case when :active then null else :now end
                where organization_id = :org and email = :email and active <> :active""")
                .param("active", active).param("now", now).param("org", orgId).param("email", email).update();
    }

    public Set<String> rolesOf(long userId) {
        return new TreeSet<>(jdbc.sql("select r.name from user_roles ur join roles r on r.id = ur.role_id where ur.user_id = :id")
                .param("id", userId).query(String.class).list());
    }

    public Map<Long, Set<String>> rolesByUserInOrg(long orgId) {
        Map<Long, Set<String>> out = new HashMap<>();
        jdbc.sql("""
                select ur.user_id, r.name from user_roles ur join roles r on r.id = ur.role_id
                join users u on u.id = ur.user_id where u.organization_id = :org""")
                .param("org", orgId).query((rs, i) -> Map.entry(rs.getLong(1), rs.getString(2))).list()
                .forEach(e -> out.computeIfAbsent(e.getKey(), k -> new TreeSet<>()).add(e.getValue()));
        return out;
    }

    /** Replaces the user's roles. The caller has verified the user belongs to the intended organization. */
    public void setRoles(long userId, Collection<String> names) {
        List<Long> ids = names.isEmpty() ? List.of()
                : jdbc.sql("select id from roles where name in (:names)").param("names", names).query(Long.class).list();
        if (ids.size() != names.stream().distinct().count()) {
            throw new InvalidUserException("unknown role in " + names);
        }
        jdbc.sql("delete from user_roles where user_id = :id").param("id", userId).update();
        for (Long rid : ids) {
            jdbc.sql("insert into user_roles (user_id, role_id) values (:u, :r)").param("u", userId).param("r", rid).update();
        }
    }
}
