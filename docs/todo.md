# What is left

State as of 6 August 2026. Written
so work can resume cold.

## Decided but not built

### Registry screens — done, and rebuilt for scale
Separate pages: `/admin/places`, `/admin/facilities`, `/admin/sites`, plus
`/admin/assignments`. Search-first and paginated, because a country runs to
thousands of facilities and hundreds of places.

The first version was built for a demo of four rows and had two real bugs at
scale, both now covered by tests: choosing a **province** matched nothing,
because facilities hang off districts and the filter was an exact
`geo_unit_id` rather than the subtree; and the assignments screen fetched a
place's facilities and then asked **one request per facility** for its sites.

The cascade of selects is gone. Twenty provinces of thirty districts made
finding a place two guesses in a fixed order, and it only worked if you already
knew which province a district was in. `PlacePicker` is a type-ahead over the
whole tree matching on the full path — "copper kit" finds Kitwe in Copperbelt —
and every row anywhere prints its path, since district and facility names
repeat.

Still missing there: renaming in place (only add and deactivate), the merge
screen for field-created duplicates, and bulk import.

### The tenant hierarchy — basic version in place
`programmes` → `organizations`, with the registry on the programme and audit
data on the organisation. That is the shape already asked for: one country,
several independent organisations, each with its own assessors, auditing the
same labs or different ones.

**Naming: `programme` in code, "Country" on screen.** Agreed as the cheap and
extensible choice — renaming the schema reaches both tenancy traits, the token
claim, the tests and the docs, and if a country ever runs two programmes
separately it would have to be renamed back. A label is one string.

A `superadmin` owns the programme and manages the organisations in it at
`/admin/organizations` — the only surface where one tenant's administrator
legitimately reaches another tenant's row, bounded to their own programme by
the token. `bin/provision-org` makes the first user of a NEW programme a
superadmin and of a joining organisation an ordinary admin.

Still open, and deliberately left until the shape is clearer: whether a country
needs its own settings screen, whether organisations need types (ministry
versus partner), and **whether organisation A may see organisation B's score on
a site they both audit** — today, no.

### Form management — deferred, and blocked on one ruling
Letting a country customise the instrument. The schema already takes a
position: `templates.organization_id NULL` is the platform's base instrument,
organisations **fork** it, publishing freezes a version, editing copy-on-writes
to v(n+1), and a template with submitted assessments cannot be edited at all —
only forked.

What is not settled is **what may be changed**, and that is a programme
decision rather than a UI one. SPI-RDT's value is that a Level 3 in one country
means what a Level 3 means in another; customisation is in direct tension with
that. My recommendation:

| | |
|---|---|
| Question wording, guidance, criteria | Yes — translation and local phrasing is the point |
| Part A fields (facility types, affiliations, roster) | Yes — already a per-country customisation point |
| `na_allowed` per question | Yes, with a warning: every N/A narrows the denominator |
| A country's own extra questions | Yes, scored separately or not at all |
| Removing or renumbering core questions | No — this is what the stable `uid` is for |
| Scoring points (Y=2, P=1, N=0, NA excluded) | No — changes these and no score is comparable |
| Band thresholds (40/60/80/90) | **Needs the programme's ruling.** Defensible as national policy, but Level 3 then means different things in different countries |

Until the table is agreed, the editor cannot be designed: "translate and tune"
and "build your own instrument" are different products.

### Stable question ids — decided, waiting on the hierarchy pass
`code` (`1.1`, `4.23`) is unique within a template and enforced, so referencing
a question works today. What it is not is stable across instrument versions: it
encodes position, so inserting a question renumbers everything after it and
`1.4` in the new template is a different question from `1.4` in the old one —
with every stored answer still saying `1.4`, and nothing erroring.

**Agreed:** add a `uid` to every question as the identity and demote `code` to a
display label. **Readable, not opaque** — `spi-rdt.s1.q1` rather than a UUID,
because the failure being guarded against is renumbering, not somebody
inferring structure from the id.

Reaches: the template JSON and its schema, `answers.question_code` and
`findings.question_code`, both scoring engines, the device's answer keys, and
the sync payload. `question_catalog` exists for this and is **empty** — nothing
populates it — so publishing should fill it as part of the same change.

Held only so it lands in one pass with any rename of `organizations`, since
both touch the template pipeline.

### Findings v2 — done
`findings.urgency` records *when* alongside `responsibility_level`'s *who*, per
finding rather than per section. Nullable with no default: blank means nobody
said, which is not the same as follow-up, and defaulting would invent a
judgement the assessor never made.

One question can now carry several findings. The upsert keys on the finding's
own device-minted id rather than the answer's natural key, and a payload
without one is dropped rather than inserted fresh on every retry. Correcting an
answer away from P/N discards *all* of that question's findings.

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
- **A deleted finding stays on the server.** Removing one locally deletes the
  only row without leaving a tombstone or marking the assessment pending, and
  sync only ever upserts what is present — so a corrective action the assessor
  withdrew stays in the site's action plan for good. `discardFindingsFor` hits
  the same path when an answer changes to Yes or N/A, which makes it the
  commoner way in. Fixing it means the payload has to be able to say what is
  gone, not just what is there, so it is a change to the protocol rather than a
  patch to a function.
- **A finding keeps the response it was created under.** Raise a finding while
  the answer is Partial, change the answer to No, and the finding still says
  Partial. `FindingPatch` has no way to correct it afterwards either.
- **A v4 device with an already-synced finding would duplicate it.** Findings
  were keyed server-side on (assessment, question, pathogen) and given a
  server-minted id; they are keyed on a device-minted id now. The v5 upgrade
  assigns a fresh id to a legacy row, so the server cannot recognise it and
  inserts a second one. No database has a finding in it yet and nothing is
  deployed, so the affected population is currently empty — but the window
  closes the moment it is not, and reconciling after the fact means matching on
  the natural key without hijacking the second finding on a question, which
  findings v2 explicitly allows.
