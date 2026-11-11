#!/usr/bin/env bash
#
# Run this ON the server, from the application root, after new files are in place.
#
#     bash scripts/deploy.sh
#
# ── What this does not do ────────────────────────────────────────────────────
# It does not build the frontend. Hostinger shared hosting has no Node, so
# `public/build` is produced on a laptop with `npm run build` and uploaded. If
# that directory is missing or stale, this script says so and stops rather than
# leaving the site serving a white page against a manifest that no longer
# matches.
#
set -euo pipefail

cd "$(dirname "$0")/.."

say()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$1"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n\n' "$1" >&2; exit 1; }

# ── Refuse to run against a half-configured install ──────────────────────────
[ -f .env ] || die "No .env. Copy .env.production.example to .env and fill it in."

grep -q '^APP_KEY=base64:' .env || die "APP_KEY is empty. Run: php artisan key:generate"

if ! grep -q '^APP_DEBUG=false' .env; then
    die "APP_DEBUG is not false. A stack trace here would show the database password."
fi

[ -f public/build/manifest.json ] || die "public/build is missing. Run 'npm run build' locally and upload it."

say "Maintenance mode"
# --secret lets whoever is deploying keep browsing the site to check it, while
# everybody else sees the holding page. Random each time: a fixed secret is a
# permanent way past maintenance mode for anyone who has seen it once.
BYPASS="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
php artisan down --render="errors::503" --secret="$BYPASS" || warn "Already down"
printf '  Preview while down: %s/%s\n' "$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')" "$BYPASS"

restore() { php artisan up >/dev/null 2>&1 || true; }
trap restore EXIT

say "PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

say "Fresh configuration"
# Cleared before anything touches the database: the cached config is the old
# release's, and migrating or seeding against it can use settings that no
# longer exist.
php artisan optimize:clear

say "Backup"
# A copy of the database before migrating, so a migration that goes wrong is
# a restore rather than a rebuild. Best effort: a host without mysqldump says
# so and carries on.
env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
if [ "$(env_value DB_CONNECTION)" = "mysql" ] && command -v mysqldump >/dev/null 2>&1; then
    mkdir -p storage/backups
    BACKUP="storage/backups/$(date +%Y%m%d-%H%M%S).sql.gz"
    if MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --quick \
        -h "$(env_value DB_HOST)" -P "$(env_value DB_PORT)" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
        | gzip > "$BACKUP"; then
        printf '  %s\n' "$BACKUP"
    else
        warn "Backup failed — continuing. Take one from hPanel before going further if this release has migrations."
    fi
else
    warn "mysqldump not available — no automatic backup. Take one from hPanel if this release has migrations."
fi

say "Database"
php artisan migrate --force

say "Roles and categories"
# DatabaseSeeder seeds the permission catalogue and the category list, and
# nothing else — no demo data, which refuses to run in production anyway.
#
# Run every deploy, not just the first: this is how a permission added in
# Permissions.php reaches the database (new permissions are given to the roles
# whose defaults include them). Categories are only seeded into an empty
# table, so ones an admin deleted or renamed stay that way. Left out, a first
# deploy finishes with no roles at all and `tcc:setup` cannot create anybody.
php artisan db:seed --force

say "Storage link"
if [ -L public/storage ] || [ -d public/storage ]; then
    printf '  link already in place
'
elif php artisan storage:link; then
    :
else
    # Some shared hosts disable PHP's symlink(). The link is still creatable
    # from the shell, and its absence shows up as broken avatars and 404s on
    # every uploaded file rather than as an error anybody would connect to it.
    warn "storage:link failed — falling back to ln"
    ln -s ../storage/app/public public/storage || die "Could not create public/storage. Create it by hand: ln -s ../storage/app/public public/storage"
fi

say "Caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

say "Clearing stale work"
# Sessions and the file cache both hold serialised objects from the previous
# release. Left in place, a renamed class comes back as an unserialisation
# error on somebody's next click.
php artisan cache:clear
php artisan queue:restart

say "Health check"
php artisan tcc:health

trap - EXIT
php artisan up

printf '\n\033[1;32m✓ Deployed.\033[0m\n\n'
