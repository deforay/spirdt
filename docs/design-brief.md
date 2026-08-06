# SPI-RDT Assessment Platform — Design Brief

## 1. What we're building

A web platform for conducting SPI-RDT site assessments — *Stepwise Process for Improving the Quality of Rapid Diagnostic Testing*. This is a WHO/MoH-style quality audit instrument used to assess point-of-care (POC) rapid diagnostic testing sites in decentralised health settings, scoring them toward a national certification level.

It replaces a paper/Word checklist and supersedes an older ODK-based tool (SPI-RRT).

Three surfaces:

1. **Assessor PWA** — offline-first. Assessors visit testing sites (labs, clinics, health centres) with unreliable connectivity, complete a 59-question checklist, score the site, capture gaps and signatures, then sync when back in coverage.
2. **Admin area** — online-only. User/role management, facility and site registry, assessment campaigns, questionnaire template management.
3. **Reporting** — Excel exports and a basic dashboard.

**Repo:** `git@github.com:deforay/spirdt.git`

---

## 2. Source documents

Two Word documents define the instrument. They live in `resources/source/` — deliberately outside `docs/`, so the published documentation site does not republish them — alongside plain-text extractions used as the working reference:

- `SPI-RDT Checklist.docx` — the instrument itself (the questions, structure, scoring, report layout)
- `SPI-RDT Checklist Guidelines.docx` — the User's Guide. For every one of the 59 questions it gives explicit `Y = … / P = … / N = …` scoring definitions. This text becomes per-question guidance in the app, and it is the primary mechanism for consistent scoring between assessors.

Appendices 2 and 3 of the Guidelines (worked examples) are headings with no content. This has been flagged to the client; treat them as missing.

---

## 3. The instrument — structure

**Part A** — Facility and testing site characteristics. Not scored. Includes a staff roster (name/title, repeating) and interviewee details.

**Part B** — Five scored sections, 59 questions total:

| Section | Name | Questions | Scope | Base points |
|---|---|---|---|---|
| 1 | Organization and Management | 8 | Facility | 16 |
| 2 | Physical Facility and Equipment | 10 | Facility | 20 |
| 3 | Safety | 9 | Facility | 18 |
| 4 | Testing | 23 | Per pathogen | 46 × N |
| 5 | Specimen Referral | 9 | Site (optional) | 18 |

**Part C** — Scoring, percentage, and Level 0–4 banding.

**Part D** — Site assessment report: every Partial or No becomes a gap with a recommendation, a responsibility level, a responsible person, and a due date.

### Section 4 is the structural complication

Section 4 repeats per pathogen, not per test. A 3-test HIV algorithm is *one* pathogen instance naming all three tests/manufacturers. The paper form lays this out as 7 side-by-side columns; the model here is a repeat with no hard cap, though the UI is designed for up to about 7.

The Guidelines warn that the same question can score differently per pathogen — Yes for HIV, No for Malaria, Partial for Syphilis. There is no "apply to all" shortcut.

---

## 4. Scoring rules

The output is a certification level, which makes scoring bugs the highest-severity class of defect in this system.

```
Y  (Yes)            = 2 points
P  (Partial)        = 1 point
N  (No)             = 0 points
NA (Not Applicable) = excluded from BOTH numerator and denominator
```

- Section possible score = `2 × (count of non-NA answers in that section)`
- Section 4 possible = `2 × (count of non-NA answers across all pathogen instances)`, so it scales with pathogen count
- Section 5 — if the site refers no specimens, the whole section is NA and contributes 0 to both numerator and denominator
- Total percentage = `(sum of section scores / sum of section possible) × 100`
- The percentage is rounded to two decimal places before banding, so 89.995 becomes 90.00 and lands in Level 4. Code and tests both make this explicit.
- A fully-NA assessment divides by zero, so the engine returns a defined result rather than NaN.

### Level bands

| Level | % Score | Meaning |
|---|---|---|
| 0 | < 40% | Needs improvement in all areas, immediate remediation |
| 1 | 40–59% | Needs improvement in specific areas |
| 2 | 60–79% | Partially eligible for site certification |
| 3 | 80–89% | Close to national site certification |
| 4 | ≥ 90% | Eligible for national site certification |

Band thresholds are template data rather than code, because the Guidelines state that countries localise them during tool customisation.

### N/A eligibility — unresolved, needs client confirmation

The Checklist prints an N/A checkbox on all 59 rows. The Guidelines only define an N/A condition for six cases:

