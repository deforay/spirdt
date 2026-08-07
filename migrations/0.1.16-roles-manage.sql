-- ─────────────────────────────────────────────
-- THE RIGHT TO CHANGE WHAT A ROLE MAY DO
-- ─────────────────────────────────────────────
--
-- 0.1.15 moved the gate from role names to permissions and left one thing out:
-- a way to change a grant without SQL. This is the permission that screen
-- requires.
--
-- Deliberately not folded into `users.manage`. That one is the right to decide
-- WHO holds a role. This is the right to decide what holding it MEANS, and it
-- is the only permission that can be used to obtain the others — somebody who
-- can edit grants can give themselves anything, so it has to be possible to
-- hand out the first without the second.
--
-- Given to admin as well as superadmin, because an organisation administrator
-- has full control within their organisation and this is part of running one.
-- The escalation that implies is bounded in RoleAdminService rather than here:
-- nobody may grant a permission they do not themselves hold, nobody may edit a
-- role that outranks their own, and nobody may take this permission off their
-- own role.

INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'roles.manage' FROM roles WHERE `key` IN ('superadmin', 'admin');
