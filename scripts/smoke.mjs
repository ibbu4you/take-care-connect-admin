/*
 | Visit every page as a real browser and fail on any JavaScript error.
 |
 | ── Why this exists ────────────────────────────────────────────────────────
 | A feature test asserting `assertOk()` passes when the server renders fine and
 | the React page then throws — the response was a perfectly good 200 containing
 | a payload the component could not read. That is exactly how /users shipped as
 | a blank screen: the controller sent Laravel's flat paginator, the component
 | expected a `meta` wrapper, and every server-side assertion was green.
 |
 | So this checks the half the PHP tests cannot see: that each page actually
 | mounts. It is a smoke test, not a functional one — it does not click
 | anything. Its whole job is to catch "renders nothing at all".
 |
 |   npm run smoke                       # against http://127.0.0.1:8000
 |   npm run smoke -- --base=http://127.0.0.1:8391
 |
 | Set CHROME_PATH if Chrome is not in the default location. Exits non-zero on
 | the first page that errors, so it is usable in CI.
 */
import { existsSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const DEFAULT_CHROME = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

function arg(name, fallback) {
    const match = process.argv.find((value) => value.startsWith(`--${name}=`));

    return match ? match.slice(name.length + 3) : fallback;
}

/**
 * The first link on the page that points at one record of a module.
 *
 * ── Why by shape rather than by exclusion ──────────────────────────────────
 * The selectors used to be `a[href*="/leads/"]` with a `:not()` for every
 * literal segment that was not an id — create, edit, board. That list is only
 * ever right until somebody adds a route: `/leads/export` slipped straight
 * through and puppeteer aborted trying to follow a CSV download, which reads as
 * a broken page rather than as a bad selector.
 *
 * A record link is `/module/<digits>`. Matching that shape needs no list.
 */
function firstRecord(page, module) {
    return page.evaluate((name) => {
        const pattern = new RegExp(`^/${name}/\\d+$`);

        const link = [...document.querySelectorAll('a[href]')]
            .map((anchor) => new URL(anchor.href).pathname)
            .find((path) => pattern.test(path));

        return link ?? null;
    }, module);
}

const base = arg('base', process.env.APP_URL || 'http://127.0.0.1:8000');
const password = arg('password', 'password');

/*
 | Each route is visited as the least-privileged role that should be able to
 | reach it, so this doubles as a check that the permission gates have not
 | quietly closed on somebody who needs the page.
 */
const ROUTES = [
    { path: '/dashboard', as: 'anchor@takecareconnect.com' },
    { path: '/dashboard', as: 'rm@takecareconnect.com' },
    { path: '/dashboard', as: 'creative@takecareconnect.com' },
    { path: '/dashboard', as: 'telecaller@takecareconnect.com' },
    { path: '/leads', as: 'telecaller@takecareconnect.com' },
    { path: '/interviews/create', as: 'telecaller@takecareconnect.com' },
    { path: '/leads/import', as: 'telecaller@takecareconnect.com' },
    { path: '/reports/impact', as: 'admin@takecareconnect.com' },
    { path: '/profile', as: 'anchor@takecareconnect.com' },
    { path: '/users', as: 'admin@takecareconnect.com' },
    { path: '/users/create', as: 'admin@takecareconnect.com' },
    {
        /*
         | Discovered rather than hard-coded.
         |
         | A fixed id breaks the moment the seed order changes, and picking id 1
         | is guaranteed to be wrong: that is the Super Admin, whom an Admin is
         | correctly refused. Taking the first edit link the list actually
         | offers means this always visits a row this account may open.
         */
        path: '/users',
        as: 'admin@takecareconnect.com',
        follow: (page) =>
            page.evaluate(() => {
                const link = document.querySelector('a[href$="/edit"]');

                return link ? new URL(link.href).pathname : null;
            }),
    },
    { path: '/interviews', as: 'anchor@takecareconnect.com' },
    { path: '/interviews', as: 'content@takecareconnect.com' },
    { path: '/interviews/my-queue', as: 'anchor@takecareconnect.com' },
    { path: '/interviews/create', as: 'rm@takecareconnect.com' },
    {
        // Whichever interview the anchor's own list actually offers.
        path: '/interviews',
        as: 'anchor@takecareconnect.com',
        follow: (page) => firstRecord(page, 'interviews'),
    },
    { path: '/articles', as: 'content@takecareconnect.com' },
    { path: '/articles/queue', as: 'content@takecareconnect.com' },
    {
        // The editor, on whichever article the queue actually offers.
        path: '/articles',
        as: 'content@takecareconnect.com',
        follow: (page) => firstRecord(page, 'articles'),
    },
    { path: '/deliverables', as: 'creative@takecareconnect.com' },
    { path: '/deliverables/board', as: 'creative@takecareconnect.com' },
    { path: '/deliverables/board', as: 'rm@takecareconnect.com' },
    {
        path: '/deliverables',
        as: 'creative@takecareconnect.com',
        follow: (page) => firstRecord(page, 'deliverables'),
    },
    { path: '/projects', as: 'rm@takecareconnect.com' },
    { path: '/projects', as: 'anchor@takecareconnect.com' },
    { path: '/projects/create', as: 'rm@takecareconnect.com' },
    {
        path: '/projects',
        as: 'rm@takecareconnect.com',
        follow: (page) => firstRecord(page, 'projects'),
    },
    { path: '/settings', as: 'admin@takecareconnect.com' },
    { path: '/reports/performance', as: 'admin@takecareconnect.com' },
    { path: '/reports/performance?months=12', as: 'content@takecareconnect.com' },
    { path: '/leads', as: 'rm@takecareconnect.com' },
    { path: '/leads', as: 'admin@takecareconnect.com' },
    { path: '/leads/create', as: 'rm@takecareconnect.com' },
    {
        path: '/leads',
        as: 'rm@takecareconnect.com',
        follow: (page) => firstRecord(page, 'leads'),
    },
    { path: '/clients', as: 'rm@takecareconnect.com' },
    { path: '/clients/create', as: 'rm@takecareconnect.com' },
    { path: '/pipeline', as: 'rm@takecareconnect.com' },
    { path: '/deals', as: 'rm@takecareconnect.com' },
    { path: '/targets', as: 'rm@takecareconnect.com' },
    { path: '/targets', as: 'admin@takecareconnect.com' },
    { path: '/follow-ups', as: 'rm@takecareconnect.com' },
    {
        path: '/clients',
        as: 'rm@takecareconnect.com',
        follow: (page) =>
            page.evaluate(() => {
                const link = document.querySelector('a[href*="/clients/"]:not([href$="/create"]):not([href$="/edit"]):not([href$="/export"])');

                return link ? new URL(link.href).pathname : null;
            }),
    },
    {
        path: '/deals',
        as: 'rm@takecareconnect.com',
        follow: (page) =>
            page.evaluate(() => {
                const link = document.querySelector('a[href*="/deals/"]');

                return link ? new URL(link.href).pathname : null;
            }),
    },
    { path: '/notifications', as: 'content@takecareconnect.com' },
    { path: '/reports', as: 'rm@takecareconnect.com' },
    { path: '/reports', as: 'admin@takecareconnect.com' },
    { path: '/reports?months=12', as: 'admin@takecareconnect.com' },
    { path: '/roles', as: 'admin@takecareconnect.com' },
    { path: '/audit', as: 'admin@takecareconnect.com' },
    { path: '/two-factor/setup', as: 'anchor@takecareconnect.com' },
];

const executablePath =
    process.env.CHROME_PATH || DEFAULT_CHROME.find((candidate) => existsSync(candidate));

if (!executablePath) {
    console.error('No Chrome found. Set CHROME_PATH to the browser binary.');
    process.exit(1);
}

const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});

