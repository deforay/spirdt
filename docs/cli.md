# CLI Reference

Every script in `bin/` carries a docblock and prints it with `--help`:

```bash
bin/setup --help
```

Most are also exposed as Composer scripts, which is the recommended way to run them. `composer run-script --list` prints all of them with descriptions.

Under Docker, prefix with `docker compose exec php`. Natively, run directly.

!!! tip "Passing flags through Composer"
    Composer needs `--` before script arguments:
    `composer setup -- --reset` and `composer migrate -- --status`.

## Setup and upgrade

### `bin/setup`

Takes a fresh clone to a running application: prerequisites, `.env` (generating `JWT_SECRET`), dependencies, database creation, migrations, then verification.

**Idempotent** — every step checks before acting.

| Flag | Effect |
|---|---|
| `--reset` | Drop every table first. Refuses on `APP_ENV=production`; requires typing the database name |
| `--yes` | Skip the typed confirmation. For CI or `exec -T` where there is no TTY. Still refuses on production |
| `--skip-deps` | Leave Composer alone |
| `--non-interactive` | Never prompt; fail rather than ask |

### `bin/upgrade`

The production path. Takes a database backup **first** and aborts if it fails, syncs code via `bin/refresh`, then runs migrations *unconditionally*, verifies, and reports versions before and after.

Migrations are deliberately **not** gated on the diff here — a previously failed run can leave pending work that this pull knows nothing about.

| Flag | Effect |
|---|---|
| `--dry-run` | Show every step, change nothing |
| `--skip-backup` | Skip the backup. This is the step that makes everything else reversible |
| `--skip-pull` | Migrate and verify only; code already updated |
| `--keep=N` | Backup retention count |

### `bin/refresh`

The developer loop: pull the latest code, then do only the work the diff actually requires.

- `composer install` runs **only** when `composer.lock` changed
- migrations run **only** when something under `migrations/` changed
- already at the target commit means the whole run is a fast no-op

It works out for itself whether to run follow-up commands natively or inside the Compose container.

A dirty working tree **aborts** rather than stashing — silently setting aside work in progress is a nasty surprise. Pass `--stash` to opt in. Pulls are `--ff-only`, so a diverged branch stops for a human instead of being quietly merged.

| Flag | Effect |
|---|---|
| `--status` | Local vs remote state; changes nothing |
| `--dry-run` | Print every step, execute none |
| `--stash` | Stash local changes first |
| `--branch=NAME` | Target a branch other than `main` |

## Diagnostics

### `bin/preflight`

Checks this machine can run the application: PHP version, extensions, `.env`, required keys, writable paths, database reachability, and whether migrations have run.

Runs identically inside Docker and on a bare host. `--quiet` prints only failures, for CI and hooks.

Exit code is 0 when everything passes, 1 otherwise.

### `bin/serve`

PHP's built-in server, for the native path. Development only.

```bash
bin/serve              # 127.0.0.1:8080
bin/serve 9000         # 127.0.0.1:9000
bin/serve 0.0.0.0:8080 # all interfaces — reach it from a phone or tablet
```

## Database

### `bin/migrate`

Applies pending migrations from `migrations/` in semver order, tracking the current version in `system_config.app_version`.

| Flag | Effect |
|---|---|
| `--status` | Current version and what is pending |
| `--dry-run` | Show what would run, execute nothing |
| `--verbose` | Report benign skips |

!!! warning "The runner exits 0 even when statements fail"
    Failures appear in the summary, but the exit code does not reflect them. `bin/setup` and `bin/upgrade` both parse the summary and refuse to continue on a non-zero failure count rather than trusting the exit code. Anything else calling `bin/migrate` should do the same.

### `composer db:backup`

