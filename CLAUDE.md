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

docker compose exec php composer phpstan           # static analysis, level 5
docker compose exec php composer phpstan-baseline  # regenerate the baseline
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
| `app:help:sync-content` | Creates the missing help sections/articles from `App\Help\HelpContentCatalog`; never overwrites what an admin has edited (`--refresh` also rewrites the untouched ones). Run it once after a deploy that adds catalogue entries |
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
- **Description technique** — `/technical`, ouverte à tous les rôles depuis le menu profil : la fiche
  technique de l'application, écrite pour les étudiants de l'établissement (le BTS SIO y est préparé,
  d'où le découpage tronc commun / SLAM / SISR). Toute la volumétrie est **mesurée à l'exécution** par
  `App\Service\TechnicalProfile` — entités depuis Doctrine, routes depuis le routeur, le reste en
  comptant les fichiers déployés. Seuls le nombre de commits et le nombre de tests sont figés, dans
  `config/tech_profile.yaml` avec leur date, `.git/` et `tests/` étant exclus de l'image de
  production ; `/beaup-deploy` les remesure à chaque livraison. Aucun chiffre écrit en dur : la page
  doit rester vraie sans être maintenue.
- **Aide** — `HelpSection`/`HelpArticle` (an article, a FAQ answer or a glossary term, one entity),
  reached from the profile menu only. Every entry names its audiences (`HelpAudience`:
  enseignant/administration/étudiant/tuteur) and `App\Service\HelpAccess` is the single place that
  answers who reads what; an admin reads everything and is the only one who writes. Students and
  tutors have no link into it yet, deliberately — their articles can be written first. A
  translation is a **second row sharing the slug**, not a field: URLs carry no language, and
  `HelpLocaleResolver` picks the reader's language entry by entry, falling back to French, so a
  half-translated section still shows its untranslated articles. `HelpOrdering` keeps every version
  of an entry in the same slot in the list.

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
hand. **It does everything a Symfony deploy needs**, migrations included: nothing in `deploy.yaml`
runs `doctrine:migrations:migrate`, and nothing should — the workflow's job stops at "run the script".

This is a real production deploy, never a dry run; the `/beaup-deploy` skill wraps it, and merging
`staging` → `main` is the only action in this repo that is always confirmed before running.

**Since 2026-08-10, `main` is only reachable through a pull request**, and that PR cannot be merged
until CI is green. Two GitHub rulesets say so:

| Branch | Rules |
|---|---|
| `main` | pull request required (**0** approvals — a sole contributor cannot approve their own PR), status check `Checks` required, signed commits, no deletion, no force-push |
| `staging` | signed commits, no deletion, no force-push — direct pushes are the normal way in |
| feature branches | none |

So a deploy is now: write the changelog entry, open the `staging` → `main` PR, wait for the green
check, merge it. That is what closes the gap this section used to end on — nothing ran between a merge to `main` and the deploy it
triggered. Note the repository-admin bypass is still granted on both rulesets, which is why a direct
push to `main` still succeeds while printing `Bypassed rule violations`: it is an escape hatch, not
the normal route.

**Every deploy writes its own release note.** `config/changelog.yaml` holds one entry per production
release — a CalVer version (`2026.08.10`: year, month, rank in the month), a date, a two-sentence
summary and one line per subject, typed `nouveaute` / `modification` / `fix` / `interne` / `autre`.
It is a file rather than a table on purpose: the changelog is part of the release, so it reaches
production by the same path as the code, with nothing to run on the server afterwards.
`App\Service\Changelog` reads it, `/changelog` renders it (profile menu, between "Aide" and
"À propos"), the "À propos" screen shows the current version, and `deploy.yaml`
posts to Discord three times (through `BEAUP_DISCORD_NOTIFS_WEBHOOK`, the same webhook the rest of
the CI already uses, and `vars.BEAUP_APP_URL` for the link) — the success one only after `/login`
has answered 200 from the open internet, VPN already dropped, since `deploy-prod.sh` returns while
the container is still installing and the site 502s for a while — when the deploy starts, when it succeeds (the summary, never the
`interne` lines), and when it fails (with a link to the run). All three are `continue-on-error` and
the script exits 0 on every error path: a silent Discord must never be what fails a deploy. The
history back to 2026-07-05 was reconstructed from the merges into `main`, one version per deploy day;
`/beaup-deploy` writes every entry from now on, which is the other reason a hand-rolled push to
`main` is the wrong move.

Commits are **SSH-signed** (`gpg.format = ssh`, a dedicated passphrase-less
`~/.ssh/id_ed25519_signing` registered on GitHub as a *signing* key). Before that, every commit
bypassed the `required_signatures` rule, which is worse than not having the rule — a warning you see
on every push is a warning you stop reading.

**CI runs on every push to `staging` and every pull request** — `.github/workflows/ci.yaml`, one job.
It boots the ordinary dev compose stack (there is no host-side PHP here, so every check is a
`docker compose exec php …`, the same command you would run locally) and then, in order:
`composer cs-check`, `lint:yaml`, `lint:twig`, `lint:container`, `composer phpstan`, then on a
throwaway database `doctrine:migrations:migrate` from empty, `doctrine:schema:validate`, and
`bin/phpunit`.

Three things about it are deliberate:

- **It needs no repository secrets.** The stack is self-contained, and the two files git does not
  carry are rebuilt in the workflow: `.env.dev.local` from its own tracked template with throwaway
  values, and `frankenphp/ldap/10-tree.local.ldif` from the tracked fictitious example (without any
  `.ldif`, openldap never creates its root entry and `up --wait` fails). Only `deploy.yaml` holds
  secrets, and only because it has to reach the school's network.
- **The migration step replays the whole history onto an empty database**, rather than running
  `doctrine:schema:create`. That is the pair that has value: migrations are what production actually
  runs and what nobody exercises by hand, and `schema:validate` right after proves the schema they
  produce is the one the entities expect. A migration someone forgot to write fails here.
- **It uses `TEST_TOKEN=ci`**, so the test database is `<database>_test**ci**`. A CI runner does not
  need the isolation; a developer replaying the sequence locally to debug a red run does, because
  otherwise it clobbers their own `_test` database.

The old template workflow (`deprecated.yaml`, wired to a `backuped` branch with every real step
commented out) was deleted rather than left alongside: it was also called `CI` and also triggered on
`pull_request`, so both would have shown up under the same name. `git log` still has it.

CI now gates `main` as well as `staging`, through the required status check on the deploy PR — see the
rulesets table above. It is the same single job in both cases; what changed is that on `main` it runs
*before* the merge rather than after.

**Test coverage is thin but no longer absent** — 162 tests: unit tests over pure services, one test
per Voter (`tests/Security/Voter/`), and a functional smoke test (`tests/Functional/`) that requests
each main screen as a student / teacher / admin / tutor and pins the answer. Run them with
`docker compose exec -e APP_ENV=test php bin/phpunit`; **`tests/README.md` explains the one-off test-database
setup they need**. Feature work is still verified in a real browser — the `browser-verify` skill drives
a headless Chrome against the dev app, and `beaup-sqs-check` polls the Courrier école queues.

When you add a screen or change who may reach one, extend `RoleAccessSmokeTest`'s table: it is the
cheapest place in this repo to notice that a role gained or lost access by accident.

**Static analysis is PHPStan at level 5 plus `checkExplicitMixed`**, configured in
`phpstan.dist.neon` over `src/` and `tests/`, with the Doctrine, Symfony and PHPUnit extensions wired
in (auto-registered by `phpstan/extension-installer`). Run it with
`docker compose exec php composer phpstan`. Two things it needs, both of which fail loudly if
missing:

- `var/cache/dev/App_KernelDevDebugContainer.xml` must exist — run `bin/console cache:clear` first if
  you have just wiped `var/`. That file is how the Symfony extension resolves service ids.
- `phpstan/object-manager.php` boots the kernel so the Doctrine extension reads real ORM metadata
  (entity field types, association targets, repository return types). `phpstan/console-application.php`
  does the same for command definitions.

**`phpstan-baseline.neon` is empty**, and worth keeping that way: the 112 findings of the first run
were fixed rather than parked (112 → 56 → 39 → 0). The run is green from a clean slate, so **any error
is yours**. The file and its `includes:` stay wired so `composer phpstan-baseline` still works if a
PHPStan upgrade or a raised level ever needs to park findings again — but adding to it is a decision,
not a reflex, and a regeneration that hides something you just broke looks exactly like one that fixed
something. Standing exemptions belong in `phpstan.dist.neon`'s own `ignoreErrors` instead, where the
reason sits next to the rule; there is one, for `Kernel::getAllowedEnvs()`.

Two habits came out of emptying it, both worth keeping:

- **Don't delete defensive code to satisfy `arrayValues.list`.** PHPStan calls `array_values()`
  redundant whenever a *docblock* promises a list, and this repo's docblocks are hand-written. On a
  public setter feeding a JSON column, that `array_values()` is input normalisation, and the promise is
  the thing that is wrong — widen the `@param` to `array<array-key, …>` rather than dropping the call.
  (The alternative, `treatPhpDocTypesAsCertain: false`, silences the whole family and was rejected: it
  would also have hidden four real findings in the same pass.)
- **A wrong docblock reads as dead code.** Two of the sharpest findings were annotations that lied —
  `readJson()` typed as a list when half its files are maps, and a `@var Collection` on a ternary that
  yields null. Both surfaced as "this branch can never run", not as a type error.

Level 5 catches the wrong-type-passed-to-a-method and undefined-method/offset families — which is
where this codebase's recurring gotchas live (the `$map[$key]?->method()` trap, stale
`@return array{...}` shapes). What it lets through is **`mixed`**: what a value becomes after a
`getScalarResult()`, a `json_decode()` or a form's `getData()` was checked nowhere. Turning
`checkExplicitMixed: true` on surfaced 313 findings; all were fixed, and the flag stays on so they
cannot come back. It is *not* level 6, which would additionally demand iterable value types
everywhere — it asks one question, and the codebase now answers it.

