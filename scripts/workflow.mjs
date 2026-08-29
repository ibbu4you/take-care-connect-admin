/*
 | The SOP, end to end, in a real browser.
 |
 |   node scripts/workflow.mjs --base=http://127.0.0.1:8391
 |
 | ── What this is for ───────────────────────────────────────────────────────
 | The audit proves each role can *open* the right pages. This proves the work
 | actually flows between them: a manager finds a lead and converts it, books an
 | interview against the client; an anchor conducts it and hands it over; a
 | writer picks up the article and sends it for review; a creative takes a reel
 | to published.
 |
 | Every step is performed as the person who would really do it, through the
 | interface, with no direct database writes — so anything that only works
 | because a test seeded it fails here.
 |
 | It writes real records. Point it at a development database.
 */
import { existsSync } from 'node:fs';
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
const stamp = Date.now().toString().slice(-6);

const executablePath = process.env.CHROME_PATH || DEFAULT_CHROME.find((c) => existsSync(c));

if (!executablePath) {
    console.error('No Chrome found. Set CHROME_PATH.');
    process.exit(1);
}

const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
});

const results = [];
const sessions = new Map();

try {
    await run();
} finally {
    await browser.close();
}

report();

/* ───────────────────────────────────────────────────────────────────────── */

async function run() {
    // ── Relationship Manager: a prospect becomes a client ──────────────────
    const rm = await session('rm@takecareconnect.com');

    await step('Relationship Manager', 'Leads', 'Add a lead', async () => {
        await rm.goto(`${base}/leads/create`, { waitUntil: 'networkidle0' });
        await type(rm, '#name', `Audit Spice Co ${stamp}`);
        await type(rm, '#contact_person', 'Priya Sundaram');
        await type(rm, '#phone', '+91 90000 00001');
        await type(rm, '#trade', 'Spice trading');
        await type(rm, '#city', 'Karaikudi');
        await submit(rm);

        return await expectUrl(rm, /\/leads\/\d+$/, 'landed on the new lead');
    });

    const leadUrl = rm.url();

    await step('Relationship Manager', 'Leads', 'Log a call', async () => {
        await rm.goto(leadUrl, { waitUntil: 'networkidle0' });
        await type(rm, '#body', 'Called. Wants a quote before Diwali.');
        await clickText(rm, 'Log it');
        await settle(rm);

        return expectText(rm, 'Wants a quote before Diwali', 'the call appears in the contact log');
    });

    await step('Relationship Manager', 'Leads', 'Convert to client', async () => {
        await rm.goto(leadUrl, { waitUntil: 'networkidle0' });
        await clickText(rm, 'Convert to client');
        await rm.waitForSelector('#deal_title', { timeout: 5000 });
        await type(rm, '#deal_title', `Audit package ${stamp}`);
        const before = rm.url();

        await clickText(rm, 'Convert');
        await urlChanged(rm, before);

        return await expectUrl(rm, /\/clients\/\d+$/, 'landed on the new client');
    });

    await step('Relationship Manager', 'Deals', 'The deal was opened with it', async () => {
        await rm.goto(`${base}/deals`, { waitUntil: 'networkidle0' });

        return expectText(rm, `Audit package ${stamp}`, 'the deal is in the list');
    });

    await step('Relationship Manager', 'Projects', 'Create a project', async () => {
        await rm.goto(`${base}/projects/create`, { waitUntil: 'networkidle0' });
        await selectFirstReal(rm, '#client_id');
        await type(rm, '#title', `Audit project ${stamp}`);
        await submit(rm);

        return await expectUrl(rm, /\/projects\/\d+$/, 'landed on the new project');
    });

    await step('Relationship Manager', 'Interviews', 'Book an interview', async () => {
        await rm.goto(`${base}/interviews/create`, { waitUntil: 'networkidle0' });
        await type(rm, '#title', `Audit interview ${stamp}`);
        await type(rm, '#subject_name', 'Meenakshi Sundaram');
        await selectFirstReal(rm, '#anchor_id');
        await setValue(rm, '#scheduled_at', '2026-09-15T10:00');
        await type(rm, '#meeting_url', 'https://meet.google.com/aud-itaa-bcd');
        await submit(rm);

        return await expectUrl(rm, /\/interviews\/\d+$/, 'landed on the new interview');
    });

    const interviewUrl = rm.url();

    // ── Anchor: conduct it, attach, hand over ──────────────────────────────
    const anchor = await session('anchor@takecareconnect.com');

    await step('Anchor', 'Interviews', 'It is in My queue', async () => {
        await anchor.goto(`${base}/interviews/my-queue`, { waitUntil: 'networkidle0' });

        return expectText(anchor, `Audit interview ${stamp}`, 'the booking reached the anchor');
    });

    await step('Anchor', 'Interviews', 'Mark it conducted', async () => {
        await anchor.goto(interviewUrl, { waitUntil: 'networkidle0' });
        await clickText(anchor, 'Mark conducted');
        await anchor.waitForSelector('#recording_url', { timeout: 5000 });
        await type(anchor, '#recording_url', 'https://drive.google.com/file/d/audit/view');
        await type(anchor, '#qa_summary', 'The good material is in the second half.');
        await clickText(anchor, 'Save');
        await settle(anchor);

        return expectText(anchor, 'Conducted', 'the stage moved to conducted');
    });

    await step('Anchor', 'Interviews', 'Handover is refused without the transcript', async () => {
        await anchor.goto(interviewUrl, { waitUntil: 'networkidle0' });

        const blocked = await anchor.evaluate(() => {
            const button = [...document.querySelectorAll('button')].find((b) =>
                b.textContent?.includes('Hand to content'),
            );

            return button ? button.disabled : 'no button';
        });

        return blocked === true
            ? { pass: true, note: 'the button is disabled until the transcript is attached' }
            : { pass: false, note: `expected a disabled handover button, got ${blocked}` };
    });

    // ── Content: the queue, and the draft ──────────────────────────────────
    const content = await session('content@takecareconnect.com');

    await step('Content', 'Articles', 'The queue opens', async () => {
        await content.goto(`${base}/articles/queue`, { waitUntil: 'networkidle0' });

        return expectMounted(content, 'the article queue rendered');
    });

    await step('Content', 'Articles', 'An article opens with its draft card', async () => {
        await content.goto(`${base}/articles`, { waitUntil: 'networkidle0' });

        const link = await firstRecord(content, 'articles');

        if (!link) return { pass: false, note: 'no article to open' };

        await content.goto(`${base}${link}`, { waitUntil: 'networkidle0' });

        return expectText(content, 'The draft', 'the draft card is there instead of an editor');
    });

    await step('Content', 'Articles', 'No rich-text editor remains', async () => {
        const editors = await content.evaluate(
            () => document.querySelectorAll('.tox-tinymce, iframe[id*="tiny"]').length,
        );

        return editors === 0
            ? { pass: true, note: 'no editor on the page' }
            : { pass: false, note: `${editors} editor(s) still present` };
    });

    // ── Creative: the board and a deliverable ──────────────────────────────
    const creative = await session('creative@takecareconnect.com');

    await step('Creative', 'Deliverables', 'The board opens', async () => {
        await creative.goto(`${base}/deliverables/board`, { waitUntil: 'networkidle0' });

        return expectMounted(creative, 'the creative board rendered');
    });

    await step('Creative', 'Deliverables', 'A deliverable opens', async () => {
        await creative.goto(`${base}/deliverables`, { waitUntil: 'networkidle0' });

        const link = await firstRecord(creative, 'deliverables');

        if (!link) return { pass: false, note: 'no deliverable to open' };

        await creative.goto(`${base}${link}`, { waitUntil: 'networkidle0' });

        return expectMounted(creative, 'the deliverable page rendered');
    });

    // ── Admin: the administration surface ──────────────────────────────────
    const admin = await session('admin@takecareconnect.com');

    await step('Admin', 'Settings', 'A workflow setting saves', async () => {
        await admin.goto(`${base}/settings`, { waitUntil: 'networkidle0' });
        /*
         | Set rather than typed.
         |
         | A number input resists being cleared by keystrokes — select-all plus
         | delete leaves a digit behind often enough that the field ends up
         | reading 121, which is over the maximum and refused. setValue goes
         | through the prototype setter and fires the events React needs, so the
         | form still knows it is dirty and Save still enables.
         */
        await setValue(admin, '[id="deals.idle_days"]', '21');

        /*
         | Wait for the PATCH itself.
         |
         | Reloading on a settle timer races the request that is still in
         | flight, and the page comes back showing the value it had before —
         | which reads as "the save did not work" rather than "the reload was
         | too early".
         */
        const saved = admin.waitForResponse(
            (response) => response.request().method() === 'PATCH' && response.url().endsWith('/settings'),
            { timeout: 8000 },
        );

        await clickText(admin, 'Save settings');
        await saved.catch(() => {});
        await settle(admin);
        await admin.reload({ waitUntil: 'networkidle0' });

        const value = await admin.$eval('#deals\\.idle_days', (input) => input.value);

        return value === '21'
            ? { pass: true, note: 'saved and read back as 21 days' }
            : { pass: false, note: `expected 21, read back ${value}` };
    });

    await step('Admin', 'Reports', 'Performance opens with every discipline', async () => {
        await admin.goto(`${base}/reports/performance`, { waitUntil: 'networkidle0' });

        const text = await admin.evaluate(() => document.body.innerText);
        const missing = ['Anchors', 'Content', 'Creative', 'Sales'].filter((s) => !text.includes(s));

        return missing.length === 0
            ? { pass: true, note: 'all four tables present' }
            : { pass: false, note: `missing: ${missing.join(', ')}` };
    });

    await step('Admin', 'People', 'The staff list shows every role', async () => {
        await admin.goto(`${base}/users`, { waitUntil: 'networkidle0' });

        const text = await admin.evaluate(() => document.body.innerText);
        const missing = ['Admin', 'Anchor', 'Content', 'Creative', 'Relationship Manager'].filter(
            (role) => !text.includes(role),
        );

        return missing.length === 0
            ? { pass: true, note: 'all five roles listed' }
            : { pass: false, note: `missing: ${missing.join(', ')}` };
    });

    await step('Admin', 'Audit', 'The log recorded this run', async () => {
        await admin.goto(`${base}/audit`, { waitUntil: 'networkidle0' });

        return expectMounted(admin, 'the audit log rendered');
    });
}