Backup via [`amitdugar/db-tools`](https://github.com/amitdugar/db-tools), configured in `db-tools.php`. Writes zstd-compressed archives to `var/backups/db/` with a retention of 14.

db-tools also provides restore, verify and point-in-time recovery:

```bash
vendor/bin/db-tools list
```

See [Backup & Upgrade](operations.md) for the encryption caveat.

### `composer db:collation`

Normalises every table to the server-default utf8mb4 collation. Idempotent.

## Accounts

Assessors and administrators are created and managed **in the app**. Only two
things are done from a terminal, and both are things the app cannot do for
itself: standing an organisation up for the first time, and getting back into
one nobody can administer any more.

### `bin/provision-org`

Onboards an organisation: creates it, seeds the five system roles, and creates
its first administrator. Prints a generated password once.

```bash
bin/provision-org --code=zm-moh --name="Ministry of Health Zambia" \
    --admin-email=grace@moh.gov.zm --admin-name="Grace Phiri" \
    --country=ZM --timezone=Africa/Lusaka
```

Run without flags it asks for each value.

| Flag | Effect |
|---|---|
| `--code` | Short unique code, used at sign-in where one address exists in two organisations |
| `--name` | Display name |
| `--admin-email` / `--admin-name` | The first administrator |
| `--programme` | Join an existing programme by code, sharing its site registry. Omit for a programme of its own |
| `--country` | ISO 3166-1 alpha-2 |
| `--timezone` | IANA zone. Validated against the system list; defaults to UTC |
| `--date-format` | Defaults to `d/m/Y` |
| `--password` | Skips generation. Avoid it — this account opens an entire organisation |

**Refuses a code that already exists.** Provisioning that quietly reuses one is
how a new administrator ends up inside somebody else's tenant. Everything after
the first administrator happens in the app.

`--programme` is for the case where two organisations audit labs in the same
country and their results have to be comparable — they then share one site
registry, so both are provably assessing the same bench rather than two
similarly-named rows. Their assessments stay private either way. Omitting it
gives the organisation a programme of its own, which is the safe default: a
site list should only be shared because somebody decided to share it.

Timezone and date format are asked for rather than defaulted because the User's
Guide makes date format a per-country choice: `05/08/2026` is August or May
depending on who reads it, and the difference stays invisible until someone
disputes a certification level.

### `bin/recover-access`

Break-glass. For the cases where the app can no longer be used to fix itself:

- the only administrator forgot their password and nobody else can reset it;
- the only administrator was changed to a role that cannot administer;
- the only administrator was deactivated;
- failed sign-ins have throttled an account and the wait is not acceptable.

Start with the listing. It shows **every** organisation, including the ones with
no administrator at all — listing only the organisations that have one would
hide the exact situation this command exists for.

```console
$ bin/recover-access --list
demo  Demo
  warn    No administrator. Nobody can administer this organisation.
    bin/recover-access --org=demo --email=... --create-admin

zm-moh  Ministry of Health Zambia
        grace@moh.gov.zm    admin    active
```

Then one action at a time, so what was done is one line in the audit log rather
than a combination somebody has to reconstruct:

| Flag | Effect |
|---|---|
| `--reset-password` | New generated password, printed once. **Revokes every session that user holds** and reactivates the account |
| `--make-admin` | Gives an existing user the admin role, and reactivates them |
| `--create-admin` | Adds a new administrator. Needs `--name` |
| `--unlock` | Clears the failed sign-in attempts throttling an account. Changes no credential |
| `--ip` | With `--unlock`, also clears failures from an address |
| `--yes` | Skips the typed confirmation. Automation only |

Each action asks you to type `recover` before it runs, because all of them
change who can reach an organisation's assessments and none is undone by
running the command again.

**Every action is written to `audit_log`** with actor type `system`. On an audit
instrument, "who reset the administrator's password, and when" has to have an
answer better than "someone with a shell".

A password reset revokes refresh tokens on purpose. If the reason for the reset
was that somebody else had the old password, leaving their session alive makes
the reset cosmetic.

!!! warning "`--unlock` may need `--ip`"
    Sign-in throttles on email **or** address. Someone behind a shared
    connection can be locked out by a colleague's typing, and clearing their
    email then does nothing at all. `--unlock` reports any address still over
    the limit and tells you the flag to clear it — but find out *why* the
    address is failing first, because clearing one is also how a
    password-guessing run gets its allowance back.

This is deliberately not a general user-management command. It does those four
things and nothing else: a recovery tool that can do everything gets used for
everything, and then it stops being audited as an exception.

### `bin/dev/create-user`

**Local development only.** Creates or updates one user, and creates the
organisation and roles if they are missing — which is exactly why it does not
belong on a server: on a typo it invents an organisation rather than refusing.
Running it against an existing address resets that person's password and role
rather than creating a duplicate.

```bash
bin/dev/create-user --org=demo --email=jane@example.org --name="Jane Doe" --role=assessor
```

Roles: `superadmin`, `admin`, `assessor`, `viewer`, `site_user`. Prompts for the
password unless `--password` is given. Minimum twelve characters.

!!! note "Roles carry no permissions yet"
    The role key travels in the token and `AuthMiddleware` attaches it to the
    request, but nothing reads it. An admin token currently opens exactly the
    same routes as an assessor token.

## Review

### `bin/dev/review`

Runs an adversarial review pass against this repository's standing brief.

```bash
bin/dev/review                  # the working branch against main
bin/dev/review <commit-sha>     # one commit
bin/dev/review --uncommitted    # the working tree, before committing
```

The reviewing CLI is named by `$REVIEW_AGENT`, read from the untracked `.env`
and falling back to your shell profile — so the tool can be swapped without
editing anything, and no vendor name is committed. Only that one key is read
from `.env`: a reviewer reading a diff has no business inheriting the database
credentials or the JWT secret.

The brief itself lives in
[Engineering Standards](engineering-standards.md) and is extracted from there
at run time rather than duplicated here. Two copies of a checklist means the
one nobody edits is the one that runs — and the script fails loudly rather than
reviewing with no brief if that heading ever moves.

## Reference data

### `bin/dev/publish-template`

Loads an instrument from `resources/templates/` into the `templates` table and
publishes it. Platform-wide by default; `--org=<code>` publishes a copy for one
organisation, which template lookup then prefers.

```bash
bin/dev/publish-template
```

**Required before any device can sync.** The server scores against the copy in
this table, not the one bundled with the app, and a payload naming a template
that is not here is refused. Re-running replaces the definition for the same
code and version.

### `bin/dev/seed-sites`

Puts a few facilities and testing sites into an organisation, so the app has
something to assess before the registry screens exist.

```bash
bin/dev/seed-sites --org=demo
```

Matched by facility and name, so running it twice does not duplicate anything.

## Quality

| Command | What it does |
|---|---|
| `composer test` | Full PHPUnit suite |
| `composer test:unit` | Unit suite — no database, fast |
| `composer test:integration` | Integration suite |
| `composer test:feature` | Feature suite — full HTTP through the Slim stack |
| `composer phpstan` | Static analysis at level 6 |
| `composer cs:check` | Report style drift |
| `composer cs:fix` | Apply style fixes |