- **1.3** — site has only one tester
- **1.7**, **1.8** — new site, no previous assessment
- **3.9** — no national Hep B vaccination requirement
- **4.10** — no equipment required for the test
- **Section 5** — entire section, if no specimen referral

Section 4's own header hedges: *"N/A is only an option if the question does not apply."*

N/A eligibility is a per-question `na_allowed` flag in the template, so the decision is data and can change without a deploy. The default is the restrictive list above, and selecting NA requires a comment. Permitting NA everywhere inflates percentages and undermines the certification banding — a point worth making to the client before the default is relaxed.

---

## 5. Architecture

| Layer | Choice |
|---|---|
| Backend | Slim 4 + PHP 8.4, MySQL 8, fully Dockerised |
| Frontend | Vue 3 + Vite + TypeScript |
| Assessor app | PWA, offline-first (Dexie.js over IndexedDB) |
| Admin | Separate Vite build, online-only, same framework |
| API | API-first. Every capability is an endpoint before it is a screen. |
| IDs | UUIDv7 or ULID, stored `BINARY(16)` — client-generated for sync idempotency |
| Tenancy | Shared schema, `organization_id` on every tenant-scoped table |

Capacitor stays viable without being committed to. Storage sits behind an adapter interface and domain code avoids browser-only APIs, so if the iOS pilot disappoints the same codebase can be wrapped in a native shell rather than rewritten.

### Multi-tenancy

Some installations serve a single organisation; others host several. Both run the same schema and the same code path.

- Every tenant-scoped table carries `organization_id`. Single-tenant installs simply have one `organizations` row.
- A `MULTI_TENANT` env flag controls UI only — whether org management is visible. It never gates the data model or the scoping logic, and there are no two code paths.
- Tenant is resolved once, in middleware, from the authenticated user — never from a request parameter, path segment, or body field. Accepting a client-supplied tenant identifier is the classic cross-tenant IDOR. (Subdomain-based resolution can be layered on later for branding; user-derived stays the source of truth, and it is what works offline in the PWA.)
- A user belongs to exactly one organisation. Cross-org users are a rabbit hole and are out of scope unless the client asks. Email is unique per organisation, not globally.

#### What is tenant-scoped vs shared

| Shared (global) | Tenant-scoped |
|---|---|
| `question_catalog` — the canonical SPI-RDT question codes *are* the instrument | users, facilities, testing_sites |
| The system-provided base template (canonical SPI-RDT, published by the platform) | campaigns, assessments, assessment_pathogens |
| | answers, findings, attachments |
| | assessment_scores, submissions_raw, audit_log |

Templates resolve the customisation tension neatly: the platform ships a global base template, and an organisation forks it into org-owned versions. Core question codes stay stable and internationally comparable (§8), while each org gets its own labels, translations, `na_allowed` flags, and band thresholds.

#### Role hierarchy

| Role | Scope | Can do |
|---|---|---|
| **Platform admin** | Installation | Create organisations, create an org's first superadmin, suspend an org. Deliberately minimal — no access to assessment data. Only meaningful in multi-tenant installs. |
| **Organisation superadmin** | One org | Full control within their org: users, facilities/sites, templates, campaigns |
| Admin | One org | Day-to-day org administration |
| Assessor | Org + geographic scope | Conduct assessments |
| Viewer | Org + geographic scope | Read-only dashboards and reports |
| Site/facility user | Org + their facility | View and close findings assigned to them |

Two hard limits: an org superadmin cannot escalate to platform admin, and cannot see another organisation. Platform admin is an operations role, not a god mode that browses health assessment data — for both security and privacy posture.

This stacks with the scoping requirement: role alone is insufficient. A user has an organisation *and* a geographic scope (national / regional / district / facility). A district viewer sees one district of one org.

#### Making leakage structurally impossible

Discipline is not a control, so the isolation is structural:

1. A global Eloquent scope applied automatically to every tenant-scoped model. Hand-written `where('organization_id', …)` in application code is a smell — if it is being written, the scope is not wired up.
2. A CI code-guard, modelled on the scope-leak checker from an earlier project in the same house style. It fails the build when a tenant-scoped model is queried outside the scope, or when a migration adds a tenant-scoped table without `organization_id` + FK + index.
3. Composite indexes lead with `organization_id` — correct for performance, and it makes the scoped access path the natural one.
4. A standing cross-tenant isolation test: create two organisations, then table-drive over the full route list asserting every endpoint returns 404/403 for cross-org access. This test grows automatically as routes are added, wired to the same route list that feeds OpenAPI generation.
5. IDs are already unguessable (UUIDv7/ULID), but that is never the control. Scope enforcement is the control; opaque IDs are defence in depth.