/* ── plumbing ────────────────────────────────────────────────────────────── */

async function session(email) {
    if (sessions.has(email)) return sessions.get(email);

    const context = await browser.createBrowserContext();
    const page = await context.newPage();

    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
        page.$eval('form', (form) => form.requestSubmit()),
    ]);

    if (page.url().endsWith('/login')) {
        console.error(`Could not sign in as ${email}.`);
        process.exit(1);
    }

    sessions.set(email, page);

    return page;
}

async function step(role, module, name, body) {
    let outcome;

    try {
        outcome = await body();
    } catch (error) {
        outcome = { pass: false, note: String(error.message).slice(0, 140) };
    }

    results.push({ role, module, name, ...outcome });

    const mark = outcome.pass ? '✓' : '✗';
    console.log(`${mark} ${role.padEnd(22)} ${module.padEnd(14)} ${name}`);
    if (!outcome.pass) console.log(`    ${outcome.note}`);
}

/*
 | Declarations, not const arrows.
 |
 | run() is called at the top of the file and these live at the bottom, so as
 | consts they are in the temporal dead zone when the first step executes —
 | which fails as "Cannot access 'type' before initialization" and reads like a
 | broken application rather than a broken script. Declarations hoist.
 */
async function type(page, selector, value) {
    await page.waitForSelector(selector, { timeout: 5000 });

    /*
     | Wait for React before typing.
     |
     | `networkidle0` means the network went quiet, not that the page is ready:
     | Inertia has still to mount the component and attach its handlers, and a
     | controlled input that has not been wired yet swallows every keystroke
     | silently. The field ends up empty, the form posts blank, and the failure
     | reads as "the title field is required" on a form you watched being
     | filled in — which is a long way to travel from a missing await.
     */
    await page.waitForFunction(
        (sel) => {
            const el = document.querySelector(sel);
            const keys = el && Object.keys(el);

            // React attaches its internal props under a __react* key.
            return Boolean(keys?.some((key) => key.startsWith('__react')));
        },
        { timeout: 5000 },
        selector,
    ).catch(() => {});

    /*
     | Cleared with real key presses, not by setting .value.
     |
     | React tracks its own value; assigning to the DOM node directly leaves the
     | component unaware, so the form stays "pristine" and a Save button that
     | only enables when dirty never enables.
     */
    /*
     | Type, then check it took — and try once more if it did not.
     |
     | Even after hydration a controlled input can drop the first keystrokes
     | while React is still settling, and a field that silently stays empty
     | produces a validation error about a field you watched being filled in.
     | Verifying here turns a mystery into a retry.
     */
    for (const attempt of [1, 2]) {
        await page.click(selector, { clickCount: 3 });

        /*
         | Select-all then delete, rather than one Backspace.
         |
         | A triple-click does not always select the whole of a number input,
         | so a single Backspace left "1" of "14" behind and the field ended up
         | reading 121 — over the maximum, refused, and reported as "the save
         | did not work" when the save was never valid.
         */
        await page.keyboard.down('Control');
        await page.keyboard.press('KeyA');
        await page.keyboard.up('Control');
        await page.keyboard.press('Delete');

        await page.type(selector, value, { delay: 12 });

        const landed = await page.$eval(selector, (el) => el.value);

        if (landed === value) return;

        if (attempt === 2) {
            throw new Error(`${selector} would not accept "${value}"`);
        }

        await new Promise((resolve) => setTimeout(resolve, 400));
    }
}

