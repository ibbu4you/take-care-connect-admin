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
# everybody else sees the holding page.
php artisan down --render="errors::503" --secret="deploying" || warn "Already down"

restore() { php artisan up >/dev/null 2>&1 || true; }
trap restore EXIT

say "PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

say "Database"
php artisan migrate --force

say "Roles and categories"
# DatabaseSeeder seeds the permission catalogue and the category list, and
# nothing else — no demo data, which refuses to run in production anyway.
#
# Run every deploy, not just the first. Both seeders are written to be
# re-runnable, and this is how a permission added in Permissions.php actually
# reaches the database: the code is the definition, the tables follow it. Left
# out, a first deploy finishes with no roles at all and `tcc:setup` cannot
# create anybody.
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
