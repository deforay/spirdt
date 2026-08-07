-- ─────────────────────────────────────────────
-- WHO, FROM WHERE, ON WHAT — for logs and for the audit trail
-- ─────────────────────────────────────────────
--
-- Both tables already record an IP address, and an IP address is not an
-- identity. Assessors work on mobile networks behind carrier-grade NAT, where
-- a whole province shares one public address: two rows with the same IP are
-- not two requests from the same person, and two rows with different IPs are
-- not two people. Reading a trail that way produces confident wrong answers,
-- which is worse than reading nothing.
--
-- session_hash is the correlator that actually holds. One sign-in, one value,
-- carried by every request it makes. It survives a changing IP, distinguishes
-- two people sharing one, and ends when they sign out.
--
-- Hashed rather than stored. It is derived from the refresh token, and a
-- session identifier that can be read out of a log is a session identifier
-- that can be replayed from one. What is kept is enough to say "these requests
-- are the same session" and not enough to become that session.
--
-- platform and browser are DERIVED from the user agent, which both tables
-- already keep. Stored alongside rather than parsed at read time, because a
-- report grouping by browser should not depend on the parser that happened to
-- be installed the day it ran — and because the raw string stays, so a better
-- parser can be run over it later.
--
-- Nullable throughout. These describe a request, and rows are written by
-- things that are not requests: bin/recover-access acts with no session, no
-- browser and no address. A column that forced a value would be filled with a
-- lie on exactly the rows that matter most.

ALTER TABLE audit_log
    ADD COLUMN session_hash CHAR(64) NULL AFTER actor_id,
    ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address,
    ADD COLUMN platform VARCHAR(40) NULL AFTER user_agent,
    ADD COLUMN browser VARCHAR(40) NULL AFTER platform,
    ADD COLUMN device_id VARCHAR(100) NULL AFTER browser;

ALTER TABLE api_logs
    ADD COLUMN session_hash CHAR(64) NULL AFTER user_id,
    ADD COLUMN platform VARCHAR(40) NULL AFTER user_agent,
    ADD COLUMN browser VARCHAR(40) NULL AFTER platform;

-- "Everything this session did" is the question both tables are read with when
-- something has gone wrong, and it is asked over a time range because that is
-- how somebody describes an incident.
CREATE INDEX idx_audit_session ON audit_log (session_hash, created_at);
CREATE INDEX idx_api_logs_session ON api_logs (session_hash, created_at);

-- ─────────────────────────────────────────────
-- THE SESSION ITSELF
-- ─────────────────────────────────────────────
--
-- Minted once, at sign-in, and carried by every access token that session
-- issues. It has to live on the refresh token row because refresh tokens
-- ROTATE: each refresh mints a new one and revokes the old, and a session
-- identifier derived from the token would change every fifteen minutes,
-- which is precisely the correlation it exists to provide. The new row copies
-- it from the row it replaces.
--
-- It ends when the session does. Signing out revokes the refresh token, so
-- nothing carries the value again — which is what makes "everything this
-- session did" a bounded question with an answer.

ALTER TABLE refresh_tokens
    ADD COLUMN session_hash CHAR(64) NULL AFTER token_hash;

CREATE INDEX idx_refresh_tokens_session ON refresh_tokens (session_hash);

-- ─────────────────────────────────────────────
-- CLIENT ERRORS
-- ─────────────────────────────────────────────
--
-- The half of the application that fails where nobody can see it.
--
-- The assessor app runs offline, on a device in a laboratory, and when it
-- throws there is no server round trip to notice. Today the only evidence is
-- a screenshot somebody thought to take. A DataCloneError that silently lost
-- every Part A write survived a full test suite and was found because a person
-- happened to open a console — and the same class of failure on a tablet in
-- the field is found by nobody.
--
-- Separate from api_logs on purpose. That table is what the server did; this
-- is what the client could not do, and the two have different lifetimes, a
-- different shape, and different rates. Mixing them means neither can be
-- pruned on its own.
--
-- assessment_id is a plain column and NOT a foreign key. The error worth
-- catching most is the one where the assessment could not be written, so a
-- constraint requiring the row to exist would refuse exactly the report that
-- explains why it does not.
--
-- Pruned by bin/housekeeping alongside api_logs.

CREATE TABLE IF NOT EXISTS client_errors (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NULL,
    user_id             INT UNSIGNED NULL,
    session_hash        CHAR(64) NULL,
    -- What the device was doing. 'sync' and 'save' are the two that cost an
    -- assessor their day; 'unhandled' is everything nobody predicted.
    kind                ENUM('unhandled', 'rejection', 'save', 'sync', 'upload') NOT NULL,
    message             VARCHAR(500) NOT NULL,
    -- Truncated by the writer. A minified stack is long, and the first frames
    -- are the ones that say anything.
    stack               TEXT NULL,
    -- The screen it happened on, as the app's own route rather than a URL, so
    -- it groups across assessments.
    context             VARCHAR(255) NULL,
    assessment_id       BINARY(16) NULL,
    app_version         VARCHAR(20) NULL,
    device_id           VARCHAR(100) NULL,
    ip_address          VARCHAR(45) NULL,
    user_agent          VARCHAR(255) NULL,
    platform            VARCHAR(40) NULL,
    browser             VARCHAR(40) NULL,
    -- When it happened on the DEVICE, which is not when it arrived. An offline
    -- error is reported on the next sync, and the gap between the two is
    -- sometimes the whole story.
    occurred_at         DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_errors_created (created_at),
    INDEX idx_client_errors_org_created (organization_id, created_at),
    INDEX idx_client_errors_kind (kind, created_at),
    INDEX idx_client_errors_session (session_hash, created_at),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