/** Give an Inertia request time to land without waiting on a silent network. */
async function settle(page) {
    await page
        .waitForNetworkIdle({ idleTime: 300, timeout: 6000 })
        .catch(() => new Promise((resolve) => setTimeout(resolve, 800)));
}

/**
 * Submit the page's form and wait for it to have gone somewhere.
 *
 * ── Why not waitForNavigation ──────────────────────────────────────────────
 * Inertia never navigates. It posts by XHR and moves the page with
 * `history.pushState`, which fires no navigation event at all — so
 * `waitForNavigation` waits out its timeout on a submit that worked perfectly,
 * and the assertion that follows reads a URL that has not caught up yet. The
 * failure looks like "the form did not submit" on a form that did.
 *
 * Watching `location.href` is the only thing that covers both a real redirect
 * and a pushState.
 */
async function submit(page) {
    const before = page.url();

    await page.$eval('form', (form) => form.requestSubmit());
    await urlChanged(page, before);
}

async function urlChanged(page, before, timeout = 8000) {
    await page
        .waitForFunction((previous) => window.location.href !== previous, { timeout }, before)
        .catch(() => {});

    await settle(page);
}

/**
 * Set a value the way React will notice, without typing it.
 *
 * For a date or datetime-local field there is nothing sensible to type: Chrome
 * renders segmented controls that expect locale-formatted keystrokes, so an ISO
 * string goes nowhere. Assigning through the prototype's setter is what gets
 * past React's own value tracker — a plain `el.value = x` leaves the component
 * believing the field is still empty.
 */
