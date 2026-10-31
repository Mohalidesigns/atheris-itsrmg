# platform-core

Contract C5 of ATH-SUI-001: tenants, users and roles shared by both products (IT Governance `itg`,
Information Security `isec`). A plain library like `suite-core` (Spring Boot 3.5, Java 21, JDBC, PostgreSQL).
Package `ng.atheris.platform`; auto-configured via `AutoConfiguration.imports`. Disable with `platform.enabled=false`.

## What it provides

- Tables `organizations` (tenant key = `slug`), `users` (unique per org on lower-cased email), `roles`
  (`ADMIN`, `SUITE_ADMIN`, `USER` seeded), `user_roles`. Migration `V900__platform_core.sql`.
- `JdbcTenantDirectory`: a tenant is known when an active organization has that slug. It replaces suite-core's
  `suite.tenants` list (this auto-configuration runs `before` `SuiteAutoConfiguration`, whose default is
  `@ConditionalOnMissingBean`). Unknown tenant -> 409 on inbound events, always on (not toggled by c5).
- `UserService` (`provision`, `update`, `deactivate`, `setRoles`): each `@Transactional`; writes the user and,
  when `suite.enabled=true` and contract `c5` is on, a `user.provisioned` / `user.deactivated` outbox event
  with subject `<suite.source>:user:<email>` and data `email, name, job_title, department, active`.
- `UserProvisionedHandler` / `UserDeactivatedHandler` (contract `c5`): upsert/deactivate by
  (org slug, lower(email)) through `UserSyncService`, which never publishes (no ping-pong), never sets a
  password and never grants roles. Created rows have `source='suite'`. Bad data -> 422. Data records live in
  `ng.atheris.platform.c5`.
- Authentication: `PlatformUserDetailsService` (active users with a password only, `ROLE_<role>` authorities,
  username = email, BCrypt `PasswordEncoder`). If the same email exists in several orgs, log in as
  `<org-slug>|<email>`. Suite-synced users have no password and cannot log in locally.
- `CurrentUser` bean (`require()`, `optional()`, `organizationId()`, `organizationSlug()`) for tenant scoping.
  Always filter your own tenant tables by `currentUser.organizationId()`.
- Admin REST (`/platform/users`): `GET` (own org only), `POST` (provision with password and optional roles),
  `POST /{id}/deactivate`, `POST /{id}/roles` (`{"roles":["ADMIN"]}`, replaces; roles are local, never synced).
  Users of another org answer 404.
- `DemoDataLoader` under Spring profile `demo` (idempotent, never publishes): orgs `kano-heritage-bank`,
  `first-bank-nigeria`; `admin@kanoheritage.ng` / `admin@firstbanknigeria.ng` (password `password`,
  ADMIN + SUITE_ADMIN) and a few staff (e.g. `ciso@kanoheritage.ng` Amina Bello).

## What a product must do

1. Add module `platform-core` (after `suite-core`) to `java/pom.xml` and depend on `ng.atheris:platform-core`.
   Provide Flyway and the PostgreSQL driver.
2. Flyway locations: `classpath:db/migration,classpath:db/migration/platform,classpath:db/migration/suite`.
   Product migrations must use versions below 900 or above 1000 (platform V900+, suite V1000+).
3. Configure `suite.*` as in suite-core; `suite.tenants` is no longer used. Toggle: `suite.contracts.c5=false`.
4. Security chain (the module does not define one):
   ```java
   http.csrf(c -> c.ignoringRequestMatchers("/api/suite/v1/**"))
       .httpBasic(withDefaults())            // or formLogin
       .authorizeHttpRequests(a -> {
           a.requestMatchers("/api/suite/v1/**").permitAll();   // HMAC-signed by suite-core
           PlatformSecurity.adminRules(a);                      // /platform/**, /suite/admin/** -> ROLE_ADMIN
           a.anyRequest().authenticated();
       });
   ```
   REST clients that POST need CSRF handled by the product. Sessions are not re-checked: a deactivated user keeps
   an existing session until it ends (use short sessions or check `active` per request).
5. Set the same organization slug in both products at installation (insert into `organizations`).

## Tests

Needs Docker (Testcontainers `postgres:17`): `mvn -f java/platform-core/pom.xml verify`
(install suite-core first: `mvn -f java/suite-core/pom.xml install`).
