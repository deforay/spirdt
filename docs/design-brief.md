# SPI-RDT Assessment Platform — Project Kickoff

## 1. What we're building

A web platform for conducting **SPI-RDT** site assessments — *Stepwise Process for Improving the Quality of Rapid Diagnostic Testing*. This is a WHO/MoH-style quality audit instrument used to assess point-of-care (POC) rapid diagnostic testing sites in decentralised health settings, scoring them toward a national certification level.

It replaces a paper/Word checklist and supersedes an older ODK-based tool (SPI-RRT).

Three surfaces:

1. **Assessor PWA** — offline-first. Assessors visit testing sites (labs, clinics, health centres) with unreliable connectivity, complete a 59-question checklist, score the site, capture gaps and signatures, then sync when back in coverage.
2. **Admin area** — online-only. User/role management, facility and site registry, assessment campaigns, questionnaire template management.
3. **Reporting** — Excel exports and a basic dashboard.

**Repo:** `git@github.com:deforay/spirdt.git` (currently empty)

---

## 2. Source documents

Two Word documents define the instrument. They live in `resources/source/` — deliberately outside `docs/`, so the published documentation site does not republish them — alongside plain-text extractions used as the working reference:

- `SPI-RDT Checklist.docx` — the instrument itself (the questions, structure, scoring, report layout)
- `SPI-RDT Checklist Guidelines.docx` — the User's Guide. **Critically important**: for every one of the 59 questions it gives explicit `Y = … / P = … / N = …` scoring definitions. This text becomes per-question guidance in the app and is the primary mechanism for consistent scoring between assessors.



**Note:** Appendices 2 and 3 of the Guidelines (worked examples) are headings with no content — flagged to the client, treat as missing.

---

## 3. The instrument — structure

**Part A** — Facility and testing site characteristics. Not scored. Includes a staff roster (name/title, repeating) and interviewee details.

**Part B** — Five scored sections, 59 questions total:

| Section | Name | Questions | Scope | Base points |
|---|---|---|---|---|
| 1 | Organization and Management | 8 | Facility | 16 |
| 2 | Physical Facility and Equipment | 10 | Facility | 20 |
| 3 | Safety | 9 | Facility | 18 |
| 4 | Testing | 23 | **Per pathogen** | 46 × N |
| 5 | Specimen Referral | 9 | Site (optional) | 18 |

**Part C** — Scoring, percentage, and Level 0–4 banding.

**Part D** — Site assessment report: every Partial or No becomes a gap with a recommendation, a responsibility level, a responsible person, and a due date.

### Section 4 is the structural complication

Section 4 repeats **per pathogen**, not per test. A 3-test HIV algorithm is *one* pathogen instance naming all three tests/manufacturers. The paper form lays this out as 7 side-by-side columns; we model it as a repeat with no hard cap (but design the UI for up to ~7).

The Guidelines explicitly warn that the same question can score differently per pathogen — Yes for HIV, No for Malaria, Partial for Syphilis. No shortcuts or "apply to all".

---

## 4. Scoring rules (get these exactly right)

The output is a **certification level**, so scoring bugs are the highest-severity class of defect in this system.

```
Y  (Yes)            = 2 points
P  (Partial)        = 1 point
N  (No)             = 0 points
NA (Not Applicable) = excluded from BOTH numerator and denominator
```

- **Section possible score** = `2 × (count of non-NA answers in that section)`
- **Section 4 possible** = `2 × (count of non-NA answers across all pathogen instances)` — so it scales with pathogen count
- **Section 5** — if the site refers no specimens, the whole section is NA and contributes 0 to both numerator and denominator
- **Total percentage** = `(sum of section scores / sum of section possible) × 100`
- **Round the percentage to 2 decimal places, then band it.** Banding on the rounded value, so 89.995 → 90.00 → Level 4. Make this explicit in code and tests.
- **Guard division by zero** — a fully-NA assessment must not crash or produce NaN.

### Level bands

