#!/usr/bin/env bash
#
# setup.sh — a bare Ubuntu server to a running SPI-RDT installation.
#
#   wget -O spirdt-setup.sh https://raw.githubusercontent.com/deforay/spirdt/main/bin/setup.sh
#   sudo bash spirdt-setup.sh
#
# HOW THIS DIFFERS FROM bin/setup — they are not duplicates, and the
# distinction is the point:
#
#   bin/setup (PHP) takes a CHECKOUT to a working application: .env, composer,
#   database, migrations. It needs PHP to run and assumes the machine is
#   already a machine that can serve the thing.
#
#   bin/setup.sh (this) takes a MACHINE to a server: Apache, PHP, MySQL, a
#   vhost, a certificate, permissions, a nightly sweep. Then it calls bin/setup
#   for the application half rather than repeating it, so the server and a
#   developer's laptop are set up by the same code from the same step onward.
#
# IDEMPOTENT. Run it again after a failure and it resumes: every step checks
# before it acts. This matters more than elegance — setup fails halfway on real
# machines (a hostname that does not resolve yet, MySQL still starting, a
# mistyped path) and the fix has to be "run it again".
#
# Usage:
#   sudo bash bin/setup.sh                       ask for what it needs
#   sudo bash bin/setup.sh --path=/var/www/spirdt --host=spi.example.org \
#        --email=ops@example.org --yes           unattended
#   sudo bash bin/setup.sh --skip-tls            leave it on port 80
#   sudo bash bin/setup.sh --skip-org            no organisation or admin
#   sudo bash bin/setup.sh --branch=main         which branch to clone
#   sudo bash bin/setup.sh --help
#
# Exit codes: 0 success, anything else failure, with the failing line named.

set -Eeuo pipefail

PHP_VERSION="8.4"
REPOSITORY="https://github.com/deforay/spirdt.git"

INSTALL_PATH=""
SERVER_NAME=""
ADMIN_EMAIL=""
BRANCH="main"
SKIP_TLS=0
SKIP_ORG=0
ASSUME_YES=0

for argument in "$@"; do
    case "$argument" in
        --path=*)   INSTALL_PATH="${argument#*=}" ;;
        --host=*)   SERVER_NAME="${argument#*=}" ;;
        --email=*)  ADMIN_EMAIL="${argument#*=}" ;;
        --branch=*) BRANCH="${argument#*=}" ;;
        --skip-tls) SKIP_TLS=1 ;;
        --skip-org) SKIP_ORG=1 ;;
        --yes | -y) ASSUME_YES=1 ;;
        --help | -h)
            sed -n '2,/^set -Eeuo/p' "$0" | sed -e 's/^# \{0,1\}//' -e '$d'
            exit 0
            ;;
        *)
            printf 'Unknown option: %s (try --help)\n' "$argument" >&2
            exit 2
            ;;
    esac
done

export ASSUME_YES

# The library sits beside this script in a checkout, and does not exist at all
# when this file was downloaded on its own to bootstrap a machine. Fetch it in
# that case — it is the only thing this script cannot do without.
SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIRECTORY}/shared-functions.sh" ]; then
    # shellcheck source=/dev/null
    source "${SCRIPT_DIRECTORY}/shared-functions.sh"
else
    LIBRARY="$(mktemp)"
    curl -fsSL "https://raw.githubusercontent.com/deforay/spirdt/${BRANCH}/bin/shared-functions.sh" -o "$LIBRARY" ||
        { echo "Could not fetch shared-functions.sh" >&2; exit 1; }
    # shellcheck source=/dev/null
    source "$LIBRARY"
fi

LOG_FILE="/tmp/spirdt-setup-$(date +'%Y%m%d-%H%M%S').log"
export LOG_FILE
trap 'on_error "$LINENO" "$BASH_COMMAND" "$?"' ERR

say step "SPI-RDT setup"
say info "Log: ${LOG_FILE}"

require_root
require_ubuntu "22.04"
silence_needrestart

# ── Where it goes ────────────────────────────────────────────────────────
#
# Asked first, because everything else is written relative to it and finding
# out at step six that the path was wrong means undoing five.

[ -n "$INSTALL_PATH" ] || INSTALL_PATH="$(ask 'Install path' '/var/www/spirdt')"
INSTALL_PATH="$(absolute_path "$INSTALL_PATH")"

[ -n "$SERVER_NAME" ] || SERVER_NAME="$(ask 'Server name (the hostname you will type)' "$(hostname -f 2>/dev/null || hostname)")"
[ -n "$SERVER_NAME" ] || die "A server name is required."

if [ "$SKIP_TLS" -eq 0 ] && [ -z "$ADMIN_EMAIL" ]; then
    ADMIN_EMAIL="$(ask 'Email for certificate expiry notices (blank to skip TLS)' '')"
    [ -n "$ADMIN_EMAIL" ] || SKIP_TLS=1
fi

# ── The stack ────────────────────────────────────────────────────────────

say step "Installing the stack"
apt_install git curl unzip ca-certificates acl
ensure_php "$PHP_VERSION"
ensure_composer
ensure_apache "$PHP_VERSION"
ensure_mysql

# ── The code ─────────────────────────────────────────────────────────────
#
# A clone rather than a tarball: bin/upgrade pulls, and an installation that
# cannot pull can never be upgraded by the tooling that ships with it. The app
# itself needs no build step here — the Vue front end is committed already
# built into public/, so no Node.js is installed on a server that would never
# use it again.

