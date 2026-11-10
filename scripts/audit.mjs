/*
 | A full access audit, in a real browser.
 |
 |   node scripts/audit.mjs --base=http://127.0.0.1:8391
 |
 | ── What this checks that the test suite does not ──────────────────────────
 | The feature tests assert the rules the application was written to. This
 | signs in as every role and walks every page, comparing what actually happens
 | against what each role is *supposed* to be able to reach — so a permission
 | that drifted away from the role's stated job shows up as a mismatch rather
 | than as a passing test of the wrong rule.
 |
 | It also catches the failure the server-side suite structurally cannot see: a
 | page that answers 200, loads its bundle, and then mounts nothing.
 |
 | Exit code is 0 when every outcome matched expectation.
 */
import { existsSync, writeFileSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const DEFAULT_CHROME = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

function arg(name, fallback = null) {
    const match = process.argv.find((value) => value.startsWith(`--${name}=`));

    return match ? match.slice(name.length + 3) : fallback;
}

const base = arg('base', 'http://127.0.0.1:8391');
const password = arg('password', 'password');

const USERS = [
    { role: 'Admin', email: 'admin@takecareconnect.com' },
    { role: 'Anchor', email: 'anchor@takecareconnect.com' },
    { role: 'Content', email: 'content@takecareconnect.com' },
    { role: 'Creative', email: 'creative@takecareconnect.com' },
    { role: 'Relationship Manager', email: 'rm@takecareconnect.com' },
    { role: 'Telecaller', email: 'telecaller@takecareconnect.com' },
    { role: 'Sales Executive', email: 'sales@takecareconnect.com' },
    { role: 'Sales Manager', email: 'salesmanager@takecareconnect.com' },
];

/*
 | What each role should be able to reach, by module.
 |
 | Written from the job each role does — "an anchor conducts interviews and
 | hands the write-up over", "a creative makes posters and reels" — rather than
 | copied from the permission seeder. Copying the seeder would only prove the
 | seeder agrees with itself; this is the check that the grants still match what
 | the roles are for.
 */
const MODULES = [
    {
        module: 'Overview',
        pages: [
            { path: '/dashboard', label: 'Dashboard', allow: 'all' },
            { path: '/notifications', label: 'Notifications', allow: 'all' },
            { path: '/profile', label: 'Profile', allow: 'all' },
        ],
    },
    {
        module: 'Leads',
        pages: [
            { path: '/leads', label: 'Lead list', allow: ['Admin', 'Relationship Manager', 'Telecaller', 'Sales Executive', 'Sales Manager'] },
            { path: '/leads/create', label: 'Add lead', allow: ['Admin', 'Relationship Manager', 'Telecaller', 'Sales Executive', 'Sales Manager'] },
            { path: '/leads/import', label: 'Import leads', allow: ['Admin', 'Relationship Manager', 'Telecaller', 'Sales Executive', 'Sales Manager'] },
        ],
    },
    {
        module: 'Clients',
        pages: [
            { path: '/clients', label: 'Client list', allow: ['Admin', 'Relationship Manager', 'Anchor', 'Content', 'Sales Executive', 'Sales Manager'] },
            { path: '/clients/create', label: 'Add client', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
        ],
    },
    {
        module: 'Projects',
        pages: [
            { path: '/projects', label: 'Project list', allow: ['Admin', 'Relationship Manager', 'Anchor', 'Content'] },
            { path: '/projects/create', label: 'New project', allow: ['Admin', 'Relationship Manager'] },
        ],
    },
    {
        module: 'Deals',
        pages: [
            { path: '/deals', label: 'Deal list', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/pipeline', label: 'Pipeline board', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/targets', label: 'Targets', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/follow-ups', label: 'Follow-ups', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/proposals', label: 'Proposals', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/sales/leaderboard', label: 'Leaderboard', allow: ['Admin', 'Relationship Manager', 'Sales Executive', 'Sales Manager'] },
            { path: '/sales/team', label: 'Sales team', allow: ['Admin', 'Sales Manager'] },
        ],
    },
    {
        module: 'Interviews',
        pages: [
            {
                path: '/interviews',
                label: 'All interviews',
                allow: ['Admin', 'Anchor', 'Content', 'Creative', 'Relationship Manager', 'Telecaller'],
            },
            {
                path: '/interviews/my-queue',
                label: 'My queue',
                allow: ['Admin', 'Anchor', 'Content', 'Creative', 'Relationship Manager', 'Telecaller'],
            },
            {
                path: '/interviews/create',
                label: 'Book interview',
                allow: ['Admin', 'Relationship Manager', 'Telecaller'],
            },
        ],
    },
    {
        module: 'Content',
        pages: [
            { path: '/articles', label: 'All articles', allow: ['Admin', 'Anchor', 'Content'] },
            { path: '/articles/queue', label: 'Article queue', allow: ['Admin', 'Anchor', 'Content'] },
        ],
    },
    {
        module: 'Creative',
        pages: [
            {
                path: '/deliverables',
                label: 'Deliverables',
                allow: ['Admin', 'Anchor', 'Content', 'Creative', 'Relationship Manager'],
            },
            {
                path: '/deliverables/board',
                label: 'Creative board',
                allow: ['Admin', 'Anchor', 'Content', 'Creative', 'Relationship Manager'],
            },
        ],
    },
    {
        module: 'Reports',
        pages: [
            { path: '/reports', label: 'Reports', allow: 'all' },
            { path: '/reports/performance', label: 'Performance', allow: ['Admin', 'Content', 'Sales Manager'] },
            { path: '/reports/impact', label: 'Impact', allow: ['Admin', 'Content', 'Sales Manager'] },
        ],
    },
    {
        module: 'Administration',
        pages: [
            { path: '/users', label: 'People', allow: ['Admin'] },
            { path: '/users/create', label: 'Add person', allow: ['Admin'] },
            { path: '/roles', label: 'Roles', allow: ['Admin'] },
            { path: '/audit', label: 'Audit log', allow: ['Admin'] },
            { path: '/settings', label: 'Settings', allow: ['Admin'] },
        ],
    },
];

const executablePath = process.env.CHROME_PATH || DEFAULT_CHROME.find((candidate) => existsSync(candidate));

if (!executablePath) {
    console.error('No Chrome found. Set CHROME_PATH to the browser binary.');
    process.exit(1);
}

const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});

const rows = [];

try {
    for (const user of USERS) {
        const page = await signIn(user.email);

        for (const group of MODULES) {
            for (const item of group.pages) {
                const expected = item.allow === 'all' || item.allow.includes(user.role) ? 'allow' : 'deny';
                const seen = await visit(page, item.path);

                rows.push({
                    role: user.role,
                    module: group.module,
                    page: item.label,
                    path: item.path,
                    expected,
                    ...seen,
                    ok: seen.actual === expected && seen.errors.length === 0 && seen.mounted !== false,
                });
            }
        }

        await page.close();
    }
} finally {
    await browser.close();
}

report(rows);

/* ───────────────────────────────────────────────────────────────────────── */

async function signIn(email) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();

    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });

    if (!(await page.$('#email'))) {
        console.error(`\nNo sign-in form at ${base}/login. Is the server running?\n`);
        process.exit(1);
    }

    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
        page.$eval('form', (form) => form.requestSubmit()),
    ]);

    if (page.url().endsWith('/login')) {
        const said = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 200));

        console.error(`\nCould not sign in as ${email}. The page says: ${said}\n`);
        process.exit(1);
    }

    return page;
}