**Type at the boundary, never cast further in.** Four objects hold the answer, and a new endpoint
should reach for one rather than invent a cast:

- `App\Service\JsonRequestPayload` — the typed reading of a fetch/Stimulus JSON body
  (`string`/`int`/`ids`/`objects`/`intLists`), plus `fromArray()` for a value that never was JSON but
  carries the same problem, such as a session entry.
- `App\Service\DataTableParams` — paging and search for a DataTables endpoint. The same eight lines
  had been copied **twelve** times, which is how `search[value]` came to be untyped in all of them.
  It also caps the page length at 50, the only thing between the endpoint and a request for the whole
  table.
- `App\Service\FormValue` — `string`/`trimmed`/`int`/`float`/`bool` off a submitted field, because
  `FormInterface::getData()` is mixed by design.
- `@phpstan-type` on the class that *produces* a shape, `@phpstan-import-type` where it is consumed —
  for DQL rows, for the envelopes of external services (S3, SQS, SES, Gotenberg: declare only the keys
  you read, all optional), and for view-model rows shared between methods.

Check the shape against a real payload rather than guessing: the EDT import's cell did not match
intuition (`heures` is a float, and `grille` is absent from the files — the command stamps it on).

**Levels 6 to 8 were measured and rejected, and the reason is structural rather than effort.**
The counts are 195 / 724 / 1397. Level 6 is nearly all annotation (123 `FormInterface<T>` generics,
71 iterable value types) and would find no bug. Level 8's dominant family is 324 "method call on a
nullable", and **281 of those are Doctrine entities whose column is `NOT NULL`** — the getter is
`?T` only because Doctrine hydrates without the constructor, so the property must default to null.
Verified: `LessonSession::$day` is flagged although the column is `NOT NULL` with zero nulls in
3 474 rows, and so is `LaptopLoan::$dueAt`. Even the 43 date calls that look like real crash risks
are the same artefact. Satisfying level 8 would mean ~281 guards on values that cannot be null —
dead branches, which is worse code. `phpstan.dist.neon` already concedes the same point through the
Doctrine extension's `allowNullablePropertyForRequiredField`.

