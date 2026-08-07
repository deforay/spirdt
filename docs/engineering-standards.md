# Engineering Standards

The bar this codebase is held to, and the review that enforces it.

## 1. Adversarial review

Run before pushing, not after somebody asks:

```bash
bin/dev/review                  # the working branch against main
bin/dev/review <commit-sha>     # one commit
bin/dev/review --uncommitted    # the working tree, before committing
```

The reviewing CLI is named by `$REVIEW_AGENT`, and `$REVIEW_AGENT_ARGS` carries
whatever it needs between its own name and the prompt — a review subcommand for
one tool, a non-interactive flag for another. Both are read from the untracked
`.env`, falling back to your shell profile.

Neither is written down here. The tool can be swapped without editing anything,
and no vendor name enters the repository. The *shape* of the invocation is kept
configurable for the same reason as the name: baking one in would mean the
script only works with the tool it was written against, which is the thing
being avoided.

**The review brief** — single source of truth, extracted verbatim by the script, so it cannot drift:
> "You are reviewing a change to SPI-RDT: an offline-first site-assessment platform for rapid diagnostic testing, with a shared-schema multi-tenant API. Do not summarize the code. Find: (1) any query reaching tenant-scoped data without the organisation scope, or the registry without the programme scope, and any use of withoutScope/acrossOrganizations/acrossProgrammes that is not justified in a comment; (2) any scoring path that could make PHP and TypeScript disagree — floating point, rounding before banding, N/A treated as zero rather than excluded; (3) anything that can lose an assessor's work: a write not persisted immediately, reliance on beforeunload, a sync that clears a dirty flag without checking the revision it sent, a retry that could duplicate a visit; (4) upload handling that trusts the client — a type taken from a header rather than sniffed and decoded, a filename that reaches a path, a checksum supplied rather than computed; (5) a route whose role gate is missing or wider than the data it exposes, and any authenticated route reachable while must_change_password is set; (6) migrations that are not re-runnable, or that drop or rewrite data an audit trail depends on; (7) tests that assert the happy path but would still pass if the invariant were deleted. Rank findings by severity. If you find nothing in a category, say 'clear' — don't pad."

**Where the second opinion matters most:** the scoring engines and their shared fixtures, the tenancy traits and `TenantContext`, the sync path on both sides, attachment upload, and every migration. Routine CRUD does not need double review — don't ritualize it into overhead.

**Discipline rule:** the same bar applies to every change regardless of how it was written. Nothing lands on "it runs". The tests and the review pass *are* the bar.

**Stopping rule:** a full pass returning zero critical findings. Not zero findings — zero critical. Lesser ones are fixed forward on `main` like ordinary work, because a branch held open for them accumulates more novel code than the next review can retire.

If a pass finds a critical issue, run another. **If a third pass finds another, stop reviewing and split the branch** — that is evidence the branch carries more novel risk than one review cycle can retire, and the answer is smaller units of work rather than harder review.

## 2. What a finding is

A finding is a defect with a failure scenario: concrete inputs or state, and the
wrong output or lost data that results. "This could be clearer" is not a
finding. Neither is a rule this repository has already decided against — the
docs below record those decisions, and a reviewer that raises one is owed a
rebuttal rather than a change.

## 3. The invariants worth knowing

| Document | What it settles |
|---|---|
| `docs/data-model.md` | The two scopes — organisation for audit data, programme for the registry — and why `organization_id` on the registry is provenance rather than scope |
| `docs/scoring.md` | Integer arithmetic, N/A excluded from both numerator and denominator, rounding to 2dp *before* banding |
| `docs/architecture.md` | Offline-first constraints, and why the device is the source of truth until acknowledged |
| `docs/signatures.md` | Why media uploads on its own channel, and what is not believed about an upload |
| `docs/templates.md` | Instruments are versioned documents; an assessment pins the version it answered |
| `docs/i18n.md` | App wording versus instrument wording, and that they fall back independently |

## 4. Gates

Everything below passes before a push:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run
cd web && npm run typecheck && npm test && npm run build
```

Static analysis findings are fixed at the cause. Suppressions, baselines and
inline overrides are not how this codebase gets to green.

The build is a gate rather than a formality here, because `web/dist` is
committed and deployed as it stands. Stage it with the source it came from —
see [Deployment](deployment.md).