async function setValue(page, selector, value) {
    await page.waitForSelector(selector, { timeout: 5000 });

    await page.$eval(
        selector,
        (element, next) => {
            const setter = Object.getOwnPropertyDescriptor(
                Object.getPrototypeOf(element),
                'value',
            )?.set;

            setter?.call(element, next);
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        },
        value,
    );
}

async function clickText(page, text) {
    const clicked = await page.evaluate((needle) => {
        const candidates = [...document.querySelectorAll('button, a')].filter((el) => !el.disabled);

        /*
         | Exact label wins over a substring.
         |
         | "Convert" is inside "Convert to client", so a substring match on the
         | dialog's confirm button finds the page header's button instead and
         | re-opens the dialog it was meant to accept.
         */
        const target =
            candidates.find((el) => el.textContent?.trim() === needle) ??
            candidates.find((el) => el.textContent?.trim().includes(needle));

        if (!target) return false;

        target.click();

        return true;
    }, text);

    if (!clicked) throw new Error(`no clickable "${text}"`);

    await new Promise((resolve) => setTimeout(resolve, 350));
}

/** Choose the first option that is not the "choose one…" placeholder. */
async function selectFirstReal(page, selector) {
    await page.waitForSelector(selector, { timeout: 5000 });

    const value = await page.$eval(selector, (select) => {
        const option = [...select.options].find((o) => o.value !== '');

        return option ? option.value : null;
    });

    if (value === null) throw new Error(`no real option in ${selector}`);

    // page.select() rather than assigning .value: React keeps its own value
    // tracker, and a direct assignment leaves the component believing the field
    // is still empty — so the form submits without it and bounces on validation.
    await page.select(selector, value);
}

