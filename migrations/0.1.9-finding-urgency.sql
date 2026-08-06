-- ─────────────────────────────────────────────
-- FINDINGS: how urgent, as well as whose job
-- ─────────────────────────────────────────────
--
-- responsibility_level already records WHO acts — site through national. This
-- records WHEN, and the two are orthogonal: a national-level immediate action
-- is a coherent and important thing to be able to say.
--
-- Not derivable from due_date, which is why it is a column rather than a rule.
-- In a quality-audit SOP "immediate" is not "soon" — it means correct before
-- the assessor leaves, or stop testing until it is fixed. A site running an
-- expired lot needs "do not test until resolved", and a date cannot express
-- that: "due today" and "suspend testing" are different instructions.
--
-- It also changes the debrief. Two tiers means the site leaves with a short
-- list of things to do now, separate from a longer improvement plan, and it
-- aggregates — "47 immediate actions open nationally" — in a way dates do not.
--
-- NULLABLE, and no default. Existing rows genuinely do not have this, and
-- defaulting them to follow_up would invent a judgement the assessor never
-- made. New findings are asked for it on screen; a blank means nobody said.
--
-- Per FINDING rather than per section. The predecessor form listed corrective
-- actions in two blocks at the end of each section, which is a layout, not a
-- data model: it cannot say that one gap in a section is urgent and another is
-- not.

ALTER TABLE findings
    ADD COLUMN urgency ENUM('immediate', 'follow_up') NULL AFTER responsibility_level;

-- What an "immediate actions outstanding" view keys on, at any level of the
-- hierarchy. status leads because every such view is scoped to open work
-- first, and urgency only narrows it.
CREATE INDEX idx_findings_org_urgency ON findings (organization_id, status, urgency);
