/*
 | Screenshot a page of the running app, signed in.
 |
 | This app is judged on how it looks as much as on what it does, and almost
 | every screen is behind a login — so "does it still look right" cannot be
 | checked with curl. This drives the Chrome already installed on the machine
 | (puppeteer-core downloads no browser of its own) through a real sign-in and
 | captures the result.
 |
 |   npm run shot -- --path=/dashboard --as=admin@takecareconnect.com
 |   npm run shot -- --path=/dashboard --dark --out=storage/shots/dark.png
 |   npm run shot -- --path=/login --anon
 |
 | Point CHROME_PATH at another binary if Chrome is not in the default location.
 */
import { existsSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
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

const flag = (name) => process.argv.includes(`--${name}`);

const base = arg('base', process.env.APP_URL || 'http://127.0.0.1:8000');
const path = arg('path', '/dashboard');
const email = arg('as', 'admin@takecareconnect.com');
const password = arg('password', 'password');
const out = resolve(arg('out', `storage/shots/${path.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'home'}.png`));
const width = Number(arg('width', '1440'));
const height = Number(arg('height', '950'));

/*
 | Capture one part of a page.
 |
 | Long forms put the thing that changed a full screen below the fold, and a
 | viewport tall enough to reach it makes everything unreadably small. --find
 | takes text, scrolls the card containing it into view, and shoots that card.
 */
const find = arg('find');

const executablePath = process.env.CHROME_PATH || DEFAULT_CHROME.find((candidate) => existsSync(candidate));

if (!executablePath) {
    console.error('No Chrome found. Set CHROME_PATH to the browser binary.');
    process.exit(1);
}

await mkdir(dirname(out), { recursive: true });

const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'],
});

try {
    const page = await browser.newPage();

    /*
     | Sign in at a comfortable size, then resize for the actual shot.
     |
     | The requested viewport is about the page being captured, and has nothing
     | to do with the sign-in page — but setting it first breaks the sign-in.
     | Below roughly 500px of height the login card overflows the viewport and
     | the form stops submitting, so the shot comes back as the sign-in page and
     | reads as a wrong password. Which cost an embarrassing number of attempts
     | to work out, because every failure looked like a credentials problem.
     */
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

    /*
     | --anon skips the sign-in.
     |
     | The sign-in page, the two-factor challenge and the error pages are the
     | screens somebody meets before they have an account in hand, and they are
     | exactly the ones that cannot be captured by signing in first: /login
     | redirects to the dashboard the moment there is a session.
     */
    if (!flag('anon')) {
        await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });

        // Already signed in from a previous run's profile? The login form will
        // not be there, and typing into a missing field throws.
        if (await page.$('#email')) {
            await page.type('#email', email);
            await page.type('#password', password);

            /*
             | Submit the form itself, rather than clicking or pressing Enter.
             |
             | Both of those depend on the page being laid out the way the
             | script expects. A short --height pushes the button below the
             | fold, where a click can land before the scroll settles; Enter
             | depends on implicit submission and quietly did nothing at small
             | viewports. Either way waitForNavigation times out and the shot
             | comes back as the sign-in page, looking like a wrong password.
             |
             | requestSubmit() runs validation and fires submit regardless of
             | what is on screen, which is the only part of this that should
             | matter to a screenshot tool.
             */
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
                page.$eval('form', (form) => form.requestSubmit()),
            ]);

            if (page.url().endsWith('/login')) {
                // Print what the page says. "Could not sign in" on its own sends
                // you looking for a password problem when the real answer —
                // throttling, a validation message, a stale build — is written
                // on the screen in front of the browser nobody can see.
                const said = await page.evaluate(() =>
                    document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 300),
                );

                console.error(`Could not sign in as ${email}.`);
                console.error(`  The page says: ${said}`);
                process.exit(1);
            }
        }
    }

    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(`${base}${path}`, { waitUntil: 'networkidle0' });

    if (flag('dark')) {
        await page.evaluate(() => {
            localStorage.setItem('tcc-theme', 'dark');
            document.documentElement.classList.add('dark');
        });
    }

    // Let webfonts settle before capturing, or the shot catches the fallback
    // face and every measurement in it is wrong.
    await page.evaluate(() => document.fonts.ready);
    await new Promise((done) => setTimeout(done, 400));

    if (find) {
    /*
     | Tag the card, then let puppeteer shoot the element.
     |
     | Computing a clip rectangle by hand is where this goes wrong: after
     | scrolling, getBoundingClientRect() is relative to the viewport while
     | screenshot({clip}) wants document coordinates, so the capture silently
     | lands somewhere else on the page. An element handle has no such gap.
     */
    const tagged = await page.evaluate((needle) => {
        // Every ancestor up to <body> also "contains" the text, so the shortest
        // match is the element the text actually belongs to.
        const match = [...document.querySelectorAll('*')]
            .filter((el) => el.textContent?.includes(needle))
            .sort((a, b) => a.textContent.length - b.textContent.length)[0];

        const card = match?.closest('[class*="rounded"]');

        if (!card) return false;

        card.setAttribute('data-shot', '');

        return true;
    }, find);

    if (!tagged) {
        console.error(`Nothing on the page contains "${find}".`);
        process.exit(1);
    }

    const element = await page.$('[data-shot]');
    await element.screenshot({ path: out });
} else {
    await page.screenshot({ path: out });
}

    console.log(`${page.url()} -> ${out}`);
} finally {
    await browser.close();
}
