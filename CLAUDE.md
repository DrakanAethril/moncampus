# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

**MonCampus** — the campus-management platform of *Institution Beaupeyrat*. A single Symfony 8.1
application (PHP ≥ 8.5.7, Doctrine ORM 3, MySQL 8) served by FrankenPHP/Caddy in worker mode, plus a
JSON API consumed by two companion Flutter apps.

It started life as the `dunglas/symfony-docker` template and still carries that Docker/Caddy plumbing,
but the application itself is now the bulk of the repository: ~690 PHP files / ~89k lines under `src/`,
152 entities, 75 controllers, 636 routes, 148 migrations, ~400 Twig templates, 84 Stimulus controllers.
Treat any leftover template documentation (`docs/*.md`, parts of the README) as upstream material, not
as a description of this app.

Companion repositories (separate git repos, same staging workflow):
- `/Users/Shared/Projets/Flutter/moncampus-mobile` — the student/teacher mobile app.
- `/Users/Shared/Beaupeyrat/e-CO` — the orienteering-race app (GitHub `e-co-mobile`).

## Commands

Everything runs inside the `php` container; there is no host-side PHP.

```console
docker compose up --wait                      # start (dev overlay is implicit)
docker compose down --remove-orphans          # stop
docker compose build --pull --no-cache        # rebuild dev images
docker compose exec php bash                  # shell in

docker compose exec php bin/console <cmd>
docker compose exec php composer <cmd>
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --filter <TestName>
```

Plain `docker compose` merges `compose.yaml` + `compose.override.yaml` (dev). Production needs both
`-f` flags, in this order:

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

Application commands (`src/Command/`), all cron-driven in production:

| Command | Role |
|---|---|
| `app:mail:consume-inbound` | Pull inbound student mail from SQS/S3 into `EmailMessage` |
| `app:mail:consume-events` | Pull SES delivery/bounce events, update `EmailEvent`/suppressions |
| `app:mail:reconcile` | Repair drift between SES state and local rows |
| `app:mail:backfill-student-aliases` | One-off alias generation for existing students |
| `app:import-edt-timetable`, `app:import-edt-periods` | Timetable import from the school's EDT export |
| `app:import-notion-sequences` | One-off import of pedagogical sequences from a Notion export |
| `app:purge-platform-activity` | Retention on `PlatformActivity` |
| `app:seed-dev-*`, `app:dev:*`, `app:configure-dev-programs` | **Dev-machine only.** Populate/inject into the local database. These must never be relied on in staging or production. |

## Runtime architecture (Docker layer)

**Single `php` service**: one FrankenPHP container serves both PHP and the HTTP/HTTPS/HTTP3 Caddy front
end — no nginx/php-fpm split. Caddy behavior lives in `frankenphp/Caddyfile` (Mercure hub, Vulcain,
worker mode, static files) and is driven by env vars rather than edited; see `docs/options.md`.

**Compose layering** (order matters, Compose merges them):
- `compose.yaml` — base `php` service, ports, shared env, plus the **`gotenberg`** service used for PDF
  rendering (Livret Alternant export).
- `compose.override.yaml` — dev overlay, auto-applied: builds `frankenphp_dev`, bind-mounts the repo,
  enables Xdebug. Also hosts the dev-only stand-ins: `database` (MySQL), `mailer` (Mailpit),
  `phpmyadmin`, `openldap` (reachable from `php` at `ldap://openldap:1389`), and `minio` +
  `minio-init` (S3 stand-in for uploads).
- `compose.prod.yaml` — prod overlay: `frankenphp_prod` target, secrets injected from the environment.
- `.devcontainer/compose.devcontainer.yaml` — extra overlay for VS Code Dev Containers.

**Multi-stage `Dockerfile`**: `frankenphp_base` (extensions via `install-php-extensions`) →
`frankenphp_dev` (Xdebug, `nonroot` user) → `frankenphp_prod_builder` (`composer install --no-dev`,
asset map) → `frankenphp_prod` (from-scratch `debian:13-slim`, copies only built artefacts, runs as
`www-data`). Symfony Flex recipes inject config between `###> recipes ###` markers — don't hand-edit
inside those.

