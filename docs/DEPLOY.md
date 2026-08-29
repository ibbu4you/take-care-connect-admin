# Deploying to `admin.takecareconnect.com`

Hostinger shared hosting. Read the whole page once before starting — the order
matters in two places, and both are called out.

**This system has its own database.** It never reads or writes the live
website's `tcif` database. If you find yourself entering the website's database
credentials, stop: you are about to merge two sets of user accounts that have
different roles and different permissions. `php artisan tcc:health` refuses to
pass if it detects this.

---

## What you need first

| | |
|---|---|
| Hostinger account | with hPanel access to `takecareconnect.com` |
| SSH access | hPanel → Advanced → SSH Access. Everything below is far easier over SSH than over the file manager |
| PHP 8.2 or newer | hPanel → Advanced → PHP Configuration |
| A MySQL database | new and empty — see step 2 |
| Node on your own machine | the server has none; the frontend is built locally |

---

## 1. Create the subdomain

hPanel → Domains → Subdomains → **Create**.

- Subdomain: `admin`
- Domain: `takecareconnect.com`

Hostinger creates a directory, usually `domains/admin.takecareconnect.com/public_html`.

**Then point its document root at the application's `public/` directory.** In
hPanel this is Domains → your subdomain → *Document root*, or you can create
the subdomain with the path already set.

This matters more than it sounds. If the document root is the application root,
then `.env` — which holds the database password and the key that decrypts every
session and every two-factor secret — sits inside the web root. One
misconfigured rewrite and it is a public URL.

If the document root genuinely cannot be changed on your plan, copy
`deploy/htaccess-subdomain-root` to `.htaccess` in the subdomain root. It works,
but treat it as the fallback it is.

Finally, issue the SSL certificate: hPanel → Security → SSL. The application
sets secure cookies and will not sign anybody in over plain HTTP.

---

## 2. Create the database

hPanel → Databases → MySQL Databases.

- Database: `tcc_admin` (Hostinger prefixes it, so you will end up with something like `u123456789_tcc_admin`)
- User: a new one, used by nothing else
- Password: generated, long, and stored in your password manager rather than a chat message

Write down all three. They go into `.env` in step 5.

---

## 3. Get the code onto the server

The repository is the release. Clone it *beside* `public_html`, not into it —
`.env` holds the database password and the key that decrypts every session and
every two-factor secret, and it must not sit anywhere the web server can reach.

```bash
cd ~/domains/admin.takecareconnect.com
git clone https://github.com/ibbu4you/take-care-connect-admin.git app
```

Then point the document root at `app/public`. Hostinger serves the subdomain
from `public_html`, so replace it with a link:

```bash
rm -rf public_html
ln -s app/public public_html
```

`ls -la` should now show `public_html -> app/public`.

If the host refuses to serve a symlinked document root — a white page or a 403
where the sign-in should be — see **If the symlink is refused** at the bottom of
this page. Do not fall back to putting the application inside `public_html`
unless nothing else works; that is what `deploy/htaccess-subdomain-root` is
for, and it defends `.env` with a rewrite rule rather than with the filesystem.

### About `public/build`

It is committed, which is unusual and deliberate. Shared hosting has no Node, so
the Vite output cannot be produced here; committing it is what makes a deploy a
`git pull` rather than a second, separate upload that can arrive out of step.

The consequence is a rule for whoever changes the frontend: **`npm run build`
and commit the result in the same commit as the source change.** A `public/build`
that does not match its code is the classic blank page with a 404 for an asset
hash that no longer exists. `tcc:health` reports the build's age for exactly
this reason.

## 4. Uploads that are not code

Nothing to do on a first deploy — but know where the line is, because it governs
every deploy after this one.

The repository carries the application. It does not carry, and must never
overwrite:

| | |
|---|---|
| `.env` | this server's configuration and keys |
| `storage/app/workfiles` | every uploaded document, recording and video cut |
| `storage/logs` | this server's logs |

