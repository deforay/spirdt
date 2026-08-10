# Installation

## Prerequisites

Docker needs only Docker and Docker Compose. Everything else needs these installed already — how you install them is your platform's business.

| | |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `intl`, `zip`, `mbstring`, `bcmath` |
| MySQL | 8, reachable over TCP |
| Composer | any current version |
| Apache | Option C only, with `mod_php` or `php-fpm` |
| Node | only if you are going to change the front end |

`composer preflight` checks the first three and names what is missing.

## The three paths

All first-class. The application reads `DB_HOST` and `DB_PORT` from `.env` and neither knows nor cares which one it is running under, so moving between them is a configuration change and never a code change.

| Path | Use it when |
|---|---|
| [Docker](#option-a-docker) | You want the whole stack in one command |
| [Native](#option-b-native) | Working on the code. Fastest loop, no web server to configure |
| [Apache and mod_php](#option-c-apache-and-mod_php) | You want the machine to run what a server runs |

## Common to all three

```bash
git clone git@github.com:deforay/spirdt.git
cd spirdt
cp .env.example .env
composer install
```

Set `JWT_SECRET` in `.env`. The application refuses to serve without at least 32 characters.

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

The app is built into `public/` and committed, so there is no front-end build step. You only need `npm ci` in `web/` if you are going to change it.

## Option A — Docker

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php composer setup

curl http://localhost:8080/api/health
```

Keep the shipped `DB_HOST=mysql` and `DB_PORT=3306`. nginx is on host port 8080 and MySQL on 3307, overridable with `HTTP_PORT` and `DB_EXPOSED_PORT` — 3307 so it does not collide with a MySQL already running locally.

## Option B — native

Create both databases and the user:

```sql
CREATE DATABASE IF NOT EXISTS spirdt      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS spirdt_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'spirdt'@'127.0.0.1' IDENTIFIED BY 'spirdt';
GRANT ALL PRIVILEGES ON spirdt.*      TO 'spirdt'@'127.0.0.1';
GRANT ALL PRIVILEGES ON spirdt_test.* TO 'spirdt'@'127.0.0.1';
GRANT PROCESS ON *.* TO 'spirdt'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Point `.env` at it — `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USER` and `DB_PASS` — then migrate **both** databases and run it:

```bash
php bin/migrate
DB_NAME=spirdt_test php bin/migrate
composer serve                  # http://127.0.0.1:8080
composer preflight
```

Three things that are not guessable:

**`GRANT PROCESS` is not decoration.** `mysqldump` reads `INFORMATION_SCHEMA.FILES` to record tablespaces and needs it server-wide. Without it the dump errors and still exits `0`, so every backup is empty and reports success. See [Operations](operations.md).

**The test database is migrated separately.** Nothing does it for you, and skipping it fails the integration suite on a missing column the moment a migration lands — which reads as a broken test rather than an unmigrated database.

**Use `127.0.0.1`, not `localhost`.** `localhost` makes PHP attempt a unix socket, which fails confusingly when the socket path differs from what `php.ini` expects.

!!! note "`bin/setup` is for provisioning a server"
    It takes a fresh clone to a running application in one unattended pass, and it is safe to run here. It is not *sufficient* here: it provisions the one database named in `.env` using credentials that must already exist, so a development machine still needs the user and the test database created by hand.

## Option C — Apache and mod_php

Do Option B first and get `composer preflight` green. This replaces only what serves the requests.

Written for Ubuntu. macOS works the same way with different paths and no `a2enmod`.

```bash
sudo a2enmod rewrite headers php8.4

sudo cp deploy/apache/spirdt.conf.example /etc/apache2/sites-available/spirdt.conf
sudo nano /etc/apache2/sites-available/spirdt.conf     # replace {{ROOT}}, {{SERVER_NAME}}, {{PORT}}
sudo a2ensite spirdt
sudo apache2ctl configtest

sudo chown -R "$USER":www-data var && sudo chmod -R g+w var

sudo systemctl reload apache2
curl http://spirdt.test/api/health
```

On a development machine add the hostname: `echo "127.0.0.1 spirdt.test" | sudo tee -a /etc/hosts`.

In `.env`, set `APP_URL` to the vhost and leave `CORS_ALLOWED_ORIGINS` **empty** — the vhost serves the app and the API from one origin, so nothing the browser sends is cross-origin.

Two things go wrong here, and both are quiet:

**A JSON 404 at `/` while `/api/health` works** means Apache reached `index.php` for the bare root. Slim's base path is `/api`, so it has no route for `/` and says so in JSON. The rewrite in `public/.htaccess` now serves `index.html` for `/` explicitly, so the remaining cause is that `index.html` is not there. Run `php bin/preflight`, which checks for it by name, and rebuild if it is missing.

**Set `DirectoryIndex index.html` in the vhost.** Without it Apache uses the global default, which on Ubuntu tries `index.html`, `index.cgi`, `index.pl` and then `index.php` — so a missing build silently serves the API front controller at the site's own address instead of failing.

**Use `Require all granted`, not `Order allow,deny`.** The latter is Apache 2.2 syntax and works on 2.4 only while `mod_access_compat` happens to be enabled.

**`DocumentRoot` is `public/`, not the checkout.** Everything is served from there — the API through `index.php`, the app through `index.html`, and `public/.htaccess` decides which. Point it at the checkout instead and every path rewrites until Apache gives up with `Request exceeded the limit of 10 internal redirects`. The shipped `.htaccess` guards against the loop, so the same mistake becomes a 404 naming the missing file. To trace it where that guard is absent: `LogLevel alert rewrite:trace3`.

**Apache runs as `www-data`, which did not clone the repository.** Hence the `chown` above. `composer preflight` cannot prove this either way — it runs as you — but it warns when `var/` is not group-writable, and that warning is a near-certainty rather than a hint.

For `php-fpm` instead of `mod_php`: enable `proxy_fcgi` rather than `php8.4` and uncomment the `SetHandler` block in the vhost template. Nothing in the application depends on the SAPI.

## Verifying

Any path should give you:

```json
{"status":"ok","app":"SPI-RDT Assessment Platform","version":"0.1.12","time":"..."}
```

When something looks wrong, `composer preflight` is the first thing to run. It checks the PHP version, extensions, `.env`, writable paths and database reachability — and names **which database target is configured**, since being pointed at the wrong one is the most common reason the application appears broken.

## Setup is idempotent

`bin/setup` checks before it acts at every step, so re-running after a failure resumes rather than breaking. That matters more than elegance: setup fails halfway on real machines — wrong password, port in use, MySQL still starting — and the fix has to be "run it again".

```bash
composer setup -- --reset
```

This drops every table first. It refuses outright when `APP_ENV=production`, and requires you to type the database name to confirm. Where there is no TTY — CI, or `docker compose exec -T` — add `--yes`.
