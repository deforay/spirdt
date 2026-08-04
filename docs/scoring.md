# Scoring

The output of an assessment is a **certification level**. Scoring bugs are therefore the highest-severity class of defect in this system, and the rules below are specified precisely rather than left to implementation.

## Response values

```
Y  (Yes)            = 2 points
P  (Partial)        = 1 point
N  (No)             = 0 points
NA (Not Applicable) = excluded from BOTH numerator and denominator
```

The N/A rule is the one that catches people out. An N/A question is **removed from the total possible score**, not scored as zero. A section of ten questions where one is N/A is scored out of 18, not 20.

## Calculating

- **Section possible** = `2 × (count of non-NA answers in that section)`
- **Section 4 possible** = `2 × (count of non-NA answers across every pathogen instance)` — so it scales with the number of pathogens assessed
- **Section 5** — when the site refers no specimens the whole section is N/A and contributes nothing to either the score or the possible total
- **Percentage** = `(sum of section scores / sum of section possible) × 100`

Two implementation rules:

**Round to two decimal places, then band.** Banding on the rounded value means 89.995 becomes 90.00 and lands in Level 4. Banding on the unrounded value would put it in Level 3 — and the boundary cases are exactly where a certification dispute happens.

**Guard division by zero.** A fully-N/A assessment must not crash or produce `NaN`.

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

The scoring engine is built **twice, deliberately**: in PHP server-side (authoritative) and in TypeScript client-side, because the auditor must see the score on-device before leaving the site. The User's Guide requires debriefing the site team with findings before the visit ends.

Two implementations of the same rules will drift unless something stops them. Three things do:

1. **The rules live in template data** — point values, `na_allowed`, band thresholds. What remains in code is summation with exclusions, which is small.
2. **A shared JSON fixture set** in `tests/fixtures/scoring/`, consumed by both the PHPUnit and Vitest suites. Any drift fails the build.
3. **Fixtures cover the boundaries explicitly** — N/A exclusion, multi-pathogen Section 4, whole-Section-5 N/A, every band boundary (39.99/40.00, 59.99/60.00, 79.99/80.00, 89.99/90.00), a fully-N/A assessment, and zero pathogens.

Computed scores are **snapshotted** into `assessment_scores` at submission and never recomputed from a live template. A certification level has to mean the same thing in a year's time, after the organisation has edited its template five times.
