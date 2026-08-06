-- ─────────────────────────────────────────────
-- FACILITIES: who to call
-- ─────────────────────────────────────────────
--
-- The registry knew what a facility is called and where it sits, and nothing
-- about how to reach it. That is the first thing anyone needs: an assessor
-- confirming a visit is expected, and whoever chases a finding that a site has
-- not closed.
--
-- Deliberately on the FACILITY rather than the testing site. A hospital has a
-- switchboard and a laboratory manager; its four benches do not have four
-- phone numbers, and duplicating the same one across them is how three of the
-- four go stale.
--
-- Separate from the interviewee recorded in Part A, which is a fact about one
-- visit — who happened to be there that day — rather than about the facility.
-- The two get conflated and should not be: a contact who has moved on is a
-- registry problem, and an interviewee who has moved on is simply history.
--
-- All nullable. A national list imported from a spreadsheet will have gaps,
-- and refusing the import over a missing phone number would mean not having
-- the list at all.

ALTER TABLE facilities
    ADD COLUMN contact_name  VARCHAR(200) NULL AFTER address,
    ADD COLUMN contact_phone VARCHAR(50)  NULL AFTER contact_name,
    ADD COLUMN contact_email VARCHAR(255) NULL AFTER contact_phone;

-- ─────────────────────────────────────────────
-- FACILITIES: where it actually is
-- ─────────────────────────────────────────────
--
-- Added now because it is two columns now and a migration over a national list
-- later. A geographic unit says which district a facility is administratively
-- in; coordinates say where to drive, and they are what any map of coverage or
-- of audit locations has to key on.
--
-- DECIMAL rather than FLOAT: coordinates are compared and grouped, and binary
-- floating point makes two records of the same place differ in the last place
-- for no reason anybody can see. 10,7 is about a centimetre, far finer than
-- anything a phone reports.

ALTER TABLE facilities
    ADD COLUMN latitude  DECIMAL(10,7) NULL AFTER contact_email,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