**Coding standard is PHP CS Fixer** on the `@Symfony` ruleset, configured in
`.php-cs-fixer.dist.php`. `composer cs-check` reports, `composer cs-fix` applies. The whole repo was
brought to the standard in one dedicated commit (`70f8e45`), separate from the config commit, so that
blame churn is identifiable at a glance. Four deliberate settings:

- **No risky rules.** It is a formatter; it must never change behavior. Anything that could is
  PHPStan's job.
- `migrations/` is excluded — Doctrine writes those and nobody re-reads them; reformatting them would
  only guarantee that every freshly generated migration fails the check.
- `design/` is excluded — gitignored reference material, not application code.
- `phpdoc_align` is **off**. This app's types are array shapes several dozen characters long:
  `@Symfony`'s vertical mode pads a short `@param` halfway across the screen to match its neighbor,
  and left mode flattens the hand-alignment that makes the long ones readable. Docblock padding stays
  the author's call.

The first pass touched 46 of 762 files, mostly dead imports and import ordering. Note CS Fixer
occasionally makes a dense one-liner *worse* (it will break a single-line `match` onto a brace without
splitting its arms) — read the diff, don't apply it blind.

**Rector is deliberately not part of the toolchain**, and the reason is no longer "nothing would catch
a bad transformation" — CI now replays migrations, PHPStan and the tests on every push to `staging`.
It was measured instead: Rector 2.6.1 installed on a throwaway branch, eight sets dry-run over `src/`
and `tests/`, then uninstalled. The result is roughly **35 real findings for ~1 000 files touched**,
and the decisive numbers are that `typeDeclarations` yields 23 changes and the `doctrine` set yields
**zero** — backfilling missing types is Rector's main selling point on a legacy codebase, and the
PHPStan level-5 pass already harvested it.

