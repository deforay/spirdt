# Scoring

The output of an assessment is a **certification level**. Scoring bugs are therefore the highest-severity class of defect in this system, and the rules below are specified precisely rather than left to implementation.

## Response values

```
Y  (Yes)            = 2 points
P  (Partial)        = 1 point
N  (No)             = 0 points
NA (Not Applicable) = excluded from BOTH numerator and denominator
(unanswered)        = 0 points, and STAYS in the denominator
```

The N/A rule is the one that catches people out. An N/A question is **removed from the total possible score**, not scored as zero. A section of ten questions where one is N/A is scored out of 18, not 20.

An unanswered question is the opposite, and the distinction is the whole point: it scores zero and stays in the denominator. A visit with ten of fifty-nine questions answered reads as a low score, not a high one over a small sample, and the figure climbs from zero towards the real one as the assessor works. Nothing about a **finished** assessment changes — with nothing missing, the two ways of counting agree exactly.

This is also what stops the obvious abuse. If silence removed a question, the way to certify would be to answer the easy questions and leave the rest blank. N/A does remove a question, which is precisely why `na_allowed` is set on five questions in the instrument and not on fifty-nine.

A visit with **no answers at all** is not scored at all. It has a denominator and no percentage, because "nobody has started this" and "this site scored zero" are different statements and a report that confuses them is worse than one that says nothing.

## Calculating

- **Section possible** = `2 × (count of non-NA answers in that section)`
- **Section 4 possible** = `2 × (count of non-NA answers across every pathogen instance)` — so it scales with the number of pathogens assessed
- **Section 5** — when the site refers no specimens the whole section is N/A and contributes nothing to either the score or the possible total
- **Percentage** = `(sum of section scores / sum of section possible) × 100`

Two implementation rules:

**Round to two decimal places, then band.** Banding on the rounded value means 89.995 becomes 90.00 and lands in Level 4. Banding on the unrounded value would put it in Level 3 — and the boundary cases are exactly where a certification dispute happens.

**Guard division by zero.** A fully-N/A assessment must not crash or produce `NaN`. It produces a `null` percentage and a `null` level — not a zero, and not Level 0. An unscorable assessment is not a failing one.

### The percentage never becomes a float until it is displayed

The obvious implementation is `round($score / $possible * 100, 2)`, and it works — until the TypeScript build has to agree with it. PHP's `round()` applies a pre-rounding correction that JavaScript's `Math.round` does not, so the two disagree on values sitting near a midpoint once binary floating point has finished with them. Two implementations disagreeing at exactly the boundary, on the number that decides whether a site is certified, is the defect this system can least afford.

So the percentage is carried as an **integer scaled by 10^dp**, computed by exact division with an explicit round-half-up, and banded by comparing scaled integers:

```
numerator = score × 100 × 10^dp
quotient  = numerator ÷ possible          (integer division)
remainder = numerator mod possible
if remainder × 2 ≥ possible: quotient += 1
```

Every step is reproducible in any language with 64-bit integers, which includes JavaScript at these magnitudes — a score of 500 scales to 5,000,000, six orders of magnitude below `Number.MAX_SAFE_INTEGER`.

Band thresholds are scaled the same way before comparison, so a band at 89.9 does not fail to match a score of exactly 89.9 because `89.9 * 100` is `8989.999999999999` in binary.

## Levels

| Level | % Score | Description |
|---|---|---|
| 0 | Below 40% | Needs improvement in all areas, immediate remediation |
| 1 | 40–59% | Needs improvement in specific areas |
| 2 | 60–79% | Partially eligible for site certification |
| 3 | 80–89% | Close to national site certification |
| 4 | 90% or above | Eligible for national site certification |

Band thresholds are **template data, not code** — the User's Guide states that countries localise them during tool customisation.

## Worked example

A site testing three pathogens, referring specimens, with two questions marked N/A:

| Section | Questions | Possible | Notes |
|---|---|---|---|
| 1 | 8 | 14 | 1.3 is N/A — single tester |
| 2 | 10 | 20 | |
| 3 | 9 | 16 | 3.9 is N/A — no national Hep B requirement |
| 4 | 23 × 3 | 138 | |
| 5 | 9 | 18 | |
| | | **206** | |

If the site scores 168 of 206, that is 81.55% — **Level 3**.

Assessing one pathogen instead of three, with no N/A and no referral, the possible total is 100. The denominator is genuinely dynamic; it cannot be hardcoded.

## Not Applicable — eligibility

!!! warning "Unresolved, pending client confirmation"
    The Checklist prints an N/A checkbox against all 59 questions. The User's Guide defines an N/A condition for only six cases:

    - **1.3** — the site has only one tester
    - **1.7**, **1.8** — new site, no previous assessment
    - **3.9** — no national Hepatitis B vaccination requirement
    - **4.10** — no equipment required for the test
    - **Section 5** — the entire section, where no specimen referral occurs

    Section 4's own header hedges: *"N/A is only an option if the question does not apply."*

    The platform implements this as a per-question `na_allowed` flag in the template, so the decision is data and can change without a deploy. The default is the restrictive list above.

    This matters because permitting N/A everywhere inflates percentages and undermines the Level 0–4 banding, which is the entire point of the instrument.

A comment is required whenever N/A is selected — the checklist asks assessors to state why the question does not apply.

## Implementation

The scoring engine is built **twice, deliberately**: in PHP server-side (authoritative) and in TypeScript client-side, because the assessor must see the score on-device before leaving the site. The User's Guide requires debriefing the site team with findings before the visit ends.

Two implementations of the same rules will drift unless something stops them. Three things do:

1. **The rules live in template data** — point values, `na_allowed`, band thresholds. What remains in code is summation with exclusions, which is small.
2. **A shared JSON fixture set** in `tests/fixtures/scoring/`, consumed by both the PHPUnit and Vitest suites. Any drift fails the build.
3. **Fixtures cover the boundaries explicitly** — N/A exclusion, multi-pathogen Section 4, whole-Section-5 N/A, every band boundary from both sides, a fully-N/A assessment, and zero pathogens.

The fixture format is documented in `tests/fixtures/scoring/README.md`. Both suites discover files by glob, so adding a case is adding a file — a case that needed a code change to run is a case that would not get added.

### Reported, not scored

Three things the engine refuses to resolve on its own, because each one silently inflates a percentage if handled any other way:

| | What it is | Why it is not just scored |
|---|---|---|
| `missing` | Expected but unanswered | Scored as zero and **kept in the denominator**, so a half-finished assessment reads as half-finished. Still reported, because a zero from silence and a zero from a *No* are different facts |
| `unexpected` | An answer the template does not expect here — a retired question code, a removed pathogen, a Section 5 answer left behind after the site was marked as referring nothing | Scoring it adds points the site never earned, on a question nobody asked |
| `violations` | Something the template forbids, chiefly N/A where `na_allowed` is false; also a duplicate or unrecognised response | Every N/A narrows the denominator, so permitting it freely lets a site certify by declaring questions inapplicable |

The engine returns these alongside the score rather than throwing. A running score on the device is **legitimately incomplete** — the assessor watches it while working, and an exception mid-visit would take away the total needed to debrief the site. The submission endpoint is what refuses, on `is_complete` and `is_valid`.

Computed scores are **snapshotted** into `assessment_scores` at submission and never recomputed from a live template. A certification level has to mean the same thing in a year's time, after the organisation has edited its template five times. `scoring_version` records which implementation produced the numbers, so a later correction to the engine can be identified rather than silently rewriting history.