say step "Fetching the application"

if [ -d "${INSTALL_PATH}/.git" ]; then
    say success "Existing checkout at ${INSTALL_PATH}"
elif [ -e "$INSTALL_PATH" ] && [ -n "$(ls -A "$INSTALL_PATH" 2>/dev/null)" ]; then
    die "${INSTALL_PATH} exists and is not a checkout. Move it aside or choose another path."
else
    install -d -o "$(invoking_user)" -g www-data "$INSTALL_PATH"
    as_owner "$(dirname "$INSTALL_PATH")" git clone --branch "$BRANCH" "$REPOSITORY" "$INSTALL_PATH"
    say success "Cloned ${BRANCH} into ${INSTALL_PATH}"
fi

cd "$INSTALL_PATH"

# ── The database ─────────────────────────────────────────────────────────
#
# Credentials are generated, never asked for and never defaulted. A password
# somebody chooses at an install prompt is a password that turns out to be on
# three other machines.

say step "Setting up MySQL"

secure_mysql

DB_NAME="$(ask 'Database name' 'spirdt')"
DB_USER="$(ask 'Database user' 'spirdt')"

if [ -f .env ] && [ -n "$(env_get .env DB_PASS)" ] && [ "$(env_get .env DB_PASS)" != "change-me" ]; then
    DB_PASS="$(env_get .env DB_PASS)"
    say info "Reusing the database password already in .env"
else
    DB_PASS="$(random_password)"
fi

create_database "$DB_NAME" "$DB_USER" "$DB_PASS"

# ── Configuration ────────────────────────────────────────────────────────
#
# Only the values a server knows and a developer's .env.example cannot: where
# the database is, what the site is called, and that this is production. The
# secrets — JWT_SECRET and APP_KEY — are left to bin/setup, which generates
# them and, crucially, leaves them alone when they already exist. Regenerating
# APP_KEY on a re-run would make every stored secret unreadable.

say step "Writing configuration"

if [ ! -f .env ]; then
    install -m 0640 -o "$(invoking_user)" -g www-data .env.example .env
    say success "Created .env from .env.example"
fi

scheme="http"
[ "$SKIP_TLS" -eq 0 ] && scheme="https"

env_set .env APP_ENV production
env_set .env APP_DEBUG false
env_set .env APP_URL "${scheme}://${SERVER_NAME}"
env_set .env DB_HOST 127.0.0.1
env_set .env DB_PORT 3306
env_set .env DB_NAME "$DB_NAME"
env_set .env DB_USER "$DB_USER"
env_set .env DB_PASS "$DB_PASS"
env_set .env LOG_LEVEL info

say success "APP_ENV=production, APP_DEBUG=false, database pointed at 127.0.0.1"

UPLOAD_BYTES="$(env_get .env UPLOAD_MAX_BYTES)"
UPLOAD_MB=$(( ${UPLOAD_BYTES:-10485760} / 1048576 ))
tune_php_ini "$PHP_VERSION" "$((UPLOAD_MB + 2))"

# ── The application ──────────────────────────────────────────────────────
#
# Handed to the repository's own installer, which does composer, the schema
# and the verification pass. Non-interactive because every question it could
# ask has already been answered above.

say step "Installing the application"

as_owner "$INSTALL_PATH" php bin/setup --non-interactive
set_ownership "$INSTALL_PATH"

# ── Serving it ───────────────────────────────────────────────────────────

say step "Configuring Apache"
write_vhost "$INSTALL_PATH" "$SERVER_NAME" 80

if [ "$SKIP_TLS" -eq 0 ]; then
    say step "Requesting a certificate"
    obtain_certificate "$SERVER_NAME" "$ADMIN_EMAIL"
fi

# ── Keeping it ───────────────────────────────────────────────────────────

say step "Scheduling housekeeping"
install_cron "$INSTALL_PATH"

# ── Somebody to sign in as ───────────────────────────────────────────────
#
# An install that finishes at an empty database finishes at a sign-in screen
# nobody can pass, and the next thing the administrator does is search the
# documentation for how to create the first account. Ask here instead.

if [ "$SKIP_ORG" -eq 0 ]; then
    say step "First organisation and administrator"

    if [ -t 0 ] && [ "$ASSUME_YES" -eq 0 ]; then
        as_owner "$INSTALL_PATH" php bin/provision-org || {
            say warn "Provisioning did not finish. Run it later: cd ${INSTALL_PATH} && php bin/provision-org"
        }
    else
        say info "No terminal to ask on. Run later: cd ${INSTALL_PATH} && php bin/provision-org"
    fi
fi

# ── Report ───────────────────────────────────────────────────────────────

say step "Verifying"
as_owner "$INSTALL_PATH" php bin/preflight || say warn "Preflight reported problems — see above."

say step "Done"
say success "SPI-RDT is installed at ${INSTALL_PATH}"
say info "Address:   ${scheme}://${SERVER_NAME}"
say info "Database:  ${DB_NAME} as ${DB_USER}@localhost (password in ${INSTALL_PATH}/.env)"
say info "Upgrades:  sudo bash ${INSTALL_PATH}/bin/upgrade.sh"
say info "Log:       ${LOG_FILE}"
