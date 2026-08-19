#!/usr/bin/env bash
#
# shared-functions.sh — what bin/setup.sh and bin/upgrade.sh both need.
#
# THE DIVIDING LINE, and it is the whole design of these two scripts: shell
# provisions the MACHINE, PHP provisions the APPLICATION. Installing Apache,
# creating a database user, writing a vhost, obtaining a certificate — those
# need root and cannot be done by an application that is not installed yet.
# Everything after that point already exists in this repository as bin/setup,
# bin/refresh, bin/migrate and bin/preflight, and is called rather than
# reimplemented. Two copies of the migration order in two languages is how a
# server ends up migrated differently from a developer's laptop.
#
# Written for Ubuntu LTS with apt and systemd. It says so and stops, rather
# than half-working on something else.
#
# Sourced, never executed. Every function is safe to call twice: these scripts
# are run again after a failure, and the second run must resume rather than
# break what the first one did.

# ─────────────────────────────────────────────────────────────────────────
# Output
# ─────────────────────────────────────────────────────────────────────────

# say <kind> <message> — the only thing in here that writes to a terminal.
#
# Colour goes through tput and degrades to nothing when stdout is not a tty,
# so a log file captured with `tee` or by cron holds words rather than escape
# codes.
say() {
    local kind="$1"
    shift
    local message="$*"
    local colour="" reset=""

    if [ -t 1 ] && command -v tput >/dev/null 2>&1 && [ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]; then
        reset="$(tput sgr0)"
        case "$kind" in
            error)   colour="$(tput setaf 1; tput bold)" ;;
            success) colour="$(tput setaf 2)" ;;
            warn)    colour="$(tput setaf 3)" ;;
            info)    colour="$(tput setaf 6)" ;;
            step)    colour="$(tput bold)" ;;
        esac
    fi

    case "$kind" in
        error)   printf '%sError:%s %s\n'   "$colour" "$reset" "$message" >&2 ;;
        warn)    printf '%sWarning:%s %s\n' "$colour" "$reset" "$message" >&2 ;;
        success) printf '%s✓%s %s\n'        "$colour" "$reset" "$message" ;;
        info)    printf '  %s\n' "$message" ;;
        step)    printf '\n%s── %s%s\n' "$colour" "$message" "$reset" ;;
        *)       printf '%s\n' "$message" ;;
    esac

    log_line "[$kind] $message"
}

# log_line <message> — append to $LOG_FILE when the caller set one.
#
# Silent when it cannot write. A script that fails because its own logging
# failed has turned a diagnostic into an outage.
log_line() {
    [ -n "${LOG_FILE:-}" ] || return 0
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG_FILE" 2>/dev/null || true
}

die() {
    say error "$*"
    exit 1
}

# on_error <line> <command> <status> — for `trap ... ERR`.
#
# Names the line and the command, because the failure that matters is usually
# an apt or mysql call thirty lines into a step and "exit status 1" alone
# means opening the script to find out which.
on_error() {
    local line="$1" command="$2" status="$3"
    say error "line ${line}: \`${command}\` exited ${status}"
    [ -n "${LOG_FILE:-}" ] && say info "Full log: ${LOG_FILE}"
    exit "$status"
}

# ─────────────────────────────────────────────────────────────────────────
# Preconditions
# ─────────────────────────────────────────────────────────────────────────

require_root() {
    [ "${EUID:-$(id -u)}" -eq 0 ] || die "Run this with sudo — it installs packages and writes to /etc."
}

# require_ubuntu <minimum version> — refuse anything this was not written for.
#
# Not gatekeeping for its own sake. Every package name, service name and
# config path below is Ubuntu's, and the failure mode on a different
# distribution is not a clean error but a half-installed machine.
require_ubuntu() {
    local minimum="$1" version
    command -v lsb_release >/dev/null 2>&1 || apt_install lsb-release
    version="$(lsb_release -rs)"

    if [ "$(printf '%s\n%s\n' "$minimum" "$version" | sort -V | head -n1)" != "$minimum" ]; then
        die "Ubuntu ${minimum} or newer is required; this is ${version}."
    fi

    say success "Ubuntu ${version}"
}

# The account that invoked sudo, which is who should own the checkout. Files
# owned by root in a working tree mean the next `git pull` as a human fails.
invoking_user() {
    printf '%s' "${SUDO_USER:-${USER:-root}}"
}

absolute_path() {
    local path="$1"
    [ -z "$path" ] && return 0
    [[ "$path" == "~"* ]] && path="${path/#\~/$HOME}"
    realpath -m -- "$path"
}