#### Bootstrapping an organisation

Org creation is rare and highly privileged, so it is a CLI script (`bin/create-organization`) rather than a platform-admin UI for something run a handful of times — the same treatment the other privileged one-off scripts get. It creates the org, its first superadmin, and forks the base template.

#### Impact on the PWA

- The assessor's JWT carries `organization_id`; down-sync only ever returns their org's data.
- Local IndexedDB is namespaced by org and user. Shared tablets are common in these settings, and a second assessor logging into the same device must not see the first one's cached sites, prior findings, or drafts.

### Offline / sync model

An assessment is a long-lived, single-owner document — one assessor, one site, one visit. There is no concurrent editing in practice, so there are no CRDTs, no operational transform, and no field-level merge. Whole-document submit, client-generated IDs for idempotent retry, last-write-wins.

- **Down-sync**: templates, facility/site lists, prior findings (for question 1.8). Read-mostly, versioned.
- **Up-sync**: whole assessment as one payload. Media (signatures, photos) upload on a separate channel, so a failed 20MB upload on 2G doesn't take the assessment with it.
- iOS has no Background Sync API, so sync is foreground-triggered: on app open, and on regaining connectivity while visible.
- Two assessors per visit is the norm. One device is the scribe and holds the record; the second assessor countersigns. A server-side guard warns, but does not block, on duplicate site+date.

### Client-side constraints

1. Auth expiry must never destroy local drafts. A token expiring offline cannot trigger redirect-and-clear. This is the single worst failure mode: an hour of work lost after the assessor has left the site.
2. Every answer change autosaves to IndexedDB, not on section navigation.
3. Service-worker updates prompt rather than auto-apply, and the prompt is suppressed entirely while an assessment is in progress with unsynced data.
4. iOS requires install-to-home-screen for durable storage. The app detects browser-tab mode on iOS and walks the user through installing before an offline assessment can start.
5. Never render all 160+ inputs at once — one section, or one question, per screen. Older iPads will not cope, and it matches how assessors actually work: walking around a lab, not sitting at a desk.

---

## 6. Ground rules

Maintainability over smart code. If a junior developer can't follow it in a year, it's wrong.

- Simplicity over over-engineering. No abstraction until there are three callers, no framework-within-a-framework, no premature generalisation.
- DRY — one source of truth for scoring rules, question text, and permissions.
- API-first — the API is the product; UIs are clients.
- Extremely simple UI/UX. Assessors are lab staff, not power users, often on a tablet in poor light. Large touch targets, high contrast, minimal chrome, obvious next action.

### Key principles: Security, Performance, UX

**Security**

- No secrets in the repo; `.env.example` stays current
- Log redaction on by default, driven by a `REDACTED_FIELDS` list
- RBAC enforced server-side on every endpoint; never trust the client
- Tenant isolation is the top security concern. Tenant resolved from the authenticated user only, enforced by a global scope, guarded in CI, and covered by a standing cross-tenant test (§5)
- Assessments are immutable once finalised — corrections are new versions, not edits
- PII present: staff names, interviewee phone numbers. No patient data, and it should stay that way.
- Log context never carries `organization_id` from an untrusted source. Logging the resolved value keeps logs usable as an isolation audit trail.

**Performance**

- Time-ordered binary IDs (not random UUIDv4) to avoid InnoDB index fragmentation
- Dashboard reads snapshotted scores, never recomputes from live templates
- Sync arrives in bursts (no iOS background sync), so endpoints handle a queue of submissions landing at once

**UX**

- Offline is a hard requirement, not a nice-to-have
- The assessor sees the score on-device before leaving — the Guidelines require debriefing the site team on site
- Visible, trustworthy "saved locally / synced" state
- i18n from day one, no hardcoded strings, even though English ships first (the predecessor tool shipped EN/FR/PT/ES)
- Date/time format is a documented customisation point per the Guidelines, so it is configurable rather than hardcoded

---

## 7. Conventions

These carry over from earlier projects in the same house style, and are worth following rather than reinventing.

### Migrations

- Plain SQL files, semver-named: `1.0.3-add-findings-table.sql`
- Idempotent DDL via information_schema-guarded helpers (`drop_column_if_exists`, `drop_index_if_exists`)
- Runner (`bin/migrate`) supports `--status`, `--dry-run`, `--verbose`
- Version tracked in a `system_config` row
- A header comment in every migration explaining *why*, not just what
- `db/seeds/` split into `production-bootstrap.sql` and `demo/`