| Level | % Score | Meaning |
|---|---|---|
| 0 | < 40% | Needs improvement in all areas, immediate remediation |
| 1 | 40–59% | Needs improvement in specific areas |
| 2 | 60–79% | Partially eligible for site certification |
| 3 | 80–89% | Close to national site certification |
| 4 | ≥ 90% | Eligible for national site certification |

Band thresholds must be **template data, not code** — the Guidelines state countries localise these during tool customisation.

### N/A eligibility — unresolved, needs client confirmation

The Checklist prints an N/A checkbox on all 59 rows. The Guidelines only define an N/A condition for six cases:

- **1.3** — site has only one tester
- **1.7**, **1.8** — new site, no previous assessment
- **3.9** — no national Hep B vaccination requirement
- **4.10** — no equipment required for the test
- **Section 5** — entire section, if no specimen referral

Section 4's own header hedges: *"N/A is only an option if the question does not apply."*

**Build it as a per-question `na_allowed` flag in the template** so the decision is data and can be changed without a deploy. Default to the restrictive list above. Require a comment whenever NA is selected. Flag to the client that permitting NA everywhere inflates percentages and undermines the certification banding.

---

## 5. Architecture — decided, do not re-litigate

| Layer | Choice |
|---|---|
| Backend | **Slim 4 + PHP 8.4**, MySQL 8, fully Dockerised |
| Frontend | **Vue 3 + Vite + TypeScript** |
| Assessor app | **PWA, offline-first** (Dexie.js over IndexedDB) |
| Admin | Separate Vite build, online-only, same framework |
| API | **API-first.** Every capability is an endpoint before it is a screen. |
| IDs | **UUIDv7 or ULID, stored `BINARY(16)`** — client-generated for sync idempotency |
| Tenancy | **Shared schema, `organization_id` on every tenant-scoped table** |

**Keep Capacitor viable without committing to it.** Storage sits behind an adapter interface; no browser-only APIs in domain code. If the iOS pilot disappoints we wrap the same codebase in a native shell rather than rewriting.

### Multi-tenancy

Some installations serve a single organisation; others host several. **Both run the same schema and the same code path.**