# ask <prompt> <default> — a question that answers itself when nobody is there.
#
# These scripts run both by hand and from an unattended provisioning step, and
# a prompt with no terminal behind it is a hang rather than a failure. With no
# tty, the default is taken and said out loud.
ask() {
    local prompt="$1" default="${2:-}" answer

    if [ ! -t 0 ] || [ "${ASSUME_YES:-0}" = "1" ]; then
        printf '%s' "$default"
        return 0
    fi

    if [ -n "$default" ]; then
        read -r -p "${prompt} [${default}]: " answer
    else
        read -r -p "${prompt}: " answer
    fi

    printf '%s' "${answer:-$default}"
}

confirm() {
    local prompt="$1" default="${2:-no}" answer

    if [ ! -t 0 ] || [ "${ASSUME_YES:-0}" = "1" ]; then
        [ "$default" = "yes" ] || [ "${ASSUME_YES:-0}" = "1" ]
        return
    fi

    read -r -p "${prompt} (y/n) [${default}]: " answer
    answer="${answer:-$default}"
    case "${answer,,}" in
        y | yes) return 0 ;;
        *) return 1 ;;
    esac
}

# ─────────────────────────────────────────────────────────────────────────
# Packages
# ─────────────────────────────────────────────────────────────────────────

# apt_refresh — update the package lists at most once per run.
#
# Every ensure_* function below wants current lists and none of them knows
# whether another already asked, so the guard lives here rather than in each
# caller.
apt_refresh() {
    [ "${APT_REFRESHED:-0}" = "1" ] && return 0
    say info "Updating package lists"
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    APT_REFRESHED=1
}

apt_install() {
    apt_refresh
    say info "Installing: $*"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" >/dev/null
}

# Ubuntu 24.04 asks, on the console, which services to restart after a library
# upgrade. Unanswered, an unattended run stops there for good.
silence_needrestart() {
    export NEEDRESTART_MODE=a
    local conf=/etc/needrestart/needrestart.conf
    [ -f "$conf" ] || return 0
    grep -q "^\$nrconf{restart} = 'a';" "$conf" && return 0
    sed -i "s/^#\?\$nrconf{restart}.*/\$nrconf{restart} = 'a';/" "$conf" || true
}

# ─────────────────────────────────────────────────────────────────────────
# PHP
# ─────────────────────────────────────────────────────────────────────────

# ensure_php <version> — PHP, the extensions this application actually uses,
# and mod_php for Apache.
#
# The extension list is not generic. It is what composer.json requires plus
# what the code loads at runtime: pdo_mysql for the database, mbstring and
# intl for the four languages, gd for signature and photo handling, zip for
# spreadsheet export, curl for outbound calls, and sodium for APP_KEY — the
# settings screen's SMTP password is encrypted with it and refuses to save
# without it.
#
# From the ondrej PPA, because Ubuntu ships one PHP per release and this
# application asks for 8.4.
ensure_php() {
    local version="$1"

    if ! php -v 2>/dev/null | grep -q "^PHP ${version}"; then
        if ! grep -rq "^deb .*ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
            say info "Adding the ondrej/php repository"
            apt_install software-properties-common ca-certificates
            LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null
            APT_REFRESHED=0
        fi

        apt_install \
            "php${version}" \
            "php${version}-cli" \
            "libapache2-mod-php${version}" \
            "php${version}-mysql" \
            "php${version}-mbstring" \
            "php${version}-intl" \
            "php${version}-xml" \
            "php${version}-curl" \
            "php${version}-gd" \
            "php${version}-zip" \
            "php${version}-bcmath"
    fi

    # sodium and openssl are compiled in on Ubuntu's build but ship as
    # separate packages on some mirrors; ask for them only if missing, so a
    # normal machine is not told to install something it has.
    php -m | grep -qi '^sodium$' || apt_install "php${version}-sodium" || true

    update-alternatives --set php "/usr/bin/php${version}" >/dev/null 2>&1 || true

    php -v | head -n1 | while read -r line; do say success "$line"; done
}

