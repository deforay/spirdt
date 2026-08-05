-- SPI-RDT Assessment Platform
-- Migration 0.1.1 — Facility and testing-site registry
--
-- One assessment covers ONE TESTING SITE. The User's Guide is explicit that
-- where a facility holds several testing sites, each is an independent
-- assessment unit and gets its own checklist. So testing_sites — not
-- facilities — is what an assessment points at.
--
-- WHY THESE TWO CARRY CLIENT-GENERATED IDs
--   An assessor can arrive at a facility or a testing site that is not in the
--   registry (a newly opened TB clinic, a site the office never listed) and
--   must be able to create it on the spot, offline. That makes both tables
--   device-originating, so both take BINARY(16) UUIDv7 primary keys on the
--   same reasoning as assessments: the client generates the ID, and a retried
--   sync upserts instead of duplicating.
--
--   The cost is duplicates — two assessors will eventually create "Makonya
--   Health Center" independently. That is a data-stewardship problem, not a
--   schema one, so it is handled with `source` (registry vs field) and
--   `merged_into_id` rather than by trying to prevent it with constraints
--   that would only push the failure onto the assessor mid-visit.

-- ─────────────────────────────────────────────
-- FACILITIES
-- ─────────────────────────────────────────────
--
-- facility_type / level / affiliation hold the OPTION KEYS defined in the
-- active template, not free text. All three are Part A customisation points
-- ("levels should be updated to reflect the local context"), so the labels
-- live in the template and only the key is stored here. The paired _other
-- columns capture the "Other (Specify):" free text the checklist allows.

CREATE TABLE IF NOT EXISTS facilities (
    id                  BINARY(16) NOT NULL PRIMARY KEY,
    organization_id     INT UNSIGNED NOT NULL,
    geo_unit_id         INT UNSIGNED NULL,
    name                VARCHAR(255) NOT NULL,
    code                VARCHAR(50) NULL,
    address             TEXT NULL,
    facility_type       VARCHAR(50) NULL,
    facility_type_other VARCHAR(255) NULL,
    level               VARCHAR(50) NULL,
    level_other         VARCHAR(255) NULL,
    affiliation         VARCHAR(50) NULL,
    affiliation_other   VARCHAR(255) NULL,
    -- 'registry' = created by an admin online. 'field' = created offline by an
    -- assessor and not yet reconciled against the registry.
    source              ENUM('registry', 'field') NOT NULL DEFAULT 'registry',
    -- Set when an admin merges this record into a canonical one. Never delete
    -- the loser of a merge: assessments already reference it, and the audit
    -- trail has to survive the clean-up.
    merged_into_id      BINARY(16) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_facilities_org_name (organization_id, name),
    INDEX idx_facilities_org_geo (organization_id, geo_unit_id),
    INDEX idx_facilities_org_source (organization_id, source),
    INDEX idx_facilities_merged (merged_into_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (geo_unit_id) REFERENCES geo_units(id) ON DELETE SET NULL,
    FOREIGN KEY (merged_into_id) REFERENCES facilities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- TESTING SITES
-- ─────────────────────────────────────────────
--
-- The unit of assessment. name is the site within the facility — "TB clinic",
-- "OPD", "ANC", "Maternity" — per the User's Guide examples.

CREATE TABLE IF NOT EXISTS testing_sites (
    id                      BINARY(16) NOT NULL PRIMARY KEY,
    organization_id         INT UNSIGNED NOT NULL,
    facility_id             BINARY(16) NOT NULL,
    name                    VARCHAR(255) NOT NULL,
    location_description    VARCHAR(255) NULL,
    source                  ENUM('registry', 'field') NOT NULL DEFAULT 'registry',
    merged_into_id          BINARY(16) NULL,
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    created_by              INT UNSIGNED NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_testing_sites_org_facility (organization_id, facility_id),
    INDEX idx_testing_sites_org_source (organization_id, source),
    INDEX idx_testing_sites_merged (merged_into_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE,
    FOREIGN KEY (merged_into_id) REFERENCES testing_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- BACKFILL: users.facility_id
-- ─────────────────────────────────────────────
--
-- 0.1.0 created users.facility_id as INT UNSIGNED and deferred its foreign
-- key until facilities existed. Facilities have since become BINARY(16)
-- (see the header), so the column type has to follow before the FK can be
-- added. Safe as a plain MODIFY: the column has never been populated —
-- site_user is the only role that sets it and no users exist yet.

ALTER TABLE users MODIFY COLUMN facility_id BINARY(16) NULL;

ALTER TABLE users ADD CONSTRAINT fk_users_facility
    FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE SET NULL;

-- ─────────────────────────────────────────────
-- ORGANISATION SETTING: assessment review
-- ─────────────────────────────────────────────
--
-- Whether a submitted assessment needs a supervisor review before it is
-- final and its score counts toward certification. Per-organisation because
-- practice differs: the User's Guide prefers two assessors for objectivity,
-- but allows a single assessor where resources are limited.
--
-- When 0, the workflow is draft → submitted → delivered.
-- When 1, it is    draft → submitted → reviewed → finalised → delivered.
-- Consumers of assessment status must handle both; see the status enum on
-- assessments in 0.1.3.

ALTER TABLE organizations
    ADD COLUMN requires_assessment_review TINYINT(1) NOT NULL DEFAULT 0 AFTER default_locale;