All three are gitignored, so `git pull` leaves them alone. That is the whole
reason the deploy is a pull rather than an rsync with `--delete`.

## 5. Configure

Over SSH, in the application root:

```bash
cp .env.production.example .env
nano .env
```

Fill in the four marked values: `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
and leave `APP_KEY` blank for now. Everything else is already set for this host.

### On a first deploy, install Composer's dependencies before anything else

`artisan` is a PHP script that starts by requiring `vendor/autoload.php`. Until
Composer has run there is no autoloader, so **every** `php artisan` command
fails — and because shared hosting runs the CLI with `display_errors` off, it
fails by printing nothing at all and returning you to the prompt. It looks
exactly like a command that worked.

```bash
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

`scripts/deploy.sh` does this too, on every later deploy. It cannot do it for
you here, because it refuses to start until `APP_KEY` is set and `APP_KEY`
cannot be generated until the autoloader exists. That circle is only broken
once, on the first deploy.

Then generate the key:

```bash
php artisan key:generate
```

If that prints nothing, Composer has not run. It should say
`INFO  Application key set successfully.`

**Generate it once, and never again on a working install.** `APP_KEY` encrypts
sessions and the two-factor secrets. Changing it signs everybody out and makes
every enrolled authenticator app stop working, with no error message that says
so — people simply find their codes rejected.

---

## 6. PHP limits

The largest thing this system accepts is a 200 MB video cut, and PHP's defaults
are nowhere near that.

```bash
cp deploy/user.ini public/.user.ini
```

If that has no effect after five minutes (PHP caches `.user.ini`), set the same
values in hPanel → Advanced → PHP Configuration:

| Setting | Value |
|---|---|
| `upload_max_filesize` | `210M` |
| `post_max_size` | `220M` |
| `max_execution_time` | `300` |
| `memory_limit` | `256M` |

Worth doing properly, because the failure is silent: when a POST exceeds
`post_max_size`, PHP discards the body before the application sees it. The
upload arrives as an empty form, and the message the creative gets is "the file
field is required" on a file they definitely attached.

---

## 7. Install and migrate

```bash
bash scripts/deploy.sh
```

That script installs dependencies, runs migrations, links storage, caches the
config and routes, and finishes by running the health check. It refuses to
start if `.env` is missing, `APP_KEY` is empty, `APP_DEBUG` is on, or
`public/build` is absent.

The first run will report one problem — no administrator yet. That is step 8.

---

## 8. Create the first account

```bash
php artisan tcc:setup
```

It asks for a name, an email and a password, and creates an administrator. Use
`--generate` if you would rather it invented the password; it is shown once and
is not recoverable afterwards.

**No password is seeded anywhere.** The demo accounts that use `password` are
in `DemoSeeder`, which refuses to run in production and is never called by
`migrate --seed`.

Two-factor authentication is available from Profile and entirely optional —
nobody is prompted or required to set it up.

---

## 9. The cron entry

**Nothing scheduled runs without this.** No queue, no morning digest, no
housekeeping.

hPanel → Advanced → Cron Jobs → **Create**. Set it to run **every minute**:

```
cd ~/domains/admin.takecareconnect.com/app && php artisan schedule:run >> /dev/null 2>&1
```

One entry, every minute, forever. Laravel decides internally what is actually
due; running it every minute costs almost nothing and is how the queue gets
drained on a host with no supervisor.

What it drives:

| When | What |
|---|---|
| every minute | drains the queue (thumbnails, exports) and exits |
| 08:00 IST, weekdays | the morning digest — one notification per person |
| Sunday 03:00 | clears read notifications older than 60 days |
| daily | prunes expired sessions |

Check it took effect the next day: if jobs are piling up, `tcc:health` says so.

---

## 10. Verify

```bash
php artisan tcc:health
```

Everything should pass. Then sign in and walk one interview through the SOP —
book it, conduct it, hand it over, rewrite it, approve it, export it. Fifteen
minutes, and it exercises every part of the system that deployment can break.

---

## Deploying again later

