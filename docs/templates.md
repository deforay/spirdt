# Templates

The instrument itself — sections, questions, guidance, Part A fields, point values and certification bands — lives in a **template**, stored as a JSON document in `templates.definition`.

## Why JSON rather than tables

A template is read whole, written rarely, and immutable once published. Normalising sections and questions into tables would mean copying several hundred rows every time a country adjusts one label, and would make *"what exactly did this assessment answer?"* an archaeology exercise.

It also decouples the instrument's shape from the database schema, so the shape can evolve without a migration.

The trade-off is that you cannot query across versions by question — which is what the shared `question_catalog` table solves. Exports and reporting join on **question code** (`1.1`, `4.23`), never on row position, because position silently misaligns once organisations customise their templates.

## Versioning

- Publishing **freezes** a version. Editing a published template copy-on-writes to `v(n+1)`.
- A template with submitted assessments against it cannot be edited at all, only forked.
- A campaign **pins** one template version, so every assessment in a round answers the same instrument.
- An assessment pins its template too, and never follows it forward.

The platform owns a **base template** — the canonical SPI-RDT instrument, with `organization_id` null. Organisations fork it. Core question codes stay stable, which keeps scores internationally comparable, while labels, translations, `na_allowed` flags and band thresholds become theirs to change.

!!! warning "What organisations should not be able to change"
    Adding or removing scored questions breaks comparability across sites and rounds, and the output of this instrument is a certification level. Safe customisation is labels, translations, guidance text, N/A eligibility and band thresholds. Country-specific *additional* questions belong in a separate addendum that scores independently.

## Structure

```json
{
  "schema_version": 1,
  "code": "spi-rdt",
  "version": "1.0.0",
  "locales": ["en"],
  "default_locale": "en",
  "scoring": {
    "responses": {
      "Y":  { "points": 2, "label": { "en": "Yes" } },
      "P":  { "points": 1, "label": { "en": "Partial" } },
      "N":  { "points": 0, "label": { "en": "No" } },
      "NA": { "points": null, "excluded": true, "label": { "en": "Not Applicable" } }
    },
    "round_dp": 2,
    "bands": [
      { "level": 0, "min_percent": 0,  "label": { "en": "Level 0" } },
      { "level": 4, "min_percent": 90, "label": { "en": "Level 4" } }
    ]
  },
  "context_fields": [ "…Part A…" ],
  "sections": [
    {
      "number": 4,
      "code": "4",
      "title": { "en": "Testing" },
      "scope": "pathogen",
      "questions": [
        {
          "code": "4.10",
          "text":     { "en": "Does the site have appropriate equipment for the test performed?" },
          "guidance": { "en": "In some settings, the site may use some equipment…" },
          "criteria": {
            "Y":  { "en": "Necessary equipment is available for the test…" },
            "P":  { "en": "Some, but not all, necessary equipment is available…" },
            "N":  { "en": "The necessary equipment is not available for the test" },
            "NA": { "en": "No equipment required" }
          },
          "na_allowed": true,
          "comment_required_for": ["P", "N", "NA"]
        }
      ]
    }
  ]
}
```

Three details worth calling out:

**Every string is an object keyed by locale**, never a bare string. Translations are a launch requirement, and a bare string is exactly what turns them into a retrofit.

**Bands carry only a lower bound.** Each runs up to the next band's `min_percent`, and the last is open-ended, so bands cannot overlap or leave a gap.

**`scope: "pathogen"`** is what makes Section 4 work. Those questions are answered once per pathogen assessed, which is why the possible total scales with pathogen count.

## Generating the base template

The base template carries 59 questions, each with question text, assessment guidance and per-response criteria. Hand-transcribing that would be tedious and unauditable, so it is **derived from the source documents** and the derivation is committed:

```bash
composer template:build
```

Reads `resources/source/`, writes `resources/templates/spi-rdt-<version>.json`. When the instrument is revised, re-run this rather than hand-editing the JSON.

!!! note "The source documents are not distributed"
    The Word originals are deliberately not committed to this repository and are gitignored. The generated template is committed, so a fresh clone works without them — but regenerating requires obtaining the sources separately.

Question text comes from the **Checklist**, since that is what assessors complete. Guidance and criteria come from the **User's Guide**. Where the two disagree the Checklist wins, and the difference is reported rather than silently resolved:

```
warn  Wording differs between the two documents for 4 question(s):
        2.4
          checklist:  Is there a designated area for testing only, …
          guidelines: Is there a designated testing area for testing only, …
```

Those four are punctuation and plural differences, but surfacing them is the point — silent divergence between an instrument and its guide is a documentation bug.

### N/A eligibility is derived, not assumed

A question allows *Not Applicable* only where the User's Guide actually defines what N/A means for it. That produces the restrictive set the guide describes — **1.3, 1.7, 1.8, 3.9, 4.10** — rather than the checkbox the Checklist prints on all 59 rows.

Section 5 is separate: it opts out wholesale via `refers_specimens`, so an auditor answers one question instead of nine N/A answers.

See [Scoring](scoring.md) for why this matters to the certification bands.

## Validating

```bash
composer template:validate
```

Two layers, because JSON Schema cannot express all of it:

**Structural** — against `resources/templates/template.schema.json`.

**Semantic** — rules spanning the whole document: unique question codes, questions belonging to their section, bands ascending with one starting at zero, exactly one excluded response, `na_allowed` matching the presence of an NA criterion, and optional sections naming an applicability field.

The semantic layer exists because those failures are the expensive ones. A band list missing its zero entry means a low percentage falls into no band at all — producing a wrong certification level rather than an error.

```
spi-rdt-1.0.0.json
  ok    Structure valid
  ok    Semantics valid
        spi-rdt v1.0.0 — 5 sections, 59 questions, 5 allow N/A
        Max possible with 1 pathogen, no N/A: 118 points
```

That 118 is a useful sanity check: 16 + 20 + 18 + 46 + 18. With three pathogens it becomes 210, because Section 4 scales.
