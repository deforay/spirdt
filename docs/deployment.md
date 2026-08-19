# Deployment

The application is two things served from one origin: a built single-page app,
and a PHP API under `/api`. Same origin is deliberate — the app calls `/api`
relatively, so there is no CORS to configure and no second hostname to keep a
certificate on.

The split is the same whichever web server you use:

| Path | Served from | Notes |
|---|---|---|
| `/api/*` | `public/index.php` | Slim calls `setBasePath('/api')`, so the prefix is not decorative |
| `/assets/*` | `public/assets` | Fingerprinted by the build, cacheable for a year |
| everything else | `public/index.html` | Unmatched paths are routes inside the app |

## One command, on a bare server

Everything below is what the installer automates, and reading it is worth the
five minutes whether or not you run the script. On an Ubuntu LTS box with
nothing on it yet:

```bash
wget -O spirdt-setup.sh https://raw.githubusercontent.com/deforay/spirdt/main/bin/setup.sh
sudo bash spirdt-setup.sh
```

It installs the stack, clones the repository, creates the database with a
generated password, writes `.env`, renders the vhost from the example in
`deploy/apache/`, obtains a certificate, sets permissions, schedules the
nightly sweep, and offers to create the first organisation. It is idempotent:
run it again after a failure.

Afterwards, `sudo bash bin/upgrade.sh` is the upgrade path. Both are described
in the [CLI reference](cli.md#setup-and-upgrade).

The rest of this page is the manual route, and what the script is doing.

## The app arrives built

`public/` is committed. A checkout is deployable as it stands: Apache or nginx,
PHP, a database, and nothing else. Node is a development dependency, not a
deployment one.

That is the point of tracking it. A server that has to build the app needs a
toolchain on it whose only output is files the developer's machine already
produced — and it needs that toolchain to keep working, on a box nobody logs
into between releases.

The build is portable because it holds nothing about where it runs. The app
calls `/api` relatively, so one artefact is correct on every host. Setting
`VITE_API_BASE` at build time would bake an origin into it and end that, which
is why nothing sets it.

The cost is that source and build have to move together. A commit that changes
`web/src` without rebuilding into `public/` leaves the server running the
previous version of the app, with nothing on screen to say so. The pre-commit hook
refuses that commit; `.gitattributes` keeps the bundle out of diffs and out of
merges, since a conflict inside a minified chunk has no resolution but a
rebuild.

So, after changing anything under `web/src`:

```
cd web
npm ci
npm run build
```

and commit `public/` alongside the source.

## nginx

Already configured in `docker/nginx/default.conf`, which the Docker stack
mounts. Nothing to do beyond building the app and restarting nginx.

## Apache

Runs with `mod_php` or with `php-fpm` behind `mod_proxy_fcgi`. Nothing in the
code depends on the SAPI.

**PHP 8.4 is a hard floor** — `composer.json` requires `^8.4`. On Debian and
Ubuntu that means the ondrej PPA, and both now treat `mod_php` as the legacy
path, so `php8.4-fpm` is the better-supported choice there.

```
sudo a2enmod rewrite headers php8.4     # or proxy_fcgi, for FPM
```

### Virtual host

A ready copy with placeholders lives at `deploy/apache/spirdt.conf.example`,
annotated with the reason for each line. Stripped of commentary it is this:

```apache
<VirtualHost *:80>
    ServerName spirdt.example.org

    DocumentRoot "/var/www/spirdt/public"
    DirectoryIndex index.html index.php

    <Directory "/var/www/spirdt/public">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
        CGIPassAuth On
    </Directory>
</VirtualHost>
```

That is the whole thing, and it is short for a reason. `public/` is the
document root: it holds the API's front controller, the built app, and the
`.htaccess` that decides which of the two answers a request. There is no second
root to keep in step and no `Alias` to get wrong.

`CGIPassAuth` is the one line that is not obvious. Every route but sign-in
carries a Bearer token; `mod_php` exposes the header through
`apache_request_headers()` and CGI and FastCGI drop it. Getting it wrong looks
like an authentication bug — signing in works, and every call after it returns
401.

`AllowOverride All` is what lets `public/.htaccess` apply. `FileInfo` would be
enough, but `All` is what a stock Ubuntu vhost uses and matching it means one
fewer thing that differs from every other site on the box.

For `php-fpm`, add this inside the directory block:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
</FilesMatch>
```

### Shared hosting

Point the document root at `public/` and there is nothing else to do — the
`.htaccess` shipped there carries the routing, the `Authorization` handling and
the cache headers, and takes effect wherever `AllowOverride` is at least
`FileInfo`.

### What must never be reachable

The document root is `public/`, never the repository root. Serving the
repository root exposes `.env`, and with it the database password and
`JWT_SECRET` — and a leaked `JWT_SECRET` lets anyone mint a token for any
organisation. `public/.htaccess` denies `.env` and the composer files as a
second line, but the document root is the real protection.

Check it after any change — and check the **body**, not the status. The
single-page fallback answers every unmatched path with the app's HTML, so
`/.env` returns `200 text/html` on a correctly configured host. A status code
alone tells you nothing here:

```
curl -s https://your-host/.env | head -c 40
```

`<!doctype html>` is correct. Anything containing `DB_PASS` or `JWT_SECRET`
means the document root is wrong, and both secrets should be rotated.


## After deploying

```
composer install --no-dev --optimize-autoloader
php bin/migrate
php bin/dev/publish-template
composer preflight
```

`publish-template` matters more than it looks: the server scores against the
template in the database rather than the one bundled with the app, and a
missing row is reported as a refused sync days later rather than as a
deployment failure.

Then schedule the sweep, which is the one thing a fresh installation has no
way to ask for:

```
17 2 * * * cd /var/www/spirdt && /usr/bin/php bin/housekeeping >> /var/www/spirdt/var/log/housekeeping.log 2>&1
```

It takes the nightly backup and prunes what is safe to prune. See
[Housekeeping](operations.md#housekeeping).
