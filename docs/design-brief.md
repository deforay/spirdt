# SPI-RDT Assessment Platform — Design Brief

Why this platform exists, what was decided at the outset, and what is still open.

This is the reasoning. The mechanics live in the reference documents and are linked from each section rather than repeated here, because a second copy is the one that goes stale.

---

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

The base template is derived from these documents rather than hand-transcribed, and the derivation is committed. See [Templates](templates.md).

---

## 3. The instrument

**Part A** — Facility and testing site characteristics. Not scored. Includes a staff roster (name/title, repeating) and interviewee details.

**Part B** — Five scored sections, 59 questions total:

| Section | Name | Questions | Scope |
|---|---|---|---|
| 1 | Organization and Management | 8 | Facility |
| 2 | Physical Facility and Equipment | 10 | Facility |
| 3 | Safety | 9 | Facility |
| 4 | Testing | 23 | Per pathogen |
| 5 | Specimen Referral | 9 | Site (optional) |

**Part C** — Scoring, percentage, and Level 0–4 banding.

**Part D** — Site assessment report: every Partial or No becomes a gap with a recommendation, a responsibility level, a responsible person, and a due date.

### Section 4 is the structural complication

Section 4 repeats per pathogen, not per test. A 3-test HIV algorithm is *one* pathogen instance naming all three tests/manufacturers. The paper form lays this out as 7 side-by-side columns; the model here is a repeat with no hard cap, though the UI is designed for up to about 7.

The Guidelines warn that the same question can score differently per pathogen — Yes for HIV, No for Malaria, Partial for Syphilis. There is no "apply to all" shortcut.

---

## 4. Why scoring is specified so tightly

The output is a certification level, which makes scoring bugs the highest-severity class of defect in this system. A wrong percentage at a band boundary is the difference between a site being certified and not.

Three decisions follow from that, and each is a constraint the implementation cannot relax:

**N/A is excluded from both numerator and denominator**, not scored as zero. This is the rule that catches people out, and it is why the denominator is dynamic rather than the fixed total the paper form implies.

**The percentage never becomes a float.** PHP and TypeScript both compute the score, because the assessor has to see it before leaving the site. Two implementations of the same rules disagree at exactly the midpoint values that decide a band, so the arithmetic is integer throughout and only becomes a float on display.

**Band thresholds are template data, not code.** The Guidelines state that countries localise them during customisation. Putting them in code would mean a deploy per country, and would make a historic report describe a band the site was never awarded.

The rules themselves, the worked example and the fixture strategy are in [Scoring](scoring.md).

### N/A eligibility is still open

The Checklist prints an N/A checkbox on all 59 rows. The Guidelines only define what N/A *means* for five of them — 1.3, 1.7, 1.8, 3.9 and 4.10 — plus Section 5 as a whole, which opts out via `refers_specimens` rather than nine N/A answers.

The platform derives eligibility from the Guidelines rather than assuming the checkbox, and carries it as a per-question `na_allowed` flag so the decision is data and can change without a deploy. Permitting N/A everywhere inflates percentages and undermines the banding, which is the entire point of the instrument. That trade-off is the client's to make, and it is listed in §9.

---

## 5. Architecture

| Layer | Choice |
|---|---|
| Backend | Slim 4 + PHP 8.4, MySQL 8, fully Dockerised |
| Frontend | Vue 3 + Vite + TypeScript |
| Assessor app | PWA, offline-first (Dexie.js over IndexedDB) |
| Admin | Separate Vite build, online-only, same framework |
| API | API-first. Every capability is an endpoint before it is a screen. |
| IDs | UUIDv7, stored `BINARY(16)` — client-generated for sync idempotency |
| Tenancy | Shared schema, scoped by programme and by organisation |

Capacitor stays viable without being committed to. Storage sits behind an adapter interface and domain code avoids browser-only APIs, so if the iOS pilot disappoints the same codebase can be wrapped in a native shell rather than rewritten.

Database-per-tenant was considered and rejected. It taxes every installation with N migration runs to serve the minority case, and the genuinely hard requirement — a ministry requiring data stays in-country — is answered by separate installations, not separate databases.

### Two scopes, not one

The tenancy model gained a level after the brief was first written, and the reason is worth recording. Within one country two organisations may both be running audits, sometimes on the same labs, independently. While the registry was organisation-scoped that was impossible: each held its own facility row, its own testing-site row, its own "Lusaka Province", with different IDs and nothing to join on. Comparing them meant matching facility names.

So the registry moved up to the programme, and everything derived from a visit stayed put:

| Scope | What it holds |
|---|---|
| Programme | The registry — geo units, facilities, testing sites, site assignments |
| Organisation | Everything a visit produces — assessments, answers, findings, scores, attachments — plus users and roles |

A programme is a country's national programme, usually the ministry. It is named `programme` in code and shown as "Country" on screen.

`organization_id` survives on the registry tables and no longer means what it did. It is provenance now: which organisation originated the row, set when an assessor created a facility in the field before it existed centrally. Nothing reads it as a scope.

Sharing a registry is deliberate rather than automatic. Organisations get a programme of their own unless one is named at provisioning, because a site list should only be shared because somebody decided to share it.

### The rest of the isolation model

Tenant is resolved from the authenticated user in middleware, never from a request parameter. A global model scope, a CI code-guard, composite indexes leading with the scope column, and a standing cross-tenant test carry the rest. Discipline is not a control, so the isolation is structural.

