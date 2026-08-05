-- ─────────────────────────────────────────────
-- SUBMISSIONS_RAW: make it an audit record rather than a child row
-- ─────────────────────────────────────────────
--
-- The table exists to prove what a device sent, separately from what the
-- server made of it. As first written it could not do that, for two reasons
-- that only showed up once something tried to write to it.
--
-- 1. A foreign key to assessments meant a payload could only be stored AFTER
--    the assessment it describes existed. The payload most worth keeping is
--    the one the server REJECTED — the malformed sync, the one naming a
--    template that is not published, the one from a device running an old
--    build. None of those produce an assessment row, so none of them could be
--    filed, which left the failures visible only in a log that rotates.
--
-- 2. That key was ON DELETE CASCADE. Deleting an assessment deleted the
--    evidence of what had been submitted for it. For an audit instrument that
--    is precisely backwards: the record has to outlive the thing it records,
--    or it is not evidence.
--
-- Both keys are therefore dropped. assessment_id stays as a plain indexed
-- column — it is what you search by, and it does not need to resolve. The
-- organisation key stays: a submission always belongs to a tenant, and if the
-- tenant is deleted the payload should go with it.
--
-- template_id becomes nullable for the same reason as (1): a payload naming a
-- template that could not be resolved still has to be storable.

ALTER TABLE submissions_raw DROP FOREIGN KEY submissions_raw_ibfk_2;
ALTER TABLE submissions_raw DROP FOREIGN KEY submissions_raw_ibfk_3;

ALTER TABLE submissions_raw MODIFY COLUMN template_id INT UNSIGNED NULL;

-- Kept as a search key now that it is no longer a constraint.
CREATE INDEX idx_submissions_assessment_only ON submissions_raw (assessment_id);

-- Why the request was refused, where it was. Null on a payload that was
-- accepted, so a count of non-null rows is a count of what the devices in the
-- field are getting wrong.
ALTER TABLE submissions_raw
    ADD COLUMN rejected_reason VARCHAR(500) NULL AFTER payload;