# tune_php_ini <version> — the handful of values that are wrong by default for
# this application, in a file of our own.
#
# A drop-in under conf.d rather than edits to php.ini: an edited php.ini is
# overwritten by the next package upgrade, and there is then no way to tell
# what was ours. Uploads are the reason most of these exist — signatures and
# photos come in from a phone on a slow link.
tune_php_ini() {
    local version="$1"
    local upload_mb="${2:-12}"

    # Written to a temporary file rather than captured into a variable: a
    # heredoc inside $( ) is a construct bash 3.2 cannot parse, and this file
    # is checked with `bash -n` on machines that still ship it.
    local body
    body="$(mktemp)"

    cat >"$body" <<INI
; Written by bin/setup.sh. Edit here rather than in php.ini: this file
; survives a PHP package upgrade and php.ini does not.
;
; Upload sizes track UPLOAD_MAX_BYTES in .env, which the application enforces
; itself. The two must agree, and the SAPI's limit has to be the larger of the
; pair — PHP rejects an oversized upload before any application code runs, so
; a lower limit here produces a failure the application cannot explain.
upload_max_filesize = ${upload_mb}M
post_max_size = $((upload_mb + 4))M
max_file_uploads = 24

; A phone syncing a visit sends a single request holding a whole assessment.
max_execution_time = 120
memory_limit = 256M

; Off in production: an error page carrying a stack trace tells whoever is
; looking at it the path of the checkout and the name of the database.
display_errors = Off
log_errors = On

expose_php = Off
INI

    for sapi in cli apache2; do
        local target="/etc/php/${version}/${sapi}/conf.d/99-spirdt.ini"
        [ -d "$(dirname "$target")" ] || continue
        install_if_changed "$target" "$body" && say success "Wrote ${target}"
    done

    rm -f "$body"
}

# ensure_composer — the real installer, checksum-verified.
#
# Ubuntu's composer package trails upstream by enough that it has refused
# lock files this project has committed.
ensure_composer() {
    if command -v composer >/dev/null 2>&1; then
        say success "Composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"
        return 0
    fi

    say info "Installing Composer"
    apt_install unzip git curl

    local installer expected actual
    installer="$(mktemp)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$installer"
    actual="$(php -r "echo hash_file('sha384', '${installer}');")"

    if [ "$expected" != "$actual" ]; then
        rm -f "$installer"
        die "Composer installer checksum mismatch — refusing to run it."
    fi

    php "$installer" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"
    say success "Composer installed"
}

# ─────────────────────────────────────────────────────────────────────────
# Apache
# ─────────────────────────────────────────────────────────────────────────

# ensure_apache <php version> — Apache with mod_php and the modules this
# application's public/.htaccess depends on.
#
# rewrite is not optional: every application route and every /api/* call is
# rewritten to a front controller by public/.htaccess, and without the module
# Apache serves the file listing instead. headers is needed because the
# .htaccess sets a couple, and Apache treats an unknown directive as a
# configuration error rather than ignoring it — so a missing module takes the
# whole site down, not just the header.
ensure_apache() {
    local version="$1"

    command -v apache2ctl >/dev/null 2>&1 || apt_install apache2

    a2enmod rewrite headers expires >/dev/null
    a2enmod "php${version}" >/dev/null 2>&1 || true

    # mpm_prefork is what mod_php requires; the default on a fresh Ubuntu is
    # mpm_event, and enabling mod_php against it fails at reload with a
    # message about a thread-safe build.
    if apache2ctl -M 2>/dev/null | grep -q 'mpm_event'; then
        a2dismod mpm_event >/dev/null 2>&1 || true
        a2enmod mpm_prefork >/dev/null 2>&1 || true
        a2enmod "php${version}" >/dev/null 2>&1 || true
    fi

    systemctl enable --now apache2 >/dev/null 2>&1 || true
    say success "Apache with mod_php${version}"
}

# write_vhost <root> <server name> <port> — from the example the repository
# already carries.
#
# deploy/apache/spirdt.conf.example is the documented vhost and it explains
# every line it contains, including the one nobody guesses: CGIPassAuth, which
# decides whether the Bearer token survives the trip to PHP. Rendering that
# file rather than writing a new one means the installed server and the
# documentation cannot drift apart.
write_vhost() {
    local root="$1" server_name="$2" port="${3:-80}"
    local template="${root}/deploy/apache/spirdt.conf.example"
    local target="/etc/apache2/sites-available/spirdt.conf"

    [ -f "$template" ] || die "Missing ${template} — is ${root} a spirdt checkout?"

    local rendered
    rendered="$(sed -e "s|{{ROOT}}|${root}|g" -e "s|{{SERVER_NAME}}|${server_name}|g" -e "s|{{PORT}}|${port}|g" "$template")"

    write_if_changed "$target" "$rendered" && say success "Wrote ${target}"

    a2ensite spirdt >/dev/null

    # The stock site answers for every hostname that is not claimed elsewhere,
    # which on a single-purpose box means somebody typing the IP address gets
    # the Apache welcome page instead of the application.
    if [ -e /etc/apache2/sites-enabled/000-default.conf ] && confirm "Disable Apache's default site?" yes; then
        a2dissite 000-default >/dev/null
    fi

    apache2ctl configtest >/dev/null 2>&1 || die "Apache rejected the configuration — run: apache2ctl configtest"
    systemctl reload apache2
    say success "Apache serving ${server_name} from ${root}/public"
}

