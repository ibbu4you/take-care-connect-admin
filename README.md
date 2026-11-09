# Take Care Connect Admin

The internal operations workspace for the team behind
[takecareconnect.com](https://takecareconnect.com) — a system of record for the
work that happens *before* something is published.

It tracks clients and sales, schedules and captures interviews, moves articles
through a rewrite pipeline, and manages creative deliverables through to their
published social links.

## What this is not

It is **not** a CMS, and it does not replace the Filament panel at
`takecareconnect.com/admin`.

This application has **its own database** and touches nothing on the live site.
When the content team finishes an article they download the draft, publish it on
the website by hand, and paste the resulting live URL back in. The public site
carries on exactly as it does today.

That separation is deliberate: it means nothing here can break the live site,
and no version of any package has to be kept in lockstep with it.

## The five working roles

| Role | Owns |
|---|---|
| **Admin** | Every module, plus accounts, roles, settings and the audit log. |
| **Anchor** | Conducts interviews, records the Q&A, hands the write-up over. |
| **Content** | Rewrites handovers into finished articles, through to published. |
| **Creative** | Posters, reels and video edits, and where they were published. |
| **Relationship Manager** | Clients, projects, the sales pipeline, booking interviews. |

## The SOP this models

```
Relationship Manager          Anchor                    Content            Creative
─────────────────────         ──────                    ───────            ────────
client → project → deal
        │
        └── books interview ──► conducts it
                                  │
              ┌───────────────────┼────────────────────┐
              │                   │                    │
      celebrity_social     business_article        meet_social
      (in person)          (Google Meet)           (Google Meet)
              │                   │                    │
              │            hands over:                 │
              │            · document                  │
              │            · recording link            │
              │            · Q&A notes                 │
              │                   │                    │
              │                   ▼                    │
              │            rewrite → review            │
              │            → approve → publish         │
              │            → paste live URL            │
              │                   │                    │
              └───────────────────┴────────────────────┘
                                  │
                                  ▼
                          posters · reels · edits
                          → approve → publish
                          → record YouTube / Instagram link
```

Only `business_article` produces a website article. The two social kinds are
tracked here and end at their published social links — by design.

## Stack

Laravel 12 · Inertia 2 · React 18 · Vite 7 · Tailwind 3 · MySQL

A single application, not a separate frontend and backend: controllers return
`Inertia::render()` and the React pages under `resources/js/Pages` mirror the
routes. There is no JSON API layer and no SSR — nothing here is crawled.

## Getting started

```bash
composer install
npm install                 # also publishes Inter into public/
cp .env.example .env
php artisan key:generate

# Create the database, then:
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder    # a worked example of the whole SOP
npm run dev                 # and, in another terminal:
php artisan serve
```

`migrate --seed` gives you the permission catalogue and the category list —
what a real installation needs and nothing more. The demo data is a separate
command because `--seed` is what somebody runs on a fresh production database,
and the first thing that team should see is their own work, not eleven
fictional clients.

`DemoSeeder` creates one account per role, all with the password `password`:

| Email | Role |
|---|---|
| `admin@takecareconnect.com` | Admin |
| `anchor@takecareconnect.com` | Anchor |
| `content@takecareconnect.com` | Content |
| `creative@takecareconnect.com` | Creative |
| `rm@takecareconnect.com` | Relationship Manager |

It refuses to run in production, and `php artisan tcc:health` fails if it finds
those accounts on a production box. The first real account is made with
`php artisan tcc:setup`, which asks for a password rather than seeding one.

## Verifying a change

```bash
php artisan test     # feature tests, SQLite in memory
npm run types        # tsc --noEmit
npm run build        # must finish with no unknown-class warnings
```

`php artisan test` covers the server. It cannot see the other half: a page that
renders a perfectly good 200 and then throws in the browser. `npm run smoke`
signs in as each role, visits every built route, and fails on any JavaScript
error or empty root element — start the app first, then:

```bash
npm run smoke                                    # against APP_URL
npm run smoke -- --base=http://127.0.0.1:8000
```

Almost every screen is behind a login, so visual regressions cannot be checked
with `curl` either. `npm run shot` drives the same browser through a real
sign-in and captures the result:

```bash
npm run shot -- --path=/dashboard --as=creative@takecareconnect.com
npm run shot -- --path=/dashboard --dark --out=storage/shots/dark.png

# One card, by the text inside it — for a long form where the thing that
# changed is a screen below the fold.
npm run shot -- --path=/users/3/edit --find="Two-factor authentication is"
```

Set `CHROME_PATH` if Chrome is not in the default location. On Git Bash, prefix
with `MSYS_NO_PATHCONV=1` so `/dashboard` is not rewritten into a Windows path.

## How access control works

Three files, and nothing else needs to be consulted:

- **`app/Support/Permissions.php`** — the `module.action` catalogue. One list
  drives the seeder, the `permission:` middleware on every route, and the
  checkbox matrix on the user form.
- **`app/Support/Roles.php`** — what each of the five roles grants.
- **`app/Support/Scope.php`** — row-level scoping.

The `.all` suffix is scope, not a separate capability: `interviews.view` means
"the interviews I am on", `interviews.view.all` means "everyone's". Most roles
hold only the former, which keeps every list about the person reading it. A team
lead is made by direct-granting the specific `.all` strings to one person rather
than by inventing a seventh role.

Two things sit on top of spatie/laravel-permission:

1. **Nothing bypasses the check.** There is no `Gate::before`: Admin is the
   top role and holds every permission by grant, so a revocation on an admin's
   own account means exactly what the matrix shows.
2. **`users.denied_permissions`** lets a single permission be taken back from one
   person. spatie is additive-only, so without it revoking one capability would
   mean cloning a whole role — and the two copies would drift. The subtraction
   is applied in `User::hasPermissionTo()`, which every check funnels through.

The effective list — role grants + direct grants − revocations — is what gets
shared to Inertia, so the sidebar can never offer a page that then answers 403.

## Theme

Blue `#335CFF` on white, Inter (self-hosted, declared in `app.blade.php`),
hairline `#E1E4EA` borders, `--radius: 0.75rem`. `resources/css/app.css` holds every token as bare
HSL channels; `tailwind.config.js` maps them to utility names.

**The one rule: never put a literal colour in a component.** Every surface is
`bg-card` / `bg-app` / `bg-sidebar`, every status chip is
`bg-stage-won/15 text-stage-won`. That rule is what makes dark mode a token
swap rather than a component-by-component audit, and it is why the whole app
re-themed correctly the moment `.dark` was added.

Stage colours live in `resources/js/config/stages.ts` as **literal class
strings**, one map per workflow. Tailwind scans source files as plain text, so a
class assembled at runtime (`bg-stage-${stage}`) is never generated and the chip
renders unstyled. The repetition is deliberate.

## Build status

**Phase 1 — Foundation. Complete.** Theme and token system, UI primitives, the
permission-filtered shell, sign-in, the five roles with their full permission
catalogue, and a role-aware dashboard.

**Phase 2 — People & access. Complete.** Staff CRUD with soft deletes, the
three-state permission matrix (role grant / direct grant / revocation), the
editable role matrix, TOTP two-factor with recovery codes, own-profile editing,
and the audit log.

The guards worth knowing about, all enforced in `UserPolicy` and
`UpdateUserRequest` and all covered by tests: nobody may deactivate or delete
their own account, nobody may remove their own administrator role, only a Super
the last active administrator cannot be demoted, deactivated, deleted, or have
their administration permissions revoked. Each list row carries the
policy's answer with it, so the interface never offers a button that would 403.

Menu items for modules that do not exist yet render greyed out with a `soon`
pill rather than being hidden, so the team can see the shape of what is coming
and nobody reports a broken link.

**Phase 3 — Interviews. Complete.** The three interview kinds, booking,
conducting with a Q&A editor, file and link attachments on a private disk, the
stage machine, reassignment, cancel/no-show, the anchor's own queue, and the
handover.

Two pieces of this phase are load-bearing for everything after it:

- **`StageMachine`** is the only code in the application that writes a `stage`
  column. It refuses illegal moves, stamps `stage_entered_at`, and writes a
  `stage_events` row inside the same transaction — so history can never drift
  from the column it describes. Articles, deliverables and deals all move
  through it in later phases.
- **The handover rule.** A business interview cannot reach the content team
  without the write-up document, the recording (uploaded or linked) and the
  typed Q&A. `Interview::missingForHandover()` is the single definition;
  `HandoverService` enforces it, and the interview page shows what is
  outstanding *before* anybody presses the button. A truth table over all eight
  combinations is in `InterviewWorkflowTest`.

**Phase 4 — Content pipeline. Complete.** Articles created automatically by the
handover, the anchor's transcript and brief one click away, the review loop, and
the live URL pasted back on publication.

The article itself is a **file**. There was a rich-text editor here once, and it
existed to produce publish-ready HTML for a bundle somebody then pasted into the
website — a second place to write the same article, and the one nobody would
keep in step. The team writes in Word and publishes on the site by hand, so the
draft is an uploaded document and every version is kept.

The rules that carry this phase:

- **An article is created by the handover, in the same transaction.** Left to be
  created by hand, a handover becomes an item in somebody's head — the interview
  claims to be with the content team and there is nothing in the queue to show
  for it. The draft is seeded with the anchor's Q&A folded into prose, which
  saves the writer transcribing and keeps the quotes accurate.
- **A writer cannot approve their own work.** A review you can grant yourself is
  not a review.
- **Nothing publishes without the live URL.** Until it is recorded, this system
  cannot tell a published article from an approved one nobody got round to — so
  the URL is what closes the loop, and it is required.
- **The body is sanitised on write, never on read.** It ends up pasted onto a
  public website; cleaning on output would leave the dangerous version in the
  database waiting for the one place that forgets to filter.

**Phase 5 — Creative. Complete.** Deliverables hanging off an interview *or* an
article, versioned uploads, the approval loop, the board, and recorded social
links.

- **The polymorphic parent is the point.** Two of the three interview kinds
  never produce an article, so a deliverable keyed to an article could not
  represent their output at all. Hanging it off either is what finally gives
  `celebrity_social` and `meet_social` somewhere for their reels to live.
- **Three hands, kept apart.** Whoever makes a piece of work cannot approve it,
  and approving is not the same permission as posting it. A poster goes out with
  a client's phone number on it, so the client-facing manager approves; the
  creative with the platform account open posts.
- **Approval is scoped, not blanket.** A manager approves work for the clients
  they look after — one with no connection to the interview holds the permission
  but not the standing.
- **Nothing is posted without the link.** Same rule as the article's published
  URL: until it is recorded, an approved reel and a posted one look identical.

**Phase 6 — Sales. Complete.** Clients with CSV export, the drag-and-drop
pipeline, deals with weighted values, monthly targets with attainment, and the
follow-up chase list.

- **One `deals` table, not leads-and-deals.** A lead that converts is the same
  conversation with the same person about the same money. Two tables would mean
  copying it across at an arbitrary moment and reconciling two histories.
- **A deal can move backwards.** Unlike every other workflow here, the pipeline
  allows any open stage to follow any other — a deal that went quiet genuinely
  is cold again, and a board that refuses is one people work around by closing
  it and opening a new one, which destroys the history the board exists to keep.
- **Closing is a separate permission from moving.** It is the one action that
  writes revenue, and every attainment figure is a sum of what it writes — so a
  win requires the amount actually signed, not the quote.
- **A closed deal stays closed.** Reopening one would make "won this month" a
  figure that can change after the month has been reported.
- **Attainment is computed on read, never stored.** A stored figure is one that
  goes stale the first time a recalculation is missed.

**Phase 7 — Dashboards, reports & notifications. Complete.** A dashboard of
real, scoped numbers led by what is actually blocked; the notification bell and
its fan-out; and a reports page covering the four questions this team asks.

- **The dashboard leads with "Needs you", not with counts.** Counts tell
  somebody how they are doing; a list of what is blocked — naming *why*, not
  just *that* — tells them what to open next, which is the only reason to look
  at a dashboard before ten in the morning.
- **Every figure is scoped exactly as its module is.** A tile counting rows the
  viewer cannot open is worse than no tile: they click it, land on an empty
  list, and stop believing the numbers.
- **Two notification rules, enforced in one place.** Nobody is told about their
  own action, and nobody is told twice about one event. Both are trivial to
  state and impossible to remember at twenty call sites.
- **`Notifier::toPermission()` reaches "whoever can approve this"** without
  naming a role — and it respects per-user revocations, which live in a JSON
  column no join can see.
- **Reports use the median, not the mean.** One interview that sat for three
  months would drag an average badly enough to make the figure useless, and
  that outlier happens once a quarter.

**Phase 8 — Deploy. Complete.** Production configuration for Hostinger shared
hosting, the scheduled work, the operator commands, and
[the deployment guide](docs/DEPLOY.md).

- **No password is seeded anywhere.** `php artisan tcc:setup` asks for one, or
  generates one and shows it once. A seeded administrator password is a
  password that exists in the repository, on the deployer's laptop, and in the
  shell history of whoever ran it.
- **One cron entry drives everything.** Shared hosting has no supervisor, so
  the scheduler starts a worker every minute that drains the queue and exits.
  The cost is up to sixty seconds of latency on a queued job; nothing
  user-facing waits on one.
- **The morning digest is one notification, not fourteen.** Everything waiting
  on a person collapses into a single line with a link to the dashboard, which
  already explains it. And it only covers work somebody *owns* — a reminder
  about a shared queue is addressed to everybody and therefore to nobody, and
  it arrives every morning until somebody unrelated clears it.
- **`php artisan tcc:health` checks what deployment actually breaks** — the
  storage symlink, a stale asset manifest, PHP's upload limits, a missing cron,
  demo accounts left live, debug mode left on. Most of these are invisible
  until somebody hits them, and two of them fail silently rather than loudly.
- **Two ways back in.** An administrator clears a lost two-factor enrolment
  from the user page; `tcc:two-factor-reset` and `tcc:password` cover the case
  the interface cannot — the only administrator, locked out, with nobody left
  to click the button.

### Fixed during this phase

**Every clock in the system was wrong by five and a half hours.** Laravel
defaults `app.timezone` to UTC, so a 10 am interview was stored as `10:00`,
sent to the browser as `10:00+00:00`, and rendered in India as 3:30 pm. Nothing
errored; the times were simply wrong, and the only way to notice was to read a
clock and know what it should have said. `TimezoneTest` now asserts the offset,
which is the thing that was actually missing.

Date-only columns were fixed in the same pass but for a different reason: a due
date is a calendar date, not a moment, and serialising it as an instant is what
makes the 25th render as the 24th for a viewer in another timezone.

**The 200 MB upload cap had never worked.** The application accepts video cuts
that large; PHP was configured for 40 MB. Over-sized uploads do not error —
PHP discards the request body before the application sees it, so the form
arrives empty and the creative is told "the file field is required" about a
file they definitely attached. `tcc:health` now compares the two limits, and
`deploy/user.ini` sets them.

**A lost phone was a permanent lockout.** Once somebody enrolled in two-factor
there was no way for anyone to clear it for them. Now there are two, and both
end that account's live sessions, because the usual reason for a reset is a
phone that went missing rather than one that was replaced.

## The three modules finished after Phase 8

**Projects.** The package a client bought — what a won deal becomes. Interviews
are booked against one, so a run of six reads as one job rather than six
unrelated rows.

- **Delivery is counted, never stored.** A stored "percent complete" is a number
  somebody has to remember to update, and the first time they forget it becomes
  a number that lies. Counting the bookings underneath is always current.
- **A cancelled booking leaves the count entirely.** A subject who cancels makes
  the package smaller, not less complete — counting them in the denominator
  would leave a project stuck below 100% through nobody's fault.
- **A project's client and an interview's client must agree.** Both fields are
  optional and independently valid, so nothing else catches a booking filed
  against a package sold to a different business — which would count that
  interview towards the wrong project's delivery.
- **Deleting is for a project created by mistake.** One with work behind it is
  cancelled instead, which keeps the history.

**Settings.** The durations that drive reminders and default deadlines, plus the
category list.

- **Bounds are the point, not tidiness.** Every value here drives something that
  runs on a schedule. A handover grace of zero days nags an anchor about an
  interview they finished ten minutes ago; an idle threshold of ten years
  switches the "gone quiet" list off without anybody realising they did it. The
  bounds are what stop a settings page being the fastest route to a quietly
  broken system, and they are enforced on read as well as on write.
- **Defaults live in config, not in the database.** A key that has never been
  written still reads correctly, so a fresh install works before anybody opens
  the page and a setting added in a later release needs no data migration.
- **A category with articles is switched off, not deleted** — those articles were
  published under that name. Renaming one leaves its slug alone once anything is
  filed under it, because the slug is part of a live URL.

**Performance.** Per-person load and turnaround.

- **It is a workload view, not a ranking**, and it says so at the top rather than
  in a footnote. Three long interviews are not less than nine short ones. Every
  table sorts by what is open *now*, not by what was finished, because "who is
  swamped and where is work stuck" is the question a manager actually has.
- **Load is present tense.** Somebody swamped today is swamped today, whatever
  they did in March — so only the finished counts and the turnarounds look back
  over the window.
- **Rework is counted** because it is the number worth knowing and the one nobody
  volunteers. A high count is far more often a vague brief than a careless
  editor, which is exactly why it belongs on a shared page next to whoever wrote
  the brief.
- **Turnaround is read against the team median**, not against the fastest person
  — a column where everybody but one reads red is a column nobody looks at
  twice.

### Fixed while building them

**Every duration in the reporting could go negative.** Carbon 3 returns a
*signed* float from `diffInDays`, so a record whose `published_at` precedes its
`created_at` — backfilled data, an import, a clock that slipped — rendered as
"-2d" and dragged the team median below zero. A report claiming work finished
before it started, with no error anywhere. `Stats::daysBetween()` now treats a
backwards duration as **unmeasurable rather than negative**; `abs()` would have
been worse, since it gives a confident answer to a question the data cannot
answer.

**A finished project was shown as overdue.** A project delivered in July was due
in July and is not late — but the shared date helper says "45d overdue" for any
past date, so a closed project rendered in red and disagreed with the overdue
count on the same page.

**The client picker on the interview form was unscoped**, offering every client
in the database to a manager who can only see their own book. That is a
directory leak wearing a picker, and it also let somebody file work against an
account they could not then open.

## Leads

A manager talks to far more people than ever become clients. Filed as clients,
the directory of people you actually look after fills with names nobody heard
back from, and every count on every page starts describing something else — so
prospects live in their own list until one of them says yes.

- **Converting creates the client; the lead survives it.** Moving the row would
  throw away where the client came from and how long they took to say yes, and
  it would make the conversion rate unanswerable because the numerator had been
  deleted.
- **The first deal is offered at the moment of conversion**, because that is
  when the value is known. Left as a second job it becomes a client with nothing
  in the pipeline — a name in a directory.
- **"Log a call" takes what happened *and* when to ring back**, on one form. The
  moment somebody finishes a call is the only moment they know when to try
  again, and the moment they are least willing to open a second form.
- **"Not a fit" asks why.** That is the answer for a lead that said no, and it
  keeps the record — which is the point of writing prospects down at all.
  Deleting is for one entered twice.

## Checking it in a browser

Two harnesses beyond the test suite. Both need the app running.

```bash
node scripts/audit.mjs     --base=http://127.0.0.1:8391   # access matrix
node scripts/workflow.mjs  --base=http://127.0.0.1:8391   # the SOP, end to end
```

`audit.mjs` signs in as all five roles and opens every module page, comparing
what happens against what that role is *supposed* to reach. The expectations are
written from each role's job rather than copied from the permission seeder —
copying it would only prove the seeder agrees with itself.

`workflow.mjs` walks the SOP as the people who would really do it: a manager
converts a lead and books an interview, an anchor conducts it, a writer opens
the article, a creative opens the board. It writes real records, so point it at
a development database.

It found the bug that every unit test missed: creating a project with the Value
field left blank returned a 500, because the column was `NOT NULL` while the
form treated it as optional. Every test passed a value.

## Operating it

| Command | |
|---|---|
| `php artisan tcc:setup` | create the first administrator |
| `php artisan tcc:health` | check the installation — run it after every deploy |
| `php artisan tcc:password EMAIL` | reset a password and end that account's sessions |
| `php artisan tcc:two-factor-reset EMAIL` | clear a two-factor enrolment |
| `php artisan tcc:reminders --dry-run` | see what the morning digest would send |

Deployment is [docs/DEPLOY.md](docs/DEPLOY.md). `bash scripts/deploy.sh` runs
the server-side half and refuses to start against a half-configured install.
