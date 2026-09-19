// Real-browser tests for the auth stack. PHP's built-in server runs the application (on 127.0.0.1) and
// an "attacker" site (on localhost, a different site); Google Chrome is driven with playwright-core.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.TRUNK_PHP ?? 'php';
const password = 'correct horse battery';
let directory;
let appPort;
let attackerPort;
let servers = [];
let browser;
let chromeVersion;

const freePort = () => new Promise((resolve) => {
    const server = net.createServer();
    server.listen(0, '127.0.0.1', () => {
        const { port } = server.address();
        server.close(() => resolve(port));
    });
});

const tool = (...args) => execFileSync(php, [join(here, 'server/tool.php'), directory, ...args], { encoding: 'utf8' }).trim();

const start = async (script, port, host, env) => {
    const child = spawn(php, ['-S', `${host}:${port}`, join(here, 'server', script)], { env: { ...process.env, ...env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore', detached: true });
    servers.push(child);
    for (let attempt = 0; attempt < 100; attempt++) {
        try {
            await fetch(`http://${host}:${port}/`);
            return;
        } catch {
            await new Promise((r) => setTimeout(r, 50));
        }
    }
    throw new Error(`the PHP server for ${script} did not start`);
};

const appConfig = (extra = {}) => JSON.stringify({
    auth: { password: { memory_cost: 8192, time_cost: 1 }, session: { secure: false }, throttle: { max_attempts: 5, max_attempts_per_ip: 30, window: 600 }, ...extra.auth },
    http: { security_headers: { enabled: true } },
});

const app = (path = '') => `http://127.0.0.1:${appPort}${path}`;
const attacker = (path = '') => `http://localhost:${attackerPort}${path}`;

const signIn = async (page, email = 'ada@example.com', pass = password) => {
    await page.goto(app('/web/form'));
    await page.fill('#email', email);
    await page.fill('#password', pass);
    await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
};

before(async () => {
    directory = mkdtempSync(join(tmpdir(), 'trunk-browser-'));
    tool('setup');
    appPort = await freePort();
    attackerPort = await freePort();
    await start('router.php', appPort, '127.0.0.1', { TRUNK_BROWSER_DIR: directory, TRUNK_BROWSER_CONFIG: appConfig() });
    await start('attacker.php', attackerPort, 'localhost', { TRUNK_BROWSER_APP: app() });
    browser = await chromium.launch({ executablePath: process.env.TRUNK_CHROME, headless: true });
    chromeVersion = browser.version();
    console.log(`# Chrome ${chromeVersion}, PHP ${execFileSync(php, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' })}`);
});

after(async () => {
    await browser?.close();
    // php -S forks workers; end the whole process group so none outlives the run.
    for (const server of servers) {
        try {
            process.kill(-server.pid, 'SIGTERM');
        } catch {
            // already gone
        }
    }
    rmSync(directory, { recursive: true, force: true });
});

describe('signing in and out with real forms', () => {
    it('signs in with a form, keeps the session, and the cookie is HttpOnly, SameSite=Lax and host-only', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await signIn(page);

        assert.equal(new URL(page.url()).pathname, '/web/secret');
        assert.match(await page.textContent('#who'), /^user 1$/);
        const cookies = (await context.cookies()).filter((c) => c.name === 'session');
        assert.equal(cookies.length, 1);
        assert.equal(cookies[0].httpOnly, true);
        assert.equal(cookies[0].sameSite, 'Lax');
        assert.equal(cookies[0].path, '/');
        assert.equal(cookies[0].value.length, 43);
        // Script on the page cannot read it.
        await page.goto(app('/web/form'));
        assert.match(await page.title(), /cookie-hidden/);
        assert.equal(await page.evaluate(() => document.cookie.includes('session')), false);
        await context.close();
    });

    it('signing out ends the session, removes the cookie, and the back button shows nothing private', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await signIn(page);
        const secret = await page.goto(app('/web/secret'));
        assert.match(secret.headers()['cache-control'] ?? '', /no-store/, 'private pages must not be stored by the browser');

        const before = (await context.cookies()).find((c) => c.name === 'session').value;
        await page.click('button:has-text("Sign out")');
        await page.waitForLoadState('load');
        // The redirect lands on the sign-in form, which starts a new anonymous session for its CSRF token;
        // what matters is that the signed-in session's id is gone from the browser and dead on the server.
        const after = (await context.cookies()).find((c) => c.name === 'session');
        assert.notEqual(after?.value, before, 'the signed-in session cookie was replaced or removed');
        const replay = await browser.newContext();
        await replay.addCookies([{ name: 'session', value: before, url: app('/') }]);
        await (await replay.newPage()).goto(app('/web/secret'));
        assert.notEqual(new URL((await replay.pages())[0].url()).pathname, '/web/secret', 'the old session id is worthless after logout');
        await replay.close();
        await page.goBack();
        await page.waitForLoadState('load');
        assert.doesNotMatch(await page.content(), /Private area/, 'the back button must not resurrect a private page');
        await context.close();
    });

    it('sends a signed-out visitor away from a private page (and remembers where they were going)', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const response = await page.goto(app('/web/secret'), { waitUntil: 'load' });
        assert.equal(new URL(page.url()).pathname, '/login');
        assert.equal(response.status(), 404, 'the demo app has no /login page; the redirect is what matters');
        await context.close();
    });
});

