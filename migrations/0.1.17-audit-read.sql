-- ─────────────────────────────────────────────
-- READING THE AUDIT TRAIL
-- ─────────────────────────────────────────────
--
-- `audit_log` has existed since 0.1.4 and held eight rows, every one of them
-- written by bin/recover-access. The application meanwhile grew a screen for
-- creating accounts, one for resetting other people's passwords, and finally
-- one for changing what a role may do — which can be used to obtain every
-- permission in the system. Something decided who MAY make those changes.
-- Nothing recorded that anybody HAD.
--
-- The writers land with this release. This is the permission for the screen
-- that reads them back.
--
-- SEPARATE FROM THE ACTIONS IT RECORDS, and that is the point of it being its
-- own key rather than part of users.manage. Somebody who may reset passwords is
-- not automatically somebody who may read the history of everybody else's. An
-- organisation may also want the reverse: a compliance reader who changes
-- nothing and sees everything.
--
-- Given to admin and superadmin because both already administer the
-- organisation, and an administrator who cannot see the trail of their own
-- organisation's changes has to ask somebody else what happened.

INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'audit.read' FROM roles WHERE `key` IN ('superadmin', 'admin');
