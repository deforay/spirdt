-- SPI-RDT Assessment Platform
-- Migration 0.1.0 — Baseline: tenancy, identity, RBAC, auth
--
-- The baseline is split across 0.1.0–0.1.4 rather than one file. The full
-- schema is ~20 tables and a single file is hard to review; the runner
-- orders by semver so the split is free. Read them in order — later files
-- carry foreign keys into earlier ones.
--
-- ID STRATEGY (applies across all baseline files)
--   BINARY(16) UUIDv7  — anything that can ORIGINATE ON A DEVICE while
--                        offline. The client generates the ID so a retried
--                        sync upserts instead of duplicating. UUIDv7 is
--                        time-ordered, so it does not fragment the InnoDB
--                        clustered index the way random UUIDv4 does.
--                        Tables: assessments, assessment_pathogens,
--                        findings, attachments.
--   INT UNSIGNED        — AUTO_INCREMENT. Server-owned reference data that
--                        is only ever created online and down-synced to
--                        devices.
--
--   Answers deliberately do NOT get a client ID: the idempotency unit is
--   the assessment (submitted as a whole document), so answers upsert on
--   their natural key. One less thing for the client to generate.
--
-- TENANCY
--   Every tenant-scoped table carries organization_id, and every index on
--   such a table LEADS with it. Single-tenant installations simply have one
--   organizations row — there is no second code path.
--
--   Shared (deliberately NOT tenant-scoped): system_config, platform_admins,
--   question_catalog (0.1.2), and the global base template (0.1.2).

