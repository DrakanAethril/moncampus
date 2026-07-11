---
name: browser-verify
description: Launch and drive this app (moncampus) in a real headless browser to verify a change end-to-end - logging in, navigating, filling forms, checking PDF exports. Use this whenever you need to prove a feature works in the actual running app, not just via lint/schema-validate/unit tests.
---

# Browser verification for moncampus

This is a Symfony/FrankenPHP app driven through Turbo Drive and a client-JS-generated
CSRF token. **`curl` cannot drive it** - form submissions need real JS execution. Use a
real (headless) browser.

## Dev server

Already running via `docker compose up --wait` for local work - check with
`docker compose ps php`. If it's not up, start it (`docker compose up --wait`) and wait
for the `php-1  | PHP app ready!` log line, not just the container's "healthy" status.

- Base URL: **`http://localhost`** (plain HTTP, port 80) - not `https://`. The dev
  FrankenPHP container has automatic HTTPS completely disabled
  (`http.auto_https  automatic HTTPS is completely disabled for server` in
  `docker compose logs php`); an `https://localhost` request just hangs/refuses.

## Auth

Log in through the real `/login` form in the browser - don't try to POST it directly.
The CSRF field's rendered value (`value="csrf-token"`) is not the real token: a Stimulus
controller (`assets/controllers/csrf_protection_controller.js`) rewrites it client-side
into a random token paired with a same-origin double-submit cookie right before submit.
A non-JS client can't replicate this.

Dev LDAP credentials (fictitious seed account, safe to use - see
`frankenphp/ldap/10-tree.local.ldif` / the tracked `examples/10-tree.example.ldif`):

- username: `admin`
- password: `password`
- Role: `ROLE_ADMIN` (member of the `admin` LDAP group) - full staff access to every
  Program, bypasses the per-Program student/teacher visibility checks
  (`StructureAccessChecker::isProgramVisible()`).

Login form fields: `input[name="_username"]`, `input[name="_password"]`,
`button[type="submit"]`.

## Playwright setup (not vendored in this repo)

No `chromium-cli` or project `node_modules` exist for this - bootstrap Playwright fresh
in your scratchpad directory (not inside the repo):

```bash
cd /path/to/scratchpad
npm init -y >/dev/null 2>&1
npm install playwright
npx playwright install chromium --with-deps
```

Then drive it with a Node script using `require('playwright')` (`chromium.launch({ args:
['--no-sandbox'] })`). See the skeleton below.

## Gotchas that will burn you

- **Turbo Drive intercepts everything.** `@symfony/ux-turbo`'s `turbo-core` is enabled
  globally (see `assets/controllers.json`). Form submits and link clicks go through a
  `fetch` + DOM morph, not a real navigation - `page.waitForLoadState('load')` /
  `'networkidle'` are unreliable (they can time out entirely, or resolve before the morph
  actually lands). **Wait for the actual expected content instead**:
  `page.waitForSelector('text=some flash message you expect')` or a specific field/value,
  never a load-state event, after a submit.
- **`page.waitForURL()` is a no-op trap when the destination equals the current URL** -
  e.g. saving a form that redirects back to the same page. The promise resolves
  immediately without ever waiting for the round trip. Prefer
  `page.waitForResponse((r) => r.request().method() === 'POST' && r.status() === 302)`
  paired with `page.click(...)` in a `Promise.all`, or (simplest and most robust) just
  wait for a `text=` selector unique to the post-submit state.
- **Feature-gated Programs 404.** Most `/programs/{id}/...` timetable routes throw
  `NotFoundHttpException` unless that Program's `timetableManagementEnabled` is `true`
  (`ProgramFeatureGuardTrait`). Most seeded dev Programs have it `false`. Find one that's
  enabled first:
  ```bash
  docker compose exec php php -d memory_limit=512M bin/console doctrine:query:dql \
    "SELECT p.id, p.shortName, p.timetableManagementEnabled FROM App\Entity\Program p" --no-interaction
  ```
  (`doctrine:query:sql` does not exist in this app - use `doctrine:query:dql`, including
  for one-off `DELETE FROM App\Entity\X ... WHERE ...` cleanup after a test.)
- **The timetable calendar hides weekends.** FullCalendar is configured with
  `weekends: false` (`assets/controllers/lesson_timetable_controller.js`). A
  `LessonSession` dated on a Saturday/Sunday silently never renders on either the
  read-only or staff calendar - always use a weekday date for a session you need to see
  rendered.
- **`bin/console` needs a memory override.** The dev container's default
  `memory_limit=128M` isn't enough for `cache:clear`/most console commands and will OOM.
  Always run `php -d memory_limit=512M bin/console ...` inside `docker compose exec php`.
- **Clean up what you create.** There's no disposable test database - any Program/
  LessonSession/etc rows created for verification stay forever unless deleted afterward
  via `doctrine:query:dql` `DELETE` statements (delete children before parents to satisfy
  FKs, e.g. attachments before their owning log row before the LessonSession).

## Minimal driver skeleton

```js
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const context = await browser.newContext({ acceptDownloads: true, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);

    await page.goto('http://localhost/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="_username"]', 'admin');
    await page.fill('input[name="_password"]', 'password');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);

    // ... navigate/fill/click, then wait for a text= selector unique to the result ...
    await page.screenshot({ path: 'result.png', fullPage: true });

    await browser.close();
})();
```

Run with `node script.js` from wherever you `npm install playwright`'d.
