# Installation

Two supported paths, and **both are first-class**. The application only reads `DB_HOST`/`DB_PORT` from `.env` and neither knows nor cares whether MySQL is a Compose service or a local install, so moving between them is a configuration change, never a code change.

## Common to both

```bash
git clone git@github.com:deforay/spirdt.git
cd spirdt
cp .env.example .env
```

`JWT_SECRET` must be set — the application refuses to serve without at least 32 characters. `bin/setup` generates one for you, or do it by hand:

```bash
php -r "echo bin2hex(random_bytes(32));"
# no PHP on the host? use:
openssl rand -hex 32
```

## Option A — Docker

Requires only Docker and Docker Compose.

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php composer setup

curl http://localhost:8080/api/health
```

Keep the shipped defaults in `.env`: `DB_HOST=mysql`, `DB_PORT=3306`.

### Ports

| Service | Host port | Override |
|---|---|---|
| nginx | 8080 | `HTTP_PORT` |
| MySQL | 3307 | `DB_EXPOSED_PORT` |

MySQL is exposed on 3307 so it does not collide with a MySQL already running locally. If 3307 is taken too — another Compose project, typically — set `DB_EXPOSED_PORT` to anything free.

## Option B — native, no Docker

Requires PHP 8.4 with `pdo_mysql`, `intl`, `zip`, `mbstring` and `bcmath`, a reachable MySQL 8, and Composer.

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
```

Point `.env` at it — `DB_HOST=127.0.0.1`, `DB_PORT=3306`, and `DB_USER`/`DB_PASS` to match — then:

```bash
composer install
composer setup
composer serve                  # http://127.0.0.1:8080

curl http://127.0.0.1:8080/api/health
```

!!! warning "Use `127.0.0.1`, not `localhost`"
    `localhost` makes PHP attempt a unix socket connection, which fails confusingly when the socket path differs from what `php.ini` expects. An IP forces TCP.

`composer serve` runs PHP's built-in server. It is **development only** — single-threaded, and not for real traffic. `bin/serve 0.0.0.0:8080` binds all interfaces, which is how you reach it from a phone or tablet on the same network when testing the PWA.

## Verifying

Either path should give you:

```json
{"status":"ok","app":"SPI-RDT Assessment Platform","version":"0.1.4","time":"..."}
```

When something looks wrong, `composer preflight` is the first thing to run:

```
  PASS  PHP >= 8.4                 8.4.24
  PASS  ext-pdo_mysql
  PASS  ext-zip
  PASS  JWT_SECRET set
  PASS  DB target                  127.0.0.1:3306/spirdt (local/remote server)
  PASS  DB reachable
  PASS  Schema migrated            version 0.1.4
```

It checks the PHP version, extensions, `.env`, writable paths and database reachability — and it names **which database target is configured**, since being pointed at the wrong one is the most common reason the application appears broken.

## Setup is idempotent

`bin/setup` checks before it acts at every step, so re-running after a failure resumes rather than breaking. This matters more than elegance: setup fails halfway on real machines — wrong password, port in use, MySQL still starting — and the fix has to be "run it again".

To rebuild from scratch during development:

```bash
composer setup -- --reset
```

This drops every table first. It refuses outright when `APP_ENV=production`, and requires you to type the database name to confirm. Where there is no TTY — CI, or `docker compose exec -T` — add `--yes`.