The mechanics, the role table and the reasoning behind platform admins living in a separate table are in [Architecture](architecture.md). The schema and the two MySQL restrictions that shaped it are in [Data Model](data-model.md).

### Bootstrapping

Org creation is rare and highly privileged, so it is a CLI script rather than a platform-admin UI for something run a handful of times. `bin/provision-org` creates the organisation, seeds the system roles and creates its first administrator; `--programme` joins an existing programme and shares its registry. `bin/recover-access` is the break-glass for an organisation nobody can administer any more. Both are documented in the [CLI Reference](cli.md).

### Offline and sync

An assessment is a long-lived, single-owner document — one assessor, one site, one visit. There is no concurrent editing in practice, so there are no CRDTs, no operational transform, and no field-level merge. Whole-document submit, client-generated IDs for idempotent retry, last-write-wins.

Media uploads on a separate channel, because a failed 20MB transfer on 2G must not take a completed assessment with it. iOS has no Background Sync API, so sync is foreground-triggered.

The client-side rules that protect an assessor's work, and the upload contract, are in [Architecture](architecture.md) and [Signatures](signatures.md).

---

## 6. Ground rules

Maintainability over smart code. If a junior developer can't follow it in a year, it's wrong.

- Simplicity over over-engineering. No abstraction until there are three callers, no framework-within-a-framework, no premature generalisation.
- DRY — one source of truth for scoring rules, question text, and permissions. That applies to this document too, which is why it links rather than restates.
- API-first — the API is the product; UIs are clients.
- Extremely simple UI/UX. Assessors are lab staff, not power users, often on a tablet in poor light. Large touch targets, high contrast, minimal chrome, obvious next action.

**Security.** No secrets in the repo. RBAC enforced server-side on every endpoint. Tenant isolation is the top concern. Assessments are immutable once finalised — corrections are new versions, not edits. PII is limited to staff names and interviewee phone numbers, and no patient data enters the system.

**Performance.** Time-ordered binary IDs to avoid index fragmentation. Dashboards read snapshotted scores rather than recomputing from live templates. Sync arrives in bursts, so endpoints handle a queue of submissions landing at once.

**UX.** Offline is a hard requirement. The assessor sees the score on-device before leaving, because the Guidelines require debriefing the site team on site. i18n from day one, even though English ships first. Date format is configurable, because the Guidelines make it a per-country choice.

The engineering bar and the review that enforces it are in [Engineering Standards](engineering-standards.md). The visual and copy rules are in [Design](design.md).

---

## 7. Conventions

These carry over from earlier projects in the same house style, and are worth following rather than reinventing.

Migrations are plain SQL, semver-named, idempotent, and every one opens with a comment explaining *why*. Logging goes through Monolog with a per-request UID, and `error_log()` is banned outside `src/Helper/Log.php`. Housekeeping will be one omnibus idempotent sweep with retention in a single array, and the audit-bearing tables are never pruned.

All three are documented with their flags and failure modes in [Backup & Upgrade](operations.md) and the [CLI Reference](cli.md).

```
bin/            CLI scripts, each with a docblock + --help
db/seeds/       production-bootstrap.sql + demo/
docs/           mkdocs sources
migrations/     semver SQL
public/         web root
resources/      source/ and templates/
routes/         api.php + api/*.php split by audience
src/            PSR-4 App\ — Bootstrap, Exception, Handler, Helper, Http,
                Middleware, Models, Scoring, Service, Support, Tenancy
tasks/          Crunz schedules
tests/          Unit/, Integration/, Feature/
var/            log/, cache/, exports/, backups/, uploads/
web/            Vue app
```

---

## 8. What ships when

Phase 1 is API-first, so the backend leads: the scaffold, the schema, the tenancy foundation, the template schema and seeded base instrument, the PHP scoring engine, auth and RBAC, provisioning, the sync API, and a generated OpenAPI spec.

The tenancy foundation comes before any tenant-scoped feature. Retrofitting isolation is how leaks happen.

Out of scope for Phase 1: the dashboard, Excel exports, and the certificate. They are much easier once the API and the template schema are settled.

Phase 1 is substantially delivered. What remains, what was deferred and why, and the known weak spots are tracked in [What is left](todo.md), which is the current status rather than the original plan.

---

## 9. Open questions for the client

1. **N/A eligibility** — the restrictive set the Guidelines define, or the checkbox the Checklist prints on all 59 rows? Blocking for scoring semantics. The flag exists; the default can be confirmed later.
2. **May organisation A see organisation B's score on a site they both audit?** Today, no. This shapes the dashboard, so it is worth settling before that work starts.
3. **What is an "organisation" in practice** — a country MoH, a programme, an implementing partner? If organisations would span countries with data-residency obligations, those become separate installations rather than separate tenants.
4. **Languages at launch** — English only, or the predecessor's EN/FR/PT/ES? The shipped translations have not been reviewed by a native speaker.
5. **Target devices** — specific iOS versions and iPad models, for the offline pilot.
6. **Historical SPI-RRT data** — import, or start clean? The two scales are not directly comparable (old: 1/0.5/0 out of a fixed 64/75; new: 2/1/0 with a dynamic denominator).
7. **Appendix 1** of the Guidelines (Pre-Assessment Preparation Checklist) — pre-visit planning rather than site assessment. In scope as a separate feature, or out?
8. **Is the instrument content restricted?** It is currently public in `resources/templates/`. The source documents are deliberately not in the repository.