- Every tenant-scoped table carries `organization_id`. Single-tenant installs simply have one `organizations` row.
- A `MULTI_TENANT` env flag controls **UI only** — whether org management is visible. It must never gate the data model or the scoping logic. No two code paths.
- **Tenant is resolved once, in middleware, from the authenticated user** — never from a request parameter, path segment, or body field. Accepting a client-supplied tenant identifier is the classic cross-tenant IDOR and is forbidden. (Subdomain-based resolution can be layered on later for branding; user-derived stays the source of truth, and it's what works offline in the PWA.)
- A user belongs to **exactly one organisation**. Cross-org users are a rabbit hole — don't build it unless the client asks. Email is unique **per organisation**, not globally.

#### What is tenant-scoped vs shared

| Shared (global) | Tenant-scoped |
|---|---|
| `question_catalog` — the canonical SPI-RDT question codes *are* the instrument | users, facilities, testing_sites |
| The system-provided **base template** (canonical SPI-RDT, published by the platform) | campaigns, assessments, assessment_pathogens |
| | answers, findings, attachments |
| | assessment_scores, submissions_raw, audit_log |

**Templates resolve the customisation tension neatly**: the platform ships a global base template; an organisation **forks** it into org-owned versions. Core question codes stay stable and internationally comparable (§8), while each org gets its own labels, translations, `na_allowed` flags, and band thresholds.

#### Role hierarchy

| Role | Scope | Can do |
|---|---|---|
| **Platform admin** | Installation | Create organisations, create an org's first superadmin, suspend an org. **Deliberately minimal — no access to assessment data.** Only meaningful in multi-tenant installs. |
| **Organisation superadmin** | One org | Full control within their org: users, facilities/sites, templates, campaigns |
| Admin | One org | Day-to-day org administration |
| Assessor | Org + geographic scope | Conduct assessments |
| Viewer | Org + geographic scope | Read-only dashboards and reports |
| Site/facility user | Org + their facility | View and close findings assigned to them |

Two hard rules: an org superadmin **cannot escalate to platform admin** and **cannot see another organisation**. Platform admin is an operations role, not a god mode that browses health assessment data — keep it that way for both security and privacy posture.

Note this stacks with the existing scoping requirement: role alone is insufficient. A user has an organisation **and** a geographic scope (national / regional / district / facility). A district viewer sees one district of one org.

#### Making leakage structurally impossible

Discipline is not a control. Engineer it out:

1. **A global Eloquent scope** applied automatically to every tenant-scoped model. Hand-written `where('organization_id', …)` in application code is a smell — if you're writing it, the scope isn't wired up.
2. **A CI code-guard**, modelled directly on `~/www/house-reference/bin/check-money-scope-leaks`. It must fail the build when a tenant-scoped model is queried outside the scope, or when a migration adds a tenant-scoped table without `organization_id` + FK + index.
3. **Composite indexes lead with `organization_id`** — correct for performance, and it makes the scoped access path the natural one.
4. **A standing cross-tenant isolation test**: create two organisations, then table-drive over the full route list asserting every endpoint returns 404/403 for cross-org access. This test grows automatically as routes are added — wire it to the same route list that feeds OpenAPI generation.
5. IDs are already unguessable (UUIDv7/ULID), but **never treat that as the control**. Scope enforcement is the control; opaque IDs are defence in depth.

#### Bootstrapping an organisation

Org creation is rare and highly privileged. Do it as a **CLI script** (`bin/create-organization`) rather than building a platform-admin UI for something run a handful of times — mirroring the `bin/set-employee-password` pattern in `house-reference`. It creates the org, its first superadmin, and forks the base template.

#### Impact on the PWA

- The assessor's JWT carries `organization_id`; down-sync only ever returns their org's data.
- **Namespace local IndexedDB by org + user.** Shared tablets are common in these settings — a second assessor logging into the same device must not see the first one's cached sites, prior findings, or drafts.

### Offline / sync model

An assessment is a **long-lived, single-owner document** — one assessor, one site, one visit. No concurrent editing in practice. So: **no CRDTs, no operational transform, no field-level merge.** Whole-document submit, client-generated IDs for idempotent retry, last-write-wins.

- **Down-sync**: templates, facility/site lists, prior findings (for question 1.8). Read-mostly, versioned.
- **Up-sync**: whole assessment as one payload. Media (signatures, photos) upload on a **separate channel** so a failed 20MB upload on 2G doesn't take the assessment with it.
- **iOS has no Background Sync API** — sync is foreground-triggered, on app open and on regaining connectivity while visible.
- Two assessors per visit is the norm; **one device is the scribe and holds the record**, the second assessor countersigns. Server-side guard warns (does not block) on duplicate site+date.

### Non-negotiable client-side rules

1. **Auth expiry must never destroy local drafts.** Token expires offline → app must not redirect-and-clear. This is the single worst failure mode: an hour of work lost after the assessor has left the site.
2. **Autosave every answer change** to IndexedDB, not on section navigation.
3. **Prompt for service-worker updates, never auto-apply** — and suppress the prompt entirely while an assessment is in progress with unsynced data.
4. **iOS requires install-to-home-screen** for durable storage. Detect browser-tab mode on iOS and walk the user through installing before allowing an offline assessment to start.
5. **Never render all 160+ inputs at once.** One section (or one question) per screen. Older iPads will not cope, and it matches how assessors actually work — walking around a lab, not sitting at a desk.

---

## 6. Ground rules

**Maintainability over smart code.** If a junior developer can't follow it in a year, it's wrong.

- **Simplicity over over-engineering.** No abstraction until there are three callers. No framework-within-a-framework. No premature generalisation.
- **DRY** — one source of truth for scoring rules, question text, and permissions.
- **API-first** — the API is the product; UIs are clients.
- **Extremely simple UI/UX.** Assessors are lab staff, not power users, often on a tablet in poor light. Large touch targets, high contrast, minimal chrome, obvious next action.

### Key principles: Security, Performance, UX

**Security**
- No secrets in the repo; `.env.example` stays current
- Log redaction on by default (mirror the `REDACTED_FIELDS` approach in `house-reference`)
- RBAC enforced server-side on every endpoint; never trust the client
- **Tenant isolation is the top security concern.** Tenant resolved from the authenticated user only, enforced by a global scope, guarded in CI, and covered by a standing cross-tenant test (§5)
- Assessments are immutable once finalised — corrections are new versions, not edits
- PII present: staff names, interviewee phone numbers. No patient data — keep it that way.
- Log context must never carry `organization_id` from an untrusted source — log the resolved value, so logs are usable as an isolation audit trail

**Performance**
- Time-ordered binary IDs (not random UUIDv4) to avoid InnoDB index fragmentation
- Dashboard reads **snapshotted** scores, never recomputes from live templates
- Sync arrives in bursts (no iOS background sync) — endpoints must handle a queue of submissions landing at once

**UX**
- Offline is a hard requirement, not a nice-to-have
- Assessor must see the score **on-device before leaving** — the Guidelines require debriefing the site team on site
- Visible, trustworthy "saved locally / synced" state
- i18n from day one — no hardcoded strings, even though English ships first (the predecessor tool shipped EN/FR/PT/ES)
- Date/time format is a documented customisation point per the Guidelines — make it configurable, don't hardcode

---

## 7. Conventions — mirror `~/www/house-reference`

That project is the house reference. Study it before writing code and follow its patterns closely. Specifically:

### Migrations — `~/www/house-reference/migrations/`, `bin/migrate`
- Plain SQL files, semver-named: `1.0.3-add-findings-table.sql`
- Idempotent DDL via information_schema-guarded helpers (`drop_column_if_exists`, `drop_index_if_exists`)
- Runner supports `--status`, `--dry-run`, `--verbose`
- Version tracked in a `system_config` row
- Header comment in every migration explaining *why*, not just what — see `1.5.96-drop-orphan-invoice-columns.sql` for the standard
- `db/seeds/` split into `production-bootstrap.sql` and `demo/`

### Logging — `src/Helper/Log.php`, `src/Middleware/ApiLoggerMiddleware.php`
- Monolog with a `UidProcessor` so every line carries a per-request UID
- `App\Helper\Log` static gateway for services that don't take a `LoggerInterface`
- **`error_log()` banned everywhere except that one file**, enforced by a CI code-guard step
- `ApiLoggerMiddleware` → `api_logs` table, with field redaction and noise-skip rules for polling endpoints
- Daily rotating files in `var/log/`

### Housekeeping — `bin/housekeeping`
- One omnibus, idempotent sweep. Retention policy in a single `$targets` array — one place to tweak.
- `--dry-run`, `--only=<target>`, `--help`
- **Audit-bearing tables explicitly excluded** — integrity before disk. For us that means `assessments`, `answers`, `findings`, `assessment_scores`, `submissions_raw`, `audit_log` are never pruned.
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
Match the `house-reference` composer script set: `migrate`, `test`, `test:fast`, `test:unit`, `phpstan`, `cs:check`, `cs:fix`, `openapi`, `openapi:check`, `db:backup`, `backup`. Include `scripts-descriptions` for every one — that's a house standard and it's genuinely useful.

- PHPStan (with baseline), php-cs-fixer, PHPUnit split by suite
- OpenAPI spec generated from `routes/`, kept in lockstep by a `.githooks/pre-commit` hook
- PHP-DI container, Eloquent (`illuminate/database`), `vlucas/phpdotenv`, `ramsey/uuid`, `openspout/openspout` for Excel

---

## 8. Data model — target shape

Unless listed as shared in §5, **every table below carries `organization_id`**, with a
composite index leading on it.

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
audit_log, api_logs                       Per house-reference patterns.
```

### Why templates are JSON, not normalised rows

A template is read whole, written rarely, and immutable once published. Normalising sections and questions into tables means copying hundreds of rows per version and makes "what exactly did this assessment answer?" hard to reconstruct. One `templates` row with a JSON `definition` and a version number is far cleaner.

`question_catalog` solves the cross-version reporting problem — dashboards and exports key on **question code**, never on position.

### Template versioning rules

- Publishing freezes a template version. Editing a published template **copy-on-writes to v(n+1)**.
- A template with submitted assessments against it **cannot be edited**, only forked.
- Campaigns pin a version, so every assessment in a round answers the same instrument.
- **Tier what admins may change.** Safe: labels, translations, guidance text, `na_allowed` flags, level band thresholds, enabling/disabling optional questions. Dangerous: adding or removing scored questions — allow only in a clearly separated national addendum that scores separately, so core scores stay internationally comparable.

This constraint exists because the output is a certification band. If admins can freely add questions, percentages stop being comparable across sites and rounds, and the instrument loses its meaning.

---

## 9. The scoring engine

Build it **twice, deliberately**: PHP server-side (authoritative) and TypeScript client-side (so the assessor sees the score before leaving the site).

To stop them drifting:

1. **Rules live in template data**, not code — point values, `na_allowed`, band thresholds. What's left in code is summation with exclusions, which is small.
2. **A shared JSON test-vector fixture set** at `tests/fixtures/scoring/*.json`, consumed by both the PHPUnit suite and the Vitest suite. Any drift fails the build.
3. Vectors must cover: NA exclusion; multi-pathogen Section 4; whole-Section-5 NA; every band boundary (39.99/40.00, 59.99/60.00, 79.99/80.00, 89.99/90.00); a fully-NA assessment; zero pathogens.
4. TS module is **plain TypeScript with no Vue imports** — testable in isolation, portable to a native shell.

---

## 10. Phase 1 scope — build this first

API-first, so the backend leads.

1. **Repo scaffold** — Docker Compose (PHP-FPM, nginx, MySQL 8), `.env.example`, composer setup, PHPStan/cs-fixer/PHPUnit config, `.githooks/`, CI workflow
2. **Core migrations** for the schema in §8
3. **Tenancy foundation** — `organizations`, the global model scope, tenant-resolution middleware, the CI scope-leak guard, and the cross-tenant isolation test. **Build this before any tenant-scoped feature** — retrofitting isolation is how leaks happen.
4. **Template JSON schema** + the global base SPI-RDT template seeded from the two source documents — all 59 questions, with the Guidelines' Y/P/N definitions as per-question guidance
5. **Scoring engine (PHP)** + the shared fixture set
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

## 11. Open questions for the client — do not guess

1. **N/A eligibility** — all 59 questions, or the restrictive six from the Guidelines? (§4) Blocking for scoring semantics; build the flag now, confirm the default later.
2. **Languages at launch** — English only, or the predecessor's EN/FR/PT/ES?
3. **What is an "organisation" in practice** — a country MoH, a programme, an implementing partner? It determines whether orgs within one installation share a jurisdiction, which is the assumption behind shared-schema tenancy (§5). If organisations would span countries with data-residency obligations, those become **separate installations**, not separate tenants.
4. **Should a national body ever see cross-organisation aggregates?** Shared schema keeps this possible; confirm whether it's wanted, because it changes what the platform-admin role is allowed to read.
5. **Target devices** — specific iOS versions and iPad models, for the offline pilot.
6. **Historical SPI-RRT data** — import, or start clean? The two scales are not directly comparable (old: 1/0.5/0 out of a fixed 64/75; new: 2/1/0 with a dynamic denominator), so any import needs deliberate handling.
7. **Appendix 1** of the Guidelines (Pre-Assessment Preparation Checklist) — that's pre-visit planning, not site assessment. In scope as a separate feature, or out?

---

## 12. First task

1. Clone `git@github.com:deforay/spirdt.git`
2. Read `~/www/house-reference` — particularly `bin/migrate`, `bin/housekeeping`, `src/Helper/Log.php`, `src/Middleware/ApiLoggerMiddleware.php`, `composer.json`, and the `routes/` split
3. Read the extracted source text in `resources/source/`
4. Propose the Phase 1 migration set and the template JSON schema **for review before writing application code**

Start with the template JSON schema and the data model. Everything else hangs off them.
