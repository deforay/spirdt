-- SPI-RDT Assessment Platform
-- Migration 0.1.2 — Question catalog and versioned templates
--
-- WHY THE TEMPLATE IS A JSON DOCUMENT, NOT NORMALISED ROWS
--   A template is read whole, written rarely, and immutable once published.
--   Normalising sections and questions into tables would mean copying several
--   hundred rows every time a country tweaks one label, and would make
--   "what exactly did this assessment answer?" an archaeology exercise.
--   One row with a JSON definition and a version number is far cleaner, and
--   it decouples the template's internal shape from the schema — the shape
--   can evolve without a migration.
--
--   The cost is that you cannot query across versions by question. That is
--   what question_catalog solves.

-- ─────────────────────────────────────────────
-- QUESTION CATALOG
-- ─────────────────────────────────────────────
--
-- SHARED, deliberately not tenant-scoped: these codes ARE the instrument.
-- Every organisation's template, however customised, answers the same
-- canonical 1.1 / 4.23, which is what keeps scores comparable between
-- countries and what dashboards and Excel exports key on.
--
-- Exports and reporting must join on `code`, NEVER on row position — once
-- organisations customise templates, position-based output silently
-- misaligns.
--
-- scope is what makes Section 4 work: 'pathogen' questions are answered once
-- per pathogen instance, everything else once per assessment.

CREATE TABLE IF NOT EXISTS question_catalog (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(10) NOT NULL,
    section_number  TINYINT UNSIGNED NOT NULL,
    sequence        SMALLINT UNSIGNED NOT NULL,
    scope           ENUM('assessment', 'pathogen') NOT NULL DEFAULT 'assessment',
    canonical_text  TEXT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_question_catalog_code (code),
    INDEX idx_question_catalog_section (section_number, sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- TEMPLATES
-- ─────────────────────────────────────────────
--
-- organization_id NULL marks the PLATFORM-OWNED BASE TEMPLATE — the canonical
-- SPI-RDT instrument. Organisations fork it into their own versions; core
-- question codes stay stable while labels, translations, na_allowed flags and
-- band thresholds become theirs to change.
--
-- org_key exists only to make the uniqueness constraint work. MySQL treats
-- NULLs as distinct in a UNIQUE index, so UNIQUE(organization_id, code,
-- version) would happily allow two global templates with the same code and
-- version. Collapsing NULL to 0 in a stored generated column fixes that while
-- keeping a real foreign key on organization_id.
--
-- IMMUTABILITY (enforced in application code, not here):
--   - publishing freezes a version; editing copy-on-writes to v(n+1)
--   - a template with submitted assessments against it cannot be edited at
--     all, only forked
-- These are not DB constraints because the rule is about workflow state, and
-- a trigger that silently blocks a write is far harder to debug than a
-- service that refuses with a reason.

CREATE TABLE IF NOT EXISTS templates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id     INT UNSIGNED NULL,
    org_key             INT UNSIGNED GENERATED ALWAYS AS (IFNULL(organization_id, 0)) STORED,
    parent_template_id  INT UNSIGNED NULL,
    code                VARCHAR(50) NOT NULL,
    version             VARCHAR(20) NOT NULL,
    title               VARCHAR(255) NOT NULL,
    -- The full instrument: sections, questions, guidance, Part A field
    -- definitions, option lists, point values and level bands.
    definition          JSON NOT NULL,
    status              ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at        DATETIME NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_templates_org_code_version (org_key, code, version),
    INDEX idx_templates_org_status (org_key, status),
    INDEX idx_templates_parent (parent_template_id),
    -- RESTRICT, not CASCADE, and not by choice: MySQL forbids a CASCADE /
    -- SET NULL referential action on a column that a stored generated column
    -- depends on, and org_key is generated from organization_id. Attempting
    -- it fails with errno 1215.
    --
    -- The semantics are defensible anyway. An organisation holding published
    -- templates — and therefore assessments scored against them — should not
    -- evaporate because of a cascade. Deactivate it, or remove its templates
    -- deliberately first.
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (parent_template_id) REFERENCES templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
