# What is left

State as of 6 August 2026, at `c3a9e42` plus the review-tooling change. Written
so work can resume cold.

## Decided but not built

### Registry screens — the API is done, the views are not
`RegistryService`, `RegistryAction` and the routes are in and tested
(`RegistryAdminTest`, 12 cases). What does not exist is the Vue side:

- a **depth-agnostic cascade** component — render N levels from the tree and take
  labels from `geo_units.level`, never from constants. Zambia is Province →
  District, Ethiopia is Region → Zone → Woreda. Hard-coding two levels means a
  rewrite for the second country
- a places screen (tree, add child, rename, deactivate)
- a facilities screen filtered by the cascade
- testing sites within a facility
- an assignments screen — the model, resolution rules and endpoints all exist and
  nothing but SQL drives them

`GET /admin/geo-units` returns the whole tree flat in one response; the client
assembles it.

### Managing the organisations in a programme
Asked for as "manage the Implementing Partners". **Blocked on one decision: who
holds programme-level power.** Roles are per-organisation rows, and
`bin/provision-org` creates the first user as `admin`, so `superadmin` is seeded
everywhere and assigned nowhere.

1. Add a `programme_admin` role key — the capability is programme-scoped, so the
   role should be. Needs a migration to seed it into existing organisations.
   **Recommended.**
2. Treat `superadmin` as programme-level. No migration, but it gives a
   per-organisation row cross-organisation power.
3. Mark one organisation as the programme owner. New column, and awkward where
   the ministry is not itself a tenant.

Whichever wins also decides who may read assessments across organisations for
the cross-organisation comparison.

### Findings v2
Both agreed, neither built:

- **`urgency ENUM('immediate','follow_up')`** alongside `responsibility_level`.
  Orthogonal axes — *when* versus *who* — and a national-level immediate action
  is a coherent thing to record. Per finding, not per section.
- **Several findings per question.** The natural key
  `(assessment, question_code, pathogen)` has to relax to a device-minted
  finding UUID, `SyncService::upsertFindings` keys on that instead, and
  `useAssessment.setResponse` discards *all* findings for a question when the
  answer stops being P/N. Scoring is unaffected — findings never touch it.

My reading of "multiple gaps/corrective actions" is N findings per question,
each one gap plus one recommendation, rather than one gap with a child table of
actions. Flagged, not confirmed.

### Responsive layout
Every screen is `max-w-[430px]` — right for a phone in one hand, wrong on the
laptops and tablets assessors also use. Deferred deliberately ("form design is
something that we can address any time"). When it happens: section rail instead
of a pill row at ≥768px, two columns on setup and review above ~900px, a
signature pad that grows with its container, and **no shrinking of touch
targets** — mouse users tolerate large ones, fingers do not tolerate small ones.

## Not yet designed

- **Dashboard.** Recommended order: assessment list and one visit's report → the
  findings tracker → charts. Ideas worth taking from the predecessor are in
  `docs/` history and the project notes: a radar chart of section scores, level
  bands as a first-class filter, worst-performance-by-question across sites,
  high-volume sites cross-referenced with score, coverage against the plan.
- **Reports and the certificate.** Nothing renders a completed visit, which is
  also why the signatures have nowhere to appear yet.
- **Photographs.** `attachments` already carries the `photo` kind,
  `question_code` and the size limit. Nothing captures one.
- **Bulk import** for the registry. Nobody hand-types a national facility list.
- **Duplicate reconciliation.** `source = 'field'` and `merged_into_id` exist for
  it; no screen merges anything.
- **Guidance sheet.** `QuestionRow` emits the event; nothing catches it.
- **Page-specific slide-out help**, following the pattern in `~/www/house-reference`.
- **`bin/housekeeping`** and OpenAPI generation.
- **Component tests** for the Vue side — there is no jsdom environment yet, so
  every frontend test today is logic rather than rendering.

## Open questions for the client

- **May organisation A see organisation B's score on a shared site?** Today: no.
  Organisations see only their own; a programme-level read sees both. This
  shapes the dashboard, so it is worth settling before that work starts.
- **N/A eligibility.** The template permits it on 1.3, 1.7, 1.8, 3.9 and 4.10
  only. Worth confirming against the source instrument.
- **Is the instrument content restricted?** It is currently public at
  `resources/templates/spi-rdt-1.0.0.json`. The source `.docx` is deliberately
  not in this repository.
- **Should individual checklist questions be optional?** Part A has `required`.
  Making checklist questions optional means changing `isComplete` in both
  scoring engines and the shared fixtures, and it loosens the gate that stops a
  partial assessment reaching a certificate.

## Known weak spots

- **Non-English wording is unreviewed.** French, Portuguese and Spanish were
  written in one pass and no native speaker has read them. One file each, about
  90 lines. See `docs/i18n.md`.
- **Server error messages are English only.** The API returns wording rather
  than keys and has no notion of the caller's language, so sign-in and sync
  failures ignore the language switch.
- **`GET /sites` returns every site in the programme.** Correct today and fine
  for hundreds; a national registry in the thousands wants pagination or a
  delta sync.
- **Local storage is not namespaced by user.** On a shared tablet, signing in as
  somebody else leaves the previous person's cached site list in place.
