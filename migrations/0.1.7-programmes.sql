-- ─────────────────────────────────────────────
-- PROGRAMMES: a level above organisations, so two of them can audit one lab
-- ─────────────────────────────────────────────
--
-- The requirement that forced this: within one country, two organisations may
-- both be running audits — sometimes on different labs, sometimes on the same
-- ones, independently — and somebody needs to see the patterns across both.
--
-- That could not be done. facilities, testing_sites and geo_units all carried
-- organization_id as their isolation boundary, so two organisations auditing
-- "Kitwe Central Hospital / TB clinic" produced two facility rows, two site
-- rows, two different UUIDs, and two separate "Lusaka Province" trees. Nothing
-- could join them, and comparing by district did not line up either.
--
-- A programme is what those organisations share: in practice a national
-- programme, usually the ministry. Organisations belong to one.
--
--   programme  (Zambia)
--   ├── organisation A   national reference lab team
--   └── organisation B   implementing partner
--
-- WHAT MOVES AND WHAT DOES NOT
--
--   The REGISTRY moves up. One "Kitwe Central Hospital", one "Lusaka
--   Province", both organisations referencing them. This is what these
--   programmes actually have in real life — a national site list — and it is
--   the only way a cross-organisation comparison can be certain it is talking
--   about the same lab rather than two similar strings.
--
--   ASSESSMENTS DO NOT MOVE. Answers, findings, scores and attachments stay
--   organisation-scoped exactly as they were. The registry is shared; the
--   audit data is not. That is the whole security property of this change and
--   it is what the tests assert.
--
-- BACKFILL IS ONE PROGRAMME PER ORGANISATION, DELIBERATELY
--
-- Grouping the existing organisations by country_code would have been "more
-- correct" and is the wrong thing to do: it would silently expose one
-- organisation's site list to another because they happen to share a country,
-- with nobody having asked for it. Every organisation therefore gets its own
-- programme and today's isolation is preserved exactly. Putting two
-- organisations into one programme is then a deliberate administrative act.

CREATE TABLE IF NOT EXISTS programmes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    country_code    CHAR(2) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_programmes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organizations ADD COLUMN programme_id INT UNSIGNED NULL AFTER id;

-- One per existing organisation. See the note above on why not by country.
INSERT INTO programmes (code, name, country_code)
SELECT o.code, o.name, o.country_code
  FROM organizations o
 WHERE NOT EXISTS (SELECT 1 FROM programmes p WHERE p.code = o.code);

UPDATE organizations o
  JOIN programmes p ON p.code = o.code
   SET o.programme_id = p.id
 WHERE o.programme_id IS NULL;

ALTER TABLE organizations
    ADD CONSTRAINT fk_organizations_programme
    FOREIGN KEY (programme_id) REFERENCES programmes(id);

-- ─────────────────────────────────────────────
-- THE REGISTRY MOVES TO THE PROGRAMME
-- ─────────────────────────────────────────────
--
-- organization_id is kept and becomes NULLABLE, with a new meaning: the
-- organisation this record ORIGINATED from, not the one allowed to see it.
-- Null means it was entered into the registry centrally. Set means an assessor
-- created it in the field before it existed centrally, which is exactly what
-- `source = 'field'` already records and what a reconciler needs in order to
-- ask the right people about a duplicate.
--
-- Not dropped, for two reasons. The provenance is genuinely useful, and
-- dropping it would mean dropping foreign keys whose names were generated
-- implicitly — the kind of migration that works on the machine it was written
-- on. Read the scope from programme_id and nothing else.

ALTER TABLE geo_units      ADD COLUMN programme_id INT UNSIGNED NULL AFTER id;
ALTER TABLE facilities     ADD COLUMN programme_id INT UNSIGNED NULL AFTER id;
ALTER TABLE testing_sites  ADD COLUMN programme_id INT UNSIGNED NULL AFTER id;

UPDATE geo_units g     JOIN organizations o ON o.id = g.organization_id SET g.programme_id = o.programme_id WHERE g.programme_id IS NULL;
UPDATE facilities f    JOIN organizations o ON o.id = f.organization_id SET f.programme_id = o.programme_id WHERE f.programme_id IS NULL;
UPDATE testing_sites t JOIN organizations o ON o.id = t.organization_id SET t.programme_id = o.programme_id WHERE t.programme_id IS NULL;

ALTER TABLE geo_units      MODIFY COLUMN programme_id INT UNSIGNED NOT NULL;
ALTER TABLE facilities     MODIFY COLUMN programme_id INT UNSIGNED NOT NULL;
ALTER TABLE testing_sites  MODIFY COLUMN programme_id INT UNSIGNED NOT NULL;

ALTER TABLE geo_units      MODIFY COLUMN organization_id INT UNSIGNED NULL;
ALTER TABLE facilities     MODIFY COLUMN organization_id INT UNSIGNED NULL;
ALTER TABLE testing_sites  MODIFY COLUMN organization_id INT UNSIGNED NULL;

ALTER TABLE geo_units      ADD CONSTRAINT fk_geo_units_programme     FOREIGN KEY (programme_id) REFERENCES programmes(id);
ALTER TABLE facilities     ADD CONSTRAINT fk_facilities_programme    FOREIGN KEY (programme_id) REFERENCES programmes(id);
ALTER TABLE testing_sites  ADD CONSTRAINT fk_testing_sites_programme FOREIGN KEY (programme_id) REFERENCES programmes(id);

-- The indexes the old scope relied on all led with organization_id, so every
-- registry lookup would now be a table scan without these.
CREATE INDEX idx_geo_units_programme_parent     ON geo_units (programme_id, parent_id);
CREATE INDEX idx_geo_units_programme_level      ON geo_units (programme_id, level);
CREATE INDEX idx_facilities_programme_name      ON facilities (programme_id, name);
CREATE INDEX idx_facilities_programme_geo       ON facilities (programme_id, geo_unit_id);
CREATE INDEX idx_testing_sites_programme_name   ON testing_sites (programme_id, name);