/**
 * Open one page and report what happened.
 *
 * `mounted` is the check the server-side suite cannot make: a 200 whose root
 * element is empty is a page that answered, loaded its bundle, and then threw.
 */
async function visit(page, path) {
    const errors = [];

    const onError = (error) => errors.push(String(error.message ?? error).slice(0, 160));
    const onConsole = (message) => {
        if (message.type() === 'error') errors.push(message.text().slice(0, 160));
    };

    page.on('pageerror', onError);
    page.on('console', onConsole);

    let status = 0;

    try {
        const response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle0' });

        status = response?.status() ?? 0;
    } catch (error) {
        errors.push(String(error.message).slice(0, 160));
    }

    const mounted =
        status === 200
            ? await page.evaluate(() => (document.getElementById('app')?.innerHTML.trim().length ?? 0) > 0)
            : null;

    page.off('pageerror', onError);
    page.off('console', onConsole);

    /*
     | A refusal logs its own console error.
     |
     | Chrome writes "Failed to load resource: 403" for the navigation itself,
     | so counting that as a JavaScript fault marks every correctly-refused page
     | as broken — 56 false failures on the first run, and a report nobody would
     | read twice. What matters on a denied page is that it was denied.
     */
    const refused = status === 403;
    const real = refused
        ? errors.filter((message) => !message.includes('403'))
        : errors;

    return {
        status,
        mounted,
        errors: real,
        actual: refused ? 'deny' : status === 200 ? 'allow' : `http ${status}`,
    };
}

function report(rows) {
    const failures = rows.filter((row) => !row.ok);

    console.log('\n════════════════════════════════════════════════════════════════');
    console.log('  ACCESS AUDIT — every role, every module');
    console.log('════════════════════════════════════════════════════════════════\n');

    for (const user of USERS) {
        const mine = rows.filter((row) => row.role === user.role);
        const bad = mine.filter((row) => !row.ok).length;

        console.log(`\n${user.role}  (${user.email})`);
        console.log('─'.repeat(64));

        let lastModule = '';

        for (const row of mine) {
            if (row.module !== lastModule) {
                console.log(`  ${row.module}`);
                lastModule = row.module;
            }

            const mark = row.ok ? '✓' : '✗';
            const verdict = row.actual === 'allow' ? 'can open' : row.actual === 'deny' ? 'refused' : row.actual;
            const note = row.ok ? '' : `  ← expected ${row.expected === 'allow' ? 'can open' : 'refused'}`;
            const js = row.errors.length ? `  [${row.errors.length} JS error]` : '';
            const empty = row.mounted === false ? '  [rendered nothing]' : '';

            console.log(`    ${mark} ${row.page.padEnd(18)} ${verdict.padEnd(9)}${note}${js}${empty}`);
        }

        console.log(`  ${bad === 0 ? 'All correct' : `${bad} mismatch(es)`}`);
    }

    console.log('\n════════════════════════════════════════════════════════════════');
    console.log(`  ${rows.length} checks · ${rows.length - failures.length} passed · ${failures.length} failed`);
    console.log('════════════════════════════════════════════════════════════════\n');

    if (failures.length) {
        console.log('MISMATCHES\n');

        for (const row of failures) {
            console.log(`  ${row.role} → ${row.path}`);
            console.log(`     expected ${row.expected}, got ${row.actual}`);
            row.errors.forEach((error) => console.log(`     js: ${error}`));
            if (row.mounted === false) console.log('     the page rendered an empty root');
        }

        console.log('');
    }

    writeFileSync('storage/audit.json', JSON.stringify(rows, null, 2));
    console.log('Full detail: storage/audit.json\n');

    process.exit(failures.length === 0 ? 0 : 1);
}
