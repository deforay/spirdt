# Scoring fixtures

These files are the contract between the two scoring engines. The server-side
PHP engine is authoritative, but the auditor has to see a score on the device
before leaving the site — the User's Guide requires debriefing the site team
with the findings before the visit ends — so the same rules are implemented in
TypeScript as well.

Two implementations of the same rules drift. Fixtures are what stop it: both
suites read these exact files, and any disagreement fails the build. Nothing
here is framework-specific, and nothing here may become so.

| Suite | Runner | Location |
|---|---|---|
| PHP | PHPUnit, `composer test:unit` | `tests/Unit/Scoring/` |
| TypeScript | Vitest | pending the frontend |

## Layout

```
banding.json      percentage arithmetic and band selection, no template needed
cases/*.json      whole assessments scored end to end
templates/*.json  synthetic templates, for cases the real instrument cannot express
```

## banding.json

A table of `score / possible` pairs and the percentage and level they must
produce, against the bands the entry names. This is where the boundary cases
live, because reaching an exact 89.995% through the real instrument would take
a template of ten thousand questions.

```json
{
  "score": 17999,
  "possible": 20000,
  "round_dp": 2,
  "expect": { "percentage": 90.0, "level": 4 },
  "why": "89.995 rounds up to 90.00 and lands in Level 4"
}
```

`expect.percentage` is `null` where `possible` is zero. So is `expect.level`.

## cases/*.json

```json
{
  "name": "worked-example-three-pathogens",
  "why": "the example in docs/scoring.md, scored end to end",
  "template": "spi-rdt-1.0.0",
  "context": { "refers_specimens": "yes" },
  "pathogens": ["hiv", "syphilis", "malaria"],
  "default_response": "Y",
  "answers": { "1.3": "NA", "4.1@hiv": "N" },
  "omit": [],
  "extra_answers": [],
  "expect": { }
}
```

**`template`** — a name, resolved as `resources/templates/<name>.json` and
falling back to `tests/fixtures/scoring/templates/<name>.json`.

**`context`** — Part A answers by field code. Only the applicability fields
matter to scoring; `refers_specimens` carries the option key `"yes"` or
`"no"`, exactly as the form stores it.

**`pathogens`** — pathogen keys in sequence order. Section 4 repeats once per
entry. An empty list is a valid case, not a broken one.

**`default_response`** — the response used for every expected question the
`answers` map does not mention. This is what makes a 105-question fixture
readable: a case says "all Yes except these", and a reviewer can see the intent
without counting rows. Omit it to leave unmentioned questions unanswered.

**`answers`** — overrides, resolved against each expected question in order:

1. `"<code>@<pathogen>"` — one pathogen instance, e.g. `"4.10@hiv"`
2. `"<code>"` — the question; for a pathogen-scoped section, every instance
3. `default_response`
4. otherwise the question is left unanswered, and the engine reports it missing

**`omit`** — expected questions to leave unanswered even where a
`default_response` is set. Same key forms as `answers`; a bare code omits every
instance. For asserting that an incomplete assessment is reported as such
rather than scored as though the unanswered questions did not exist.

**`extra_answers`** — answer rows appended verbatim, in the engine's own input
shape, after expansion:

```json
{ "question_code": "5.1", "pathogen": null, "response": "Y" }
```

For asserting that answers the template does not expect here — a retired
question, a removed pathogen, a Section 5 answer left behind when the site was
marked as referring nothing — are ignored rather than scored.

**`expect`** — any subset of the result. A key that is absent is not asserted,
so a case stays focused on what it exists to prove.

```json
{
  "total_score": 168,
  "total_possible": 206,
  "percentage": 81.55,
  "level": 3,
  "pathogen_count": 3,
  "is_scorable": true,
  "is_complete": true,
  "is_valid": true,
  "missing_count": 0,
  "unexpected_count": 0,
  "violation_count": 0,
  "sections": { "1": { "score": 11, "possible": 14, "answered": 7, "excluded": 1, "applicable": true } },
  "pathogens": { "hiv": { "score": 39, "possible": 46, "answered": 23, "excluded": 0 } }
}
```

`sections` is keyed by section code and `pathogens` by pathogen key; within
each, only the keys present are asserted.

## Adding a case

Add the JSON. Both suites discover files by glob, so there is nothing to
register — which is the point. A case that needs a code change to run is a case
that will not be added.

State in `why` what the case exists to prove. A fixture whose expected numbers
were copied from what the implementation happened to produce tests only that
the implementation has not changed, which is not the same as testing that it is
right. Every number in `cases/` is arrived at by hand or taken from
`docs/scoring.md`.