### Logging

- Monolog with a `UidProcessor`, so every line carries a per-request UID
- `App\Helper\Log` static gateway for services that don't take a `LoggerInterface`
- `error_log()` banned everywhere except `src/Helper/Log.php`, enforced by a CI code-guard step
- `ApiLoggerMiddleware` writes to an `api_logs` table, with field redaction and noise-skip rules for polling endpoints
- Daily rotating files in `var/log/`

### Housekeeping

- One omnibus, idempotent sweep in `bin/housekeeping`. Retention policy lives in a single `$targets` array, so there is one place to tweak.
- `--dry-run`, `--only=<target>`, `--help`
- Audit-bearing tables are explicitly excluded — integrity before disk. Here that means `assessments`, `answers`, `findings`, `assessment_scores`, `submissions_raw` and `audit_log` are never pruned.
- Scheduled via Crunz (`tasks/*Tasks.php`)

### Project layout

```
bin/            CLI scripts, each with a docblock + --help via bin/lib/help.php
db/seeds/       production-bootstrap.sql + demo/
docs/           schema/, ops/, source/, openapi.json
migrations/     semver SQL
public/         web root
resources/      css/, js/
routes/         api.php + api/*.php split by audience
src/            PSR-4 App\ — Auth, Bootstrap, Constants, Controller, Exception,
                Handler, Helper, Middleware, Model, Routing, Service
tasks/          Crunz schedules
tests/          Unit/, Integration/, Feature/, e2e/
var/            log/, cache/, exports/, backups/
```

### Tooling

The composer script set: `migrate`, `test`, `test:fast`, `test:unit`, `phpstan`, `cs:check`, `cs:fix`, `openapi`, `openapi:check`, `db:backup`, `backup`. Each one gets a `scripts-descriptions` entry — a house standard, and genuinely useful.

- PHPStan (with baseline), php-cs-fixer, PHPUnit split by suite
- OpenAPI spec generated from `routes/`, kept in lockstep by a `.githooks/pre-commit` hook
- PHP-DI container, Eloquent (`illuminate/database`), `vlucas/phpdotenv`, `ramsey/uuid`, `openspout/openspout` for Excel

---

## 8. Data model — target shape

Unless listed as shared in §5, every table below carries `organization_id`, with a composite index leading on it.

```
organizations                             Tenant root. Single-tenant installs
                                          have exactly one row.

users, roles, permissions, user_scopes    RBAC. Role ALONE is insufficient —
                                          every user has an organisation AND a
                                          geographic scope (national / regional
                                          / district / facility). A district
                                          viewer must not see another district,
                                          or another org.

facilities                                Physical facility
testing_sites                             Many per facility. ONE ASSESSMENT
                                          PER TESTING SITE — each site is an
                                          independent assessment unit even
                                          within one facility.

templates                                 Versioned, IMMUTABLE once published.
                                          Definition stored as a JSON document,
                                          not normalised rows. A GLOBAL base
                                          template is platform-owned; orgs fork
                                          it into org-owned versions.
question_catalog                          SHARED, not tenant-scoped — these
                                          codes are the instrument. Flat
                                          dimension table: stable question code
                                          (1.1, 4.23), canonical text, section.
                                          Join key for cross-version reporting.

campaigns                                 Assessment round. Pins ONE template
                                          version + a site list + a date window.

assessments                               One per site per visit. Belongs to a
                                          campaign. Status: draft → submitted →
                                          reviewed → finalised → delivered.
assessment_pathogens                      Section 4 repeat instances.
answers                                   (assessment, question_code,
                                          nullable pathogen_id) → Y/P/N/NA
                                          + comment.
findings                                  Auto-created for every P or N. Gap,
                                          recommendation, responsibility level
                                          (Site/Facility/District/Regional/
                                          National), responsible person, due
                                          date, completion date, status.
assessment_scores                         SNAPSHOT at submission. Never
                                          recomputed.
submissions_raw                           Immutable payload as received +
                                          template version. Provenance.
attachments                               Signatures, photos.
audit_log, api_logs                       Standard house patterns.
```

### Why templates are JSON, not normalised rows

A template is read whole, written rarely, and immutable once published. Normalising sections and questions into tables means copying hundreds of rows per version, and makes "what exactly did this assessment answer?" hard to reconstruct. One `templates` row with a JSON `definition` and a version number is far cleaner.

