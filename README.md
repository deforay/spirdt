# SPI-RDT Assessment Platform

Site assessment platform for the **Stepwise Process for Improving the Quality of Rapid Diagnostic Testing (SPI-RDT)** — a quality audit instrument for point-of-care rapid diagnostic testing sites, scoring each site toward a national certification level.

Replaces a paper checklist and supersedes the older ODK-based SPI-RRT tool.

## Documentation

**<https://deforay.github.io/spirdt/>** — everything below the surface lives there, and this file deliberately does not repeat it. Two copies of an instruction drift, and the copy nobody publishes is the one that goes stale.

| If you want to | Read |
|---|---|
| Set this up on a machine | [Installation](https://deforay.github.io/spirdt/getting-started/) |
| Know why it is built this way | [Design brief](https://deforay.github.io/spirdt/design-brief/) |
| Understand the scoring | [Scoring](https://deforay.github.io/spirdt/scoring/) |
| Change the schema | [Data model](https://deforay.github.io/spirdt/data-model/) |
| Put it on a server | [Deployment](https://deforay.github.io/spirdt/deployment/) |
| Run a backup or an upgrade | [Operations](https://deforay.github.io/spirdt/operations/) |
| Work on the front end | [Design](https://deforay.github.io/spirdt/design/) |
| Know what is left | [What is left](https://deforay.github.io/spirdt/todo/) |

The sources are in `docs/`, published to GitHub Pages on push to `main`.

## Stack

| Layer | Choice |
|---|---|
| Backend | Slim 4, PHP 8.4, MySQL 8.4 |
| Assessor app | Vue 3, Vite, TypeScript. Offline-first, Dexie over IndexedDB |
| Styling | Tailwind v4, Reka UI primitives, Phosphor icons |
| Runtime | Apache with mod_php, nginx, Docker Compose, or PHP's own server |
| Backups | `amitdugar/db-tools` (zstd) |

## Getting started

Three supported paths, all first-class. The application reads `DB_HOST` and `DB_PORT` from `.env` and neither knows nor cares which one it is running under.

| Path | Use it when |
|---|---|
| Docker | You want the whole stack in one command |
| Native, PHP's own server | Working on the code. Fastest loop, no web server to configure |
| Apache and mod_php | You want the machine to run what a server runs |

On a bare Ubuntu server there is a fourth route and it is one command — `bin/setup.sh` installs the stack, clones, creates the database, writes the vhost, gets a certificate and schedules the nightly sweep. See [Deployment](https://deforay.github.io/spirdt/deployment/).

Each of the three above is written out step by step in [Installation](https://deforay.github.io/spirdt/getting-started/). Three things there are worth knowing before you start, because none of them are guessable:

- The **test database is migrated separately** — `DB_NAME=spirdt_test php bin/migrate`. Nothing does it for you, and skipping it fails the integration suite on a missing column.
- The app user needs a **global `PROCESS` grant**. Without it `mysqldump` errors and still exits `0`, so every backup is empty and reports success.
- **`bin/setup` is for provisioning a server**, not for a development machine. It is safe to run, and it does not finish the job.

The app is built into `public/`, the document root, and committed — so a fresh clone serves it with no build step. Change anything under `web/src` and you must rebuild and stage `public/` with it. A pre-commit hook refuses the commit otherwise, and that hook is installed by `composer install`.

## Common commands

Run directly. Under Docker, prefix with `docker compose exec php`.

| Command | What it does |
|---|---|
| `composer preflight` | Check this machine can run the app |
| `composer serve` | Built-in server on 8080. Development only |
| `composer migrate` | Apply pending migrations |
| `composer refresh` | Pull, then install and migrate only if the diff needs it |
| `composer test` | Full PHPUnit suite |
| `composer test:unit` | Unit suite — no database, fast |
| `composer phpstan` | Static analysis |
| `composer cs:fix` | Apply style fixes |
| `composer db:backup` | Backup via db-tools |
| `composer housekeeping` | The nightly sweep: backup, expired tokens, old exports |
| `composer app:upgrade` | Backup, sync code, migrate, verify. The production path |
| `npm --prefix web test` | Device-side suite |
| `npm --prefix web run build` | Rebuild the app into `public/` |

`composer run-script --list` prints every script with a description, and every `bin/` script takes `--help` and prints its own docblock. The full reference is in [CLI](https://deforay.github.io/spirdt/cli/).

## Layout

```
bin/            CLI scripts. Each carries a docblock; --help prints it
deploy/apache/  vhost template, annotated
docker/         Dockerfile, php.ini, nginx conf
docs/           mkdocs sources — published to GitHub Pages
migrations/     Plain SQL, semver-ordered, idempotent
public/         Web root. API front controller + the built app, committed
resources/      Instrument templates and their JSON schema
routes/         api.php + api/*.php, split by audience
src/            PSR-4 App\
tests/          Unit/, Integration/, Feature/, fixtures/
var/            log/, cache/, exports/, backups/, uploads/
web/            Vue app sources. Builds into public/
```

## Two hard limits

- **Tenant isolation is the top security concern.** The tenant is resolved from the authenticated user in middleware, never from a request parameter.
- **Maintainability over clever code.** If a junior developer cannot follow it in a year, it is wrong.

Both are expanded, with the reasoning, in the [design brief](https://deforay.github.io/spirdt/design-brief/).

## Status

In development, and usable end to end: an assessor can sign in offline, work a visit through all five sections, record findings, sign, and sync. The server scores what arrives, snapshots the result and refuses an incomplete or invalid submission.

Not built yet: the report and certificate, the dashboard, photograph capture, and bulk registry import. [What is left](https://deforay.github.io/spirdt/todo/) is kept current and says why for each.
