# Installation

Three supported paths, and **all three are first-class**. The application only reads `DB_HOST`/`DB_PORT` from `.env` and neither knows nor cares whether MySQL is a Compose service or a local install, so moving between them is a configuration change, never a code change.

| Path | Use it when |
|---|---|
| [Docker](#option-a-docker) | You want the whole stack in one command and do not care what is inside it |
| [Native, PHP's own server](#option-b-native-no-docker) | Working on the code. Fastest loop, no web server to configure |
| [Apache and mod_php](#option-c-apache-and-mod_php) | You want the machine to run what a server runs |

## Common to all three

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
-- Global, and not an oversight. mysqldump reads INFORMATION_SCHEMA.FILES to
-- record tablespaces, which needs PROCESS across the server rather than on one
-- schema. Without it every backup is EMPTY and still reports success — see
-- docs/operations.md.
GRANT PROCESS ON *.* TO 'spirdt'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

Point `.env` at it — `DB_HOST=127.0.0.1`, `DB_PORT=3306`, and `DB_USER`/`DB_PASS` to match — then:

```bash
composer install
php bin/migrate
composer serve                  # http://127.0.0.1:8080

curl http://127.0.0.1:8080/api/health
```

The test database is migrated separately, and nothing does it for you:

```bash
DB_NAME=spirdt_test php bin/migrate
```

Skip it and the integration suite fails on a missing column the moment a
migration lands, which reads as a broken test rather than an unmigrated
database.

!!! note "Where `bin/setup` fits"
    `composer setup` is written for **provisioning a machine** — production, or
    a demo box — and takes a fresh clone to a running application in one
    unattended pass. It is safe to run here: it creates the database only if
    missing, never overwrites an existing `.env`, and drops tables only under
    `--reset`, which refuses on `APP_ENV=production` and asks you to type the
    database name.

    It is not *sufficient* here. It provisions one database, using credentials
    that must already exist, so a development machine still needs the user and
    both databases created by hand as above. That is the reason the manual
    steps are the documented path and not a fallback.

!!! warning "Use `127.0.0.1`, not `localhost`"
    `localhost` makes PHP attempt a unix socket connection, which fails confusingly when the socket path differs from what `php.ini` expects. An IP forces TCP.

`composer serve` runs PHP's built-in server. It is **development only** — single-threaded, and not for real traffic. `bin/serve 0.0.0.0:8080` binds all interfaces, which is how you reach it from a phone or tablet on the same network when testing the PWA.

## Option C — Apache and mod_php

Everything from Option B applies: the same PHP, the same MySQL, the same `.env`, the same `bin/migrate`. This replaces only what serves the requests, so do Option B first and confirm `composer preflight` is green. Debugging a web server and a database at the same time is two problems; doing them in order is one and then one.

Nothing needs building. `web/dist` is committed, so a fresh clone is already servable — see [Deployment](deployment.md#the-app-arrives-built).

### 1. Install the parts

On macOS with Homebrew:

```bash
brew install httpd php@8.4 mysql
brew services start mysql
```

`php@8.4` ships the Apache module at `$(brew --prefix)/opt/php@8.4/lib/httpd/modules/libphp.so`. Check that the module and the CLI are the same build — two PHP versions on one machine is a confusing way to spend an afternoon:

```bash
php -v
```

On Debian or Ubuntu, PHP 8.4 needs the ondrej PPA; distribution repositories do not carry it. Note that both now treat `mod_php` as the legacy path, and `php8.4-fpm` behind `mod_proxy_fcgi` is better supported. The application does not care either way.

### 2. Enable `mod_rewrite`

Uncomment it in `httpd.conf`, or on Debian:

```bash
a2enmod rewrite
```

Without it every URL returns 404. The application says so rather than leaving you to guess: `public/.htaccess` sets an `X-Spirdt-Error` header when the module is missing.

### 3. Configure the vhost

```bash
cp deploy/apache/spirdt.conf.example /opt/homebrew/etc/httpd/extra/spirdt.conf
```

Replace `{{ROOT}}`, `{{SERVER_NAME}}`, `{{PORT}}` and `{{PHP_PREFIX}}`, include it from `httpd.conf`, and add the hostname:

```bash
echo "127.0.0.1 spirdt.test" | sudo tee -a /etc/hosts
```

The file itself explains each decision, including the two that are easy to get wrong: `DocumentRoot` is `web/dist` rather than the checkout, and `CGIPassAuth` is what makes Bearer tokens survive the SAPI.

### 4. Let Apache write to `var/`

This is the step that is skipped and then debugged. Apache runs as the `User` in `httpd.conf` — `_www` on macOS, `www-data` on Debian — which is **not** the account that cloned the repository. So `var/log`, `var/cache` and `var/uploads` are owned by one user and written by another, and the failure surfaces as an application error with nothing pointing at permissions.

`composer preflight` cannot prove this either way, because it runs as you: a directory writable to it may still be closed to the server. What it can do is check the group-write bit, and it warns when that is unset:

```
WARN  var/ group-writable   not group-writable: var/log, var/cache …
```

Green there is not a guarantee. A warning is a near-certainty.

On a development machine, run Apache as yourself:

```apache
User  your-username
Group staff
```

On a server, give the group write instead:

```bash
sudo chown -R "$USER":www-data var
sudo chmod -R g+w var
```

### 5. Point `.env` at it

Two values differ from Option B:

```dotenv
APP_URL=http://spirdt.test:8080
CORS_ALLOWED_ORIGINS=
```

`CORS_ALLOWED_ORIGINS` is **empty and correct that way**. The vhost serves the app and the API from one origin, so no request the browser makes is cross-origin and there is nothing to allow. Listing your own origin is inert rather than wrong. See [Deployment](deployment.md) for the case where it is not empty.

### 6. Start it

```bash
brew services restart httpd
curl http://spirdt.test:8080/api/health
open http://spirdt.test:8080
```

### When every URL loops

`Request exceeded the limit of 10 internal redirects` means a rewrite target re-entered the rule that produced it, ten times, and Apache gave up. Almost always `DocumentRoot` is not `web/dist`, so `/index.html` resolves to nothing and the rule that rewrites to it matches its own output forever.

The supplied vhost guards against it, so the same mistake gives a 404 naming the missing file. To find it in a vhost that does not:

```apache
LogLevel alert rewrite:trace3
```

The log then names the rule on every pass.

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
