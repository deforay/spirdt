-- ─────────────────────────────────────────────
-- FINDINGS: which side minted the id
-- ─────────────────────────────────────────────
--
-- Findings were keyed server-side on (assessment, question, pathogen) and
-- given an id the server minted. They are keyed on the device's own id now,
-- because one question may carry several findings — a single No can hide the
-- SOP being missing AND the staff being untrained on it — and a natural key
-- that is no longer unique cannot identify a row.
--
-- The device's version 5 upgrade re-keys the findings it is holding, and it
-- has to: the server refuses an id that is not a UUID, so a row carried
-- through with its old composite key is dropped on every sync, silently, with
-- the gap still on screen. What the device cannot do is reproduce the id the
-- server minted, because it was never told it. So the first sync after that
-- upgrade offers a finding the server already has, under an id it has never
-- seen, and the server stores a second copy of it.
--
-- Duplicates cost more here than anywhere else in the schema. Findings become
-- a site's action list, and the same gap listed three times is three things to
-- chase.
--
-- This column is what lets the sync tell the two cases apart. A row the SERVER
-- keyed can be adopted — re-keyed to the id the device now uses — because the
-- old key was unique, so there is at most one candidate for a given question.
-- A row the DEVICE keyed must never be adopted, or the second finding raised
-- on a question would land on top of the first.
--
-- The window this closes is currently empty: no database holds a finding yet.
-- It stops being empty at the first deployment, and after that the damage is
-- unattributable — two rows with the same gap and no way to tell which came
-- from the duplicate.
--
-- Written as an add-then-change-the-default rather than an add-and-backfill.
-- Every row existing at this moment was keyed by the old server, and every row
-- inserted from here on is keyed by a device, which is exactly what the two
-- statements below say. It also survives a re-run: bin/migrate skips an ADD
-- COLUMN whose column is already there, whereas an UPDATE would have run again
-- and quietly marked every device-keyed finding adoptable. MODIFY only
-- restates the column, so it is a no-op the second time too.
--
-- MODIFY rather than the shorter ALTER COLUMN id_origin SET DEFAULT. bin/migrate
-- parses each statement and rebuilds it before running it, and the parser drops
-- everything after SET in that form — it fails loudly rather than quietly, but
-- it fails.
--
-- No index. The adoption lookup is scoped to one assessment, which already has
-- idx_findings_assessment, and a visit carries findings in the tens.

ALTER TABLE findings
    ADD COLUMN id_origin ENUM('server', 'device') NOT NULL DEFAULT 'server' AFTER id;

ALTER TABLE findings
    MODIFY COLUMN id_origin ENUM('server', 'device') NOT NULL DEFAULT 'device';
