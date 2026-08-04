# Architecture

## Stack

| Layer | Choice |
|---|---|
| Backend | Slim 4, PHP 8.4 |
| Database | MySQL 8 |
| Frontend | Vue 3 + Vite + TypeScript *(not yet scaffolded)* |
| Auditor app | Offline-first PWA *(not yet scaffolded)* |
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

Discipline is not a control:

1. **A global model scope** applied automatically to every tenant-scoped model. Hand-written `where('organization_id', …)` in application code is a smell — if you are writing it, the scope is not wired up.
2. **A CI code-guard** that fails the build when a tenant-scoped model is queried outside the scope, or when a migration adds a tenant-scoped table without `organization_id` plus an index.
3. **Composite indexes lead with `organization_id`** — correct for performance, and it makes the scoped access path the natural one.
4. **A standing cross-tenant isolation test** that creates two organisations and table-drives over the full route list, asserting every endpoint returns 404/403 for cross-org access.
5. IDs are unguessable (UUIDv7), but that is **never** treated as the control. Scope enforcement is the control; opaque IDs are defence in depth.

### Roles

| Role | Scope | Can do |
|---|---|---|
| Platform admin | Installation | Create organisations and their first superadmin, suspend an organisation. **No access to assessment data** |
| Organisation superadmin | One org | Full control within their organisation |
| Admin | One org | Day-to-day administration |
| Auditor | Org + geographic scope | Conduct assessments |
| Viewer | Org + geographic scope | Read-only dashboards and reports |
| Site user | Org + their facility | View and close findings assigned to them |

Two hard rules: an organisation superadmin cannot escalate to platform admin, and cannot see another organisation.

Role alone is insufficient — a user has an organisation **and** a geographic scope. A district viewer sees one district of one organisation.

Platform admins live in a **separate table** from users, not as a flag. That makes "platform admin cannot read assessment data" structural rather than a permission check someone can get wrong: they hold no `organization_id`, so the tenant scope has nothing to resolve. It also keeps `users.organization_id` `NOT NULL` — the one column the whole isolation model rests on.

## Offline and sync

An assessment is a **long-lived, single-owner document** — one auditor, one site, one visit. There is no concurrent editing in the real world, which means no CRDTs, no operational transform, and no field-level merge.

- **Down-sync**: templates, facility and testing-site lists, prior findings. Read-mostly and versioned.
- **Up-sync**: the whole assessment as one payload, upserted on a client-generated ID so a retry is idempotent rather than duplicating.
- **Media** — signatures and photos — uploads on a **separate channel**. Media dominates payload size, and a failed 20 MB transfer on a weak connection must not take a completed assessment with it. The assessment lands first and media reconciles afterwards.

Two assessors per visit is the norm. One device is the scribe and holds the record; the second assessor countersigns. The server warns, but does not block, on a likely duplicate for the same site and date — a repeat visit is legitimate, and blocking mid-visit is the wrong response to a data-stewardship question.

### Client-side rules

These are not negotiable, because the failure mode is an auditor losing an hour of work after leaving the site:

1. **Authentication expiry must never destroy local drafts.** A token expiring offline must not trigger a redirect-and-clear.
2. **Autosave on every answer change**, not on section navigation.
3. **Prompt for service-worker updates, never auto-apply** — and suppress the prompt entirely while an assessment is in progress with unsynced data. A silent update can swap app code underneath a database written by the previous version.
4. **iOS requires install-to-home-screen** for durable storage, and has no Background Sync API, so sync is foreground-triggered.
5. **Never render all 160+ inputs at once.** One section, or one question, per screen — older tablets will not cope, and it matches how the work actually happens.
6. **Namespace local storage by organisation and user.** Shared tablets are normal in these settings; a second auditor must not inherit the first one's cached sites or drafts.

## Request handling

Middleware, outermost first: security headers, JSON body parsing, CORS, routing.

Security headers are owned solely by `SecurityHeadersMiddleware`. nginx deliberately sets none — `public/` contains only the front controller, so every response originates in PHP, and setting them in both places emitted each header twice.

Every failure leaves as the same JSON envelope, whatever threw:

```json
{"error":{"status":404,"message":"Not found.","reference":"6bc294b89b2d"}}
```

Detail is exposed only when `APP_DEBUG` is on. In production a 5xx returns the reference ID and the real message goes to the log under the same request UID.

Boot fails fast on a misconfigured deployment — `APP_DEBUG` true under `APP_ENV=production`, or an unset `JWT_SECRET` — rather than serving traffic insecurely.
