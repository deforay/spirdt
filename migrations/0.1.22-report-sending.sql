-- ─────────────────────────────────────────────
-- SENDING A REPORT TO THE SITE IT IS ABOUT
-- ─────────────────────────────────────────────
--
-- Until now a report could be read by whoever had an account and downloaded by
-- the same people. The laboratory the report is ABOUT has neither, and the way
-- it reached them was somebody exporting a file and attaching it to their own
-- email — which happens, and leaves no record anywhere that it did.
--
-- SEPARATE FROM READING, and that is the point of it being its own key. A
-- viewer whose whole job is these screens can be trusted with what is in them
-- without being able to send a laboratory's score to any address they can
-- type. Reading is reversible in the sense that nothing left the building;
-- sending is not, and no permission granted afterwards can call a message
-- back.
--
-- Given to admin and superadmin. Not to viewer, for the reason above, and not
-- to assessor: an assessor's account exists to collect a visit on a device,
-- and the debrief they give the site happens in the room.
--
-- No schema change. Who sent what, to whom, and when is recorded in audit_log
-- like every other consequential action, and `idx_audit_entity` already indexes
-- it by the assessment the send was about.

INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'reports.send' FROM roles WHERE `key` IN ('superadmin', 'admin');