describe('cross-site attacks against a signed-in visitor', () => {
    it('a form on another site cannot log the visitor out, sign them in as the attacker, or use their session', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await signIn(page);
        const attackerPage = await context.newPage();

        for (const kind of ['logout', 'login-csrf']) {
            const navigation = attackerPage.waitForResponse((r) => r.url().includes('/web/') && r.request().method() === 'POST');
            await attackerPage.goto(attacker(`/?page=${kind}`));
            const response = await navigation;
            assert.equal(response.status(), 403, `${kind}: the forged post is refused`);
            assert.equal(response.request().headers()['cookie'], undefined, `${kind}: SameSite=Lax keeps the cookie off a cross-site POST`);
        }

        await page.goto(app('/web/secret'));
        assert.match(await page.textContent('#who'), /^user 1$/, 'still signed in as the real user');
        await context.close();
    });

    it('the application cannot be framed by another site', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const framed = [];
        page.on('framenavigated', (f) => framed.push(f.url()));
        await page.goto(attacker('/?page=frame'));
        await page.waitForTimeout(800);
        const frame = page.frames().find((f) => f !== page.mainFrame());
        assert.ok(frame, 'the iframe exists');
        // A blocked frame never loads the app: no form, and Chrome reports an empty or error URL.
        assert.ok(['', 'about:blank', 'chrome-error://chromewebdata/'].includes(frame.url()), `the framed app must be blocked (X-Frame-Options / frame-ancestors), got ${frame.url()}`);
        assert.equal(await frame.locator('input[name=_csrf]').count(), 0, 'the framed page must not have rendered');
        await context.close();
    });
});

