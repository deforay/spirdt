-- ─────────────────────────────────────────────
-- ATTACHMENTS: record who the mark claims to be
-- ─────────────────────────────────────────────
--
-- A signature with no name against it is a squiggle. As first written, the
-- attachments table stored the image and the role and left the name to be
-- worked out later from somewhere else — the assessment's created_by for the
-- assessor, the context JSON for the interviewee. That is wrong for two
-- reasons.
--
-- It does not survive. A user can be renamed, deactivated or corrected after a
-- visit, and the name that appears beside a signature would follow them. What
-- has to be recoverable is the name as it stood at the moment of signing, for
-- the same reason an assessment pins its template version: the record has to
-- stay readable against what was actually in front of the person.
--
-- And for a second assessor there is nowhere to derive it from at all. The
-- system knows who is signed in, and Part A records the interviewee, but a
-- colleague who attended the visit and countersigned appears in neither.
--
-- Nullable because photographs have no signatory, and because the rows written
-- before this column existed genuinely do not have the name — a backfill would
-- have to guess, and a guessed name on a signature is worse than a blank one.

ALTER TABLE attachments
    ADD COLUMN signed_name VARCHAR(200) NULL AFTER role;

-- ─────────────────────────────────────────────
-- ATTACHMENTS: make the idempotency key role-aware
-- ─────────────────────────────────────────────
--
-- The unique key was (assessment_id, checksum), which assumed two people can
-- never produce the same bytes. Freehand, that is true. But a signature slot
-- accepts a single tap, and a tap in the same place on the same device
-- produces a byte-identical PNG — so a second assessor who taps rather than
-- signs would collide with the first.
--
-- The consequence was silent, which is what makes it worth a migration rather
-- than a note. The upload would match the existing row on checksum, return it,
-- and the device would mark its own signature clean and stop retrying. One
-- image on the server, two roles claiming it, and nothing anywhere looking
-- wrong.
--
-- Role joins the key. Retries stay free — the same role sending the same
-- bytes still matches — while two roles keep their own marks.
--
-- NOTE for photographs, which nothing captures yet: role is null for those,
-- and MySQL treats nulls in a unique key as distinct, so this constraint will
-- not catch a duplicate photo. The application check does, provided it tests
-- the column with IS NULL rather than = NULL. See AttachmentService.

ALTER TABLE attachments DROP INDEX uq_attachments_checksum;

ALTER TABLE attachments
    ADD UNIQUE KEY uq_attachments_role_checksum (assessment_id, role, checksum);