-- ─────────────────────────────────────────────
-- SYSTEM CONFIG (version tracking)
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS system_config (
    `key`       VARCHAR(100) PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_config (`key`, value) VALUES ('app_version', '0.1.0')
ON DUPLICATE KEY UPDATE value = '0.1.0';

-- ─────────────────────────────────────────────
-- ORGANIZATIONS (tenant root)
-- ─────────────────────────────────────────────
--
-- date_format and timezone are here because the SPI-RDT User's Guide makes
-- them an explicit customisation point: "the date format must be agreed
-- upon during tool customization". Never hardcode either.

CREATE TABLE IF NOT EXISTS organizations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    country_code    CHAR(2) NULL,
    timezone        VARCHAR(64) NOT NULL DEFAULT 'UTC',
    date_format     VARCHAR(20) NOT NULL DEFAULT 'd/m/Y',
    default_locale  VARCHAR(10) NOT NULL DEFAULT 'en',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_organizations_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- PLATFORM ADMINS
-- ─────────────────────────────────────────────
--
-- Deliberately a SEPARATE table from users, not a flag on it.
--
-- Two reasons. First, it makes "platform admin cannot read assessment data"
-- structural rather than a permission check someone can get wrong: they hold
-- no organization_id, so the global tenant scope has nothing to resolve and
-- every tenant-scoped query returns empty by construction.
--
-- Second, a nullable users.organization_id would poison that scope for every
-- other user in the system — the one column the whole isolation model rests
-- on would become "usually set". Keeping the realms apart means
-- users.organization_id is NOT NULL, always.
--
-- Only meaningful in multi-tenant installations. Created by bin/create-organization.

CREATE TABLE IF NOT EXISTS platform_admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(200) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- GEOGRAPHIC UNITS
-- ─────────────────────────────────────────────
--
-- Self-referencing hierarchy (national → regional → district → …) so each
-- country can model its own tiering depth without a schema change.
--
-- This is what user scoping hangs off: role alone is insufficient, a user is
-- (organisation + geographic scope). A district viewer sees one district of
-- one org. A NULL geo_unit_id on a user means org-wide.
--
-- Distinct from facilities.level, which is the facility's own position in the
-- tiered LABORATORY network (Part A of the checklist) — a different concept
-- that happens to use similar words.

CREATE TABLE IF NOT EXISTS geo_units (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    parent_id           INT UNSIGNED NULL,
    level               VARCHAR(30) NOT NULL,
    name                VARCHAR(200) NOT NULL,
    code                VARCHAR(50) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geo_units_org_parent (organization_id, parent_id),
    INDEX idx_geo_units_org_level (organization_id, level),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES geo_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ROLES AND PERMISSIONS
-- ─────────────────────────────────────────────
--
-- Roles are per-organisation so a superadmin can rename them to local
-- vocabulary. The PERMISSION KEYS themselves are a code constant
-- (App\Constants\PermissionCatalog), not a table — permissions change with
-- deploys, not with data, and a catalog in code is greppable and typo-proof.
--
-- is_system marks the seeded five (superadmin, admin, auditor, viewer,
-- site_user). They may be renamed but not deleted, and superadmin's
-- permission set may not be reduced — otherwise an org can lock itself out.

CREATE TABLE IF NOT EXISTS roles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    `key`               VARCHAR(50) NOT NULL,
    name                VARCHAR(100) NOT NULL,
    description         VARCHAR(255) NULL,
    is_system           TINYINT(1) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_org_key (organization_id, `key`),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id             INT UNSIGNED NOT NULL,
    permission_key      VARCHAR(100) NOT NULL,
    PRIMARY KEY (role_id, permission_key),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- USERS
-- ─────────────────────────────────────────────
--
-- One role per user. Multi-role is a rabbit hole this domain does not need —
-- an auditor is an auditor. If it is ever required, add a user_roles pivot
-- then, not now.
--
-- Email is unique PER ORGANISATION, not globally: the same person may
-- legitimately exist in two organisations on a shared installation, and
-- global uniqueness would leak the existence of accounts across tenants.
--
-- facility_id is set only for site_user — the role that closes findings
-- assigned to their own facility.

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    role_id             INT UNSIGNED NOT NULL,
    geo_unit_id         INT UNSIGNED NULL,
    facility_id         INT UNSIGNED NULL,
    email               VARCHAR(255) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    full_name           VARCHAR(200) NOT NULL,
    title               VARCHAR(150) NULL,
    phone               VARCHAR(50) NULL,
    locale              VARCHAR(10) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at       DATETIME NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT UNSIGNED NULL,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_org_email (organization_id, email),
    INDEX idx_users_org_role (organization_id, role_id),
    INDEX idx_users_org_geo (organization_id, geo_unit_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (geo_unit_id) REFERENCES geo_units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users.facility_id FK is added in 0.1.1, once facilities exists.

-- ─────────────────────────────────────────────
-- AUTH TOKENS AND THROTTLING
-- ─────────────────────────────────────────────
--
-- refresh_tokens covers both realms, hence the nullable pair — exactly one
-- of user_id / platform_admin_id is set. Enforced in application code rather
-- than a CHECK so the constraint message stays useful.
--
-- Long TTLs matter here: an auditor may be offline for days between issuing
-- a token and syncing. Expiry must never be the thing that destroys local
-- drafts — the PWA holds drafts independently of auth state.
--
-- All three tables are pruned by bin/housekeeping.

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NULL,
    platform_admin_id   INT UNSIGNED NULL,
    token_hash          CHAR(64) NOT NULL,
    device_id           VARCHAR(100) NULL,
    user_agent          VARCHAR(255) NULL,
    issued_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NOT NULL,
    revoked_at          DATETIME NULL,
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    INDEX idx_refresh_tokens_user (user_id, expires_at),
    INDEX idx_refresh_tokens_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_admin_id) REFERENCES platform_admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    token_hash          CHAR(64) NOT NULL,
    expires_at          DATETIME NOT NULL,
    used_at             DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_hash (token_hash),
    INDEX idx_password_reset_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NULL,
    email               VARCHAR(255) NOT NULL,
    ip_address          VARCHAR(45) NOT NULL,
    succeeded           TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_time (email, attempted_at),
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
