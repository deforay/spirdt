-- ─────────────────────────────────────────────
-- PHOTOGRAPHS, PER SECTION OF AN AUDIT
-- ─────────────────────────────────────────────
--
-- An assessor works a section, sees what is missing, and agrees with the site
-- what will be done about it — that is where corrective actions are collected,
-- and it is the same moment a photograph belongs to. The empty shelf, the
-- fridge with no temperature log, the wall with no organogram. Up to five per
-- section, each with its own comment, because a photograph nobody captioned is
-- a picture of a shelf that means nothing a year later.
--
-- ON THE AUDIT, NOT ON THE REGISTRY. `testing_sites.photo_path` is one current
-- picture of the bench answering "which bench is this", shared across the
-- programme and edited by administrators. These are evidence of one visit by
-- one organisation: the same bench audited twice carries two separate sets,
-- and they are correctly invisible to the other organisations in the country.
-- That is exactly the boundary `attachments` already sits on.
--
-- THREE COLUMNS, AND WHY THE EXISTING ONES WOULD NOT DO
--
--   section_code — `question_code` is the question a photograph is evidence
--   for, which is a narrower thing that this table already supports and which
--   these are not. A section photograph belongs to the debrief at the end of
--   Section 2, not to question 2.4.
--
--   caption — the assessor's own words about what the image shows. Distinct
--   from a finding, which says what will be DONE; this says what is THERE.
--
--   client_key — the identity of one photograph, minted on the device before
--   it is uploaded. Signatures are identified by (assessment, role, checksum)
--   because there is exactly one per role and a redraw replaces it. Several
--   photographs per section breaks both halves of that: role is not unique,
--   and two photographs of the same shelf a minute apart may be byte-identical
--   from a phone that did not move. Without an identity the device minted, a
--   retried upload would be indistinguishable from a second photograph.

ALTER TABLE attachments
    ADD COLUMN section_code VARCHAR(10) NULL AFTER question_code,
    -- The assessor's words. Long enough for a sentence about what is in shot,
    -- short enough that it cannot quietly become the findings field.
    ADD COLUMN caption      VARCHAR(500) NULL AFTER section_code,
    -- A UUID from the device. Nullable because every row written before this
    -- migration is a signature, which is identified by role instead.
    ADD COLUMN client_key   CHAR(36) NULL AFTER caption;

-- What makes a retry free. The device sends the same key for the same
-- photograph however many times the connection drops, and the second upload
-- updates the caption rather than storing the image again.
--
-- Nullable columns in a unique key are treated as distinct by MySQL, so this
-- constrains photographs without touching the signatures that have no key.
ALTER TABLE attachments
    ADD UNIQUE KEY uq_attachments_client_key (assessment_id, client_key);

-- What the review screen and the report ask for: every photograph of one
-- visit, grouped by the section it belongs to, in the order they were taken.
CREATE INDEX idx_attachments_section ON attachments (assessment_id, section_code, uploaded_at);
