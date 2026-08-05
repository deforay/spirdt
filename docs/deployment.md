# Deployment

The application is two things served from one origin: a built single-page app,
and a PHP API under `/api`. Same origin is deliberate — the app calls `/api`
relatively, so there is no CORS to configure and no second hostname to keep a
certificate on.

The split is the same whichever web server you use:

| Path | Served from | Notes |
|---|---|---|
| `/api/*` | `public/index.php` | Slim calls `setBasePath('/api')`, so the prefix is not decorative |
| `/assets/*` | `web/dist/assets` | Fingerprinted by the build, cacheable for a year |
| everything else | `web/dist/index.html` | Unmatched paths are routes inside the app |

## Build the app first

Nothing serves `/` until this has been run:

```
cd web
npm ci
npm run build
```

That writes `web/dist`. Without it the web server returns 404 for the front
page, which is the correct and visible failure — an unbuilt app should not be
papered over.

## nginx

Already configured in `docker/nginx/default.conf`, which the Docker stack
mounts. Nothing to do beyond building the app and restarting nginx.

## Apache

The application runs on Apache with `mod_php` or with `php-fpm` behind
`mod_proxy_fcgi`. Nothing in the code depends on the SAPI.

**PHP 8.4 is a hard floor.** `composer.json` requires `^8.4`. On Debian and
Ubuntu that means the ondrej PPA — distribution repositories do not carry it.
Note that Debian and Ubuntu now treat `mod_php` as the legacy path; `php8.4-fpm`
with `mod_proxy_fcgi` is better supported and needs no change to the
application.

Enable `rewrite`, and `proxy_fcgi` if you are using FPM:

```
a2enmod rewrite
```

### Virtual host

Preferred over `.htaccess`: Apache re-reads `.htaccess` on every request, and a
vhost is parsed once at start-up.

```apache
<VirtualHost *:80>
    ServerName spirdt.example.org

    # The built app is the document root. The API is aliased in beneath it,
    # so both are same-origin and the app can call /api relatively.
    DocumentRoot /var/www/spirdt/web/dist

    Alias /api /var/www/spirdt/public

    <Directory /var/www/spirdt/public>
        Options -Indexes
        AllowOverride None
        Require all granted

        # Every route but sign-in is a Bearer token, so this line decides
        # whether the application works at all. mod_php exposes the header
        # through apache_request_headers(); CGI and FastCGI drop it, and the
        # symptom is that signing in works and every later call returns 401.
        CGIPassAuth On

        RewriteEngine On
        RewriteBase /api

        RewriteCond %{HTTP:Authorization} .
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    <Directory /var/www/spirdt/web/dist>
        Options -Indexes
        AllowOverride None
        Require all granted

        # An unknown path is a screen in the app, not a missing file. Without
        # this, reloading on any screen but the first returns 404 — and a
        # reload is exactly what someone does when a screen looks wrong.
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ /index.html [L]
    </Directory>

    # Fingerprinted filenames: a changed file is a changed URL.
    <LocationMatch "^/assets/">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </LocationMatch>

    # Security headers come from SecurityHeadersMiddleware and from nowhere
    # else. Setting them here as well sends each one twice.

    ErrorLog  ${APACHE_LOG_DIR}/spirdt-error.log
    CustomLog ${APACHE_LOG_DIR}/spirdt-access.log combined
</VirtualHost>
```

With `php-fpm` instead of `mod_php`, add this inside the `public` directory
block:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
</FilesMatch>
```

### Shared hosting

Where a vhost cannot be edited, `public/.htaccess` carries the same rewrite and
`Authorization` handling, and takes effect as long as `AllowOverride` is at
least `FileInfo`. The app itself still has to be served from somewhere — point
the document root at `web/dist` and alias `/api`, or place the two on separate
hosts and set `VITE_API_BASE` at build time.

### What must never be reachable

The document root is `web/dist` and `public/`, never the repository root.
Serving the repository root exposes `.env`, and with it the database password
and `JWT_SECRET` — and a leaked `JWT_SECRET` lets anyone mint a token for any
organisation. `public/.htaccess` denies `.env` and the composer files as a
second line, but the arrangement is the real protection.

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
