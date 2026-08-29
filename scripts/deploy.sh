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

say "Storage link"
# Idempotent, and cheap to repeat — the symlink does not survive every upload
# method, and its absence shows up as broken avatars rather than an error.
php artisan storage:link 2>/dev/null || warn "Link already exists"

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