`question_catalog` solves the cross-version reporting problem — dashboards and exports key on question code, never on position.

### Template versioning rules

- Publishing freezes a template version. Editing a published template copy-on-writes to v(n+1).
- A template with submitted assessments against it cannot be edited, only forked.
- Campaigns pin a version, so every assessment in a round answers the same instrument.
- What admins may change is tiered. Safe: labels, translations, guidance text, `na_allowed` flags, level band thresholds, enabling/disabling optional questions. Dangerous: adding or removing scored questions, which is allowed only in a clearly separated national addendum that scores separately, so core scores stay internationally comparable.

This constraint exists because the output is a certification band. If admins can freely add questions, percentages stop being comparable across sites and rounds, and the instrument loses its meaning.

---

## 9. The scoring engine

It is built twice, deliberately: PHP server-side (authoritative) and TypeScript client-side, so the assessor sees the score before leaving the site.

What stops the two drifting:

1. Rules live in template data rather than code — point values, `na_allowed`, band thresholds. What's left in code is summation with exclusions, which is small.
2. A shared JSON test-vector fixture set at `tests/fixtures/scoring/*.json`, consumed by both the PHPUnit suite and the Vitest suite. Any drift fails the build.
3. Vectors cover NA exclusion, multi-pathogen Section 4, whole-Section-5 NA, every band boundary (39.99/40.00, 59.99/60.00, 79.99/80.00, 89.99/90.00), a fully-NA assessment, and zero pathogens.
4. The TS module is plain TypeScript with no Vue imports — testable in isolation, and portable to a native shell.

---

## 10. Phase 1 scope

API-first, so the backend leads.

1. **Repo scaffold** — Docker Compose (PHP-FPM, nginx, MySQL 8), `.env.example`, composer setup, PHPStan/cs-fixer/PHPUnit config, `.githooks/`, CI workflow
2. **Core migrations** for the schema in §8
3. **Tenancy foundation** — `organizations`, the global model scope, tenant-resolution middleware, the CI scope-leak guard, and the cross-tenant isolation test. This comes before any tenant-scoped feature, because retrofitting isolation is how leaks happen.
4. **Template JSON schema** and the global base SPI-RDT template seeded from the two source documents — all 59 questions, with the Guidelines' Y/P/N definitions as per-question guidance
5. **Scoring engine (PHP)** and the shared fixture set
6. **Auth + RBAC** with org and geographic scoping
7. **`bin/create-organization`** — org + first superadmin + base template fork
8. **Sync API** — bootstrap/down-sync, whole-assessment upsert, separate attachment upload
9. **OpenAPI spec** generated from routes

### Explicitly out of scope for Phase 1

The PWA, the admin UI, Excel exports, and the dashboard. They come next, and they're much easier once the API and the template schema are settled.

### Definition of done for Phase 1

- `docker compose up` gives a working API on a clean machine
- `composer migrate` runs from empty to current on a fresh DB
- `composer test` passes; PHPStan clean; cs-fixer clean
- The base SPI-RDT template seeds and validates against the template schema
- Scoring fixtures pass
- `bin/create-organization` produces a working second org, and the cross-tenant isolation test proves neither can see the other
- `docs/openapi.json` is current and the pre-commit hook enforces it
- A README that gets a new developer running in under ten minutes

---

## 11. Open questions for the client

1. **N/A eligibility** — all 59 questions, or the restrictive six from the Guidelines? (§4) This is blocking for scoring semantics; the flag exists now, the default can be confirmed later.
2. **Languages at launch** — English only, or the predecessor's EN/FR/PT/ES?
3. **What is an "organisation" in practice** — a country MoH, a programme, an implementing partner? It determines whether orgs within one installation share a jurisdiction, which is the assumption behind shared-schema tenancy (§5). If organisations would span countries with data-residency obligations, those become separate installations rather than separate tenants.
4. **Should a national body ever see cross-organisation aggregates?** Shared schema keeps this possible. Worth confirming whether it's wanted, because it changes what the platform-admin role is allowed to read.
5. **Target devices** — specific iOS versions and iPad models, for the offline pilot.
6. **Historical SPI-RRT data** — import, or start clean? The two scales are not directly comparable (old: 1/0.5/0 out of a fixed 64/75; new: 2/1/0 with a dynamic denominator), so any import needs deliberate handling.
7. **Appendix 1** of the Guidelines (Pre-Assessment Preparation Checklist) — that's pre-visit planning, not site assessment. In scope as a separate feature, or out?
