-- ─────────────────────────────────────────────
-- WHICH ROUND THIS AUDIT BELONGS TO
-- ─────────────────────────────────────────────
--
-- Audits are run in rounds: a programme assesses every site it covers, acts on
-- what it found, and comes back. Until now the only thing separating one pass
-- from the next was the date, and a date does not answer the question anybody
-- actually asks — "how did round 2 go" — because rounds do not fall inside
-- tidy calendar boundaries. A round runs until the last site is done, which on
-- a national programme is months, and two rounds overlap at the edges while
-- the stragglers of one are still being finished during the start of the next.
--
-- TEXT, NOT A NUMBER, and this is the whole reason the column exists rather
-- than an integer being added to the end of the table. Rounds are usually
-- numbered, and the first one is very often not: it is the baseline, the one
-- everything after is measured against, and programmes name it accordingly.
-- Others carry a year, a phase, or a funder's own label. Storing 1, 2, 3 and
-- forcing "Baseline" to be round 0 would be the tool telling a ministry what
-- to call its own work, and the first thing that happens is somebody types
-- "Baseline" into a comment field instead.
--
-- Sorting is therefore lexical and that is accepted rather than worked around.
-- A programme that wants its rounds to sort will number them; one that mixes
-- words and numbers has already said the order is not the point.
--
-- NULLABLE, and every row that exists today is null. This ships to an
-- installation with a year of audits already in it, and inventing a round for
-- them would be fabricating a fact about work that was done before anybody was
-- asked to record it. Nothing gates on it: an audit with no round recorded is
-- an audit, and a device in a laboratory with no signal must never be stopped
-- by a metadata field.
--
-- 30 characters. Long enough for "Baseline", "Round 3 (2026)" or "Phase II";
-- short enough that it cannot quietly become the notes field.

ALTER TABLE assessments
    ADD COLUMN audit_round VARCHAR(30) NULL AFTER assessed_on;

-- What the report list asks: the audits of one organisation, newest first,
-- optionally narrowed to a round. The round goes last because it is a filter
-- applied inside a scope, never the thing scanned first.
CREATE INDEX idx_assessments_round ON assessments (organization_id, audit_round);
