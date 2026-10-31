-- platform-core (ATH-SUI-001 C5): tenants, users, roles. Versions 900+ run before suite-core's V1000.
create table organizations (
    id          bigserial primary key,
    slug        varchar(100) not null unique,
    name        varchar(255) not null,
    active      boolean not null default true,
    created_at  timestamptz not null default now(),
    constraint organizations_slug_format check (slug ~ '^[a-z0-9][a-z0-9-]*$')
);

create table roles (
    id    bigserial primary key,
    name  varchar(64) not null unique
);

create table users (
    id              bigserial primary key,
    organization_id bigint not null references organizations (id),
    email           varchar(254) not null,
    name            varchar(255) not null,
    job_title       varchar(255),
    department      varchar(255),
    password_hash   varchar(255),
    active          boolean not null default true,
    source          varchar(10) not null default 'local',
    created_at      timestamptz not null default now(),
    updated_at      timestamptz not null default now(),
    deactivated_at  timestamptz,
    constraint users_source_check check (source in ('local', 'suite')),
    constraint users_email_lower check (email = lower(email)),
    constraint users_org_email_unique unique (organization_id, email)
);

create table user_roles (
    user_id  bigint not null references users (id) on delete cascade,
    role_id  bigint not null references roles (id),
    primary key (user_id, role_id)
);

insert into roles (name) values ('ADMIN'), ('SUITE_ADMIN'), ('USER');