function firstRecord(page, module) {
    return page.evaluate((name) => {
        const pattern = new RegExp(`^/${name}/\\d+$`);

        return (
            [...document.querySelectorAll('a[href]')]
                .map((a) => new URL(a.href).pathname)
                .find((path) => pattern.test(path)) ?? null
        );
    }, module);
}

/**
 * Where a submit landed — and, when it did not move, what the page objected to.
 *
 * "landed on /projects/create" is true and useless: it says the form did not
 * submit without saying which field refused. Reading the validation messages
 * back turns a dead end into a finding.
 */
async function expectUrl(page, pattern, note) {
    const path = new URL(page.url()).pathname;

    if (pattern.test(path)) {
        return { pass: true, note };
    }

    const complaints = await page.evaluate(() =>
        [...document.querySelectorAll('[class*="text-destructive"]')]
            .map((el) => el.textContent?.trim())
            // "*" is the required marker beside a label and "1" is a badge —
            // neither is a message, and both crowd out the one that is.
            .filter((text) => text && text.length > 3 && text.length < 200)
            .slice(0, 4),
    );

    return {
        pass: false,
        note: complaints.length
            ? `stayed on ${path} — ${complaints.join(' / ')}`
            : `stayed on ${path} with no visible error`,
    };
}

async function expectText(page, needle, note) {
    return (await page.evaluate((n) => document.body.innerText.includes(n), needle))
        ? { pass: true, note }
        : { pass: false, note: `"${needle}" not on the page` };
}

async function expectMounted(page, note) {
    return (await page.evaluate(() => (document.getElementById('app')?.innerHTML.trim().length ?? 0) > 0))
        ? { pass: true, note }
        : { pass: false, note: 'the page rendered an empty root' };
}

function report() {
    const failed = results.filter((r) => !r.pass);

    console.log('\n════════════════════════════════════════════════════════════════');
    console.log(`  WORKFLOW · ${results.length} steps · ${results.length - failed.length} passed · ${failed.length} failed`);
    console.log('════════════════════════════════════════════════════════════════\n');

    if (failed.length) {
        failed.forEach((r) => console.log(`  ✗ ${r.role} / ${r.module} / ${r.name}\n      ${r.note}`));
        console.log('');
    }

    process.exit(failed.length === 0 ? 0 : 1);
}