**Prod PHP tuning** lives in `frankenphp/conf.d/`. `20-app.prod.ini` raises `memory_limit` to 256M
specifically because `opcache.preload` re-compiles the whole container at every worker start and the app
has outgrown the 128M default — lowering it crash-loops the container with a context-free
"Allowed memory size exhausted ... in Unknown on line 0".

**Flex markers**: several files carry `###> package ### … ###< package ###` blocks. Composer rewrites
their contents on install/remove — don't restructure them by hand.

## Application architecture

### Domain map

Roughly, by navigation entry — this is the fastest way to find where a feature lives:

- **Accueil** — `HomeController`, a different dashboard partial per role
  (`templates/home/_*_dashboard.html.twig`).
- **Emploi du temps** — `LessonSession`, `Room`, `Period`; imported from the school's EDT.
- **Formations (per Section > Program)** — the largest area. `Program` ties a `Cohort` to a
  `SchoolYear`; hanging off it: students/teachers, timetable, `Assignment` (travail à faire),
  `LessonLog` (cahier de texte), gradebook (`Evaluation`/`Grade`/`Topic`), quiz instances,
  `Progression`, sequences, syllabus, reporting, exports, financial items, per-program settings.
- **Travail à faire** — `Assignment` + `AssignmentExpectedProduction` + `AssignmentSubmission`;
  `App\Service\StudentWorkBoard` owns the entire state rule (one row per expected production, not per
  assignment) — put board logic there, not in the controller.
- **Outils** (teachers/staff) — tirage au sort, création de groupes (`GroupCreationService`),
  progression, bibliothèque (sequences/séances/phases + quiz library), enregistrements audio,
  cahier de texte, carnet de notes.
- **Quiz** — `QuizTemplate`/`QuizQuestion` (library) → `QuizInstance` (launched snapshot) →
  `QuizAttempt` (passation). Live multiplayer (`QuizLiveSession`) runs over Mercure/SSE.
- **UFA** (apprenticeship unit) — `Internship*` entities: alternance periods, the 4-role signature
  wizard of the Livret Alternant, tutor links, evaluations, reminders, plus laptop loans
  (`Laptop`/`LaptopLoan`) and the UFA configuration screens.
- **Stage / recherche d'emploi** — `JobSearch`, `JobApplication`, `TrainingOffer`,
  `TrainingApplication` (postulation with free-form attachments). Note: there is deliberately **no**
  Enterprise entity on this side — the job search names its own démarches; `Enterprise` belongs
  exclusively to UFA.
- **Courrier école** — student mailboxes: `EmailAlias`, `EmailMessage`, `EmailAttachment`,
  `EmailEvent`, suppressions. Inbound via SES→S3→SQS, outbound via SES.
- **Messagerie** — `MessageThread`/`Message`, audience-resolved. Present on web, **not** exposed in the
  mobile app (code kept, tab replaced by Quiz).
- **e-CO** — orienteering races: `EcoCourse`, `EcoParcours`, `EcoCheckpoint`, `EcoRunner`. Runners have
  no account at all; they authenticate by join token, checked manually in `EcoRunnerApiController`.
- **Annuaire / Paramètres** — LDAP directory browsing, structure
  (`Section > Track > Cohort`, `Option`/`Modality`, `SchoolYear`, `Program`), student mail aliases.
- **Support** — `Ticket`/`TicketComment`/`TicketCategory`, with Discord notification.

### Controller layout

Most controllers sit flat in `src/Controller/`, one per feature area. Two sub-namespaces break that
pattern deliberately, because their area is a tab shell rather than a single screen:

- **`src/Controller/Settings/`** — Paramètres > Configuration / Pédagogique. One controller per tab
  (`SectionController`, `CohortController`, `PeriodGroupController`, …) plus `SettingsTabTrait` for the
  handful of genuinely shared helpers (`renderTab`, DataTables param reading, audit stamping, CSRF).
- **`src/Controller/Program/`** — the two program-scoped tab shells. `Settings*` for Formation >
  Paramétrage (`SettingsMemberController`, `SettingsSkillGroupController`, …, plus
  `ProgramSettingsTabTrait`); `Internship*` for Formation > Livret de l'alternant
  (`InternshipTutorController`, `InternshipEvaluationPeriodController`,
  `InternshipContractModalityController`, …, plus `ProgramInternshipTrait`).
