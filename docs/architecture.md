# Architecture

## Stack

| Layer | Choice |
|---|---|
| Backend | Slim 4, PHP 8.4 |
| Database | MySQL 8 |
| Frontend | Vue 3 + Vite + TypeScript *(not yet scaffolded)* |
| Assessor app | Offline-first PWA *(not yet scaffolded)* |
| Runtime | Docker Compose, or native PHP + MySQL |
| Backups | `amitdugar/db-tools` |

The API is the product; the UIs are clients. Every capability becomes an endpoint before it becomes a screen.

## Multi-tenancy

Some installations serve a single organisation; others host several. **Both run the same schema and the same code path.**

Tenancy is **shared-schema**: every tenant-scoped table carries `organization_id`, and single-tenant installations simply have one `organizations` row. A `MULTI_TENANT` environment flag controls *UI only* — whether organisation management is visible. It never gates the data model or the scoping logic, because two code paths is how isolation bugs get in.

Database-per-tenant was considered and rejected. It would tax every installation with N migration runs to serve the minority case, and the genuinely hard requirement — a ministry of health requiring data stays in-country — is solved by separate installations, not separate databases.

### Tenant resolution

Tenant is resolved **once, in middleware, from the authenticated user**. Never from a path segment, query parameter or request body. Accepting a client-supplied tenant identifier is the classic cross-tenant IDOR and is forbidden.

A user belongs to exactly one organisation. Email is unique **per organisation**, not globally — the same person may legitimately exist in two organisations on a shared installation, and global uniqueness would leak the existence of accounts across tenants.

### Making leakage structurally impossible

Discipline is not a control, so the isolation is structural:

1. A global model scope applied automatically to every tenant-scoped model. Hand-written `where('organization_id', …)` in application code is a smell — if it is being written, the scope is not wired up.
2. A CI code-guard that fails the build when a tenant-scoped model is queried outside the scope, or when a migration adds a tenant-scoped table without `organization_id` plus an index.
3. Composite indexes lead with `organization_id` — correct for performance, and it makes the scoped access path the natural one.
4. A standing cross-tenant isolation test that creates two organisations and table-drives over the full route list, asserting every endpoint returns 404/403 for cross-org access.
5. IDs are unguessable (UUIDv7), but that is never treated as the control. Scope enforcement is the control; opaque IDs are defence in depth.

### Roles

| Role | Scope | Can do |
|---|---|---|
| Platform admin | Installation | Create organisations and their first superadmin, suspend an organisation. **No access to assessment data** |
| Organisation superadmin | One org | Full control within their organisation |
| Admin | One org | Day-to-day administration |
| Assessor | Org + geographic scope | Conduct assessments |
| Viewer | Org + geographic scope | Read-only dashboards and reports |
| Site user | Org + their facility | View and close findings assigned to them |

Two hard rules: an organisation superadmin cannot escalate to platform admin, and cannot see another organisation.

Role alone is insufficient — a user has an organisation **and** a geographic scope. A district viewer sees one district of one organisation.

Platform admins live in a **separate table** from users, not as a flag. That makes "platform admin cannot read assessment data" structural rather than a permission check someone can get wrong: they hold no `organization_id`, so the tenant scope has nothing to resolve. It also keeps `users.organization_id` `NOT NULL` — the one column the whole isolation model rests on.

### Permissions

**Routes gate on permissions, not on role names.** Each role holds a set of keys in `role_permissions`, and every route group requires one of them. The role table above describes what those grants add up to by default. It is not what the code compares against, and `/admin/roles` changes it.

| Permission | Allows |
|---|---|
| `assessments.submit` | File an assessment against a testing site |
| `registry.read` | Look up places, facilities, testing sites, and who covers them |
| `registry.write` | Add and correct those records, including merging duplicates |
| `assignments.write` | Decide which assessor covers which place |
| `reports.read` | Read collected assessments and their scores |
| `users.manage` | Create accounts, change roles, reset passwords, deactivate |
| `roles.manage` | Change what a role may do |
| `audit.read` | Read the trail of who did what |
| `organizations.manage` | Add organisations to the programme |

The difference matters when an organisation wants something the five roles do not express. Naming roles in routes makes a new capability a new role, and every route that should have included it has to be found and edited. Naming the capability moves the grant and leaves the routes alone.

Grants are read from the database on every authenticated request, alongside the account's active flag and its role. A permission withdrawn is withdrawn on the next request, not when the access token expires.

A role holding no rows reaches nothing. There is no fallback to the defaults, so revoking the last permission means what it says rather than restoring every permission the role ever had.

The sign-in response carries the list so the management app can hide a link it would only be refused on. That is a description of what will happen, never the thing that decides it.

`roles.manage` is the one permission that can be used to obtain the others, so editing grants is bounded by three rules:

1. Nobody may grant a permission they do not hold. Permissions in an organisation can be redistributed, never enlarged from below.
2. Nobody may edit a role that outranks their own. This is the rule user administration already applies to people.
3. Nobody may remove `roles.manage` from their own role. Every other removal is reversible by the person who made it. That one is not.

## Offline and sync

An assessment is a **long-lived, single-owner document** — one assessor, one site, one visit. There is no concurrent editing in the real world, which means no CRDTs, no operational transform, and no field-level merge.

- **Down-sync**: templates, facility and testing-site lists, prior findings. Read-mostly and versioned.
- **Up-sync**: the whole assessment as one payload, upserted on a client-generated ID so a retry is idempotent rather than duplicating.
- **Media** — signatures and photos — uploads on a **separate channel**. Media dominates payload size, and a failed 20 MB transfer on a weak connection must not take a completed assessment with it. The assessment lands first and media reconciles afterwards.

Two assessors per visit is the norm. One device is the scribe and holds the record; the second assessor countersigns. The server warns, but does not block, on a likely duplicate for the same site and date — a repeat visit is legitimate, and blocking mid-visit is the wrong response to a data-stewardship question.

### Client-side rules

These are not negotiable, because the failure mode is an assessor losing an hour of work after leaving the site:

1. Authentication expiry never destroys local drafts. A token expiring offline must not trigger a redirect-and-clear.
2. Every answer change autosaves, not on section navigation.
3. Service-worker updates prompt rather than auto-apply, and the prompt is suppressed entirely while an assessment is in progress with unsynced data. A silent update can swap app code underneath a database written by the previous version.
4. iOS requires install-to-home-screen for durable storage, and has no Background Sync API, so sync is foreground-triggered.
5. All 160+ inputs never render at once. One section, or one question, per screen — older tablets will not cope, and it matches how the work actually happens.
6. Local storage is namespaced by organisation and user. Shared tablets are normal in these settings; a second assessor must not inherit the first one's cached sites or drafts.

## Request handling

Middleware, outermost first: security headers, JSON body parsing, CORS, routing.

Security headers are owned solely by `SecurityHeadersMiddleware`. nginx deliberately sets none — `public/` contains only the front controller, so every response originates in PHP, and setting them in both places emitted each header twice.

Every failure leaves as the same JSON envelope, whatever threw:

```json
{"error":{"status":404,"message":"Not found.","reference":"6bc294b89b2d"}}
```

Detail is exposed only when `APP_DEBUG` is on. In production a 5xx returns the reference ID and the real message goes to the log under the same request UID.

Boot fails fast on a misconfigured deployment — `APP_DEBUG` true under `APP_ENV=production`, or an unset `JWT_SECRET` — rather than serving traffic insecurely.
