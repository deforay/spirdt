# Part A validation fixtures

These files are the contract between the two context validators. Part A is
checked on the device as the assessor types, and again on the server when the
visit is synced, because neither check can be the only one: a device is what
the assessor is standing in front of, and a server is the only thing that can
be trusted.

Two implementations of one set of rules drift. These fixtures are what stop it.
Both suites read these exact files, and any disagreement fails the build.

| Suite | Runner | Location |
|---|---|---|
| PHP | PHPUnit | `tests/Unit/Validation/` |
| TypeScript | Vitest | `web/src/validation/__tests__/` |

## What a case looks like

```json
{
    "name": "integer-rejects-a-negative",
    "why": "There cannot be minus two testing sites.",
    "template": "spi-rdt-1.0.0",
    "context": { "poc_site_count": "-2" },
    "expect": [
        { "field": "poc_site_count", "reason": "below_min", "params": { "min": 0 } }
    ]
}
```

`expect` is every problem, in template field order. An empty array means the
answers are acceptable.

## Reason codes

A code, never a sentence. The assessor reads these on a device set to their own
language and the server has no notion of who is asking, so wording chosen here
would arrive in English on a French tablet. The device holds the wording.

| Reason | Raised when | `params` |
|---|---|---|
| `not_an_integer` | An integer field holds text, a decimal, or anything that is not a whole number | — |
| `below_min` | Below the template's `min` | `min` |
| `above_max` | Above the template's `max` | `max` |
| `not_a_date` | Not `YYYY-MM-DD`, or a day the calendar does not have | — |
| `in_the_future` | A date field with `not_future` holds a date later than today | — |

`params` carries the limit that was exceeded, not the value that exceeded it.
The value is already on screen in front of the person reading.

## Dates and the two clocks

`in_the_future` cannot be pinned to a fixed date in a fixture, because "today"
moves. The cases use a year far enough away — 2099 — that the assertion holds
whenever the suite runs.

The server allows one day of grace before calling a date future. A device in
Suva and a server in London disagree about today for ten hours out of every
twenty-four, and without that grace a visit recorded on the morning of the 8th
is refused by a server for which it is still the 7th. The assessor would be
shown a date error they cannot act on, because the date is correct where they
are standing. One day absorbs any real offset and still catches the thing this
exists for, which is a year typed wrongly.

The device applies no grace. It knows where it is.

## Adding a rule

Constraints are declared in the template, never in either codebase — which
field may not hold a future date is a fact about the instrument, and a country
customising the form changes it without a deploy. Add the constraint to
`resources/templates/template.schema.json`, then a case here, then make both
implementations pass.
