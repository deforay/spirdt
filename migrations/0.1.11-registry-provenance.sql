-- ─────────────────────────────────────────────
-- REGISTRY: stop an organisation taking the shared list with it
-- ─────────────────────────────────────────────
--
-- 0.1.7 moved the registry to the programme and redefined organization_id on
-- those three tables as PROVENANCE — who first entered a row, not who may see
-- it. What it did not do is revisit the foreign key underneath, which still
-- said ON DELETE CASCADE from the days when that column WAS the scope.
--
-- The consequence only appears once a programme has two organisations in it,
-- which is the case the layer exists for. Organisation A types in a facility;
-- organisation B, sharing the programme, starts assessing it. Deleting A then
-- deletes that facility and its testing sites out from under B — or, where B
-- already has assessments against them, fails the delete outright on a
-- restrictive reference and leaves A undeletable for reasons nobody can see.
--
-- SET NULL is what provenance means: the organisation that entered it is gone,
-- so nobody entered it as far as the record is concerned, and the row itself
-- is untouched because it never belonged to them in the first place.
--
-- The constraint names are the ones MySQL generated in 0.1.1, in creation
-- order. Dropping by generated name is what 0.1.5 already does; the runner
-- skips a DROP whose constraint is absent, so a re-run is safe.

ALTER TABLE facilities    DROP FOREIGN KEY facilities_ibfk_1;
ALTER TABLE geo_units     DROP FOREIGN KEY geo_units_ibfk_1;
ALTER TABLE testing_sites DROP FOREIGN KEY testing_sites_ibfk_1;

ALTER TABLE facilities
    ADD CONSTRAINT fk_facilities_origin
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

ALTER TABLE geo_units
    ADD CONSTRAINT fk_geo_units_origin
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

ALTER TABLE testing_sites
    ADD CONSTRAINT fk_testing_sites_origin
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- ─────────────────────────────────────────────
-- ORGANISATIONS: every one belongs to a programme
-- ─────────────────────────────────────────────
--
-- 0.1.7 added programme_id, backfilled it and added the foreign key, but left
-- the column nullable — which made "every organisation has a programme" a fact
-- about the data at that moment rather than a rule.
--
-- Any path creating an organisation without one produces a tenant that signs
-- in and then cannot work: the token carries prg = null, and every registry
-- read throws rather than returning rows. It fails closed, which is the right
-- direction, but the account is simply broken and nothing says why.
--
-- The backfill is repeated defensively. It is a no-op on a database that ran
-- 0.1.7 cleanly, and it is the difference between this migration succeeding
-- and failing on one where an organisation was created in between.

INSERT INTO programmes (code, name, country_code)
SELECT o.code, o.name, o.country_code
  FROM organizations o
 WHERE o.programme_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM programmes p WHERE p.code = o.code);

UPDATE organizations o
  JOIN programmes p ON p.code = o.code
   SET o.programme_id = p.id
 WHERE o.programme_id IS NULL;

-- MySQL refuses to change a column's nullability while a foreign key sits on
-- it (error 1832), so the constraint comes off and goes straight back on. It
-- is the same constraint either way; only the column underneath changes.

ALTER TABLE organizations DROP FOREIGN KEY fk_organizations_programme;

ALTER TABLE organizations MODIFY COLUMN programme_id INT UNSIGNED NOT NULL;

ALTER TABLE organizations
    ADD CONSTRAINT fk_organizations_programme
    FOREIGN KEY (programme_id) REFERENCES programmes(id);
