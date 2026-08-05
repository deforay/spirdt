# Data Model

25 tables across five migrations. Plain SQL, semver-named, applied in order by `bin/migrate`.

| Migration | Covers |
|---|---|
| `0.1.0-baseline` | Tenancy, identity, RBAC, auth |
| `0.1.1-registry` | Facilities and testing sites |
| `0.1.2-templates` | Question catalog and versioned templates |
| `0.1.3-assessments` | Campaigns, assessments, answers, findings, scores |
| `0.1.4-ops` | Audit log and API logs |

Each migration file opens with a comment explaining *why*, not just what. Read them in order — later files carry foreign keys into earlier ones.

## Identifiers

Two strategies, split on a single rule: **can this record originate on a device while offline?**

**`BINARY(16)` UUIDv7** — yes. The client generates the ID, so a retried sync upserts rather than duplicating. UUIDv7 is time-ordered, so it does not fragment the InnoDB clustered index the way random UUIDv4 does.

`facilities`, `testing_sites`, `assessments`, `assessment_pathogens`, `findings`, `attachments`.

**`INT UNSIGNED AUTO_INCREMENT`** — no. Server-owned reference data, created online and down-synced.

Facilities and testing sites are in the first group because an assessor can arrive at a site that is not in the registry — a newly opened TB clinic, a site the office never listed — and must be able to create it on the spot. The cost is duplicates, handled with `source` (`registry` vs `field`) and `merged_into_id` rather than by constraints that would push the failure onto the assessor mid-visit.

Answers deliberately get **no** client-generated ID: the idempotency unit is the assessment, submitted as a whole document, so answers upsert on their natural key.

## Core tables

### Registry

`facilities` → `testing_sites`, one to many. **One assessment covers one testing site.** The User's Guide is explicit that where a facility holds several testing sites, each is an independent assessment unit with its own checklist.

`facility_type`, `level` and `affiliation` store option *keys* defined in the active template, not free text — all three are Part A customisation points.

### Templates

`templates.definition` is a **JSON document**, not normalised rows. A template is read whole, written rarely, and immutable once published; normalising sections and questions would mean copying hundreds of rows every time a country tweaks one label, and would make "what exactly did this assessment answer?" an archaeology exercise. It also decouples the template's internal shape from the schema, so the shape can evolve without a migration.

`organization_id NULL` marks the **platform-owned base template** — the canonical SPI-RDT instrument. Organisations fork it into their own versions, so core question codes stay stable while labels, translations, `na_allowed` flags and band thresholds become theirs.

`question_catalog` is **shared, not tenant-scoped** — those codes *are* the instrument. Exports and reporting join on `code` (`1.1`, `4.23`), never on row position, because once organisations customise templates, position-based output silently misaligns.

### Assessments

`template_id` is **pinned** at creation and never follows the template forward. An assessment must always be readable against the exact instrument it answered.

`refers_specimens` drives Section 5. When false, the whole section is N/A — stored as one flag rather than nine N/A answers, so the scoring engine skips the section outright and the assessor answers one question instead of nine.

`previous_assessment_id` is what lets question 1.8 — *"have gaps from the last assessment been addressed?"* — answer itself from prior findings.

`assessment_pathogens` holds the Section 4 repeat. One row per **pathogen**, not per test: a three-test HIV algorithm is one row with all three named in `tests_description`.

### Findings

Every *Partial* or *No* becomes a finding with a gap, recommendation, `responsibility_level` (site / facility / district / regional / national), owner, due date and status.

On paper this is Part D — a table filled in after the visit that nobody follows up. Here it is a tracked item, which is the main thing the platform offers over the paper form.

### Scores

`assessment_scores` is a **snapshot**, computed once server-side and never recomputed from a live template. Top-level columns are what dashboards aggregate on; `breakdown` holds the per-section and per-pathogen detail the report needs.

`percentage` is `DECIMAL(5,2)` — see [Scoring](scoring.md) for why rounding happens before banding.

`submissions_raw` keeps the payload exactly as the device sent it, immutably. This is an audit instrument: proving what was submitted, distinct from what the server made of it, is worth the storage.

## Two MySQL restrictions worth knowing

Both were found by running the migrations, not by reading documentation.

**A `CASCADE` referential action is illegal on the base column of a stored generated column.** MySQL fails with errno 1215.

This affects two places. `templates.org_key` is generated from `organization_id`, and `answers.pathogen_key` from `pathogen_id`. Both foreign keys therefore use `RESTRICT`.

The semantics are defensible anyway — an organisation holding published templates, and assessments scored against them, should not evaporate through a cascade. Deleting an assessment was verified to still cascade correctly through its pathogens and answers.

**`UNIQUE` treats NULLs as distinct.** Without care, `UNIQUE(assessment_id, question_code, pathogen_id)` would not fire for assessment-scoped answers, and duplicate answers to question 1.1 would silently accumulate — corrupting scores.

Both tables solve this with a stored generated column that collapses NULL to a sentinel:

```sql
pathogen_key BINARY(16) GENERATED ALWAYS AS
    (IFNULL(pathogen_id, X'00000000000000000000000000000000')) STORED,
UNIQUE KEY uq_answers_natural (assessment_id, question_code, pathogen_key)
```

Verified against MySQL 8.4: duplicate assessment-scoped answers are rejected, while distinct pathogen-scoped answers to the same question coexist.

## Audit and logs

`audit_log` and `api_logs` have deliberately opposite lifetimes — evidence versus debugging exhaust.

`audit_log.organization_id` is nullable, and it is the only tenant table where that is true: platform-admin actions belong in the audit trail but sit above any tenant. The global scope must treat this table specially — a platform row is invisible to every organisation, which is the intent.

`api_logs.request_body` is redacted before it is written, and truncated rather than storing a multi-megabyte sync payload. The full payload already lives in `submissions_raw`.
