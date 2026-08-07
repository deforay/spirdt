-- ─────────────────────────────────────────────
-- WHAT A ROLE MAY DO, WRITTEN DOWN
-- ─────────────────────────────────────────────
--
-- `role_permissions` has existed since the baseline and has never held a row.
-- Every gate in the application compared the role's NAME instead: a route said
-- "admin or superadmin", and adding a capability meant finding every route that
-- should have included it. Worse, it made the answer to "what can a viewer do?"
-- something you could only get by reading the routing table.
--
-- This fills the table in. Nothing about who can reach what changes today —
-- the grants below are exactly the role lists the routes carried, transcribed.
-- What changes is where the answer lives, and that an organisation can now
-- alter it without a deploy.
--
-- ONE STATEMENT PER PERMISSION, so each reads as "here is a capability, here is
-- who holds it". The role keys are fixed and the display names beside them are
-- not, which is why these match on `key` and never on `name`.
--
-- These grants are a snapshot. App\Auth\Roles carries the same map and applies
-- it to roles created from now on, but nothing reads it at request time — after
-- a role exists, this table is the only authority. An organisation that takes a
-- permission away has made a decision, and no later deploy may quietly reverse
-- it.
--
-- Re-running is safe: INSERT IGNORE against the (role_id, permission_key)
-- primary key. It restores a default that was removed by hand, which is why it
-- belongs in a migration that runs once rather than anywhere on a schedule.

-- File an assessment against a testing site. A viewer reads collected data and
-- does not collect it, and a site_user is staff at the place being assessed.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'assessments.submit' FROM roles WHERE `key` IN ('superadmin', 'admin', 'assessor');

-- Look up places, facilities, testing sites and the assignment plan. Held by
-- the viewer as well, because the dashboard filters by the same hierarchy and a
-- filter nobody can populate is not a filter.
--
-- Deliberately NOT the assessor. The site list a device needs comes from
-- /sites, which already returns every site in the programme and is scoped on
-- its own terms.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'registry.read' FROM roles WHERE `key` IN ('superadmin', 'admin', 'viewer');

-- Add and correct those records, including folding duplicates together.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'registry.write' FROM roles WHERE `key` IN ('superadmin', 'admin');

-- Decide which assessor covers which place.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'assignments.write' FROM roles WHERE `key` IN ('superadmin', 'admin');

-- Read collected assessments and their scores. Separate from registry.read
-- because the registry is a list of laboratories and this is how each one is
-- performing. Somebody may need the first without the second.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'reports.read' FROM roles WHERE `key` IN ('superadmin', 'admin', 'viewer');

-- Create accounts, change roles, reset passwords, deactivate.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'users.manage' FROM roles WHERE `key` IN ('superadmin', 'admin');

-- Add organisations to the programme. The one capability that reaches across
-- tenants, bounded to the holder's own programme by the token.
INSERT IGNORE INTO role_permissions (role_id, permission_key)
    SELECT id, 'organizations.manage' FROM roles WHERE `key` = 'superadmin';