- **`src/Controller/Ufa/`** — the UFA alternance area, split by sub-feature rather than by tab:
  `DashboardController`, `AlternanceController` (the dossier itself), `EngagementController`,
  `PeriodWizardController` (the four role variants of the evaluation wizard), `ReminderController`,
  `BookletController`, plus `UfaAlternanceTrait`.

Each replaced a 1000–1400-line controller that had accumulated an entire screen or feature area. When
adding a tab or sub-feature to one of these areas, add a controller — don't grow an existing one. Note
`App\Controller\Settings\ProgramController` (the Formations settings tab) is a different class from
`App\Controller\ProgramController` (a program's own screens); the namespace is what tells them apart.

More generally, business rules belong in `src/Service/`, not in the controller. Controllers still hold
far more logic than they should (~28k lines against ~13k of services) — when you touch a fat one,
extract rather than extend.

### Cross-cutting building blocks

Prefer these over re-implementing:

- `App\Security\StructureAccessChecker` — the program-scoping authority: `isStaff()`,
  `isProgramVisible()` (enrolled student *or* teacher, staff bypass), `isProgramTeacher()` (stricter,
  students excluded), `isProgramReferentTeacher()` (deliberately **not** staff-bypassed),
  `matchesTestMode()`.
- `App\Service\AudienceResolver` + the `AudienceTargetable` interface — the shared "who is this
  addressed to" rule for `MessageThread`, `Announcement`, `AgendaEvent`.
- `App\Entity\AuditableTrait` — created/updated/inactivated by whom and when; mixed into
  `AbstractStructureNode` and the standalone structure entities.
- `App\Entity\AbstractStructureNode` — shared base of Section/Track/Cohort/Option/Modality.
- `App\Service\FileUploadService` / `flysystem` `uploads.storage` — never write to the filesystem
  directly; uploads go to S3 (MinIO in dev), URLs are built by the `file_url` Twig function.
- Twig helpers in `src/Twig/`: `is_staff`, `is_program_teacher`, `file_url`, `avatar_url`,
  `visibility_allows`, `structure_nav_*`, `student_nav_*`, `ufa_nav_*`, `unread_message_thread_count`.

### Test accounts / "zone de test"

`User::$testUser` and `Program::$testProgram` implement a parallel world for demos and rehearsal. The
rule is **deliberately asymmetric** (`StructureAccessChecker::matchesTestMode()`): a test account sees
*only* test programs; a real account still sees test programs, because somebody has to build them —
that's what the nav's "ZONE DE TEST" group is for. Screens where the two worlds must not mix (timetable,
dashboards) apply their own strict same-side check. Don't "fix" this into symmetry.

## Authentication & authorization

Users authenticate against the school's LDAP, but every identity is mirrored into a Doctrine
`App\Entity\User` row (just-in-time provisioning), so relations attach to a stable local entity. No
password hash is ever stored locally.

- `App\Security\LdapAuthenticator` (web firewall `main`) binds as the service account
  (`LDAP_SEARCH_DN`/`LDAP_SEARCH_PASSWORD`), searches `(uid=…)`, find-or-creates the `User`, syncs
  `email`/`displayName`/roles from `groupOfNames` memberships (`ROLE_<GROUP_CN>`) on **every** login,
  then verifies the password with a second bind as the user's own DN.
- `App\Security\ApiLdapAuthenticator` — `POST /api/login` for the mobile apps, stateless, issues a
  LexikJWT token. Every other `/api/*` route is Bearer-JWT.
- `App\Security\MagicLinkAuthenticator` + `MagicLoginToken` — passwordless mailed-link login (web and
  mobile), rate-limited on both IP and address.
- `app_user_provider` (entity provider, property `username`) only refreshes the session token between
  requests; it never checks credentials.
- Firewall order in `config/packages/security.yaml` matters: `api_login` must precede `api`, and every
  `PUBLIC_ACCESS` rule must precede the `^/` → `ROLE_USER` catch-all — `#[IsGranted]` on a controller
  cannot loosen `access_control`.

**Roles** come from LDAP groups: `ROLE_ADMIN`, `ROLE_STAFF`, `ROLE_STAFF-LEAD`, `ROLE_TEACHER`,
`ROLE_STUDENT`, `ROLE_TUTOR` (external apprenticeship tutors), `ROLE_SUPPORT-TECH`, `ROLE_ECO`,
`ROLE_EXTERNAL`. `ROLE_TUTOR` and `ROLE_EXTERNAL` are both excluded from message recipients.

**Fine-grained checks** are Voters (`src/Security/Voter/`, 12 of them: Assignment, Evaluation,
LessonLog, MessageThread, Progression, QuizTemplate, SequenceTemplate, SignupList, Ticket,
InternshipTutorLink, EcoParcours, AudienceTargetable). New per-object rules belong in a Voter, not
inline in a controller.

`src/Security/Ldap*Syncer.php` also **writes** provisioning requests (`LdapManageUser`,
`LdapManageGroup`, `LdapManagePassword`) that an external script at
`/Users/Shared/Beaupeyrat/Scripts/samba/ldap/` consumes — MonCampus never mutates LDAP directly.

## External services

| Service | Purpose | Env |
|---|---|---|
| AWS S3 | Uploads (attachments, audio, PDFs) — MinIO in dev | `AWS_S3_*`, `AWS_CLOUDFRONT_DOMAIN` |
| AWS SES + S3 + SQS | Courrier école, inbound and outbound. **Separate AWS account** from the uploads bucket | `AWS_MAIL_*`, `AWS_SES_*`, `MAIL_STUDENT_DOMAIN` |
| Gotenberg | HTML→PDF (Livret Alternant) | `GOTENBERG_URL` |
| Mercure | Turbo streams + live quiz SSE | `MERCURE_URL`, `MERCURE_PUBLIC_URL` |
| Matomo | Analytics, **consent-gated** (`requireConsent`, opt-in banner) | `MATOMO_URL`, `MATOMO_SITE_ID` |
| Discord | Support-ticket notifications | `DISCORD_WEBHOOK_*` |
| LDAP | Authentication + directory | `LDAP_*` |

`.env.prod.local` **on the development machine holds decoy values.** Never infer the real production
region, bucket or DSN from it.

## Design

The design reference is the set of handoffs under **`design/design_handoff_*/`** (gitignored,
reference-only). Each holds a `README.md` and numbered screens; `design/design_handoff_projet/designs/*.dc.html`
carries the overall "Campus Manager" system. When building or restyling a screen, work from the handoff
that names it, and **the mockups win over Tabler's own styles** — the app is progressively leaving
Tabler behind rather than conforming to it.

Current state, which is a deliberate in-between and not an inconsistency:
- Tabler 1.4.0 CSS/JS are still vendored at `assets/tabler/{css,js}/tabler.min.*` and loaded by
  `templates/base.html.twig`; a lot of markup is still Bootstrap/Tabler-shaped.
- On top of it, `assets/styles/app.css` (~5 500 lines) implements the handoff design system: ~1 900
  `--cm-*` custom properties and a `cm-` class family (`cm-btn`, `cm-badge`, `cm-tabs`, `cm-actionbar`,
  `cm-action--{positive,danger,neutral,warning,off}`, …). New UI should use `cm-*`.
- Fonts are **Source Sans 3** (body) and **Spectral** (headings), from Google Fonts — not Tabler's Inter.
- Theme is per-user (`User::$themePreference`), falling back to a cookie for anonymous visitors, with
  deliberately no hardcoded default.

`templates/layout/app.html.twig` is the authenticated app shell (horizontal navbar, role-dependent, +
`page_title`/`main` blocks). New authenticated screens extend it. `login` extends `base.html.twig`
directly so it shows no navbar. Shared `_tabs.html.twig` / `_breadcrumb.html.twig` partials implement
the global tab + breadcrumb pattern.

**Breadcrumb rule.** Every authenticated screen fills the `page_breadcrumb` block, and **the trail
always opens on `Accueil`** — `{label: 'homeNavLabel'|trans, url: path('app_home')}`, which the partial
renders with the house pictogram. This is stated in several handoffs ("commence toujours par Accueil
avec picto maison") and holds across the app; a screen that starts its trail on a section name instead
is a bug, not a variant. After Accueil come the real parent levels, then the current page last:

- **2 segments** — the screen hangs straight off Accueil (`Accueil › À propos`).
- **3+ segments** — one entry per intervening level (`Accueil › Configuration › Nouvelle salle`,
  `Accueil › e-CO › Parcours › Nouveau parcours`). Depth follows navigation, not URL structure.

Every segment stays a real `<a href>`, including the last, which carries `.current`. When the trail
varies between callers of a shared template, build it with `{% set segments = [...] %}` + `|merge`
rather than duplicating the block — see `templates/audio_recording/_breadcrumb.html.twig` and
`templates/activity/history.html.twig`. Deliberately suppressing the breadcrumb (`{% block
page_breadcrumb %}{% endblock %}`) is rare and should carry a comment saying why, as
`templates/profile/index.html.twig` does.

The WYSIWYG editor is **HugeRTE** (MIT TinyMCE fork), vendored under **`public/hugerte/`** and loaded by
a plain `<script src="/hugerte/hugerte.min.js">`, *not* through AssetMapper. This is deliberate: HugeRTE
fetches its own `skins/`/`themes/`/`plugins/`/`icons/` by relative HTTP at runtime, which breaks the
moment AssetMapper content-hashes those filenames. Upgrading means re-copying the same minified subset
from a fresh `npm install hugerte` (in a scratch dir, never at the repo root) and checking the Network
tab for new 404s under `/hugerte/`.

## Conventions

- **Code is English, display text is French.** Identifiers, comments and docblocks are always English;
  only user-visible strings are French. This is the convention that slips most often in `app.css` and in
  Twig — check before writing the comment. (`app.css` still contains French comments from before the
  rule; fix them opportunistically, don't sweep.)
- **URLs are English too** — route paths were swept to English (117 of them). Route names follow, and
  are English apart from the domain word `seance` (`app_library_seances_*`, `app_progression_seance_*`),
  kept as a domain term the way `Program` or `Cohort` are.
- **i18n**: `fr` is the default, `en` is the second locale. `LocaleSubscriber` resolves it in order:
  session (`_locale`), then the logged-in user's `locale`, and it runs late enough not to be overridden
  by the `_locale` route attribute. Translation keys are semantic camelCase (`studentWorkNavLabel`),
  never the French sentence. `messages.en.yaml` is currently incomplete (~390 keys missing).
- **Forms**: checkbox groups rather than `<select multiple>`. Any select used for *input* needs a
  placeholder; selects used for *consultation* may start on the first entry. Picking Users (not
  Programs/Options) always uses tomselect + ajax, with tags below the field for multi-select.
- **Interactivity** is Stimulus controllers under `assets/controllers/*_controller.js` — never inline
  JS.
- **Indentation**: 4 spaces by default; tabs in Dockerfile/Caddyfile/`*.sh`; 2 spaces in Compose and
  GitHub Actions YAML (`.editorconfig`).
- **Configuration** (`SERVER_NAME`, ports, Caddy behavior) goes through `.env`, not by editing
  `compose.yaml`/`Caddyfile` — see `docs/options.md`.

## Gotchas worth knowing before you hit them

**Doctrine / PHP**
- `$map[$key]?->method()` still raises "Undefined array key" — the nullsafe operator does not guard the
  array access.
- `GROUP` is a MySQL 8 reserved word, so the `Group` entity carries an explicitly backtick-quoted
  ``#[ORM\Table(name: '`group`')]``. Same reflex for any other reserved word used as a table/column
  name.
- Durations: pedagogical **séances are in MINUTES**, timetable **`LessonSession` slots are in decimal
  HOURS**. Convert once at the boundary; never compare the raw numbers.
- `#[Assert\File(extensions: 'csv')]` rejects genuine CSV files, which are guessed as `text/plain` —
  map the extension to your own MIME list instead.

**Turbo / Stimulus / front end**
- A POST form handled by Turbo **must** redirect. For "show me a result" forms, use `method="GET"`.
- Never bind `data-controller` to an element a DOM-rewriting library (DataTables) moves or rewraps.
- Stimulus Array/Object values re-parse on every access — they are not cached; read once into a local.
- DataTables `footerCallback` must be a regular function using `this.api()`, not an arrow function
  closing over the controller's `this`.
- Inside a `.navbar`, Bootstrap disables Popper positioning; submenus are placed by CSS alone, with
  manual reframing in `nav_submenu_placement_controller.js`.
- Tabler nav active class: on the `<li>` for a top-level `nav-item`, on the `<a>` itself for a
  `dropdown-item`.
- Bootstrap's `.d-flex` (and friends, `!important`) override the plain `hidden` attribute.
- A stray `*/` inside a CSS comment closes it early and silently deletes the next rule in `app.css`.
- HugeRTE must sync its content back to the textarea on **every change**, not only on submit.
- Small actions inside a form recur as two bug classes: CSRF token read from the header vs the body, and
  nested `<form>` elements. Check both when adding a button inside an existing form.

**Infrastructure**
- `symfony/ux-turbo` pings Mercure on **every** flush, including from CLI commands — `MERCURE_URL` must
  be set in any context that writes to the database, and the failure surfaces at flush time, not at
  startup.
- `config/reference.php` produces a non-deterministic docblock-ordering diff from the running dev
  container. It is safe to `git checkout --` without asking; it is not real work.

## Deployment & CI

`git push` to `main` triggers `.github/workflows/deploy.yaml`: it connects to the school's OpenVPN, SSHes
into the production host and runs `deploy-prod.sh` **there**. That script is gitignored and lives only on
the server (it carries environment-specific but non-secret values) — changing it means copying it over by
hand. This is a real production deploy, never a dry run; the `/beaup-deploy` skill wraps it, and merging
`staging` → `main` is the only action in this repo that is always confirmed before running.

**There is currently no CI.** `.github/workflows/deprecated.yaml` is the old template workflow, wired to
a `backuped` branch, with its PHPUnit / migrations / `doctrine:schema:validate` steps still commented
out. Nothing is verified automatically between a commit and a production deploy.

**Test coverage is thin but no longer absent** — 152 tests: unit tests over pure services, one test
per Voter (`tests/Security/Voter/`), and a functional smoke test (`tests/Functional/`) that requests
each main screen as a student / teacher / admin / tutor and pins the answer. Run them with
`docker compose exec -e APP_ENV=test php bin/phpunit`; **`tests/README.md` explains the one-off test-database
setup they need**. Feature work is still verified in a real browser — the `browser-verify` skill drives
a headless Chrome against the dev app, and `beaup-sqs-check` polls the Courrier école queues.

When you add a screen or change who may reach one, extend `RoleAccessSmokeTest`'s table: it is the
cheapest place in this repo to notice that a role gained or lost access by accident.

There is also no static analysis (no PHPStan/Psalm/CS-Fixer/Rector).

**Error alerting** is Discord-only and prod-only: `config/packages/monolog.yaml`'s `when@prod` block
sends anything error-and-worse to `App\Monolog\DiscordWebhookHandler`, which posts to the same webhook
`TicketDiscordNotifier` uses for support tickets. Everything else is still stderr (JSON) into the
server's Docker logs. Two things to keep in mind before touching it: a plain 404 is logged by Symfony at
*error* level, which is why the handler sits behind the same `fingers_crossed` + `excluded_http_codes`
gate as the stderr handler; and the handler throttles per-process (same error once per 5 min, 10 alerts
per minute maximum), so a burst is deliberately not reported message-for-message.

## Licensing

MonCampus is **AGPL-3.0-or-later**, copyright Sébastien Tharaud (`LICENSE`, `NOTICE`). Two practical
consequences when adding code:

- Any new dependency must be AGPL-compatible. Permissive licences (MIT, BSD, Apache-2.0, ISC) are fine
  and are what every current dependency uses; proprietary or AGPL-incompatible components are not.
- AGPL §13 requires that users interacting with the app over a network can obtain its source. Keep
  whatever "source code" link the UI exposes working, and pointing at the public repository.

Institution Beaupeyrat's names, logos and emblems are excluded from the licence — see `NOTICE` for the
file list.

The companion mobile repositories are intended to carry a **permissive** licence (MIT) rather than the
AGPL: they are thin clients with nothing to protect, and Apple's App Store terms are widely held to be
incompatible with the GPL family, which would block an iOS release. Don't "harmonise" them onto the
AGPL.

## Git workflow

Branch **before** the first edit of any task (`git checkout -b <name> origin/staging`), then land the
work on `staging` in the same turn it is verified — commit, `git merge --no-ff` into `staging`,
`git push origin staging`, and `git branch -d` the feature branch, all chained. Feature branches are
local-only; there is nothing to delete on the remote. Work in progress belongs on `staging`; only
production deploys are ever asked about. Commit messages are French, `type(scope): …`, with no
`Co-Authored-By` trailer.