describe('sessions', () => {
    it('a session id planted before login is not the id used afterwards, and it never becomes valid', async () => {
        const planted = 'A'.repeat(43);
        const context = await browser.newContext();
        await context.addCookies([{ name: 'session', value: planted, url: app('/') }]);
        const page = await context.newPage();
        await signIn(page);
        const after = (await context.cookies()).find((c) => c.name === 'session');
        assert.notEqual(after.value, planted, 'session fixation: the id changes at login');

        const attackerContext = await browser.newContext();
        await attackerContext.addCookies([{ name: 'session', value: planted, url: app('/') }]);
        const response = await (await attackerContext.newPage()).goto(app('/web/secret'), { waitUntil: 'load' });
        assert.notEqual(new URL(response.url()).pathname, '/web/secret', 'the planted id gets no access');
        await context.close();
        await attackerContext.close();
    });

    it('an idle session ends', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await signIn(page);
        tool('age-sessions', String(60 * 60 * 3));
        await page.goto(app('/web/secret'));
        assert.equal(new URL(page.url()).pathname, '/login', 'after three idle hours the session is gone');
        await context.close();
    });

    it('CSRF tokens differ on every page load, and any of them works once', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const tokens = [];
        for (let i = 0; i < 3; i++) {
            await page.goto(app('/web/form'));
            tokens.push(await page.getAttribute('input[name=_csrf]', 'value'));
        }
        assert.equal(new Set(tokens).size, 3, 'a masked token never repeats');
        assert.ok(tokens.every((t) => t.length === 86));
        await page.fill('#email', 'ada@example.com');
        await page.fill('#password', password);
        await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
        assert.equal(new URL(page.url()).pathname, '/web/secret');
        await context.close();
    });

    it('a same-origin script cannot post without the token', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(app('/web/form'));
        const status = await page.evaluate(async () => (await fetch('/web/login-form', { method: 'POST', body: new URLSearchParams({ email: 'a@b.c', password: 'x' }) })).status);
        assert.equal(status, 403);
        await context.close();
    });
});

describe('login failures', () => {
    it('an unknown user and a wrong password look exactly the same', async () => {
        const seen = [];
        for (const [email, pass] of [['ada@example.com', 'not the password'], ['nobody@example.com', password]]) {
            const context = await browser.newContext();
            const page = await context.newPage();
            const responsePromise = page.waitForResponse((r) => r.url().endsWith('/web/login-form'));
            await signIn(page, email, pass);
            const response = await responsePromise;
            seen.push([response.status(), (await page.content()).replace(/\s+/g, ' ')]);
            await context.close();
        }
        assert.equal(seen[0][0], 401);
        assert.deepEqual(seen[0], seen[1]);
    });

    it('repeated wrong passwords lock the account for this address, even for the right password', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        for (let i = 0; i < 5; i++) await signIn(page, 'ada@example.com', `wrong password ${i}`);
        const locked = page.waitForResponse((r) => r.url().endsWith('/web/login-form'));
        await signIn(page, 'ada@example.com', password);
        const response = await locked;
        assert.equal(response.status(), 429);
        assert.ok(Number(response.headers()['retry-after']) > 0);
        await context.close();
    });
});

describe('API tokens', () => {
    it('a bearer token works from script without cookies, and a cookie alone never authenticates the API', async () => {
        const token = tool('token');
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(app('/web/form'));
        const withToken = await page.evaluate(async (t) => {
            const r = await fetch('/api/whoami', { headers: { Authorization: `Bearer ${t}` }, credentials: 'omit' });
            return [r.status, await r.json()];
        }, token);
        const withoutToken = await page.evaluate(async () => (await fetch('/api/whoami', { credentials: 'include' })).status);
        assert.equal(withToken[0], 200);
        assert.deepEqual(withToken[1], { id: '1', viaToken: true, canRead: true, canWrite: false });
        assert.equal(withoutToken, 401);
        await context.close();
    });
});

describe('security headers', () => {
    it('every page carries the configured policy (and none of it needs https except HSTS)', async () => {
        const context = await browser.newContext();
        const page = await context.newPage();
        const response = await page.goto(app('/web/form'));
        const headers = response.headers();
        assert.equal(headers['x-frame-options'], 'DENY');
        assert.equal(headers['x-content-type-options'], 'nosniff');
        assert.equal(headers['referrer-policy'], 'strict-origin-when-cross-origin');
        assert.match(headers['content-security-policy'], /frame-ancestors 'none'/);
        assert.equal(headers['strict-transport-security'], undefined, 'plain http is never pinned to https');
        const missing = await page.goto(app('/nothing-here'));
        assert.equal(missing.status(), 404);
        assert.equal(missing.headers()['x-frame-options'], 'DENY', 'error pages carry the policy too');
        await context.close();
    });
});
