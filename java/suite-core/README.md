# suite-core

Shared plumbing for the Atheris suite (ATH-SUI-001, work item S0). Both products (IT Governance `itg`,
Information Security `isec`) include it as a Maven module. It gives each product signed HTTP+JSON
events, a transactional outbox/inbox, query client, deep links and a delivery-failures admin API.
Spring Boot 3.5, Java 21+, PostgreSQL, JDBC only (no JPA, no Spring Security).

Everything is auto-configured and active only when `suite.enabled=true`. When false: no beans besides a
no-op `SuitePublisher`, no outbox rows, no endpoints.

## Configuration

```yaml
suite:
  enabled: true
  source: itg                    # this product's code, stamped on every envelope
  client-id: itg-client          # sent as X-Suite-Client
  secret: ${SUITE_SECRET}
  previous-secret: ${SUITE_PREVIOUS_SECRET:}          # accepted until previous-secret-expires-at (24h rotation)
  previous-secret-expires-at: 2026-11-01T09:00:00Z
  clock-skew-seconds: 300
  query-timeout: 3s
  peer:
    url: http://127.0.0.1:8081   # peer product, usually same server
    client-id: isec-client       # the client id the peer sends to us
  tenants: [kano-heritage-bank]  # used by the default TenantDirectory
  contracts:                     # c1..c15, default true
    c9: false
  outbox:
    poll-interval: 5s
    batch-size: 50
    max-age: 72h                 # after this, a failing event becomes `failed`
```

## Using it in a product

1. Add the dependency `ng.atheris:suite-core:0.1.0-SNAPSHOT`, plus Flyway (`flyway-core`,
   `flyway-database-postgresql`) and the PostgreSQL driver (not pulled in transitively).
2. Add the migration location: `spring.flyway.locations=classpath:db/migration,classpath:db/migration/suite`
   (creates `suite_outbox`, `suite_inbox`, `suite_audit_log`).
3. Implement, as Spring beans:
   - `SuiteEventHandler` per event type (`type()`, `handle(Envelope)`, optional `contract()`); throw
     `SuiteValidationException` for bad data (422). Handlers run in the inbox transaction.
   - `SuiteRefResolver` (`supports(SuiteRef)`, `urlFor(SuiteRef)`) for `/suite/open?ref=...`.
   - Optionally your own `TenantDirectory` to replace the `suite.tenants` list.
4. Emit events with `SuitePublisher.publish(...)` inside your business `@Transactional` method. It throws if
   there is no transaction. Catch `SuitePeerUnavailableException` from `SuiteClient.query(...)` to show the
   "peer unavailable" banner.
5. **Secure the unsigned endpoints yourself.** `/suite/open` and `/suite/admin/**` (GET `failures`,
   POST `failures/{id}/retry`) carry no authentication; put them behind your login, and admin behind an
   admin role. `/api/suite/v1/**` is HMAC-signed and must be reachable without your session/CSRF
   (exclude it from CSRF if you use Spring Security).

Notes: per-subject ordering holds a later pending event until the earlier one is delivered (`failed`
events do not block). Retry backoff is 30s, 2m, 10m, 30m, 1h, then hourly until `max-age`. 4xx answers
fail immediately; only 5xx and network errors retry.

## Tests

Needs Docker (Testcontainers, `postgres:17`).

```bash
mvn -f java/suite-core/pom.xml verify
```

`LoopbackIT` runs one app as its own peer (same client id and secret) end to end.
