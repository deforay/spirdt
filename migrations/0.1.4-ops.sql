-- SPI-RDT Assessment Platform
-- Migration 0.1.4 — Operational logging
--
-- Two tables with opposite lifetimes, which is the whole point of separating
-- them: audit_log is evidence and is never pruned; api_logs is debugging
-- exhaust and is pruned aggressively.

-- ─────────────────────────────────────────────
-- AUDIT LOG
-- ─────────────────────────────────────────────
--
-- Who did what. This is a health-system audit instrument, so the record of
-- who submitted, reviewed, finalised or reopened an assessment is part of the
-- product, not just operations.
--
-- organization_id is NULLABLE here and ONLY here among tenant tables:
-- platform-admin actions (creating or suspending an organisation) belong in
-- the audit trail but sit above any tenant. The global tenant scope must
-- therefore treat this table specially — a platform row is invisible to every
-- organisation, which is the intent.
--
-- entity_id is VARBINARY(16) so it can hold either an INT id or a BINARY(16)
-- UUID without a second column. Nothing joins on it; it exists so a human
-- reading the trail can find the record.
--
-- Excluded from bin/housekeeping pruning, permanently.

CREATE TABLE IF NOT EXISTS audit_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NULL,
    actor_type          ENUM('user', 'platform_admin', 'system') NOT NULL DEFAULT 'user',
    actor_id            INT UNSIGNED NULL,
    action              VARCHAR(100) NOT NULL,
    entity_type         VARCHAR(50) NULL,
    entity_id           VARBINARY(16) NULL,
    metadata            JSON NULL,
    ip_address          VARCHAR(45) NULL,
    request_uid         VARCHAR(32) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_org_created (organization_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_actor (actor_type, actor_id, created_at),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- API LOGS
-- ─────────────────────────────────────────────
--
-- Request-level debugging exhaust, correlated to application log lines by
-- request_uid (the UidProcessor value from src/Helper/Log.php).
--
-- request_body is REDACTED before it is written — passwords, tokens and
-- phone numbers have no business sitting in a log row. A redacted log is
-- still debuggable; a leaked credential is not.
--
-- Sync endpoints receive the largest bodies in the system, so the writer
-- truncates rather than storing a multi-megabyte assessment payload here.
-- The full payload already lives in submissions_raw, immutably, which is the
-- copy that matters.
--
-- Pruned by bin/housekeeping. This is the table that grows fastest.

CREATE TABLE IF NOT EXISTS api_logs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NULL,
    user_id             INT UNSIGNED NULL,
    request_uid         VARCHAR(32) NULL,
    method              VARCHAR(10) NOT NULL,
    path                VARCHAR(255) NOT NULL,
    status_code         SMALLINT UNSIGNED NULL,
    duration_ms         INT UNSIGNED NULL,
    request_body        MEDIUMTEXT NULL,
    ip_address          VARCHAR(45) NULL,
    user_agent          VARCHAR(255) NULL,
    device_id           VARCHAR(100) NULL,
    app_version         VARCHAR(20) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_logs_created (created_at),
    INDEX idx_api_logs_org_created (organization_id, created_at),
    INDEX idx_api_logs_uid (request_uid),
    INDEX idx_api_logs_status (status_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
