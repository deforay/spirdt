# SPI-RDT Assessment Platform

Site assessment platform for the **Stepwise Process for Improving the Quality of Rapid Diagnostic Testing (SPI-RDT)** — a quality audit instrument for point-of-care rapid diagnostic testing sites, scoring each site toward a national certification level.

Replaces a paper/Word checklist and supersedes the older ODK-based SPI-RRT tool.

> **Documentation:** <https://deforay.github.io/spirdt/>
>
> **Start here:** [`docs/design-brief.md`](docs/design-brief.md) is the authoritative brief — the domain, the scoring rules, the architecture decisions, and the conventions this codebase follows. Read it before writing code.

---

## Stack

| Layer | Choice |
|---|---|
| Backend | Slim 4, PHP 8.4, MySQL 8.4 |
| Frontend | Vue 3 + Vite + TypeScript *(not yet scaffolded)* |
| Auditor app | Offline-first PWA *(not yet scaffolded)* |
| Runtime | Docker Compose, or native PHP + MySQL |
| Backups | `amitdugar/db-tools` (zstd) |

---

## Getting started

Two supported paths. **Both are first-class** — the application only reads
`DB_HOST`/`DB_PORT` from `.env` and neither knows nor cares whether MySQL is a
Compose service or a local install. Switching between them is a `.env` change,
never a code change.

Common to both:

```bash
cp .env.example .env

# JWT_SECRET must be set; boot fails fast without it.
php -r "echo bin2hex(random_bytes(32));"     # paste into .env
# no PHP on the host? use:  openssl rand -hex 32
```

### Option A — Docker

Requires only Docker and Docker Compose.

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php composer preflight
docker compose exec php composer migrate

curl http://localhost:8080/api/health
```

Keep the shipped defaults in `.env`: `DB_HOST=mysql`, `DB_PORT=3306`.

| Service | Host port | Override |
|---|---|---|
| nginx | 8080 | `HTTP_PORT` |
| MySQL | 3307 | `DB_EXPOSED_PORT` |

MySQL is exposed on 3307 so it doesn't collide with a MySQL you already run
locally. If 3307 is taken too — another Compose project, typically — set
`DB_EXPOSED_PORT` to anything free.

### Option B — native, no Docker

Requires PHP 8.4 with `pdo_mysql`, `intl`, `zip`, `mbstring`, `bcmath`, plus a
MySQL 8 you can reach and Composer.

```bash
# One-time: create the databases and a user
mysql -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS spirdt      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS spirdt_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'spirdt'@'127.0.0.1' IDENTIFIED BY 'spirdt';
GRANT ALL PRIVILEGES ON spirdt.*      TO 'spirdt'@'127.0.0.1';
GRANT ALL PRIVILEGES ON spirdt_test.* TO 'spirdt'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# Point .env at it: DB_HOST=127.0.0.1, DB_PORT=3306, DB_USER/DB_PASS to match

composer install
composer preflight
composer migrate
composer serve                  # http://127.0.0.1:8080