Two rules must never be run on this repository. Both look like the "smart", framework-aware ones, and
both are wrong here:

- `ControllerMethodInjectionToConstructorRector` (102 files) hoists each action's dependencies into
  the constructor, so every request instantiates the services of *all* actions instead of the one that
  runs. That is the opposite of Symfony's own recommendation, and this app's controllers are fat.
- `RemoveDefaultValueFromAssignedPropertyRector` (92 files) strips the `= null` from Doctrine entity
  properties. A typed property with no default is *uninitialized*, not null: any path that reads it
  outside full hydration raises an `Error` instead of returning null.

`SortAttributeNamedArgsRector` (46) and `NewMethodCallWithoutParenthesesRector` (51) are pure churn.

Two one-shot passes were applied and the tool removed again (staging `bc95af5`):
`AddOverrideAttributeToOverriddenMethodsRector`, and `SafeDeclareStrictTypesRector` over the 206 of
758 files where no scalar coercion is statically resolvable — **the other 552 are still weakly
typed**, and the split says where: `Enum`/`Form`/`Repository`/`Service` passed wholesale, only 6
controllers and 2 entities did.

If a future migration set ever justifies another pass, run **one named rule at a time**, never a
prepared set: install as a dev dependency, `git checkout -- composer.json composer.lock` immediately
after (`vendor/` stays), keep the throwaway `rector.php` out of the commit, then `composer install` to
restore `vendor/`. And verify a form submission in a browser afterwards — that is precisely what the
162 tests do not cover.

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