let failures = 0;

try {
    // One session per account, reused across that account's routes — signing in
    // eleven times would trip the login rate limiter and report a false failure.
    const sessions = new Map();

    for (const route of ROUTES) {
        const page = await session(sessions, route.as);
        const errors = [];

        const onError = (error) => errors.push(String(error.message ?? error));
        const onConsole = (message) => {
            if (message.type() === 'error') {
                errors.push(message.text());
            }
        };

        page.on('pageerror', onError);
        page.on('console', onConsole);

        let path = route.path;
        let response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle0' });

        // A route that finds its own target: load the listing, read a real link
        // out of it, then check that instead.
        if (route.follow) {
            const found = await route.follow(page);

            if (!found) {
                failures++;
                console.log(`FAIL  ${path} → (no link found to follow)`);

                continue;
            }

            path = found;
            response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle0' });
        }

        // An empty root element is the signature failure this exists to catch:
        // the server answered, the bundle loaded, and React mounted nothing.
        const mounted = await page.evaluate(() => {
            const root = document.getElementById('app');

            return Boolean(root && root.children.length > 0);
        });

        page.off('pageerror', onError);
        page.off('console', onConsole);

        const status = response?.status() ?? 0;
        const ok = status < 400 && mounted && errors.length === 0;

        if (!ok) {
            failures++;
        }

        const label = `${path}  (${route.as.split('@')[0]})`;
        console.log(
            `${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(44)} ${status}${mounted ? '' : '  EMPTY ROOT'}`,
        );

        errors.slice(0, 3).forEach((error) => console.log(`        ${error}`));
    }
} finally {
    await browser.close();
}

console.log(failures === 0 ? '\nAll pages rendered.' : `\n${failures} page(s) failed.`);
process.exit(failures === 0 ? 0 : 1);

async function session(sessions, email) {
    if (sessions.has(email)) {
        return sessions.get(email);
    }

    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1440, height: 950 });

    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });

    /*
     | Say what is actually wrong.
     |
     | Without this, pointing at the wrong port or a server that is not running
     | surfaces as puppeteer's "No element found for selector: #email" and a
     | stack trace into its internals — which reads like a broken login page
     | rather than a missing --base flag.
     */
    if ((await page.$('#email')) === null) {
        console.error('');
        console.error(`Could not find the sign-in form at ${base}/login`);
        console.error(`  Landed on: ${page.url()}`);
        console.error(`  Title:     ${await page.title()}`);
        console.error('');
        console.error('  Is the server running, and is --base pointing at it?');
        console.error('  e.g. node scripts/smoke.mjs --base=http://127.0.0.1:8391');
        console.error('');
        process.exit(1);
    }

    await page.type('#email', email);
    await page.type('#password', password);
    // Submit the form rather than clicking the button, which depends on the
    // button being on screen. See the note in scripts/screenshot.mjs.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
        page.$eval('form', (form) => form.requestSubmit()),
    ]);

    sessions.set(email, page);

    return page;
}