curl http://127.0.0.1:8080/api/health
```

Use `127.0.0.1`, not `localhost` — `localhost` makes PHP try a unix socket,
which fails confusingly when the socket path differs from what `php.ini`
expects.

`composer serve` runs PHP's built-in server, which is **development only** —
single-threaded and not for real traffic. `bin/serve 0.0.0.0:8080` binds all
interfaces, which is how you reach it from a phone or tablet on the same
network when testing the PWA.

### Verifying either path

```json
{"status":"ok","app":"SPI-RDT Assessment Platform","version":"0.1.0","time":"..."}
```

`composer preflight` is the first thing to run when something looks wrong. It
checks the PHP version, extensions, `.env`, writable paths, and database
reachability — and it names which target you are pointed at, since being
pointed at the wrong one is the most common reason the app appears broken.

---

## Common commands

Native, run directly. Under Docker, prefix with `docker compose exec php`.

| Command | What it does |
|---|---|
| `composer setup` | First-run setup. Idempotent — safe to re-run |
| `composer setup -- --reset` | Drop every table and rebuild (refuses on production) |
| `composer app:upgrade` | Backup, sync code, migrate, verify |
| `composer db:backup` | Backup via db-tools |
| `composer refresh` | Pull latest, then install/migrate only if needed |
| `composer refresh -- --status` | Local vs remote state, changes nothing |
| `composer preflight` | Check this machine can run the app |
| `composer serve` | Built-in server on 8080 (native path, dev only) |
| `composer migrate` | Apply pending migrations |
| `composer migrate -- --status` | Current version and what's pending |
| `composer migrate -- --dry-run` | Show what would run, execute nothing |
| `composer test` | Full PHPUnit suite |
| `composer test:unit` | Unit suite — no DB, fast |
| `composer phpstan` | Static analysis |
| `composer cs:check` | Report style drift |
| `composer cs:fix` | Apply style fixes |

`composer run-script --list` prints every script with a description. Every
`bin/` script takes `--help` and prints its own docblock.

### Setup, upgrade and backup

`bin/setup` takes a fresh clone to a running application: prerequisites, `.env`
(generating `JWT_SECRET`), dependencies, database creation, migrations, then
verification. It is **idempotent** — every step checks before acting, so when
it fails halfway on a real machine the fix is to run it again.

`bin/upgrade` is the production path: it takes a **database backup first** and
aborts if that fails, syncs code via `bin/refresh`, then runs migrations
*unconditionally* — never gated on the diff, because a previously failed run
can leave pending work this pull knows nothing about.

Backups use [`amitdugar/db-tools`](https://github.com/amitdugar/db-tools), the
same tool as the other house projects, configured in `db-tools.php`. It also
provides restore, verify and PITR — see `vendor/bin/db-tools list`.

> **The php image ships MySQL's own client, not the distro package.** On both
> Alpine and Debian, `mysql-client` is MariaDB's client, and it cannot talk to a
> default MySQL 8.4 server at all: it rejects the server's self-signed TLS
> certificate, and it has no `caching_sha2_password` plugin. The Dockerfile
> copies the real client from the `mysql:8.4` image. See the comment there.

> **Backups are currently unencrypted.** db-tools encrypts by default, but
> supplying an encryption password produces no archive at all, so `--no-encrypt`
> is passed explicitly — a backup step that silently writes nothing is worse
> than an unencrypted one. Revisit alongside a key-management story.

### Staying up to date

```bash
composer refresh
```

Fetches, fast-forwards, and then gates the expensive work on what the diff
actually contains — `composer install` runs only when `composer.lock` changed,
and migrations only when something under `migrations/` changed. If the checkout
is already at the target commit the whole thing is a fast no-op.

It figures out for itself whether to run those follow-up commands natively or
inside the Compose container, so it is the same command on both paths.

A dirty working tree **aborts** rather than stashing — silently setting aside
work in progress is a nasty surprise. Pass `--stash` to opt in. Pulls are
`--ff-only`: a diverged branch is something a human should look at, not
something a refresh script should quietly resolve.

---

## Layout

```
bin/            CLI scripts. Each carries a docblock; --help prints it.
db/seeds/       production-bootstrap.sql + demo fixtures
docker/         Dockerfile, php.ini, nginx conf
docs/           mkdocs site sources — published to GitHub Pages
resources/      source/ (the two source checklist documents)
migrations/     Plain SQL, semver-ordered, idempotent
public/         Web root — front controller only
routes/         api.php + api/*.php, split by audience
src/            PSR-4 App\
tasks/          Crunz schedules
tests/          Unit/, Integration/, Feature/
var/            log/, cache/, exports/, backups/, uploads/
```

---

## Conventions

Carried over from the house reference project (`~/www/house-reference`) — follow them rather than inventing alternatives.

**Migrations** are plain SQL, named `<semver>-<slug>.sql`, applied in semver order by `bin/migrate` and tracked in `system_config.app_version`. Common DDL is routed through idempotent helpers that check `information_schema` first, so a retried deploy is safe. Every migration opens with a comment explaining *why*, not just what.

**Logging** goes through Monolog with a `UidProcessor`, so every line emitted while handling one request shares a UID. Services that don't take a `LoggerInterface` use the `App\Helper\Log` static gateway. **`error_log()` is banned everywhere except `src/Helper/Log.php`** — the pre-commit hook enforces it.

**Errors** always leave as the same JSON envelope, whatever threw. Detail is exposed only when `APP_DEBUG` is on; in production a 5xx returns a reference ID and the real message goes to the log under the request UID.

**Housekeeping** will live in one idempotent `bin/housekeeping` sweep with retention policy in a single array. Audit-bearing tables — assessments, answers, findings, scores, raw submissions, audit log — are never pruned.

### Non-negotiables

- **Tenant isolation is the top security concern.** Tenant is resolved from the authenticated user in middleware — never from a request parameter. See `docs/design-brief.md` §5.
- **Maintainability over smart code.** If a junior developer can't follow it in a year, it's wrong.

---

## Status

Scaffolding stage. Working on both the Docker and native paths: Slim bootstrap with DI and middleware, migration runner, preflight doctor, `/api/health`, test harness, static analysis, pre-commit hook.

Next, in order: the remaining baseline migrations, the template JSON schema, the seeded base SPI-RDT template, and the scoring engine. Phase 1 scope and its definition of done are in `docs/design-brief.md` §10.