# ─────────────────────────────────────────────────────────────────────────
# MySQL
# ─────────────────────────────────────────────────────────────────────────

ensure_mysql() {
    if ! command -v mysql >/dev/null 2>&1; then
        apt_install mysql-server
    fi

    systemctl enable --now mysql >/dev/null 2>&1 || true

    local waited=0
    until mysqladmin ping --silent >/dev/null 2>&1; do
        waited=$((waited + 1))
        [ "$waited" -gt 30 ] && die "MySQL did not come up within 30s."
        sleep 1
    done

    say success "MySQL $(mysql --version | awk '{print $3}' | tr -d ',')"
}

# secure_mysql — what mysql_secure_installation does, without the prompts.
#
# Done here rather than by pointing at that command because it cannot be
# driven non-interactively on Ubuntu, and because two of the four things it
# does are wrong for a server that has just been provisioned: it offers to set
# a root password on an installation that authenticates root by unix socket,
# which is stronger than a password, and it offers a validate_password policy
# that then rejects the generated application password.
secure_mysql() {
    mysql --protocol=socket -uroot <<'SQL'
DELETE FROM mysql.user WHERE User = '';
DELETE FROM mysql.db WHERE Db IN ('test', 'test\\_%');
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
SQL
    say success "Removed anonymous accounts and the test database"
}