```bash
ssh USER@in-mum-web785.main-hosting.eu
cd ~/domains/admin.takecareconnect.com/app
git pull
bash scripts/deploy.sh
```

That is the whole loop. `scripts/deploy.sh` puts the site into maintenance mode
first and takes it out at the end, including if it fails partway. While it is
down you can still browse the site yourself via
`https://admin.takecareconnect.com/deploying` — that sets a cookie that bypasses
the holding page, so you can check the release before anybody else sees it.

Before you push, on your own machine:

```bash
npm run build      # if anything under resources/js or resources/css changed
git add -A && git commit && git push
```

---

## When something is wrong

**A blank page, or 404s for `/build/assets/…`**
The uploaded `public/build` does not match the uploaded code. Rebuild locally,
upload `public/build`, and run `php artisan view:clear`.

**A 500 with no detail**
That is correct in production — the detail is in `storage/logs/laravel-*.log`.
Read the newest one. Do not turn `APP_DEBUG` on to investigate: a stack trace on
a live page shows the database credentials to whoever triggered it.

**"The file field is required" on a file that is definitely attached**
PHP's upload limits. Step 6.

**Everybody signed out, and authenticator codes rejected**
`APP_KEY` changed. If you have the old one, put it back. If you do not, the
sessions are gone regardless and every enrolment has to be cleared — see below.

**Somebody lost the phone their two-factor is on**
An administrator clears it from Users → the person → **Clear two-factor**. They
are then asked to enrol a new device on their next sign-in.

If the locked-out person is the *only* administrator, nobody can click that
button, so do it over SSH:

```bash
php artisan tcc:two-factor-reset them@takecareconnect.com
```

Both routes end that account's signed-in sessions, because the usual reason for
one is a phone that went missing rather than one that was replaced.

**Nothing scheduled has run**
The cron entry. Step 9. Confirm with `php artisan schedule:run` by hand — if
that works, the application is fine and the cron is not configured.

**Changed `.env` and nothing happened**
Production caches the config. `php artisan config:cache` after every `.env`
edit. This catches everyone once.

**Locked out entirely**
Over SSH:

```bash
php artisan tcc:password you@takecareconnect.com --generate
```

It prints a new password once and ends every signed-in session for that
account.

---

## If the symlink is refused

Some hosts will not serve a document root that is itself a symlink. The symptom
is a white page or a 403 at the subdomain, while `php artisan tcc:health` on the
server passes — the application is fine and Apache never reached it.

The fix is a `public_html` that is a real directory holding nothing but a
doorway into the application:

```bash
cd ~/domains/admin.takecareconnect.com
rm -f public_html            # remove the symlink
mkdir public_html
cp app/public/.htaccess public_html/
cp app/public/index.php  public_html/
ln -s ../app/public/build   public_html/build
ln -s ../app/public/fonts   public_html/fonts
ln -s ../app/public/img     public_html/img
ln -s ../app/storage/app/public public_html/storage
cp app/public/favicon.ico app/public/favicon.png app/public/apple-touch-icon.png app/public/robots.txt public_html/
```

Then edit the two paths in `public_html/index.php` so they climb one level
further — `__DIR__.'/../vendor/autoload.php'` becomes
`__DIR__.'/../app/vendor/autoload.php'`, and the same for
`bootstrap/app.php`.

Symlinks *inside* the document root are accepted far more widely than a
symlinked root, which is why this works when the other does not. The
application still lives outside the web root, which is the part that matters.

Only if even this fails does `deploy/htaccess-subdomain-root` come out — it puts
the application inside `public_html` and defends `.env` with a rewrite rule.
Read the header of that file before using it.

---

## Backups

Hostinger's automatic backups cover both, but confirm rather than assume —
these are the two things that cannot be rebuilt from the repository:

| | |
|---|---|
| The database | every client, deal, interview and article |
| `storage/app/workfiles` | every uploaded document, recording and video cut |

A monthly restore test is worth more than a daily backup nobody has ever
restored from.
