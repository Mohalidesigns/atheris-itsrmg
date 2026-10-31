-- ATH-SUI-001 S0: suite outbox / inbox / audit tables.

create table suite_outbox (
    seq             bigint generated always as identity unique,   -- global insertion order, used for per-subject ordering
    id              uuid primary key,
    type            varchar(100) not null,
    version         integer      not null,
    tenant          varchar(100) not null,
    subject         varchar(96)  not null,
    payload         jsonb        not null,                        -- the complete event envelope
    status          varchar(16)  not null default 'pending' check (status in ('pending', 'delivered', 'failed')),
    attempts        integer      not null default 0,
    next_attempt_at timestamptz  not null,
    last_error      text,
    created_at      timestamptz  not null,
    delivered_at    timestamptz
);
create index suite_outbox_due_idx     on suite_outbox (status, next_attempt_at);
create index suite_outbox_subject_idx on suite_outbox (subject);

create table suite_inbox (
    id           uuid primary key,                                -- the event id: PK conflict = duplicate
    type         varchar(100) not null,
    tenant       varchar(100) not null,
    received_at  timestamptz  not null,
    processed_at timestamptz,
    result       varchar(32)
);

create table suite_audit_log (
    id         bigint generated always as identity primary key,
    event_id   uuid,
    type       varchar(100),
    subject    varchar(96),
    direction  varchar(8)   not null check (direction in ('send', 'receive')),
    outcome    varchar(48)  not null,
    detail     text,
    created_at timestamptz  not null default now()
);
create index suite_audit_log_event_idx on suite_audit_log (event_id);