# create_database <name> <user> <password>
#
# utf8mb4 with utf8mb4_unicode_ci, matching what the migrations declare. A
# database created with the server default and tables created with the
# migration's collation is the setup where a JOIN on two varchars fails with
# an illegal mix of collations.
#
# The account is scoped to localhost. Nothing outside this machine speaks to
# MySQL — the application, the migrator and the backup tool all run here.
#
# PROCESS is granted globally and it is not an oversight. mysqldump asks the
# server for its process list; without the grant it errors and still exits 0,
# so every nightly backup is empty and reports success, and nobody finds out
# until the day one is needed. It is a read-only grant on server metadata and
# it is the one privilege this user needs outside its own database.
create_database() {
    local name="$1" user="$2" password="$3"

    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${name}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${password}';
ALTER USER '${user}'@'localhost' IDENTIFIED BY '${password}';
GRANT ALL PRIVILEGES ON \`${name}\`.* TO '${user}'@'localhost';
GRANT PROCESS ON *.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL

    say success "Database ${name} and user ${user}@localhost, with PROCESS for backups"
}

# ─────────────────────────────────────────────────────────────────────────
# Files
# ─────────────────────────────────────────────────────────────────────────

# write_if_changed <path> <content> — returns 0 when it wrote, 1 when the file
# already said that.
#
# Rewriting an unchanged file is not harmless here: it moves the mtime, which
# is what somebody reads when working out whether a config was touched during
# an incident.
write_if_changed() {
    local target="$1" content="$2" temp
    temp="$(mktemp)"
    printf '%s\n' "$content" >"$temp"

    if [ -f "$target" ] && cmp -s "$temp" "$target"; then
        rm -f "$temp"
        return 1
    fi

    install -D -m 0644 "$temp" "$target"
    rm -f "$temp"
    return 0
}

# install_if_changed <target> <source file> — the same promise as
# write_if_changed, for content that is already in a file.
install_if_changed() {
    local target="$1" source="$2"

    if [ -f "$target" ] && cmp -s "$source" "$target"; then
        return 1
    fi

    install -D -m 0644 "$source" "$target"
    return 0
}

# env_set <file> <key> <value> — set a key in .env, in place.
#
# Appends when the key is absent rather than rewriting the file from a
# template, because .env holds values nobody can regenerate: APP_KEY decrypts
# stored secrets and JWT_SECRET invalidates every open session if it changes.
env_set() {
    local file="$1" key="$2" value="$3"

    if grep -qE "^${key}=" "$file"; then
        # A comment after the value is normal in this file's example, and
        # sed's replacement would eat it. Match only up to the end of line.
        local escaped
        escaped="$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')"
        sed -i -E "s/^${key}=.*/${key}=${escaped}/" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

env_get() {
    local file="$1" key="$2"
    grep -E "^${key}=" "$file" 2>/dev/null | head -n1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"//' -e 's/"$//' -e 's/[[:space:]]*$//'
}

random_password() {
    # No shell metacharacters: this value goes into a MySQL statement, a .env
    # line and possibly a connection string, and a quote in it breaks one of
    # the three in a way that looks like a different bug.
    tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32
}

# set_ownership <root> — who owns the checkout, and who may write to var/.
#
# The checkout belongs to the human who will `git pull` it; var/ is the one
# tree Apache writes to, so www-data joins it by group with the setgid bit on
# so tomorrow's log file inherits the group instead of being created root-only.
# This is the failure that presents as "the application worked and then
# stopped at midnight".
set_ownership() {
    local root="$1" owner
    owner="$(invoking_user)"

    chown -R "${owner}:www-data" "$root"
    find "${root}/var" -type d -exec chmod 2775 {} + 2>/dev/null || true
    find "${root}/var" -type f -exec chmod 0664 {} + 2>/dev/null || true

    # .env holds the database password and two keys. Readable by the group so
    # Apache can start; readable by nobody else.
    [ -f "${root}/.env" ] && chmod 0640 "${root}/.env"

    say success "Ownership ${owner}:www-data, var/ writable by Apache"
}

# ─────────────────────────────────────────────────────────────────────────
# Scheduling and certificates
# ─────────────────────────────────────────────────────────────────────────

# install_cron <root> — one line in root's crontab, calling one sweep.
#
# One entry rather than several, because a crontab with four application lines
# in it is four things to keep in step with the code. What runs nightly is
# decided by bin/housekeeping, in the repository, versioned with everything
# else. The marker comment is what makes this idempotent and what lets an
# administrator find it.
install_cron() {
    local root="$1"
    local marker="# spirdt housekeeping (bin/setup.sh)"
    local line="17 2 * * * cd ${root} && /usr/bin/php bin/housekeeping >> ${root}/var/log/housekeeping.log 2>&1"

    local current
    current="$(crontab -l 2>/dev/null || true)"

    if printf '%s\n' "$current" | grep -Fq "$marker"; then
        printf '%s\n' "$current" | grep -v -F "$marker" | grep -v -F 'bin/housekeeping' >/tmp/spirdt-crontab
    else
        printf '%s\n' "$current" >/tmp/spirdt-crontab
    fi

    {
        printf '%s\n' "$marker"
        printf '%s\n' "$line"
    } >>/tmp/spirdt-crontab

    crontab /tmp/spirdt-crontab
    rm -f /tmp/spirdt-crontab
    say success "Nightly housekeeping at 02:17 in root's crontab"
}

# obtain_certificate <server name> <email> — Let's Encrypt through certbot's
# Apache plugin.
#
# certbot edits the vhost written above and installs its own renewal timer, so
# there is nothing else to schedule. It is skipped rather than attempted when
# the name does not resolve to this machine: a failed challenge counts against
# a rate limit that locks the name out for a week, which is a worse outcome
# than finishing without TLS and being told to run one command later.
obtain_certificate() {
    local server_name="$1" email="$2"

    case "$server_name" in
        localhost | *.local | *.test | *.localdomain)
            say warn "Skipping TLS: ${server_name} is not a public name."
            return 0
            ;;
    esac

    local resolved
    resolved="$(getent hosts "$server_name" | awk '{print $1}' | head -n1)"
    if [ -z "$resolved" ]; then
        say warn "Skipping TLS: ${server_name} does not resolve yet."
        say info "Once DNS points here, run: certbot --apache -d ${server_name}"
        return 0
    fi

    command -v certbot >/dev/null 2>&1 || apt_install certbot python3-certbot-apache

    if certbot certificates 2>/dev/null | grep -q "Domains:.*\b${server_name}\b"; then
        say success "Certificate for ${server_name} already installed"
        return 0
    fi

    if certbot --apache --non-interactive --agree-tos --redirect \
        -m "$email" -d "$server_name"; then
        say success "TLS enabled for ${server_name}, renewal handled by certbot's timer"
    else
        say warn "certbot failed — the site is up on port 80. Retry: certbot --apache -d ${server_name}"
    fi
}

# ─────────────────────────────────────────────────────────────────────────
# Handing off to the application's own scripts
# ─────────────────────────────────────────────────────────────────────────

# as_owner <root> <command...> — run one of the repository's PHP scripts as
# the account that owns the checkout.
#
# Composer refuses to run as root without being told to, and it is right to:
# a vendor tree written by root cannot be updated by the human afterwards.
as_owner() {
    local root="$1"
    shift
    local owner
    owner="$(invoking_user)"

    if [ "$owner" = "root" ]; then
        (cd "$root" && "$@")
    else
        sudo -u "$owner" -H bash -lc "cd $(printf '%q' "$root") && $(printf '%q ' "$@")"
    fi
}
