-- SPI-RDT Assessment Platform
-- Migration 0.1.3 — Campaigns, assessments, answers, findings, scores
--
-- The core of the system. Read 0.1.2 first for why templates are JSON.
--
-- ID STRATEGY HERE
--   Everything an auditor creates during a visit is BINARY(16) UUIDv7,
--   generated on the device: assessments, assessment_pathogens, findings,
--   attachments. Answers are the exception — see the note on that table.

-- ─────────────────────────────────────────────
-- CAMPAIGNS
-- ─────────────────────────────────────────────
--
-- An assessment round. Pins ONE template version, so every assessment in the
-- round answers the same instrument — which is what makes the scores in it
-- comparable. The User's Guide requires an annual assessment plan; campaign_
-- sites is that plan, and comparing it against actual assessments gives the
-- coverage figure.

CREATE TABLE IF NOT EXISTS campaigns (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    template_id         INT UNSIGNED NOT NULL,
    name                VARCHAR(200) NOT NULL,
    starts_on           DATE NULL,
    ends_on             DATE NULL,
    status              ENUM('planned', 'active', 'closed') NOT NULL DEFAULT 'planned',
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaigns_org_status (organization_id, status),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_sites (
    campaign_id         INT UNSIGNED NOT NULL,
    testing_site_id     BINARY(16) NOT NULL,
    organization_id     INT UNSIGNED NOT NULL,
    added_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id, testing_site_id),
    INDEX idx_campaign_sites_org_site (organization_id, testing_site_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (testing_site_id) REFERENCES testing_sites(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ASSESSMENTS
-- ─────────────────────────────────────────────
--
-- One per testing site per visit. template_id is PINNED at creation and never
-- follows the template forward — an assessment must always be readable against
-- the exact instrument it answered.
--
-- refers_specimens drives Section 5. When 0, the whole section is N/A and
-- contributes nothing to either the score or the possible total. Storing it
-- as one flag rather than nine N/A answers means the scoring engine can skip
-- the section outright and the auditor answers one question instead of nine.
--
-- previous_assessment_id is what lets question 1.8 ("have gaps from the last
-- assessment been addressed?") answer itself from prior findings, instead of
-- relying on the auditor remembering.
--
-- NO UNIQUE on (testing_site_id, assessed_on): a repeat visit on the same day
-- is legitimate, and two assessors both syncing is a data-stewardship
-- question, not something to block mid-visit. The application WARNS on a
-- likely duplicate; the index below is what makes that check cheap.
--
-- status: draft → submitted → [reviewed → finalised] → delivered.
-- The bracketed pair only occurs when organizations.requires_assessment_review
-- is 1 (see 0.1.1). Consumers must handle both shapes.

CREATE TABLE IF NOT EXISTS assessments (
    id                      BINARY(16) NOT NULL PRIMARY KEY,
    organization_id         INT UNSIGNED NOT NULL,
    campaign_id             INT UNSIGNED NULL,
    template_id             INT UNSIGNED NOT NULL,
    testing_site_id         BINARY(16) NOT NULL,
    -- Denormalised from testing_sites so scoping and reporting queries do not
    -- need the join. Written once at creation; a site never changes facility.
    facility_id             BINARY(16) NOT NULL,
    status                  ENUM('draft', 'submitted', 'reviewed', 'finalised', 'delivered')
                            NOT NULL DEFAULT 'draft',
    assessed_on             DATE NOT NULL,
    started_at              DATETIME NULL,
    ended_at                DATETIME NULL,
    previous_assessment_id  BINARY(16) NULL,
    previous_assessed_on    DATE NULL,
    refers_specimens        TINYINT(1) NULL,
    -- Part A answers that are per-visit rather than per-site: POC site count,
    -- the list of tests conducted, the staff roster, interviewee details, and
    -- a snapshot of facility type/level/affiliation as they stood on the day.
    -- JSON because none of it is queried analytically — it is report content.
    context                 JSON NULL,
    device_id               VARCHAR(100) NULL,
    app_version             VARCHAR(20) NULL,
    created_by              INT UNSIGNED NULL,
    submitted_at            DATETIME NULL,
    submitted_by            INT UNSIGNED NULL,
    reviewed_at             DATETIME NULL,
    reviewed_by             INT UNSIGNED NULL,
    finalised_at            DATETIME NULL,
    finalised_by            INT UNSIGNED NULL,
    delivered_at            DATETIME NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assessments_org_site_date (organization_id, testing_site_id, assessed_on),
    INDEX idx_assessments_org_status (organization_id, status),
    INDEX idx_assessments_org_campaign (organization_id, campaign_id),
    INDEX idx_assessments_org_facility (organization_id, facility_id),
    INDEX idx_assessments_previous (previous_assessment_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (template_id) REFERENCES templates(id),
    FOREIGN KEY (testing_site_id) REFERENCES testing_sites(id),
    FOREIGN KEY (facility_id) REFERENCES facilities(id),
    FOREIGN KEY (previous_assessment_id) REFERENCES assessments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ASSESSMENT PATHOGENS
-- ─────────────────────────────────────────────
--
-- Section 4 repeats PER PATHOGEN, not per test. A three-test HIV algorithm is
-- ONE row here, with all three tests named in tests_description. The paper
-- form lays this out as seven side-by-side columns; nothing here caps it, but
-- the UI should stay usable to about that many.

CREATE TABLE IF NOT EXISTS assessment_pathogens (
    id                  BINARY(16) NOT NULL PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    assessment_id       BINARY(16) NOT NULL,
    sequence            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    pathogen_name       VARCHAR(150) NOT NULL,
    -- Manufacturer and test names for the algorithm, per the Part 4 header
    -- row "Name all tests where there are testing algorithms".
    tests_description   TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pathogens_assessment_seq (assessment_id, sequence),
    INDEX idx_pathogens_org (organization_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ANSWERS
-- ─────────────────────────────────────────────
--
-- Deliberately NO client-generated ID. The idempotency unit is the assessment,
-- which syncs as a whole document, so answers upsert on their natural key —
-- one less thing for the device to generate and reconcile.
--
-- pathogen_key is a stored generated column that collapses NULL to a
-- zero-UUID. Without it the unique key would not fire for assessment-scoped
-- answers, because MySQL treats NULLs as distinct in a UNIQUE index and you
-- would silently accumulate duplicate answers to question 1.1. Verified
-- against MySQL 8.4 before this migration was written.
--
-- NO stored points column. Response plus the pinned template is sufficient to
-- compute a score, and duplicating the arithmetic per row invites drift.
-- The computed result is snapshotted once, in assessment_scores.
--
-- The comment is required for P, N and NA — the checklist says to describe
-- the gap or state why the question does not apply. Enforced in application
-- code so the message can explain itself.

CREATE TABLE IF NOT EXISTS answers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    assessment_id       BINARY(16) NOT NULL,
    question_code       VARCHAR(10) NOT NULL,
    pathogen_id         BINARY(16) NULL,
    pathogen_key        BINARY(16) GENERATED ALWAYS AS
                        (IFNULL(pathogen_id, X'00000000000000000000000000000000')) STORED,
    response            ENUM('Y', 'P', 'N', 'NA') NOT NULL,
    comment             TEXT NULL,
    answered_at         DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_answers_natural (assessment_id, question_code, pathogen_key),
    INDEX idx_answers_org_question_response (organization_id, question_code, response),
    INDEX idx_answers_pathogen (pathogen_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    -- No ON DELETE action on pathogen_id, and again not by choice: MySQL
    -- forbids CASCADE / SET NULL on the base column of a stored generated
    -- column, and pathogen_key is generated from pathogen_id (errno 1215).
    --
    -- Deleting an assessment still removes its answers, via the assessment_id
    -- cascade above — verified against MySQL 8.4, including the case where the
    -- assessment has pathogens and pathogen-scoped answers. What this does
    -- prevent is deleting a single pathogen while its answers remain, which is
    -- the correct refusal: drop the answers first, or delete the assessment.
    FOREIGN KEY (pathogen_id) REFERENCES assessment_pathogens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- FINDINGS
-- ─────────────────────────────────────────────
--
-- Every Partial or No becomes a finding. On paper this is Part D, a table
-- filled in after the visit that nobody follows up; here it is a tracked item
-- with an owner and a due date, which is the main thing this platform offers
-- over the paper form.
--
-- responsibility_level matters: the User's Guide is emphatic that gaps outside
-- the site's control must be escalated to district, regional or national level
-- rather than counted against the site and forgotten.
--
-- Findings carry over between assessments — resolving question 1.8 from data
-- rather than memory. See assessments.previous_assessment_id.

CREATE TABLE IF NOT EXISTS findings (
    id                      BINARY(16) NOT NULL PRIMARY KEY,
    organization_id         INT UNSIGNED NOT NULL,
    assessment_id           BINARY(16) NOT NULL,
    question_code           VARCHAR(10) NOT NULL,
    pathogen_id             BINARY(16) NULL,
    response                ENUM('P', 'N') NOT NULL,
    gap                     TEXT NOT NULL,
    recommendation          TEXT NULL,
    responsibility_level    ENUM('site', 'facility', 'district', 'regional', 'national')
                            NOT NULL DEFAULT 'site',
    responsible_person      VARCHAR(200) NULL,
    due_date                DATE NULL,
    status                  ENUM('open', 'in_progress', 'closed', 'escalated')
                            NOT NULL DEFAULT 'open',
    closed_on               DATE NULL,
    closed_by               INT UNSIGNED NULL,
    closure_note            TEXT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_findings_org_status_due (organization_id, status, due_date),
    INDEX idx_findings_org_responsibility (organization_id, responsibility_level, status),
    INDEX idx_findings_assessment (assessment_id),
    INDEX idx_findings_org_question (organization_id, question_code),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (pathogen_id) REFERENCES assessment_pathogens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ASSESSMENT SCORES (snapshot)
-- ─────────────────────────────────────────────
--
-- Computed once, server-side, and never recomputed from a live template.
-- A certification level has to mean the same thing in a year's time, even
-- after the organisation has edited its template five times.
--
-- Top-level columns are what dashboards aggregate on; breakdown holds the
-- per-section and per-pathogen detail that only the report needs.
--
-- percentage is DECIMAL(5,2) — rounded to two places BEFORE banding, so
-- 89.995 becomes 90.00 and lands in Level 4. Banding on the unrounded value
-- would put it in Level 3, and the boundary cases are exactly where a
-- certification dispute happens.
--
-- scoring_version records which implementation produced the numbers, so a
-- future correction to the engine can be identified rather than silently
-- changing history.

CREATE TABLE IF NOT EXISTS assessment_scores (
    assessment_id       BINARY(16) NOT NULL PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    template_id         INT UNSIGNED NOT NULL,
    pathogen_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total_score         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_possible      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    percentage          DECIMAL(5,2) NULL,
    level               TINYINT UNSIGNED NULL,
    breakdown           JSON NULL,
    scoring_version     VARCHAR(20) NOT NULL,
    scored_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scores_org_level (organization_id, level),
    INDEX idx_scores_org_scored (organization_id, scored_at),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- RAW SUBMISSIONS
-- ─────────────────────────────────────────────
--
-- The payload exactly as the device sent it, kept immutably. This is an audit
-- instrument: being able to prove what was submitted, distinct from what the
-- server made of it, is worth the storage.
--
-- Deliberately excluded from bin/housekeeping pruning.

CREATE TABLE IF NOT EXISTS submissions_raw (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    assessment_id       BINARY(16) NOT NULL,
    template_id         INT UNSIGNED NOT NULL,
    payload             JSON NOT NULL,
    app_version         VARCHAR(20) NULL,
    device_id           VARCHAR(100) NULL,
    received_from_ip    VARCHAR(45) NULL,
    received_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submissions_assessment (assessment_id, received_at),
    INDEX idx_submissions_org_received (organization_id, received_at),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- ATTACHMENTS
-- ─────────────────────────────────────────────
--
-- Signatures and photos. Uploaded on a SEPARATE channel from the assessment
-- payload: media dominates upload size, and a failed 20MB transfer on a weak
-- connection must not take a completed assessment down with it. The assessment
-- lands first and media reconciles afterwards, which is why nothing here is
-- required for an assessment to be valid.
--
-- checksum is what makes a retried upload idempotent.

CREATE TABLE IF NOT EXISTS attachments (
    id                  BINARY(16) NOT NULL PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    assessment_id       BINARY(16) NOT NULL,
    kind                ENUM('signature', 'photo', 'document') NOT NULL,
    -- For signatures: 'assessor_1' / 'assessor_2'. For photos: the question
    -- code the evidence belongs to.
    role                VARCHAR(50) NULL,
    question_code       VARCHAR(10) NULL,
    storage_path        VARCHAR(500) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    byte_size           INT UNSIGNED NOT NULL DEFAULT 0,
    checksum            CHAR(64) NULL,
    uploaded_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachments_assessment (assessment_id, kind),
    INDEX idx_attachments_org (organization_id),
    UNIQUE KEY uq_attachments_checksum (assessment_id, checksum),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
