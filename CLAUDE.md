# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

**MonCampus** — a campus-management platform, **mostly but not only pedagogical**: timetable, lesson log, assignments, gradebook, progressions, quizzes and surveys first, then apprenticeship tracking, student mail, directory, file library and wiki, a campus game, lab virtual machines, equipment loans, support and orienteering races. Deployed for *Institution Beaupeyrat*, which uses it — the application is not theirs. A single Symfony 8.1
application (PHP ≥ 8.5.7, Doctrine ORM 3, MySQL 8) served by FrankenPHP/Caddy in worker mode, plus a
JSON API consumed by two companion Flutter apps.

It started life as the `dunglas/symfony-docker` template and still carries that Docker/Caddy plumbing,
but the application itself is now the bulk of the repository: 1 496 PHP files / ~200k lines under `src/`,
216 entities, 206 controllers, 1 015 routes, 227 migrations, 634 Twig templates, 157 Stimulus controllers.

Every count in this file is a snapshot taken on **2026-08-29**. Treat them as orders of magnitude, not
as facts: the `/technical` screen recounts the same things at each display (`App\Service\TechnicalProfile`)
and is the one to trust when a number matters. The figures that report a *one-off measurement* — the
112 PHPStan findings of the first run, the 46 files of the first CS Fixer pass, the 195/724/1397 of the
level probe — are history and must not be "refreshed": rewriting them would erase what was actually
observed that day.

Treat any leftover template documentation (`docs/*.md`, parts of the README) as upstream material, not
as a description of this app.

Companion repositories (separate git repos, same staging workflow):
- `/Users/Shared/Projets/Flutter/moncampus-mobile` — the student/teacher mobile app (APK and
  IPA). Its **web build at `/campus-app/` is retired** (2026-11-04): the site itself is the
  installable app, « beaup-app » (see « Installer beaup-app » below), and only two PWAs come from
  this server, beaup-app (scope `/`) and e-CO (`/eco-app/`). `public/campus-app/` now holds one
  file, the kill-switch service worker `campus_sw.js` at the old worker's address (it clears the
  old `campus-app-*` caches, unregisters itself and sends its windows to `/`); every other
  `/campus-app/…` address is a 301 to `/` (`CampusAppRetiredController`). Chrome does not check
  the old worker for an update when that redirect takes the old icon's start page out of its
  scope, so every page of the site also unregisters it and deletes those caches
  (`assets/web_app/retired_campus_app.js`, imported by `app.js`) - only those: e-CO keeps its own
  caches in the same origin. **Never run that repo's
  `tool/build_pwa.sh` into this folder again** - it would overwrite the kill switch and bring the
  old app back. A browser still declaring `client: moncampus-web` (`MobileApp::CampusWeb`) is sent
  the site's own magic link (`MagicLoginService::requestLinkForApp()`); its last sessions stay
  listed in « Mon profil » until they expire.
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
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4   # whole suite, ~2 min (setup: tests/README.md)

docker compose exec php composer phpstan           # static analysis, level 5
docker compose exec php composer phpstan-baseline  # regenerate the baseline
```

Plain `docker compose` merges `compose.yaml` + `compose.override.yaml` (dev). Production needs both
`-f` flags, in this order:

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

Application commands (`src/Command/`). **They are not all scheduled** — the table says which is
which, and the distinction is worth keeping: a one-off, a diagnostic and a scheduled task each fail
differently when nobody runs them. **There is no crontab any more** (since 2026-09-28): the
scheduled ones are declared in one place, `App\Scheduler\PlatformSchedule` (Symfony Scheduler), and
run by the `worker` service — see « Runtime architecture » below and `docs/production.md`,
« Scheduled tasks ». `PlatformScheduleTest` pins that list, so adding a task is a decision the test
makes visible. A scheduled command that exits non-zero is logged at error level with its output
(`App\Scheduler\ScheduledCommandLogger`) and so reaches Discord. A command that locks itself uses
`App\Command\SharedLockableTrait`, **never Symfony's `LockableTrait`**: the worker runs the same
command instance again and again in one process, and `LockableTrait` — which releases only when
asked — made the second run throw « A lock is already in place ».

| Command | Role |
|---|---|
| `app:mail:consume-inbound` | Pull inbound student mail from SQS/S3 into `EmailMessage` |
| `app:mail:consume-events` | Pull SES delivery/bounce events, update `EmailEvent`/suppressions |
| `app:mail:reconcile` | Repair drift between SES state and local rows |
| `app:mail:backfill-student-aliases` | One-off alias generation for existing students |
| `app:mail:relink-applications` | **Repair pass, not scheduled.** Re-reads the Courrier pro mails that have a student but no démarche, and files the ones that *quote* a send: the Message-ID a failure notice copies back, failing that the failing address found among the recipients of that student's own sends — and only when every match agrees on **one** démarche. The whole rule lives in `App\Service\SchoolMailApplicationRecovery`, which the inbound worker now applies on arrival; the command exists only for the rows written before it. `--dry-run` names each mail and the evidence found, which is how it should be read first: it files mails under démarches nobody named |
| `app:import-edt-timetable`, `app:import-edt-periods` | Timetable import from the school's EDT export |
| `app:import-notion-sequences` | One-off import of pedagogical sequences from a Notion export |
| `app:purge-platform-activity` | Retention: 12 months on `PlatformActivity` and on `QuizAttemptEvent`, **24 months after the last activity on a learning-path follow-up** (`LearningPathEnrollment`, with its visits and attempts), **90 days on `ConsoleSession`** with the screen transcripts it carries, **30 days past expiry on the Claude connector's OAuth codes and tokens** (and on its upload addresses, on the clients nobody ever consented to, and on the dead mobile sessions), and **24 months on `JobboardOffer`** read on the advert's publication date (an offer with no date is judged on the day it was first seen — `sort_date` holds 1000-01-01 for those and reading the threshold against it would empty the board). **Scheduled daily at 03:15** — it was never wired to the old crontab, and the schedule is what finally runs it. The journal at `/infrastructure/console-sessions` prints « Conservation 90 jours » on screen, so a command nobody runs turns that line into a promise nothing keeps. Volume is *not* the argument — a transcript measures a couple of kibibytes — the retention decision is. See `docs/production.md` |
| `app:antivirus:check` | **Diagnostic, not scheduled.** Scans a clean file and the EICAR test string through the configured `ANTIVIRUS_DSN`; exits non-zero unless uploads are genuinely being refused. The state it exists for is the silent one — a blank DSN disables scanning without announcing it anywhere |
| `app:help:sync-content` | Creates the missing help sections/articles from `App\Help\HelpContentCatalog`; never overwrites what an admin has edited (`--refresh` also rewrites the untouched ones). Run it once after a deploy that adds catalogue entries |
| `app:vm-batch:advance` | Continues every VM deployment already under way, one machine per pass. **Scheduled every minute.** It is what makes a deployment survive the browser tab that started it — without it the batch screen's own loop is the only thing pressing, and a closed tab leaves machines cloned and never configured. It never *starts* a deployment: a batch whose machines are all still `planned` is a plan, not an instruction |
| `app:ldap:apply-account-requests` | Relit l'annuaire pour chaque demande de `ldap_manage_account` que le script consommateur a terminée, et applique la conséquence côté application — aujourd'hui la bascule de `User::$username` après un renommage confirmé. **Scheduled every minute.** C'est ce qui fait qu'un onglet fermé ne décide de rien : la fiche sonde la même chose toutes les 2 s, mais la boucle du navigateur n'est jamais ce qui porte le travail. Idempotente (`applied_at`) et verrouillée (`SharedLockableTrait`). Voir `docs/production.md` |
| `app:proxmox:secrets` | **Diagnostic, not scheduled.** Says whether the sealed Proxmox secrets still open, and prints a fingerprint of the `PROXMOX_SECRET_KEY` in use. Run it before and after a deploy: a fingerprint that changes means the key is not being carried across releases, which makes every stored token unreadable at once and looks, from the screen, exactly like Proxmox refusing them |
| `app:guest-accounts:prune` | **Maintenance à la demande, jamais planifiée.** Supprime les lignes `guest_account` dont la machine n'existe plus sur l'hyperviseur, ou dont le VMID a été repris depuis par un autre lot — Proxmox rend un numéro dès qu'une machine est détruite, et les lignes de l'ancienne occupante restent classées dessous (cette moitié-là se décide sur les seules données de la plateforme, sans rien demander à l'hyperviseur ; `App\Service\Proxmox\VmidHandover` la ferme à la source lors des créations suivantes). C'est l'état que /infrastructure ne montre pas : ces écrans lisent Proxmox à l'affichage, donc une machine détruite cesse d'y être listée, tandis que ses comptes restent — et « Mes machines virtuelles » est bâti sur ces comptes. Un hôte injoignable ne décide rien : ses comptes sont comptés à part et laissés tels quels. `--dry-run` nomme chaque ligne avant d'y toucher. Jamais planifiée : supprimer est une décision, pas un horaire |
| `app:ecoledirecte:check` | **Diagnostic, not scheduled.** Runs the one step of an École Directe login that needs no account (the GTK cookie of `login.awp?gtk=1`) and says whether École Directe still answers it from this server. The platform holds no École Directe credentials and must not, so this is the most it can prove on its own |
| `app:counters:recompute` | **Scheduled, once a night** (`docs/production.md`). Checks every stored counter of the platform (`App\Counter\RecomputableCounter`, one tagged service each) against its source and corrects the drifts - each correction logged at error level, so it reaches Discord: a drift is a bug, not a figure to patch. The counters move in real time with what changes them; this is the safety net. `--counter=`, `--dry-run` |
| `app:quiz-tournament:close-duels` | **Scheduled every minute.** Finishes the tournament duels both players left: a duel runs on the server's clock whether anybody watches it, and the duel screen finishes it as soon as one player asks after its last question - but nobody asks when both are gone, and an unfinished duel refuses « Prêt » to both players in every tournament. Goes through `QuizTournamentDuelEngine::settle()`, the screens' own door (scores, ratings, exactly once). Also writes the challenges whose 24 h are over as expired / missed (two guarded UPDATEs: a room that starts its duel in the same second keeps « played »). Locked (`SharedLockableTrait`) |
| `app:eco:read-terrain` | **Scheduled every minute.** Asks the IGN's Géoplateforme what e-CO's statistics read: the terrain analysis of the oldest parcours waiting for one (button « Analyser le terrain », last flag located, or a race on a parcours never analysed), then the terrain altitude / path / wood of every GPS fix of the oldest closed race not yet read. A Géoplateforme that does not answer is a *warning* and a retry next minute, never a non-zero exit. See `docs/production.md`, « e-CO and the IGN's Géoplateforme » |
| `app:rncp:fetch` | **Scheduled every minute.** Serves the « Récupérer chez France compétences » requests of the portfolio's Référentiels tab: finds the day's `export-fiches-rncp-v4-1-*.zip` through the data.gouv.fr API (no URL written down), downloads it once into `var/rncp/`, streams through it (`XMLReader` over `zip://`) and writes the fiche into the `RncpImport`. Nothing requested, nothing downloaded. A network failure is « En échec » on screen, never a non-zero exit. `--file=` reads a local export |
| `app:rncp:check` | **Scheduled Mondays 05:30.** Rereads each référentiel's RNCP fiche - still active, end of registration, a fiche that now replaces it - into `Referential::$rncpWatch`; the Référentiels screen turns a change into a banner, logged at *warning*. Never rewrites a référentiel: a new fiche is a new version |
| `app:class-board:photo` | **Scheduled every hour at :07.** The virtual board's photograph of the day (« Photo », the default background): the first pass of the day draws a photograph from Wikimedia Commons' featured nature categories (`App\Service\ClassBoard\CommonsNaturePhotos`; `CommonsPhoto::fromImageInfo()` is the whole rule - camera EXIF, 4:3 to 2:1, CC0/PD/BY/BY-SA), copies it into the uploads bucket and records it (`ClassBoardPhoto`), the others only clean: bytes deleted two days after their day, rows kept so nothing repeats within two years. A Commons that does not answer is a *warning*, never a non-zero exit. `--replace` draws another one for today |
| `app:serious-games:sync` | **Run once after a deploy that adds a serious game, a template or a ticket, never scheduled.** Creates or updates each game of `config/serious_games/*/game.yaml` (unpublished on creation) and installs the templates the database lacks as version 1, validated, after a bench of 200 seeds - a red bench refuses the template. A scenario's tickets are read into the manifest, checked whole by `ScenarioValidator`. Never overwrites a version written in the database; `--refresh` rewrites a shipped version still current and never served. See `docs/production.md` |
| `app:serious-games:bench` | **Diagnostic, not scheduled.** The bench of a game's question templates (1000 seeds by default): answer accepted typed and displayed, every wrong value recognised as its own typical error, no NaN, determinism, series without duplicates, comets. `--files` reads the repository without a database, `--draft` the latest drafts, `--save` stores the reports - which is what makes a draft validatable |
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
  `phpmyadmin` and `openldap` (reachable from `php` at `ldap://openldap:1389`). Uploads have no
  stand-in: dev writes to the real S3 bucket under the `dev/` prefix, and the test environment
  swaps the storage for a directory under `var/cache`.
- `compose.prod.yaml` — prod overlay: `frankenphp_prod` target, secrets injected from the environment.
  Also the **`worker`** service: same image, `messenger:consume scheduler_default`, i.e. every
  recurring task of `App\Scheduler\PlatformSchedule`. It bypasses the entrypoint (which migrates —
  `php` does that) and disables the inherited healthcheck (which asks Caddy — left on, `up --wait`
  would hang). Its `environment:` repeats `php`'s and must be kept in step: a command that flushes
  needs `MERCURE_URL`. `php` and `worker` share the `app_locks` volume, which `LOCK_DSN`
  (`flock:///app/var/lock`) points at — that is what keeps a lock one lock across the two
  containers (a manual run vs. the scheduled one, the batch screen vs. `app:vm-batch:advance` on a
  VMID and on a batch). In dev the worker is opt-in: `docker compose --profile worker up -d worker`.
  Messenger has no other use: no queue transport, and the mailer's and notifier's messages are
  handled synchronously, in the request that sends them.
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
  cahier de texte, carnet de notes. **Four libraries now carry the same folder tree** — fichiers,
  quiz, sondages, séquences — and it is deliberately one design copied four times rather than one
  shared implementation: `FileLibraryNode` → `QuizFolder` → `SurveyFolder` → `SequenceFolder`, each
  with its own `*FolderManager`/`*FolderTree`/`*FolderVoter`, all delegating the path arithmetic to
  `App\Service\FileLibraryTree`. Their rails must stay identical gesture for gesture: a « Nouveau
  dossier » button pinned to the foot, and on each row a pencil (renommer) and a bin (supprimer).
  The séquence library is the one that differs, and only there: a séquence row is dragged to
  **reorder** the folder (the ⠿ handle), never to file it, so filing goes through « Déplacer vers… »
  alone — one row cannot mean two things while being dragged.
- **Tableau virtuel** (Outils › Animer la classe) — `ClassBoard`, `App\Controller\ClassBoard\*`,
  `src/Service/ClassBoard/`, spec `design/validated/tableau-virtuel.md`. A Digiscreen-like board for
  the projector, under `Feature::ClassTools`: **personal** (`ClassBoardVoter`, owner only, admin
  included, 404 for anyone else), linked to a class or to none, one window (no pilot screen). The
  widgets are **one JSON document** saved whole, 2 s after the last change, refused on a stale
  `revision` (two tabs) and refused whole at its first invalid element by `ClassBoardLayout`, the
  only door - which keeps what was *set*, never what runs. `ClassBoardWidgetType` is the catalogue
  the dock, the validation and the partials (`templates/class_board/widgets/_<type>.html.twig`) all
  read. What a widget reads of the class goes through `ClassBoardWidgetData`, asked again at every
  opening, with the source screen's own rules: « Travail à faire » lists the owner's own work only,
  « Déroulé » shows a phase's name and duration and nothing else of it, slots are converted from
  hours to minutes once (`TodaySlots`). A drawing is a PNG under `class-board/{board}/{widget}-…`,
  a key the layout only ever takes from the server or for that very widget (`ClassBoardDrawings`).
  The board writes nothing students see elsewhere; a saved draw's history goes through the random
  draw tool's own route. **« Groupes » is the group creation tool's left panel, not a copy of it**:
  one partial (`program/_group_creation_panel.html.twig`), the tool's own
  `group_creation_controller.js` nested in the widget, the tool's own routes - minus saving: no lot
  is ever written from the board, and the layout keeps the panel's settings, never the groups, the
  absentees or the pairs. Every widget's head carries three sizes (whole surface, half, quarter):
  they only write `x`/`y`/`w`/`h`, and the widget in hand (`.is-front`) is drawn over the two bars
  so that a head taken to the top edge stays in reach.
- **Murs collaboratifs** (Outils › Animer la classe; « Ressources » for a student) — `Wall` →
  `WallList` → `WallCard` → `WallComment`, `App\Controller\Wall\*`, `src/Service/Wall/`, handoff
  `design/design_handoff_murs_collaboratifs/` (no spec in `design/validated/`: the handoff is the
  design, and the rules it leaves open are the ones below). A Trello and a Padlet in one: lists
  holding cards (label, text, picture, link, file, checklist, comments), read as « Colonnes » or
  « Grille », copied or moved from one wall to another, shown full screen or one card at a time.
  **`Feature::Walls` is off for every role: administrators only, until Gestion › Fonctionnalités
  opens it** - one line for both sides of the room, like the word cloud. There are **no template
  walls**: the handoff's third tab was dropped. Rules the code holds:
  - **`App\Service\Wall\WallAccess` is the whole « qui fait quoi »**, read by `WallVoter` and by
    nothing else. Its owner; a **Manager** - a colleague a teacher shared with, who runs the wall
    (settings, lists, validation, copies) but neither shares nor deletes it; a **Participant** - a
    student of a class the wall is shared with, or anybody a *student* invited, teachers included.
    Tutors and outside accounts have no walls at all.
  - **A student's wall is read by the teachers of the class** - `WallSupervisors`, a fourth role
    (**Supervisor**) that reads everything, the cards awaiting validation included, and writes
    nothing: no card, no tick, no comment. It follows the *student* (a wall a student owns, read by
    whoever teaches an active class of its owner or of a classmate named on it) and the *class*:
    a teacher's own wall is supervised by nobody, and the administration and the establishment's
    other teachers read no student's wall on the strength of their role. **Nobody else outside a
    wall opens it, administrators included** (404). The student is told so where they share
    (`wall/_share_fields.html.twig`).
  - **« Murs étudiants »** (Ressources, for `ROLE_TEACHER`; `StudentWallController`,
    `StudentWallDirectory`): the class - one taught goes straight to it - then two sections, each
    student's own walls and the walls of groups of students (a student's wall with another student
    on it; a teacher they invited does not make a group). It lists the classes one teaches
    (`ProgramRepository::findAllForTeacher()`) and no other. The wall opens through its own route,
    told by `?class=` which trail to draw and by the `wall_supervised` request attribute which
    menu to light - the parameter opens nothing.
  - **`WallAudience` bounds what is *added***: a teacher adds the classes they teach
    (`ClassBoardPrograms`, the virtual board's own list) and colleagues, never students one by one;
    a student adds classmates and the teachers of their active classes, never a class. Somebody
    already on a wall stays until the owner takes them off.
  - **The page draws nothing of its own.** Every action answers the same document - the wall as
    *this* person reads it (`WallResponder::state()`: revision, canvas in their view, the card they
    have open) - and `wall_controller.js` swaps it in. `WallLiveNotifier` only announces a revision
    over Mercure; each browser then asks for its own reading, because a card awaiting validation is
    not everybody's to read. A redraw waits for a field to be left and for one's own request to
    come back (the hub is told before the answer leaves).
  - **A card awaiting validation** (`WallCardStatus::Pending`, only on a moderated wall, only for a
    participant's card) is left out of `WallBoardView` for everybody but its author and the
    managers - never hidden by a stylesheet - and is **never projected**. A card that arrives from
    another wall is moderated by the wall it lands on, and is never published by being copied.
  - **`WallWriter` and `WallCopier` hold every write** and never flush; `WallResponder::commit()`
    does, moving `Wall::$revision`. An update names its fields and writes no other. A card is moved
    by naming the card it lands *before*, never a rank: a participant does not see the pending
    cards, so the rank they count is not the list's. `WallBoardView` sorts cards by rank itself -
    the collection in memory keeps the order it was loaded in, in the very request that moved one.
  - **Files** (`WallFiles`, prefix `walls/`): the browser stages on `/uploads/stage`, the wall is
    sent the token. A picture is `UploadPolicy::images()`, a file the platform rule. A copied card
    owns **copies** of its objects, so forgetting one card never takes another's file.
  - A list's colour is `null` for the default grey (it then follows the theme); a chosen colour is
    drawn as chosen whatever the theme, with the light theme's ink on it. A link without a scheme
    is https, and anything that is not http(s) is refused (`WallWriter::url()`).
  - Two things the handoff does not draw and a wall cannot do without: the title and « Supprimer
    le mur » are in « Paramètres », and « Étiquette » joins the open card's « Ajouter ». Deleting is
    a two-click button (the label becomes « Confirmer la suppression »), never a `confirm()`.
- **Quiz** — `QuizTemplate`/`QuizQuestion` (library, filed in `QuizFolder`s) → `QuizInstance`
  (launched snapshot) → `QuizAttempt` (passation). Live multiplayer (`QuizLiveSession`) runs over
  Mercure/SSE and **follows Kahoot's run of a question** (2026-11-05, `App\Enum\LiveSessionStatus`):
  the statement alone, then the answers and the clock - closed by the last answer, the clock (the
  host's screen posts it) or « Passer » -, the results, the scoreboard (skipped after a vote and after
  the last question), the podium. Every transition names the step it was asked on and runs under a
  lock on the session row (`QuizLiveSessionService::locked()`, not `wrapInTransaction()`, which closes
  the entity manager on a refused answer). The room plays **every type a Kahoot carries**
  (`App\Service\QuizLive\LiveAnswerKind`): QCM and QCM multiple up to six shapes, ordre tapped shape
  by shape, numérique on a slider (`numericConfig.range`), réponse courte, vrai/faux and the
  **`vote`** type - propositions with no right one, played live only (`QuestionType::
  isAvailableOutsideLive()` keeps it out of every other launch). Texte à trous, zones, apparier and
  calculée are left out at launch and named. A live answer is one JSON `response`
  (`App\Service\QuizLive\LiveResponse`); right is right whatever the type, no partial points.
  **Kahoot reports (.xlsx)** are imported by the screen and by the connector's `quiz_import_kahoot`
  (a .zip of them too) through `App\Service\Kahoot\KahootReportReader`: both report layouts, every
  type, each question's own time (`seconds` in « moncampus-quiz/1 »), a slider's margin guessed from
  who scored (`KahootSliderTolerance`); nothing of the players is kept, and a report has no images. The « mode contrôle » times each question **server-side**
  (`QuizAttemptAnswer::$servedAt`, stamped only if null, so F5 cannot reset the clock) — the point of
  that timing is to *exonerate*: three seconds on a question is proof nobody had time to look it up.
- **Tournois de quiz** — `QuizTournament*` entities, `src/Service/QuizTournament/`, spec
  `design/validated/quiz-tournament.md` (being built in lots; lot 1 the model and the rules, lot 2
  the administration and the standings page, lot 3 the challenges, the room, the duel and its
  history, lot 4 the rounds in class, lot 6 « Jouer », lot 8 the avatar moved to « Mon profil »). Students reached by a **targeting** challenge each other to synchronous duels of 20
  questions drawn from ordinary library quizzes; an Elo rating ranks them. `Feature::QuizTournaments`
  is off for every role; creating and opening is `ROLE_ADMIN` alone whatever the matrix says
  (`QuizTournamentVoter`, the only arbiter, facts from `QuizTournamentAccess`). Rules the code holds:
  - **Targeting is a shared brick**: `StructureTargetable` + `StructureTargetsTrait` (ManyToMany to
    Section, Track, Program; join tables named after the using entity) and
    `App\Service\StructureTargeting\StructureTargeting`, whose decision is the pure
    `StructureTargetRule`: an **active** Program targeted itself or under a targeted Track or Section,
    with the test zone's asymmetry. Read at every gesture, never frozen. The serious games will use it
    too.
  - **Every rule is a pure function on primitives**, tested as such: `QuizTournamentEligibility`
    (eligible, in progress, view/play/manage/open a round), `QuizTournamentChallengeRules` (each
    refusal a `QuizTournamentChallengeRefusal` case the button names; 3 encounters a day per pair is a
    **Paris** civil day, round duels included), `QuizTournamentElo` (1000, K 40 then 20, the « enjeu »),
    `QuizTournamentStanding` (four blocks; the ladder rule reads flags **stamped when the duel or the
    challenge happened** - « nearest ranked at the time » cannot be recomputed later; with nobody else
    ranked it holds by itself), `QuizTournamentQuestionDraw` (seeded, proportional, vrai/faux capped,
    never fails; formats from `QuestionType::isPlayableInTournament()`), `QuizTournamentRoundPairing`.
  - A pending challenge past 24 h **is** expired and an accepted one past its room **is** missed,
    whatever the stored status says (`QuizTournamentChallenge::statusAt()`); queries count « en
    attente » with `expiresAt > now` for the same reason.
  - A duel's questions are frozen through `QuizQuestionDefinitionTrait`, their choices a JSON snapshot
    in the order both players see; `servedAt` is stamped once.
  - **Administration** is Paramètres › Pédagogique › « Tournois » (`App\Controller\Settings\QuizTournamentController`,
    the tab shown to ROLE_ADMIN once the feature is lit): every tournament, opened or not; « Ouvrir » is
    its own gesture (born closed). `QuizTournamentEditor` is the one door: the save-time reserve check
    (`QuizTournamentReserve`, « N questions ignorées : apparier, ordre » and what is missing), and **after
    the first duel only the name, the end and the targeting are fields** (`QuizTournamentType` `locked`
    renders nothing else, so a forged POST moves nothing); deleting only before any duel. Sources come
    from the creator's own quiz library, through the library picker rows of « Lancer un quiz ».
  - **`StructureTargetsType`** is the one targeting field (`inherit_data`, writes `targetSections` /
    `targetTracks` / `targetPrograms` onto an entity or an array): the active structure only
    (`StructureTargetChoices`), test formations apart, what is already named kept even once
    deactivated. Its boxes are placed in the tree by id (`templates/form/structure_targets.html.twig`);
    it never ticks a child for the person.
  - **The standings page** (`/tournaments/{id}`, `App\Controller\QuizTournament\TournamentController`)
    reads `QuizTournamentStandingsAssembler` - players, eligible students (`reachedStudents()` +
    ROLE_STUDENT), 14-day activity, ladder facts and rating trend, a fixed number of queries - and
    **writes « la classe affichée » on the player as it shows it** (§2). Eligibility is read now even
    for a tournament that is over.
  - **The tournament's dress is its own stylesheet**, `assets/styles/quiz_tournament.css`, scoped under
    `.qt[data-tournament-palette]` and loaded by the tournament screens alone (H « Pop », Archivo and
    Archivo Black self-hosted in `assets/fonts/`); a palette is three custom properties whose values
    `QuizTournamentPaletteStylesheetTest` pins against `QuizTournamentPalette::colors()`. Every H screen
    extends `templates/quiz_tournament/_layout.html.twig` (trail, head with the emblem, tab bar). The
    logo is data uploaded per tournament (`UploadPolicy::images()`, no SVG): the Tournoi RGPD's shield
    is shipped ready to upload as `public/quiz-tournaments/logo-tournoi-rgpd.png` (its SVG source
    beside it); without a logo, `QuizTournamentMonogram` draws one from the name.
  - **Challenges** (`App\Controller\QuizTournament\ChallengeController`, `QuizTournamentChallenges`):
    send / accept / refuse read every rule again server-side under one lock per tournament, and a
    refusal comes back as the sentence of its enum (`QuizTournamentRefusal`). The standings' « Défier »
    is greyed with its reason from `QuizTournamentAccess::challengeFactsByOpponent()` (every line in a
    fixed number of queries - it must stay equal to `challengeFacts()`, `QuizTournamentFactsTest` pins
    it) and opens the H-defier sheet, fetched into a `<dialog>`. **« Mes défis » expires lazily**:
    `QuizTournamentChallenge::settle()` writes what `statusAt()` reads (24 h, and the tournament's end
    closes pending challenges and rooms) when the board touches a row; `QuizTournamentChallengeBoard`
    is the pure reading, rooms first, and its `count()` is the tab's counter. Mes défis is a student's
    screen (404 for anyone else).
  - **No notification, only page updates over Mercure** (`QuizTournamentLive`): a private topic per
    student and tournament for the « Défis (n) » counter, one per room for « Prêt » and « start »,
    subscribed through the bundle's cookie (`subscriber` hub) by the tournament screens alone, and
    published after each change. Never add a listener outside the tournament screens.
  - **The room** (`RoomController`, `QuizTournamentRooms`, `QuizTournamentRoomRules`): « Prêt » is a
    POST repeated every 20 s, lost after 45 s, cleared by a beacon when the page goes (`pagehide` *and*
    the Stimulus `disconnect`, a Turbo visit fires no pagehide); refused while either player has an
    unfinished duel anywhere. **`QuizTournamentDuelStarter` is the only door to a challenge's duel**
    (the duel itself is made by `QuizTournamentDuelFactory`, shared with the rounds):
    room lock (LOCK_DSN) + the challenge row FOR UPDATE, an existing duel answered as is, then
    `shouldStart()` on fresh facts, the draw, the frozen questions (choices with local ids,
    `QuizTournamentDuelSnapshot`; images referenced, not copied), ladder flags and ratings before.
  - **The duel is server-timed and lazy** (`QuizTournamentDuelEngine`, the one door: the screen's
    state, an answer, a draft, `app:quiz-tournament:close-duels`). `QuizTournamentDuelClock` is the pure
    timeline: the first question served `LEAD_SECONDS` after the duel's creation (the room's
    countdown), **each player with their own deadline** - the question's limit (30 s, 40 s for a QCM
    multiple by default) plus their « ajout de temps », frozen on the duel at its creation
    (`QuizTournamentDuel::timeLimits()`: the tournament is a quiz) - the question closing once each
    player answered or ran out, the next served `TRANSITION_SECONDS` later, the duel over two seconds
    after its last. A player's deadline reaches only their own requests (`clock`), never the published
    state. What is ticked or typed without « Valider » is a `QuizTournamentDuelDraft`, which becomes
    the answer, dated at that player's deadline, once their time is out. Every request first stamps
    the serves whose time has come - **at the computed moment, never the request's**, and only once
    (`serve()`), so a reload restarts nothing and nobody's absence pauses anything - and finishes a
    duel that is over: one transaction, the duel row FOR UPDATE, both players locked in id order,
    `QuizTournamentElo::rate()`, `recordDuel()` on both, `finish()`; a finished duel is left as it
    is. All under a LOCK_DSN lock per duel. Times are whole seconds, what the columns keep.
  - **What a player learns during a duel**: the public state (same for both, published on the duel's
    private topic) carries the question without its correction, who answered, right or wrong for
    each once the question closed, the scores - never the right answer, never the other's pick; one's
    own pick comes back only in the answer to one's own request (`mine`). Grading is
    `QuizAnswerChecker` on the frozen choices (`QuizTournamentDuelGrader`): 0 or 1 a question - the
    four duel formats are all-or-nothing on this platform, a QCM à réponses multiples included - no
    penalty, an unanswered question 0.
  - **After the duel** (`DuelController`): result or defeat (`QuizTournamentDuelReports`, rules in the
    pure `QuizTournamentDuelReading` - the consolation reads the ratings *before* the duel, the errors
    are grouped by the question's source quiz), the correction one question at a time behind links
    (`?filter=errors`, `?theme=`, `?q=`), « Mes duels » with the rating line
    (`QuizTournamentRatingChart`, server-drawn SVG, hover by Stimulus). **A duel is its two players'
    alone** - teachers and administrators included get a 404 - and its result and correction exist
    only once it is finished. A player who lost their eligibility keeps them.
  - **Rounds in class** (§8, `RoundController`, `QuizTournamentRounds`, rules in the pure
    `QuizTournamentRoundRules`): a teacher of a targeted class (`OPEN_ROUND` for that class) opens
    one, narrowed or not to **one** Option of the class or **one** group of a `GroupBatch` of that
    class they own or were shared - **one open round per class at a time**. Expected = the class's
    students still eligible ∩ the restriction, **read on the day** (a deleted lot expects nobody).
    Students join from the tournament page or « Mes défis » (the card, `_round_entry.html.twig`),
    then their page beats every 10 s (`QuizTournamentPlayer::$lastSeenAt`, absent after 30 s). No
    timer: « Clore et apparier » pairs the entrants - participants still expected, present, able to
    play, in no unfinished duel anywhere - with `QuizTournamentRoundPairing`, every duel with the
    **same** start, origin Round; the odd one out is exempted (`recordRoundExemption()`). Students
    are told on their own counter topic (`{"event": "round"}` / `{"event": "duel", "url"}`), never on
    a shared one. « Annuler la ronde »; a round never expires. **The projected screen**
    (`round.html.twig`, outside the app shell, a 16:9 stage sized in em so 1280 × 720 scales whole)
    is its opener's alone (404 for anybody else, administrators included) and follows the round's
    topic: roster changes, then a per-duel summary (scores, question - never a question's text)
    that `QuizTournamentLive::publishDuel()` adds for a round duel; it draws no « Formatool ».
  - **« Jouer »** (§15, `PlayController`, `/play`, `App\Service\Play\PlayHub`, rules in the pure
    `PlayHubRule`): a **pure student's** entry and page - the tournaments that reach them (opened, the
    feature lit, eligible; listed until 30 days after their end) and the serious games open to them
    (`SeriousGameShelf`), each card in its content's dress (the tournament's palette and emblem, the
    catalogue's own `serious_game/_card.html.twig`). The entry is drawn only when one of the two has
    something (`play_hub_visible()`, the rule the page 404s by) and **never carries a counter**: the
    card's « n défis en attente » is the page's content, not an alert (§7). The page belongs to two
    features, read as an OR (`#[RequiresFeature(QuizTournaments, SeriousGames)]`). Adults get no bar
    entry: Outils › Animer la classe › « Tournois » (`quiz_tournaments_followed()`: a teacher once an
    opened tournament targets a class they teach, an administrator once one exists) opens `/tournaments`.
- **Sondages** — the same three-step shape as Quiz, and deliberately so: `SurveyTemplate` (library,
  `SurveyFolder`s) → `SurveyCampaign` (launched wave, carrying a frozen copy of the questions) →
  `SurveyResponse`. `SurveySeries` replays a campaign to compare waves. Two things freeze at launch
  and never move: the `SurveyTarget` (the denominator of the response rate) and `$anonymous` —
  **anonymity is not a permission**, no name is stored, and staff are not exempt.
- **Jeu du campus** — gamification, off by default per role. `GameEntry` is an **append-only**
  ledger: no balance is stored anywhere, a family's points are the sum of its lines, and a
  contested gesture is undone by an *inverse line* (`reversalOf`), never a delete. An entry carries
  the date it happened on, so which month it counts towards is a *reading*, never a condition on
  writing it. Ranking is on a **rate** over a calendar month (`GameMonthScore`), and
  `app:game:close-month` is the scheduled task that closes one. The six level thresholds are
  establishment-wide, in `App\Service\Game\GameLevels`; `GameLevelLabel` holds only their *wording*
  per filière — a threshold that moved between formations would make the avatar's ring mean nothing.
- **Serious games** (`/serious-games`) — `SeriousGame*` entities, `src/SeriousGame/`,
  `App\Controller\SeriousGame\*`, spec `design/validated/serious-games.md`; ten games built,
  **L'Observatoire** (automatismes de 1re), **Les Tablettes de Pompéi** (déclinaisons latines,
  4e-2nde, `design/validated/serious-games-pompei.md`), **Station Abysse** (SVT, 2nde-1re,
  `serious-games-abysse.md`), **The Irregulars** (anglais, 4e-3e, `serious-games-irregulars.md`), **Panique
  à la Neko-Con** (HTML et CSS, BTS SIO 1, `serious-games-neko-con.md`) and **Black-out** (réseau et
  cybersécurité, BTS SIO 2 SISR, `serious-games-blackout.md`) and **Rétro Rush** (bases de données
  et SQL, BTS SIO 2 SLAM, `serious-games-retro-rush.md`) and **Radio-Comète** (scripts Bash, BTS SIO SISR, `serious-games-radio-comete.md`) and **Fusion** (le plan de bascule, BTS SIO 2 SISR, `serious-games-fusion.md`) and **Service de nuit** (PHP pour débutants, BTS SIO 1, `serious-games-service-de-nuit.md`), the six of the « scénario » mechanic. **In the margin of Formatool**: it shares the sign-in
  and « the teachers of my classes read my results », nothing else - no work, no reminder, no
  mark, no link with the Jeu du campus (`Feature::Game`, unrelated). `Feature::SeriousGames` plus,
  per game, **for adults** one line in the « Serious games » block of Paramètres › Fonctionnalités
  (`SeriousGameRoleSetting`, by role, no student column - `SeriousGameRules::matrixRoles()`) and **for
  students its targeting** (`SeriousGame` is a `StructureTargetable`: sections, filières, formations;
  `StructureTargeting::reaches()`), set in Paramètres › Serious games › « Qui joue (étudiants) »
  (`SeriousGameTargetingController`, with whom it reaches today, `SeriousGameStudentReach`). An empty
  targeting closes the game to every student - and **every game started closed** when the student
  cells were dropped (2026-11-04, nothing carried over). A student holding an adult role still plays
  by that role's cell. The targeting decides who *opens* a game, never what is kept: losing access
  deletes no progress, and a teacher reads past results whatever the targeting
  (`SeriousGameReadingTest`). `SeriousGameShelf::cardsFor()` lists the games open to a person with
  their progress; `SeriousGameRules` is the whole « qui joue, qui lit »
  (read by `SeriousGameVoter`): a student's results are read by them, by every teacher of one of
  their **active** classes (history included - asked now, not at the time of play, and never
  through `isProgramTeacher()`, which lets staff in) and by administrators; staff read figures
  without names; tutors and externals nothing. **A student's door is « Jouer »** in their bar (the
  tournaments' page too, see « Tournois de quiz »), the adults' Ressources (play) and Outils
  (results). Rules the code holds:
  - **The server generates and corrects.** Questions come from declarative JSON templates
    (`moncampus-gabarit/1`, `App\SeriousGame\Template\TemplateInterpreter`, ExpressionLanguage
    over `TemplateFunctions` - no PHP, so an administrator rewrites them with Claude through the
    connector), one seed per question and per student; a question is its seed and its template
    **version**, never its statement, which is how a teacher replays « la question vue ». The game
    never receives an answer before it is given, and the final test no correction before its end.
    A template version is stored as **text** (`SeriousGameTemplate::$definition`): MySQL reorders
    a JSON column's keys, and `params` are drawn in the order written.
  - **Templates are versioned, never rewritten once validated**; a draft serves nobody until its
    bench is green and it is validated (`SeriousGameTemplates`, the one door of the screen and the
    connector). `app:serious-games:sync` installs the shipped ones; `TemplateBench` is what makes a
    version validatable (CI runs 200 seeds per template, `ObservatoireTemplatesTest`,
    `PompeiTemplatesTest`, `AbysseTemplatesTest`, `IrregularsTemplatesTest`).
  - **A game is its manifest** (`config/serious_games/{slug}/game.yaml`, read by
    `SeriousGameManifest`): chapters and units, series, final test, comets, grades, rewards - and
    its own **`words`**, which Formatool's screens use for it (`serious_game_words()`: a star is
    « allumée », a tablet « tracée »; a comet is a « lézarde » in Pompéi). Never write a game's
    vocabulary into a translation key.
  - **Four kinds of answer**: `short` (typed), `choice` (a QCM), `order` (items to put in order)
    and `spot` (the wrong word of a line, or « correct »), from the answer type of each variant
    (`AnswerType::kind()`); a game accepts the kinds an administrator ticks. A question of a
    refused kind is never planned (`QuestionEngine`), a unit with no servable variant is hidden. A
    QCM's propositions are the answer and its typical wrong answers, shuffled by the seed - a
    proposition that is also right (an ambiguous Latin form) must be excluded by its `when`. Items
    to put in order reach the game shuffled, never in the answer's order nor a wrong answer's.
  - **A series may climb** (`series.tiers` in the manifest, Station Abysse's 200 / 1 000 / 3 000 m):
    so many questions of difficulty 1, then 2, then 3, a missing tier borrowing the nearest
    difficulty (`QuestionEngine::seriesPlan()`).
  - **The Latin is a grammar, not a word list**: `App\SeriousGame\Latin\LatinMorphology` declines
    nouns, adjectives and pronouns from their dictionary entry (the templates hold the lexicon);
    the game's Bibliothèque (`public/serious-games/pompei/library.js`) is a dump of it, and
    `PompeiLibraryTest` fails when the two drift apart.
  - **Nothing depends on time or absence** (`AcquisitionRules`): a unit lights at 6/12, its
    brightness is its best series and never goes down; a comet is an error made twice among the
    last 30 answers; only the final test is timed (extra time from `AccommodationResolver`).
  - The game is a static package (`public/serious-games/{slug}/`) in an `<iframe sandbox="allow-scripts">`
    - an opaque origin - talking to its host page by `postMessage`
    (`serious_game_host_controller.js` relays to `GameApiController`, and gives the frame its
    address only once it listens). The host bar is the platform's, for every game: a game **opens
    full screen** (`sg_launch_controller.js` asks the browser on the « Jouer » click, the host
    covers the window otherwise) with a toggle, and shows **the time left** whenever a session
    carries a deadline, read against `serverTime`. The avatar and the reward
    drawings are one JavaScript module each, shared by the platform and the games
    (`public/serious-games/shared/`); students only collect. **The avatar is drawn on « Mon profil »**
    (see « Mon avatar »), never in the module: a game always draws the person's drawing, whatever
    they show elsewhere, and the default one for whoever never drew one - the host bar, the
    catalogue and the player page then offer « Créer mon avatar », a link to the profile. Wearing a
    pin from the player page changes the drawing, never the choice.
  - **Two mechanics** (`SeriousGame::$mechanic`, the manifest's `mechanic`): the four games above are
    **series** of generated questions; Neko-Con is a **scenario** (`src/SeriousGame/Scenario/`,
    `SeriousGameScenarioPlay`, `ScenarioApiController`) - 39 hand-written tickets in
    `config/serious_games/neko-con/tickets/*.yaml`, played once each, opened by a graph onto a
    Kanban board of four. A ticket is a unit (`SeriousGameUnitState::$status`, `$stepId`, `$hints`…),
    each opening a session of mode `ticket`. **The right answers live in those YAML files, never in
    the game's package**: the server corrects explanations, guided questions and the password; the
    browser alone measures a repair and sends `{passed, failed}` - **the code delivered is never
    kept**, only the failed criteria (`ScenarioRules::grade()`). Stars: 3 with neither error nor
    help, an error made during Inès' help not counted. The sync checks a scenario whole
    (`ScenarioValidator`). **A step's options reach the game shuffled** (`ScenarioRules::optionOrder()`,
    seeded by the session and the step): the game answers a position, the server maps it back to
    the written rank, which is what it corrects and stores - tests answer through
    `SeriousGameTestTrait::shown()`. The rendering of each ticket is checked by « Banc des tickets »
    (Paramètres › Serious games, the game's own `bench.html` in a sandboxed frame, fed the scenario
    by `sg_ticket_bench_controller.js`). The teachers read it through `SeriousGameScenarioReading`
    (class map with « Bloqués » / « Beaucoup d'aide », « Notions à reprendre », the student's sheet).
    **Black-out** is the second scenario (36 tickets in a GLPI-like helpdesk, Nadia as the helper -
    `scenario.helper`): its repairs are checked by a network simulator in the package (`net.js`,
    Debian configuration files), it has no password, and it added the **« order » step** (the
    report's timeline, corrected by the server, the right order never sent) and the « line » step
    outside the help, on a `view` of the game (a log, a capture). The host page relays an order's
    ranks (`serious_game_host_controller.js`, `stepInput()`) - a new kind of input must be added
    there too, or it reaches the server mangled.
    **Radio-Comète** is the fourth (40 steps in five acts, the fader rack of a radio's desk, Colette as the
    helper) and adds nothing to the server either: a script is a `fix` step, written in the game's editor
    and tested in a **Bash interpreter written for the game** (`public/serious-games/radio-comete/`
    `shell.js`, `bashparse.js`, `builtins.js`, `utils*.js`, `sed.js`, `awk.js` over a virtual file system
    with mount points of fixed capacity, thirteen machines in `world.js`), then replayed on **hidden
    machines** (3 to 6 per script, each a trap: names with spaces, a full disk, a muted host, a held lock…)
    against the reference script the bench keeps; only `{passed, failed}` leaves the browser. The reference
    scripts stay in the package for the bench (like Black-out's repairs). The source of the game, its
    engine tests (98) and its bench live in the Cours repository, `Games/Radio-Comete/`.
    **Fusion** is the fifth (40 steps in five acts, the architect's model of two merging agencies, Hélène as
    the helper) and adds nothing to the server either: its `fix` steps are **cutover plans** built in the
    game's plan editor (a window, operations before or during the night, a check and a rollback per
    operation, a point of no return) and played by a **night simulator** (`public/serious-games/fusion/sim.js`)
    on a visible night and 3 to 6 **hidden nights**, each an incident; or configuration forms checked by hidden
    cases. Only `{passed, failed}` leaves the browser; the reference plans stay in the package for the bench,
    which also demands that every hidden night catch a mistake the visible night does not show. The source,
    its bench and its playthrough live in the Cours repository, `Games/Fusion/`.
    **Service de nuit** is the sixth (17 nights in five acts, a small hotel's PHP site, Sami as the helper,
    one night open at a time) and adds nothing to the server either, but it is the one where **the student
    keeps their code**: the site is one, from the first night to the last, and it travels as the game's
    **display state** (`saveProgress`, within the scenario's 256 KB) - never as an answer, which stays
    `{passed, failed}` (the user's decision of 2026-11-03). Its `fix` steps serve that site to visitors (a
    time, a day, a registre of variables, a `$_GET`) with a **PHP interpreter written for the game**
    (`public/serious-games/service-de-nuit/php.js`, checked against real PHP 8.5 in the Cours repository);
    « Essayer » plays the visible ones inside the frame and costs nothing, « Servir la nuit » adds the hidden
    ones and is the delivery. A page that shows a PHP warning is badly served. The sign's letters (one per
    notion) and Augustin's notebooks live in the game alone. The source, its bench, the cross-check with
    real PHP and the playthrough live in the Cours repository, `Games/Service-de-nuit/`.
    **Rétro Rush** is the third (45 issues in a GitLab-like forge, Jade as the helper) and adds
    nothing to the server: its repairs are SQL, run in the frame by **sql.js** (SQLite in
    WebAssembly, `vendor/`, served with the fonts' CORS header) behind a MySQL layer
    (`sqlcompat.js`: MySQL functions, the ONLY_FULL_GROUP_BY refusal, a bounded translator of
    MySQL triggers - what it cannot translate is refused, never guessed), each deliverable replayed
    on a fresh visible base **and a hidden one** (`base.js`, generated) against its reference
    answer; the game half and the bench share `benchcore.js`.
  - **`/serious-games/stats`** (`StatsController`, `App\SeriousGame\Stats\`): usage, progression,
    analysis of the units, a class's list and the years, for every game - read by **every teacher**
    and the administrators with the names (`SeriousGameVoter::READ_STATS`, the user's decision of
    2026-10-29), students only counted. What each student gets wrong stays the student's sheet.
  - **What keeps a player in the game is one shared module too** (`shared/engage.js` +
    `engage.css`, spec §1.17): the gauge of a series in progress (levels 6/8/10/12, the record,
    « encore N justes pour… »), the « N d'affilée » streak, « Encore une » on Entrée at the end of a
    series with the unit nearest its next level beside it, synthesised sounds (Web Audio, one
    voice per game, **on by default**, muted from the game's bar into `data.mute`) and the
    bestiary of elucidated comets (`getProgress()`'s `bestiary`, a reading of progress, not a
    reward - adults have it too). **Nothing of it runs during the final test before its end**:
    no gauge, no streak, no sound - each would tell an answer was right. Each game also tells a
    story (`{slug}/story.js`, `SGEngage.story`, spec §1.18): one page per unit, opened by the
    *count* of units lit so it reads in order, and a last page only after the final test is played
    on a complete map.
- **Bibliothèque de fichiers et partages** — `FileLibraryNode`, one table for folders and files.
  The library is **personal**: `owner` is the access model, not a scope to widen later
  (`FileLibraryVoter`). `path` holds the ancestors' ids (`/12/48/`) so a subtree is one `LIKE`.
  Sharing to a class, sharing a whole folder, and the content-sharing screens all hang off the same
  node — no form on the platform carries bytes any more, uploads go through `FilePickerType` and
  `/uploads/stage`.
- **Wiki** — `Wiki`/`WikiNode`/`WikiRevision`/`WikiAttachment`. Two kinds, and the difference is
  structural: a **Personal** wiki is one per owner (a UNIQUE index says so) and refuses members and
  programs at the entity level; a **Shared** one takes members and/or whole classes and is never
  scoped to a Program, so a cross-class wiki is legal. Sharing therefore always means creating a
  Shared wiki, never widening a personal one. Mermaid diagrams are edited through an « object » and
  stored self-contained — a `<style>` never survives the sanitizer.
- **Base documentaire** — `DocumentationArticle`/`DocumentationTag`. `App\Service\DocumentationAccess`
  is the single answer to "may this person read this article": three ANDed conditions — published and
  inside its diffusion window, naming one of the reader's audiences, **and** posted on a perimeter
  group among the reader's own groups or their ancestors (`DocumentationPerimeter` expands that).
  Staff and admin skip all three. A tutor carries no perimeter group, so naming « Tuteurs » in the
  visibility changes nothing until the annuaire gives them one — deliberate, not an oversight.
- **Infrastructure et machines virtuelles** — the SISR side of the platform, and the only area that
  drives hardware. `ProxmoxHost`/`ProxmoxOperation` talk to Proxmox VE; `VmBatch`/`VmBatchItem`
  deploy one machine per student of a class, `GuestAccount` is the account created on each; the web
  terminal is `ConsoleSession`/`ConsoleBroadcast`/`ConsoleSnippet` over SSH (**not** Proxmox's PTY).
  Two rules the code depends on: **one pass does exactly one step** (`app:vm-batch:advance`, scheduled
  every minute, is what makes a deployment survive a closed browser tab), and the application never
  destroys a machine — an expired batch reminds, an administrator deletes in Proxmox. A batch is
  held by **one pass at a time** (`VmBatchExecutor::run()`, a lock shared by the screen and the
  scheduler), and an account is created inside the machine under `flock`, only if still missing:
  two concurrent `useradd` of one login both exit 0 and leave the account under a UID its home
  directory does not belong to (`GuestAccountSyncer::createAccountCommand()`).
- **Matériel (Gestion > Matériel)** - the small-equipment inventory, `App\Controller\Equipment\*`.
  **Not the UFA laptops**, which keep their own inventory. `EquipmentType` is followed either
  *à l'unité* (each `EquipmentItem` has a label code `CA-0142-0`: one sequence shared by every type,
  never reused, Luhn check digit - `App\Service\Equipment\EquipmentCode`; the labeller is a keyboard
  Dymo, so the code is typed by hand) or *en quantité*. `EquipmentMovement` is an **append-only**
  journal; the type's counters are **stored** and move only through `EquipmentLedger`'s atomic
  `UPDATE … + delta` - never through the entity - and `EquipmentMovementKind::delta()` is the single
  rule both the live update and the recomputation read. Kept by admin, staff, staff-lead and
  support-tech; nothing is ever imputed to a class or a student, a room is optional. An incident is
  **Disparu** or **Hors d'usage** plus a cause, and leaves the count it came from; « Retrouvé » /
  « Réparé » points at the incident it answers (`resolves`), so the annual report
  (`EquipmentLossReport`, per `SchoolYear`) takes the loss back out of the year it was declared in.
  « À commander » (`EquipmentReorderPlanner`) counts what is on order as coming; a count
  (`EquipmentStocktake`, closed by `EquipmentStocktakeCloser`) writes its differences as
  *inventory-gap* lines, which the report keeps apart from declared losses.
- **Agenda, Annonces, Listes d'inscription** — `AgendaEvent`, `Announcement`, `SignupList`; the
  first two resolve who they are for through `AudienceResolver` like `MessageThread` does.
- **Connecteur Claude (MCP)** — `/mcp`, the platform as a remote MCP server a teacher adds to
  their **own** claude.ai (the intelligence is paid by their subscription; a built-in chatbot would
  need API credits nobody pays — the « Beaupie » handoff is on hold for that reason). Two halves:
  - **OAuth 2.1, hand-rolled and minimal** (`src/OAuth/`, `App\Controller\OAuth\*`): discovery
    (RFC 8414/9728), dynamic client registration (open to the internet by spec, rate-limited, redirect
    URIs from a closed list: claude.ai/claude.com callbacks and the loopback), a consent screen, PKCE
    S256 only. **The consent screen never takes the establishment password**: it is public and signs
    the person in with the password they chose for that service in « Mon profil »
    (`App\Security\ExternalServicePasswords`, `ExternalServicePassword`, one per `ExternalService`).
    That password is hashed (`external_service` hasher), strong (`App\Validator\StrongPassword`,
    shared with the account password change) and **must differ from the directory password** —
    checked by binding with it (`PlatformPasswordCheck`, a directory that cannot be asked means
    « refuse »), both when chosen and at every sign-in. No service password, no authorisation;
    changing or removing it revokes every grant. `OAuthGrant` is the « connection » the profile card lists and
    revokes; codes and tokens are selector + hashed verifier like `JobboardToken`, the refresh token
    **rotates**, and replaying a used code or refresh token revokes the whole grant.
  - **MCP, stateless** (`src/Mcp/`, `App\Controller\McpController`): JSON-RPC over POST, one message
    per request, no `Mcp-Session-Id`, no SSE — a held stream would pin one of production's 8 workers.
    Its own `mcp` firewall turns the access token into a **real authenticated user**
    (`McpAccessTokenAuthenticator`, the `CalendarTokenAuthenticator` idea), so voters and
    `FeatureAccess` answer unchanged; a call without token gets 401 + `WWW-Authenticate:
    resource_metadata=…`, which is how claude.ai discovers where to sign in.
  A tool is one `App\Mcp\McpTool` service (autoconfigured tag), **thin**: it reads arguments, asks the
  same voters and calls the same writers as the screens (`QuizTemplateImportWriter`,
  `SequenceImportWriter`, `FileLibraryWriter`, `EvaluationRubricBuilder`…), and is listed only when
  its features are lit. Texts sent to the model (tool descriptions, `McpInstructions`, prompts) are
  **French prompt text**, like the prompt catalogues. Rules the tools hold: nothing is deleted; a
  document is refused whole at the first invalid item (Claude corrects and resends); no image
  reference crosses (`mediaRef`/`imageKey`); an evaluation it creates is visible to students only
  at a **future** date (D+1 by default) - and `evaluation_update`, which names its fields and reads
  them all before writing one, moves that date only to another future moment
  (`McpGradebook::futureVisibility()`): « visible now » stays a gesture of the screen; a barème
  with points entered is never rebuilt; **students'
  names and marks cross through two tools only**, `grades_get` and `grades_set` (the user's decision
  of 2026-10-09 - every other tool still exposes neither). `grades_get` is the entry screen read:
  the class in surname order, the barème's questions, what was entered, behind
  `EvaluationVoter::READ_GRADES` (titulaires, the class's referents, staff - never a student, which
  `VIEW` lets through). `grades_set` passes `MANAGE` and writes through `App\Service\GradeEntryWriter`,
  the screen's own door: `answers` per question when the evaluation has a barème (the total is
  computed), one `grade` per student when it has none. `App\Mcp\McpGradeSheet` adds what a whole
  class sent at once needs: checked whole before anything is written, nothing erased (an empty value
  is refused), a mark already there rewritten only with `replace`, and a grade above the scale
  refused where the grid clamps it. The emploi du temps (`timetable_get`) hands out the `sessionId` the
  cahier de texte tools take: `lesson_log_write` passes `LessonLogVoter::EDIT` (the créneau's own
  teacher or co-animator, never staff by role), takes Markdown through the library's renderer and
  sanitizer, **never overwrites a part that says something unless `replace`**, and moves a part's
  visibility only when named — a cahier opened there starts hidden like on screen.
  `lesson_log_attach` files a document under one part, through the same `EDIT`: a library file as a
  **reference** (`UploadIntake::store()`, like `file_link`) or an http(s) link. It follows its part's
  visibility, and the same document filed twice under one part answers the row already there.
  **Files reach the platform, the platform never fetches them**: `file_upload` takes a small
  file as base64; `file_upload_url` hands out an address (`/mcp/uploads/{secret}`,
  `App\Entity\McpUploadSlot`) that Claude's sandbox sends **one** file to with
  `curl -X PUT -H "Content-Type: application/octet-stream" --data-binary` - Caddy forces that type on the path anyway (`@mcpUpload`): curl's default form type made `Request::createFromGlobals()` decode the file as form fields and die on `max_input_vars`. The address is bound beforehand to the teacher, the connection, the
  folder, the name, the exact size and an optional SHA-256; it lives 15 minutes, serves once
  (spent by an atomic UPDATE, a refused send spends it too) and dies with its connection. Its own
  `mcp_upload` firewall turns the secret into the teacher (`McpUploadSlotAuthenticator`, the
  `calendar` shape), and the body - streamed to disk, never a string - goes through
  `FileLibraryWriter::writeFile()`: type, quota, antivirus like any upload. Every answer is JSON,
  refusals included: curl's output is all the model reads.
  `quiz_import_kahoot` turns Kahoot game reports deposited in the library (an .xlsx, or a .zip of
  them) into quizzes, one per report: the whole batch is read first, `dryRun` says what would be
  created, a game exported twice or a quiz of the same name already in the folder is skipped.
  `library_move` files quizzes, files and whole folders elsewhere in the quiz or file library -
  a move through each library's own manager, never a copy, so ids and links survive; the call is
  checked whole before anything moves, then each move is flushed in turn inside one transaction
  (a folder's subtree and its siblings' names are read from the database).
  `progression_get` is **read-only**: Claude suggests a progression, the teacher builds it on the
  progression screens (`McpTimetable` holds the shared doors). `format_guide` is assembled from the import assistants' own catalogues,
  so the screen's prompt and the connector's guide cannot drift. `Feature::ClaudeConnector` is off
  for every role; an admin tries it first. `DocumentTextExtractor` reads PDF (poppler's `pdftotext`,
  in the image since this lot), Word, PowerPoint, OpenDocument, Excel and HTML;
  `moncampus-bareme/1` (`EvaluationRubricJsonImporter`) is the barème's document format, read strictly.
- **École Directe (Outils)** — `src/EcoleDirecte/`, `App\Controller\EcoleDirecte\*`. **ROLE_ADMIN only for
  now** (`EcoleDirecteControllerTrait::administrator()`, the menu entry, `RoleAccessSmokeTest`). A
  teacher signs in to their **own** École Directe account from the platform's server (Aplim publishes
  no API: this is the private one its website calls, teacher routes on `apip.ecoledirecte.com`), reads
  it, and sends their cahier de texte and an evaluation's grades there. Rules the code holds:
  - **The identifiant and the password are never kept.** They are arguments of `EcoleDirecteClient`'s
    login methods, sent again by the browser when the identity question replays the login, and nothing
    else. What spans requests is the handshake, sealed by `EcoleDirecteSessionSealer` (key derived from
    APP_SECRET, 10 min sliding, bound to the user) and held in the page's JS memory only. The client is
    **stateless** (worker mode).
  - **Two doors on the client.** `read()` forces `verbe=get`; `send()` knows `put` and `post` and throws
    on anything else. Nothing is ever deleted from École Directe.
  - **Every call is a button**, and every send is a preview first; the send reads École Directe again
    before writing. Cahier de texte: séance and slot are matched on day + start time, the whole slot
    goes back with `verbe=put` and only its base64 `contenu` changed (`EcoleDirecteLessonLogPlanner`
    says which part lands where). Grades: the evaluation is found by name + date or created, then the
    grid's students are posted; a student is matched by the link remembered for them
    (`EcoleDirecteStudentLink`, one per student establishment-wide, set by hand from the preview,
    never guessed), else by exact name, and statuses are written `abs` / `ne` / `(12)` -
    `EcoleDirecteGradePlanner::noteFor()` is the one place that mapping lives. « Ramener sur 20 »
    and the coefficient are chosen per send (`EcoleDirecteGradeOptions`); the coefficient only
    shapes an evaluation the send *creates* - an existing one keeps its own, the preview says so.
  `Feature::EcoleDirecte` is off for every role. École Directe changes its protocol without notice -
  an answer the client does not recognise is logged at error level and refused, never guessed at.
- **Portfolio E5 / E6** (BTS SIO) — `src/Service/Portfolio/`, `App\Controller\Portfolio\*`, spec
  `design/validated/portfolio.md`. **`Feature::Portfolio` is off for every role: administrators
  only, until Gestion › Fonctionnalités opens it.** A `Portfolio` follows the **student**, not the
  class (UNIQUE student + `Referential`, across SIO 1 and SIO 2); a `Program` only *enables* it and
  names the référentiel, its cursus year, session and deadlines. The `Referential` is national and
  separate from the livret's `SkillGroup`/`Skill`, imported from France compétences (`src/Service/Rncp/`,
  `app:rncp:fetch`) - « France compétences proposes, the administrator decides » the option letters
  and each block's role (E5 synthesis / E6 fiches / none). **Only a `PortfolioValidator` decides**:
  designated by an admin per class **and** option, still teaching in the class - no admin, referent
  or other teacher bypass (`App\Service\Portfolio\PortfolioValidators`, the one rule the voter,
  the queue, the menu and the home card read). A validation covers one `revision`: a validated
  réalisation that changes, evidence included, goes back to « À valider », its E6 fiche with it.
  The E5 table is **computed** (`PortfolioSynthesis`, the only reading for screen, class view,
  .xlsx, PDF and deposit): only a retained claim on a validated réalisation ticks. The official
  .xlsx is **uploaded per session** (`ReferentialTemplate`), inspected by landmarks
  (`E5TemplateInspector`) and filled by `E5SynthesisXlsxWriter` (ZipArchive + DOM, rows added when a
  part overflows) - never shipped in the code, never another year's. Deposits are frozen snapshots;
  deadlines lock nothing. The commission has no access at all. Students have no file library, so
  evidence is an upload, a link or a piece of work handed in.
- **Cours en ligne** (Outils › Préparer du contenu) — `App\Controller\OnlineCourse\*`,
  `src/Service/OnlineCourse/`, spec `design/validated/cours-en-ligne.md`. **`Feature::OnlineCourses`
  is off for every role: administrators only, until Gestion › Fonctionnalités opens it.** A teacher
  puts courses online on **their own public page**, `/courses/{handle}`, read **without an account**
  (`^/courses/` is `PUBLIC_ACCESS`; the public controllers carry no `#[RequiresFeature]` and extend
  `layout/public.html.twig`, not the app shell). **There is no catalogue across teachers** - it was
  proposed and refused; `/courses` alone matches no route and nothing lists the pages. Named
  `OnlineCourse` because « Mes cours » already means the course space of a class. Rules the code holds:
  - **A course is its author's alone**, administrators included (`OnlineCourseVoter`, 404 never 403);
    the one thing an administrator holds on somebody else's is UNPUBLISH. It is born a **draft**
    whoever creates it; publishing asks for a title, a summary, a material and a page with an
    address (`OnlineCourseWriter::publishRefusals()` names all that is missing); a published course
    is taken offline before it is deleted.
  - **A teacher's page is theirs, not the establishment's**: their banner (`OnlineCoursePage`'s
    title, colours, height in px and optional picture - `OnlineCourseImageStore` - drawn by
    `online_course/_page_banner.html.twig` for the page and for the « Ma page » preview alike)
    replaces the shell's bar; no emblem, no teacher name, no footer - and so on a course's page and
    its test screens too (`online_course/public/_course_layout.html.twig`), where the banner's title
    leads back to the teacher's page (`_page_header.html.twig`, one copy for all). With no footer
    left on these screens, the source-code link (AGPL §13) is in « À propos ». The courses come in rows -
    « Récemment mis à jour », then one per tag in alphabetical order, its courses by title - each
    cut to one line by `online_course_row_controller.js`; « Voir tout » is `?tag=` / `?view=recent`
    on the same address, and `?support=` (a material kind or `test`) narrows rows and lists alike. A signed-in visitor gets the app's own user menu (`layout/_user_menu.html.twig`,
    shared with the app shell, which is why it takes `compact` and `impersonation`).
  - **The page's address changes at any time**, during diffusion included: every address a page
    carried keeps its row (`OnlineCoursePageHandle`, UNIQUE), so the old one answers a 301 and is
    never given to another teacher (`OnlineCoursePageHandles`). It is never the login. A course's own
    slug, by contrast, is frozen by its first publication (`OnlineCourse::$publishedAt`, never cleared).
  - **Every material is served by the CDN** (`OnlineCourseContentOrigin`), never by the application.
    A material's files are **revisions**, each in a folder of its own -
    `online-courses/{course}/{token}/{segment}/r{n}/` - so replacing changes the address of the
    bytes and never the address of the material; the revision before the live one is kept (« Revenir
    à la révision »), older ones go to the deferred purge. A file picked from the bibliothèque is
    **copied** here, unlike everywhere else where a link is a reference (`OnlineCourseMaterialStore`).
  - **A course may carry a test quiz** (`OnlineCourse::$quizTemplate`, spec §18): one quiz of the
    author's own library with a question (`OnlineCourseWriter::linkQuiz()`, the screen's picker and
    the connector's `quizId` alike), offered as « Test » on the public card. Taken at
    `/courses/{handle}/{slug}/test` by whoever may read the course, **without an account too**, as
    often as wanted: every question, questions and answers shuffled at each go, and **at the end the
    success rate alone** - never which question was right, never an answer, so that a low score
    sends the reader back to the course rather than to the answers. « Bravo » from the author's
    threshold (`OnlineCourse::$testPassPercent`, 80 % by default, `testPassPercent` on the
    connector), « Revoir le cours » below. **Recorded nowhere**: the run lives in the reader's
    session (`OnlineCourseTestRunner`). The card is no longer an `<a>`: its title's link is
    stretched over it, so « Test » can be a link of its own.
  - **A course has a picture** (`OnlineCourse::$imageKey`, `OnlineCourseImageStore`): JPEG, PNG or
    WebP, 5 Mo, under `online-courses/{course}/{token}/image/` with a new random name at each
    change (no stale CDN copy), a library file copied like a material. Shown on the public card,
    in « Mes cours » and as `og:image`; the connector sets it by `imageFileId` only.
  - `OnlineCourseMaterialKind` is the catalogue of natures (what each accepts, its label, its
    player); `OnlineCourseTag` is **per author** (UNIQUE owner + normalized label), created by typing.
  - **An interactive course is a `.zip` holding an `index.html`**, unpacked file by file under the
    revision's folder by `OnlineCourseBundlePublisher` - **the only door HTML and JavaScript enter the
    bucket by** (`html`/`js` stay « archive only » in `UploadPolicy` everywhere else).
    `OnlineCourseBundleReader` decides first, writing nothing: a closed list of web file types, each
    served under the type that list gives it; one file outside it, a path that leaves the folder or
    a missing index refuses the **whole** archive and names the file. The macOS litter and one
    wrapping folder are tolerated.
  - **The isolation of a course's JavaScript is the origin**: it runs on the CDN's host, in a
    sandboxed frame with no `allow-top-navigation`, and reads neither the session nor the page.
    `OnlineCourseContentOrigin::isIsolatedFrom()` is asked before a frame is drawn - a content host
    equal to the application's draws no frame and logs an error.
  - **A dynamic material opens full page although the CDN serves it** (the user called it crucial):
    `/courses/{handle}/{slug}/{material}/play` is nothing but the frame, 100 % of the window
    (`templates/online_course/public/play.html.twig`). Its floating bar folds into a handle rather
    than « reappearing when the mouse moves »: over a frame from another origin the page hears no
    mouse at all (`online_course_player_controller.js`).
  - **The Claude connector manages courses and their materials** (`course_list`, `course_get`,
    `course_tag_list`, `course_create`, `course_update`, `course_material_add`,
    `course_material_replace`, `course_publish`, `course_unpublish`; `App\Mcp\McpOnlineCourses`).
    Thin, like every tool: the same Voter, the same `OnlineCourseWriter` / `OnlineCourseMaterialStore`
    as the screens. A course created by Claude is **always a draft** and publishing is a second call
    - « Claude peut publier » was the user's decision; an update names its fields and writes no
    other; a material takes exactly one source (`fileId` copied from the bibliothèque, `html` for an
    interactive course, `markdown` rendered as a PDF); nothing is deleted. `format_guide` has a
    `cours_interactif` entry (`InteractiveCourseGuide`, French prompt text).
  - **Learning paths** (`LearningPath*`, `src/Service/LearningPath/`, `App\Controller\LearningPath\*`):
    an ordered run of the author's own courses, with a **validation quiz wherever the author puts
    one - never required**. Composing one is `online_courses` (Cours en ligne › Mes parcours);
    *following* one is `Feature::LearningPaths`, also off for every role. **Nothing of a path is read
    without an account**: its routes are `/paths/…`, outside the `^/courses/` opening, and it has no
    public address. The rule is `LearningPathRule`, a pure function: a step is open when every quiz
    before it is validated (best score ≥ threshold, *read*, never stored); a step once opened stays
    open; an unavailable step (course offline, quiz deleted) is skipped. `LearningPathBoard` loads the
    facts once per screen and is read by the follower's plan and the author's follow-up alike; every
    step's door asks it again. A course can be **« Réservé aux parcours »** (`OnlineCourseStatus::PathOnly`):
    off the author's page, read only from an opened step - its CDN files stay reachable by whoever
    has their address, which the spec accepts. The quiz is a library `QuizTemplate`, attempted in
    `LearningPathQuizAttempt` (questions frozen, unlimited attempts, new draw each time, correction at
    the end), graded by `VideoCueGrader`/`QuizAnswerChecker` and drawn with
    `templates/quiz/_question_take_body.html.twig`, the partial the video markers share - never a
    `QuizInstance`, which belongs to a class. The **follow-up is read by the path's author alone,
    administrators not included** (`LearningPathVoter::TRACK`), is announced to the follower, is not
    exposed by the connector, and is purged 24 months after the last activity
    (`app:purge-platform-activity`). Connector tools `path_list`, `path_get`, `path_create`,
    `path_set_steps` (a whole list, kept steps kept, refused if it would drop a step people worked
    on) and `path_publish`.
- **Accès aux fonctionnalités** — `App\Enum\Feature` (62 cases) + `#[RequiresFeature]` +
  `App\Security\FeatureAccess`: which features are lit, per role and per formation. Gestion >
  Fonctionnalités is the screen. **The whole Pédagogie family is off by default**, with four
  exceptions named in `Feature::defaultRoles()` (`student_work`, `shared_documents`, `wiki` for
  students, `class_tools` for teachers) — so a screen answering 404 in dev is far more often an
  unlit feature than a bug. `ROLE_ECO`, `ROLE_SUPPORT-TECH` and `ROLE_EXTERNAL` are delivered
  nothing unless a feature names them. See the cross-cutting blocks below — a new controller needs
  a `#[RequiresFeature]`.
- **UFA** (apprenticeship unit) — `Internship*` entities: alternance periods, the 4-role signature
  wizard of the Livret Alternant, tutor links, evaluations, reminders, plus laptop loans
  (`Laptop`/`LaptopLoan`) and the UFA configuration screens. An employer's SIRET is **proposed by
  the État and confirmed by a person, never associated automatically** (`src/Service/Sirene/`,
  `EnterpriseSiretController`, spec `design/validated/siret-entreprises.md`): only « Associer »,
  « Confirmer » or saving a changed number in « Modifier l'entreprise » stamps
  `Enterprise::$siretConfirmedAt`; imports and « nouvelle entreprise » leave it « à confirmer », and
  `EnterpriseRepository::queryPendingSiret()` is the one definition the queue and its counter read.
  **The teachers of a formation in alternance read its booklets, and only read them**: Section >
  Formation > « Livrets d'alternance » (`App\Controller\Program\TeacherBookletController`) lists
  the class's live alternances and opens the shared reader, with no export and no way into the
  wizard. `App\Security\ProgramInternshipBookletAccess` is the one rule the menu entry and the
  screens ask: the `ufa_booklet` feature, `Program::$internshipManagementEnabled`, a formation
  carrying the alternance modality, and a teacher of that very formation (staff bypassed).
  The **livret ECF** (livret d'évaluations passées en cours de formation, `App\Controller\Ufa\Ecf*`,
  `src/Service/Ecf/`, spec `design/validated/ecf-booklet.md`) is switched on per formation
  (`ProgramEcfSettings`: the switch, the organisme and the lieu, kept in UFA › Formations ›
  « Dénomination » - there is no ECF tab; `App\Service\Ecf\EcfSettingsEditor`) and lives on the alternance follow-up:
  read by the administration, written and signed by administrators only (`EcfBookletVoter`). Its
  activity-types are the Livret de l'alternant's groups, options included
  (`BookletSkillGroups::forTutorLink()`), found again from one year to the next by
  `SkillGroup::$code` - a group with no code stays out; the booklet follows the student and the
  titre (code + millésime), not the Program. **The titre is never typed in the ECF tab**: it is read
  per student from « Dénomination » (`EcfTitle`) - the denomination, the level, code titre,
  millésime **and the dates** (arrêté, J.O., date d'effet, template update) of their option's
  `ProgramCertification`, the option's short name as sigle: two options prepare two titres. A visa is the connected person's click, dated by the server, and freezes what it signed.
  Once closed, the administration offers it to the student, who reads it and signs it « pour
  information » from « Mon alternance » (`EcfCandidateSignature`); reopening the booklet withdraws both.
  The PDF (`templates/ufa/ecf/print.html.twig`, also the online reader's frame) reproduces the
  ministry's template page for page - lengths in points measured on it; an element wider than the
  page makes Chromium scale the whole document down, so nothing may overflow.
- **Dossiers intervenants** (UFA › INTERVENANTS; « Mes dossiers administratifs » in the profile
  menu) — `TeacherDossier` → `TeacherDossierGroup` / `TeacherDossierDocument` →
  `TeacherDossierSubmission` → `TeacherDossierReview`, `App\Controller\Ufa\TeacherDossier*`,
  `src/Service/TeacherDossier/`, spec `design/validated/dossiers-intervenants.md`. The pieces the
  administration asks of **one** teacher (identity card, RIB, attestations…), each one deposited
  or validated. **`Feature::TeacherDossiers` is off for every role: administrators only, until
  Gestion › Fonctionnalités opens it** - for staff, staff-lead **and** teacher, the intervenant's
  own screens sitting behind the same switch. Rules the code holds:
  - **The dossiers documentaires' idea, deliberately not their tables.** The access rule is the
    opposite - here the administration enters by its role, there nobody does - and the files are
    somebody's papers. What the two share is the rule: `DossierStatusResolver` reads a piece and its
    dépôts through three interfaces (`RequestedPiece`, `DepositVersion`, `DepositReview`) both
    families implement, plus the enums, the `templates/dossier/_*` partials and the `cm-dd-*` kit.
    A teacher's piece is always visible and its date limite closes nothing (« En retard », no more).
  - **A model is a dossier with no teacher** (`TeacherDossier::asTemplate()` / `forTeacher()`, the
    only two ways in): same table, same editor. « Depuis un modèle » **copies** it, for one teacher
    or several (`TeacherDossierComposer`); `$sourceTemplate` is kept for the record only, so
    changing or deleting a model touches no dossier. In Twig say `dossier.isTemplate` or
    `dossier.sourceTemplate`, never `dossier.template` - it resolves to whichever method comes first.
  - **`TeacherDossierVoter` is the whole « qui fait quoi »**: MANAGE and REVIEW for admin, staff and
    staff-lead (no validateur is named), VIEW and SUBMIT for the intervenant on their own dossier
    once published; **nobody reviews their own**; every other teacher gets a 404. The administration
    also deposits *for* the intervenant (`TeacherDossierSubmission::$submittedBy`).
  - **Draft → Published → Closed** (reopenable). Only a published dossier takes a dépôt or a review;
    a closed one stays readable on both sides and no longer lights the banner.
  - **`TeacherDossierSheets` is the one reading** (status per piece, avancement over the pieces
    *obligatoires*, points d'attention) for the list, the fiche, the intervenant's page and
    `TeacherDossierPending` - the home banner and the menu entry. **The banner counts what the
    intervenant has to do** (to deposit, late, to correct), never a piece waiting for the staff.
  - **A piece's bytes never get the CDN address.** `TeacherDossierFiles` writes under
    `teacher-dossiers/{dossier}/{32 hex}.{ext}`; `TeacherDossierFileController` is the only door:
    Voter, then a redirect to a short-lived signed URL, framed by an app screen. Never
    `file_url()` on one of these keys. PDF and pictures only (`UploadPolicy::administrativePapers()`),
    no library tab. Deleting a dossier hands its files to the deferred purge first.
  - A piece somebody deposited on is no longer deleted (made facultative instead). No mail, no
    notification - the banner is the only signal (user's decision). Not exposed to the connector
    nor to the mobile app.
- **Stage / recherche d'emploi** — `JobSearch`, `JobApplication`, `TrainingOffer`,
  `TrainingApplication` (postulation with free-form attachments). The job search names its own
  démarches: a démarche is never *made* an `Enterprise`, it may only *point at* one of the vivier
  (and at a SIRET) when the student kept it from « Trouver une entreprise » - see the next entry.
- **Trouver une entreprise et vivier d'entreprises** — `App\Controller\CompanySearch\*`,
  `src/Service/CompanySearch/`, `src/Service/EnterprisePool/`, spec `design/validated/vivier-entreprises.md`.
  **`Feature::CompanySearch` and `Feature::EnterprisePool` are off for every role: administrators
  only, until Gestion › Fonctionnalités opens them.** Two halves:
  - **the search** reads the État's register (`RechercheEntreprisesClient::browse()`) through
    `CompanySearchCriteria`, the one place filters become API parameters. Categories are **one list
    for the whole establishment** (`CompanySearchCategory`, kept by ROLE_ADMIN), sole traders are
    out unless a box says otherwise, and around a commune (`/near_point`) the API filters on activity
    only - size, type and sole traders are then checked page by page (`CompanySearchCriteria::keeps()`);
  - **the vivier** is `Enterprise`, no longer the UFA's alone: `EnterpriseHosting` (a **stage** or an
    **alternance**, never added together, never defaulted), `EnterpriseContact` (communicable to
    students only when ticked), `EnterpriseTeacherContact`, `EnterpriseNote` (the team's, **never
    read by a student**). The UFA's alternances are **read** from `InternshipTutorLink`, never
    copied: `EnterpriseHostings` is the one reading and drops a stored duplicate. A line of the
    register is recognised by **confirmed** SIRET only; choosing an establishment in the register
    confirms it (same rule as the SIRET queue), an import never does.
  `EnterpriseVoter` holds the whole « qui voit quoi »: the hostings and shared contacts to all, the
  team's part to teachers/staff once `enterprise_pool` is lit, the students' names, the two filters on
  stage/alternance and every write to ROLE_ADMIN. A student's « Garder » is a `JobApplication` with no
  mail yet (« à écrire », derived, never a status), carrying the SIRET; its `studentNote` is read by
  their teachers. « Marquer terminé » on 1a asks the outcome: « Stage trouvé » enters the vivier,
  « Alternance trouvée » does not (the UFA contract will).
- **Emails (UFA › Configuration)** — `App\Controller\Ufa\EmailCatalogController`,
  `src/Service/PlatformEmail/`. The mails the platform writes itself, **to read only**: the list on
  the left, the chosen one (subject + body) in the middle. `PlatformEmailCatalog` names each mail
  and the context its sender passes, with a placeholder (`$PERIODE`, `$ALTERNANT`…) wherever a
  value changes from one send to the next; `PlatformEmailPreviewer` renders it through **the
  sender's own template**, so the screen cannot show a text the mail does not carry. A template
  that sends two texts is two entries. `PlatformEmailCatalogTest` refuses a template of
  `templates/emails/` no entry names: a new mail is listed or the test fails. Courrier pro is
  absent on purpose - students write those.
- **Courrier pro** — student mailboxes: `EmailAlias`, `EmailMessage`, `EmailAttachment`,
  `EmailEvent`, suppressions. Inbound via SES→S3→SQS, outbound via SES.
- **Messagerie** — `MessageThread`/`Message`, audience-resolved. Present on web, **not** exposed in the
  mobile app (code kept, tab replaced by Quiz).
- **e-CO** — orienteering races: `EcoCourse`, `EcoParcours`, `EcoCheckpoint`, `EcoRunner`. Runners have
  no account at all; they authenticate by join token, checked manually in `EcoRunnerApiController`.
  **Maps are the IGN's** (web `assets/controllers/eco_map.js`, mobile `lib/widgets/eco_ign_map.dart`
  in the e-CO repo): Plan IGN / photos + contour lines / LiDAR relief, keyless WMTS. **What the IGN
  says about the ground is never read at display**: `app:eco:read-terrain` writes it -
  `EcoParcours::$terrainAnalysis` (a JSON snapshot stamped with `locationFingerprint()`, so a moved
  flag shows it stale), `EcoCheckpoint::$groundAltitude`/`$canopyHeight` (the tolerance advice,
  `App\Service\Eco\EcoToleranceAdvisor`) and per `EcoPositionPing` the terrain altitude and the
  path/wood flags. The statistics (`App\Service\Eco\EcoTerrainStats`) read those, and fall back to
  the phone's figures, labelled « GPS », until 90 % of a race's fixes are read. `src/Service/Ign/`
  holds the one client; `src/Service/Eco/` the terrain rules. A parcours is **shared** with
  colleagues holding ROLE_TEACHER **and** ROLE_ECO (`EcoParcours::$sharedWith`,
  `App\Service\Eco\EcoParcoursSharing`): same rights as its creator, `isManagedBy()` is the one
  rule. A « Balises spécifiques » course runs on Départ, Arrivée and the flags it names, in order or
  not: **`EcoCourse::getRaceCheckpoints()` is the only list a course is read through** (scan,
  ranking, maps, statistics, runner API), never the parcours' own - and the runner app is sent
  `runnerMode()` (imposed/free order), which it already understands. **Any flag may be renamed**
(screen 1e, `App\Service\Eco\EcoCheckpointRenamer`), Départ and Arrivée included: a flag's role
is its type and position, never its name - nothing may test a name. A course not yet closed has
an « Affiche QR » (`app_eco_course_join_pdf`): an A4 page whose QR opens `/eco-app/?code=…`, which
the PWA's join screen reads (a race under way on that phone still wins). The same Flutter app also
  runs **as a PWA at `/eco-app/`** (`public/eco-app/`, a build output of the e-CO repo's
  `tool/build_pwa.sh` - never edited here; its README says what the web build changes): same
  origin as the API, offline queue in IndexedDB, and its own service worker, which also sends that
  queue by background sync when the page is frozen. The Ressources page offers it next to the APK.
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
- **Installer beaup-app** — `/install` (`InstallController`, profile menu, every role, outside the
  feature catalogue): the site installable as a web app named **« beaup-app »**,
  design/validated/quiz-tournament.md §13. It replaced the Flutter web build at `/campus-app/`
  (retired, see the companion-repos paragraph): two PWAs only, beaup-app and e-CO.
  `public/manifest.webmanifest` (`id`/`scope` `/`, standalone) and the icons under
  `public/beaup-app/` sit **outside AssetMapper** - an installed app keeps asking the addresses it
  was installed from; `icon.svg`/`icon-maskable.svg` (a geometric B) are the sources, the PNGs are
  rendered from them (headless Chrome screenshot), and the mark is the project's own: never an
  Institution emblem. Caddy sends the manifest's type itself (`@webAppManifest`): the prod image
  has no `/etc/mime.types`. **No service worker, no cache, no notification**: Chromium 156 reports
  no installability error and fires `beforeinstallprompt` with none registered, so adding one would
  buy nothing. The offer is caught on every page by `assets/web_app/install_prompt.js` (imported by
  `app.js`, kept across Turbo visits) and used only by the screen's button; an SVG icon in the
  manifest made Chrome refuse installation, so it lists PNGs only. A page installable as something
  else replaces `base.html.twig`'s `web_app_meta` block whole (the e-CO GPS probe). The Ressources
  page of the mobile app links here instead of offering a web build.
- **Mon avatar** (« Mon profil », `App\Controller\ProfileAvatarController`, every account, outside
  the feature catalogue; design/validated/quiz-tournament.md §16) — what stands for a person wherever
  Formatool draws a face is **their explicit choice**, `User::$avatarChoice` (`AvatarChoice`: `photo` |
  `drawn`, null = initials), for every role alike. `App\Service\UserAvatars` is the one reading
  (`decide()` is the rule on primitives; `of()` / `forUsers()` batched, memoised per request,
  `ResetInterface`); templates ask `user_avatar(user)` and draw a square face with
  `partials/_user_face.html.twig`. **Choosing deletes nothing**: the photo and the drawing both stay
  and « Utiliser … » switches back; a choice with nothing behind it shows the initials and is refused
  by the server. Sending a photo (`app_profile_avatar`) chooses it; saving a drawing in the creation
  tunnel (`/profile/avatar/draw`, the serious games' editor moved here - `sg_avatar_editor_controller.js`
  over `public/serious-games/shared/avatar.js`) chooses it (`SeriousGameAvatars::draw()`). The
  drawing is still the `SeriousGameAvatar` row (name and table kept), with no feature condition; pins
  are offered to whoever holds one and a pin not obtained is refused - nothing is earned in the
  profile. `/serious-games/avatar` answers a 301 to the tunnel, for anybody.
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

More generally, business rules belong in `src/Service/`, not in the controller. The ratio has turned
over — ~52k lines of controllers against ~61k of services, where it was 29k against 16k on
2026-08-10 — so the rule is now being followed rather than aspired to. Individual controllers are
still fat; when you touch one, extract rather than extend.

### Cross-cutting building blocks

Prefer these over re-implementing:

- `App\Security\StructureAccessChecker` — the program-scoping authority: `isStaff()`,
  `isProgramVisible()` (enrolled student *or* teacher, staff bypass), `isProgramTeacher()` (stricter,
  students excluded), `isProgramReferentTeacher()` (deliberately **not** staff-bypassed),
  `matchesTestMode()`.
- `App\Security\ProgramTimetableAccess` — "may this person see this formation's timetable": the
  feature, `Program::$timetableManagementEnabled` **and** `Program::$timetableVisibility`, ANDed.
  A per-formation setting is always *cumulative* with the feature, never overridden by it — read
  it from this one place (Twig: `timetable_visible(program)`), and hand `visibleTiers()` to a
  query that has to pick a day before it knows what the reader may see.
- `App\Service\AudienceResolver` + the `AudienceTargetable` interface — the shared "who is this
  addressed to" rule for `MessageThread`, `Announcement`, `AgendaEvent`.
- `App\Entity\AuditableTrait` — created/updated/inactivated by whom and when; mixed into
  `AbstractStructureNode` and the standalone structure entities.
- `App\Entity\AbstractStructureNode` — shared base of Section/Track/Cohort/Option/Modality.
- `App\Service\FileUploadService` / `flysystem` `uploads.storage` — never write to the filesystem
  directly; uploads go to S3, URLs are built by the `file_url` Twig function.
- `App\Attribute\RequiresFeature` + `App\Security\FeatureAccess` — "this screen belongs to that
  feature; if it is off, it does not exist". Read on `kernel.controller` by `FeatureAccessSubscriber`,
  which answers a **404, never a 403**: an extinguished screen does not exist, it is not forbidden.
  **Every new controller needs one** — `tests/Functional/FeatureCoverageTest.php` fails on a route
  that neither carries one, inherits one from its class, nor is named in its exemption list.
- `App\Form\FilePickerType` — no form on this platform carries bytes. The picker
  stages each file on its own XHR to `/uploads/stage` and the form submits signed tokens; the field
  declares an `UploadPolicy`, not constraints.
- `App\Enum\HasBadge` + `App\Enum\BadgeTone` — any value shown as a `.cm-badge` implements it
  (`labelKey()` + `badgeTone()`), and `<twig:Cm:Badge :of="…"/>` renders it. The pairing of a state
  with its colour is written on the enum, never as a ternary in a template. Enums drawn in another
  pill family (`cm-audio-pill`, `cm-wc-tag`, `cm-step`, Tabler's `bg-*-lt`) keep their own
  `badgeClass()`.
- `Cm:*` Twig components — see "Design" below.
- Twig helpers in `src/Twig/`: `is_staff`, `is_program_teacher`, `file_url`, `user_avatar`, `avatar_url`,
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
  LexikJWT token. Every other `/api/*` route is Bearer-JWT. The JWT lives **one hour**; with it
  comes a refresh token (`App\Security\MobileSessions`, one `MobileSession` row per phone) that
  `POST /api/token/refresh` trades for the next pair — own `api_token` firewall, `security: false`,
  since the caller's JWT has run out. It **rotates**: the generation just replaced is forgiven for
  60 s (a phone in a wood loses answers), a replay past that revokes the session. 30 days idle
  ends it; « Mon profil » lists the phones and signs one out; the magic link opens one too.
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

**Fine-grained checks** are Voters (`src/Security/Voter/`, 36 of them: Assignment, AudienceTargetable, ClassBoard,
DocumentationArticle, Dossier, EcfBooklet, EcoParcours, Enterprise, Evaluation, FileLibrary, GameGesture, GuestAccount,
GuestConsole, InternshipTutorLink, LearningPath, LessonLog, MessageThread, OnlineCourse, Portfolio, Progression, ProxmoxHost, QuizFolder,
QuizTemplate, QuizTournament, SequenceFolder, SequenceInstance, SequenceTemplate, SeriousGame, SignupList, Survey, SurveyFolder, TeacherDossier, Ticket,
Wall, Wiki, WordCloud).
New per-object rules belong in a Voter, not inline in a controller.

`src/Security/Ldap*Syncer.php` also **writes** provisioning requests (`LdapManageUser`,
`LdapManageGroup`, `LdapManagePassword`) that an external script at
`/Users/Shared/Beaupeyrat/Scripts/samba/ldap/` consumes — MonCampus never mutates LDAP directly.

## External services

| Service | Purpose | Env |
|---|---|---|
| AWS S3 | Uploads (attachments, audio, PDFs) — dev shares the bucket under `AWS_S3_PREFIX=dev/` | `AWS_S3_*`, `AWS_CLOUDFRONT_DOMAIN` |
| AWS SES + S3 + SQS | Courrier pro, inbound and outbound. **Separate AWS account** from the uploads bucket | `AWS_MAIL_*`, `AWS_SES_*`, `MAIL_STUDENT_DOMAIN` |
| Gotenberg | HTML→PDF (Livret Alternant) | `GOTENBERG_URL` |
| Mercure | Turbo streams + live quiz SSE | `MERCURE_URL`, `MERCURE_PUBLIC_URL` |
| Matomo | Analytics, **consent-gated** (`requireConsent`, opt-in banner) | `MATOMO_URL`, `MATOMO_SITE_ID` |
| Discord | Support-ticket notifications | `DISCORD_WEBHOOK_*` |
| LDAP | Authentication + directory | `LDAP_*` |
| École Directe (Aplim) | Read and send to one's own teacher account from the server (private API, no stored credentials, admins only for now) | `ECOLEDIRECTE_*` |
| Recherche d'entreprises (DINUM) | UFA: proposes an employer's SIRET from its name and address (SIRENE + RNE); a person always confirms. « Trouver une entreprise »: the register searched by NAF code, département or radius. Open, keyless, **7 requests/s per IP** - one shared token bucket (`sirene_api`) and a day's cache (`cache.sirene`) for the whole platform. Licence Ouverte. Only search criteria and employers' names and addresses are sent, nothing about a student | — |
| Google Maps (JavaScript API) | « Trouver une entreprise » and the vivier's fiches: the map, loaded **only after the visitor agrees** (`google_map_controller.js`, cookie `google_maps_consent`); itineraries are plain links, the billed Directions API is never called. An empty key draws no map | `GOOGLE_MAPS_API_KEY` |
| IGN Géoplateforme | e-CO: map tiles (browser and phone, WMTS) and terrain readings (server: altimetry, BD TOPO WFS, pedestrian routing, reverse geocoding). « Trouver une entreprise »: forward geocoding of a commune (never an address) and of the school. Open, keyless, Etalab 2.0 - credit the IGN | — |
| data.gouv.fr (France compétences) | Portfolio: the RNCP fiche of a référentiel, read from the daily open-data export by `app:rncp:fetch` / `app:rncp:check` - never during a request. Open, keyless, Licence Ouverte 2.0 | — |
| Wikimedia Commons | The virtual board's photograph of the day, fetched by `app:class-board:photo` only and served from the uploads bucket - classroom browsers never call Wikimedia. Open, keyless; CC0/PD/CC BY/CC BY-SA, credited on the board | — |
| claude.ai (Anthropic) | Calls the Claude connector (`/mcp`, OAuth) from `160.79.104.0/21` — inbound only, the app never calls Anthropic | — |

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
- On top of it, `assets/styles/app.css` (~12 000 lines) implements the handoff design system: 108
  `--cm-*` custom properties (each declared twice, light and dark) used ~4 400 times, and some 2 760
  `cm-*` selectors (`cm-btn`, `cm-badge`, `cm-tabs`, `cm-actionbar`,
  `cm-action--{positive,danger,neutral,warning,off}`, …). New UI should use `cm-*`.
- Fonts are **Source Sans 3** (body) and **Spectral** (headings), from Google Fonts — not Tabler's Inter.
- Theme is per-user (`User::$themePreference`), falling back to a cookie for anonymous visitors, with
  deliberately no hardcoded default.

`templates/layout/app.html.twig` is the authenticated app shell (horizontal navbar, role-dependent, +
`page_title`/`main` blocks). New authenticated screens extend it. `login` extends `base.html.twig`
directly so it shows no navbar.

**Twig components** (`symfony/ux-twig-component`, since 2026-09-27) carry the design system's
recurring pieces, under the `Cm:` prefix: the template in `templates/components/Cm/`, the class, when
there is a rule to hold, in `src/Twig/Components/Cm/`. A component is where a rule lives once instead
of being recopied: **new markup uses the component, never the raw `cm-*` classes it renders.**

| Component | Replaces | The rule it holds |
|---|---|---|
| `Cm:Breadcrumb` | `_breadcrumb.html.twig` (deleted) | prepends « Accueil » itself, refuses a caller that passes it again |
| `Cm:Tabs` | `_tabs.html.twig` (deleted) | the `action` block renders in the *caller's* context, so its partial sees the screen's variables |
| `Cm:Badge` | `<span class="cm-badge cm-badge--…">` | `:of="value"` reads text and colour from a `HasBadge` enum; a hand-typed `tone` must be a `BadgeTone` |
| `Cm:Button` | `<a/button class="cm-btn …">` | `<a>` with an `href`, else a `<button type="button">`; refuses a variant `app.css` does not draw |
| `Cm:ActionBar` | `<div class="cm-actionbar">` + `__meta` + `__buttons` | content = the buttons, pushed right; `meta` block on the left; `:lastModified="entity"` writes « Modifié le … par … » only once a real edit exists (replaces `_last_modified_meta.html.twig`, deleted) |
| `Cm:Subhead` | `<div class="cm-subhead">` + `__title` + `__hint` | `:title` / `:hint` are text, escaped; an `action` block goes on the right |

**Icons** are `symfony/ux-icons`, not a component of ours: one SVG file per icon in `assets/icons/`
(`folder`, `pencil`, `trash`, `lock`, `plus`…), drawn for the handoffs, rendered with
`<twig:ux:icon name="folder" width="15" height="15"/>` — or `ux_icon('send', {…})` inside a Twig
string. The file carries the viewBox and the stroke; an attribute at the call site wins, which is
how one drawing serves at 12 px and at 20 px. Iconify is switched off in
`config/packages/ux_icons.yaml`: an unknown name is an error, never a download. Production renders
nothing for a missing icon rather than fail the page, so `tests/Functional/IconNamesTest.php` checks
every name a template uses. About 90 `<svg>` stay inline - drawings used once, the Tabler sprite `<use>`s, the
store logos; a second use is when one becomes a file.

Two call styles, one choice each: `{{ component('Cm:Breadcrumb', {segments: [...]}) }}` when only
props are passed (a long Twig literal reads better outside an HTML attribute), `<twig:Cm:Tabs
:tabs="...">` + `<twig:block name="action">` when a block is. Breadcrumb, Tabs, Subhead
(all but a handful whose head carries more than one title and one hint) and the ActionBar in its standard shape are
migrated everywhere. **24 action bars are deliberately left raw**: primary button on the left
(content-share duplication), `justify-content-end`, buttons split across both sides - each a layout
decision, not a leftover, to make with the handoff open. Button and Badge are migrated **as screens
are touched** — some 265 hand-written
`cm-btn` and 838 Bootstrap `btn` remain, and that is the backlog, not a second convention.
`tests/Twig/Components/CmComponentsTest.php` pins each component's markup.

**Breadcrumb rule.** Every authenticated screen fills the `page_breadcrumb` block, and **the trail
always opens on `Accueil`** — which `Cm:Breadcrumb` prepends itself, with the house pictogram,
pointing at `app_home` (`HomeController` sends a tutor on to their own home). Callers pass only the
levels *after* it; one that still passes Accueil is refused at render time, and
`tests/Functional/BreadcrumbConventionTest.php` refuses both a trail drawn outside the component and
a template naming `homeNavLabel` again. This is stated in several handoffs ("commence toujours par Accueil
avec picto maison") and holds across the app; a screen that starts its trail on a section name instead
is a bug, not a variant. After Accueil come the real parent levels, then the current page last:

- **2 segments** — the screen hangs straight off Accueil (`Accueil › À propos`).
- **3+ segments** — one entry per intervening level (`Accueil › Configuration › Nouvelle salle`,
  `Accueil › e-CO › Parcours › Nouveau parcours`). Depth follows navigation, not URL structure.

**This convention outranks a handoff.** A créa that draws a different trail is drawing one screen;
the rule holds across all 120-odd of them. The "Description technique" handoff, for instance, shows
`Accueil › À propos › Description technique` — wrong, because nothing navigates through "À propos"
to reach it: it hangs off the profile menu, so it is two segments.

Every segment stays a real `<a href>`, including the last, which carries `.current`. When the trail
varies between callers of a shared template, build it with `{% set segments = [] %}` + `|merge`
rather than duplicating the block — see `templates/audio_recording/_breadcrumb.html.twig` and
`templates/activity/history.html.twig`. Deliberately suppressing the breadcrumb (`{% block
page_breadcrumb %}{% endblock %}`) is rare and should carry a comment saying why, as
`templates/profile/index.html.twig` does — the convention test refuses an empty block without one.
The same test refuses an authenticated screen with no trail at all, and a screen that redraws the
whole `page_header` without calling the trail inside it (`{{ block('page_breadcrumb') }}`, as
`templates/my_alternance/index.html.twig` does). The 37 screens that had none were given theirs on
2026-09-27; the sequence library's show/edit screens walk the séquence's folders like the quiz
screens do (`SequenceLibraryFolderTrait::sequenceFolderTrailOf()`, owner only).

The WYSIWYG editor is **HugeRTE** (MIT TinyMCE fork), vendored under **`public/hugerte/`** and loaded by
a plain `<script src="/hugerte/hugerte.min.js">`, *not* through AssetMapper. This is deliberate: HugeRTE
fetches its own `skins/`/`themes/`/`plugins/`/`icons/` by relative HTTP at runtime, which breaks the
moment AssetMapper content-hashes those filenames. Upgrading means re-copying the same minified subset
from a fresh `npm install hugerte` (in a scratch dir, never at the repo root) and checking the Network
tab for new 404s under `/hugerte/`.

**FullCalendar 6** is vendored too, under `assets/fullcalendar/`, but through AssetMapper: the
package's raw ESM files, minified one by one without bundling and mapped with `path` entries in
`importmap.php`. `importmap:require` cannot be used for it: jsDelivr's `+esm` build copies
FullCalendar's internal classes into two modules that the plugins and the application each import
one of, and the calendar dies on its first render (`Class constructor … cannot be invoked without
'new'`). `assets/fullcalendar/README.md` says why the relative imports are rewritten as bare
specifiers, and how to upgrade. Changing `importmap.php` needs `docker compose restart php` in dev:
the worker keeps the old map in memory and answers « vendor asset is missing ».

## Conventions

- **Code is English, display text is French.** Identifiers, comments and docblocks are always English;
  only user-visible strings are French. This is the convention that slips most often in `app.css` and in
  Twig — check before writing the comment. The whole repository was swept on **2026-08-15** (235 files
  across `src/`, `tests/`, `config/`, `assets/`, `templates/` and `migrations/`), so a French comment is
  now a regression rather than a leftover. Two things stay French on purpose and are **not** violations:
  screen and label names quoted inside an English sentence (« Nouveau travail », « Visibilité pour les
  étudiants »…), and the domain vocabulary the code already speaks (séance, séquence, créneau, matière,
  cahier de texte, carnet de notes, livret alternant). Beware when re-running such a sweep:
  `QuizPromptCatalog` and `SequencePromptCatalog` hold French heredocs whose Markdown headings look
  exactly like `#` comments — they are prompt text sent to the model, and translating them would change
  behavior.
- **URLs are English too** — route paths were swept to English (117 of them). Route names follow, and
  are English apart from the domain word `seance` (`app_library_seances_*`, `app_progression_seance_*`),
  kept as a domain term the way `Program` or `Cohort` are.
- **i18n**: `fr` is the default, `en` is the second locale. `LocaleSubscriber` resolves it in order:
  session (`_locale`), then the logged-in user's `locale`, and it runs late enough not to be overridden
  by the `_locale` route attribute. Translation keys are semantic camelCase (`studentWorkNavLabel`),
  never the French sentence. Of 7 315 French keys, **676 have no English translation** — the
  configured `fallbacks: ['fr']` is what keeps those screens readable rather than showing raw keys.
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
- `$request->query->getInt('x')` **throws a 400 on the empty string** — the default applies only when
  the key is *absent*. A filter bar whose "Toutes" option is `value=""` submits `?x=` as a matter of
  course, so the screen dies the moment a user touches any filter. It reached production once
  (`Input value "classe" cannot be converted to "int"` on `/assignments`) and four screens had it at
  the same time. Read filters with `App\Service\QueryValue`; `getBoolean()` is safe, `""` being
  false. Guarding by hand is how the fourth screen got it wrong — it tested `null !== …get('x')`,
  which lets the empty string straight through.

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
- **Gotenberg 8.16's `/forms/pdfengines/split` with `splitUnify` returns a damaged PDF about one
  time in two** on the Livret (measured 2026-09-25: same request, varying sizes, truncated tails
  that every merge engine then refuses). Don't cut a PDF with it; print the parts separately and
  merge them, as `InternshipBookletPdfExporter` does.
- **Chromium lays the first page out with the default `@page` margins before applying a named
  page's.** A full-bleed 296mm cover on a named page with `margin: 0` came out cut in three. Make
  the full-bleed page the *default* `@page` and name the others (`templates/internship/booklet.html.twig`).
  Chromium (132 in Gotenberg) paints `@page` margin boxes with `counter(page)`, and `html {
  counter-reset: page N }` starts the count at N+1, but it has no `target-counter()`: a table of
  contents learns its page numbers from the named destinations of a first print
  (`App\Service\PdfNamedDestinations`) — written only for anchors some link points at.
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
release — a CalVer version that **is** the date it went live (`2026.08.10`, with `2026.08.10.2` for
a second deploy the same day), plus a two-sentence summary and one line per subject, typed
`nouveaute` / `modification` / `fix` / `interne` / `autre`. The earlier scheme numbered releases by
rank in the month; while that rank stayed under 31 it read as a day number, and `2026.08.11` dated
10 August proved it on the day it shipped.
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
the suite through ParaTest, 4 processes, each on its own copy of the migrated database
(`app:test:parallel-databases`) - about 3 minutes where `bin/phpunit` took 8 to 9.

**A release commit skips all of that.** When a commit changes `config/changelog.yaml` and
`config/tech_profile.yaml` and nothing else, and its parent's `Checks` run succeeded, the job checks
those two files (`.github/scripts/release_files.py`, stricter than the page that reads them) and
reports `Checks` green in about a minute: its code is its parent's, which has just passed. That is
what `/beaup-deploy` pushes before opening the deploy PR. It is why the release commit must go on top
of a staging whose run has *finished* green: pushed while that run is still going, it cancels it
(`cancel-in-progress`), the parent never turns green, and the release commit gets the full run.

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
- **A push whose files are all `**.md` skips it**, and that exclusion is on the `push` trigger only.
  Never add one to `pull_request`: `main`'s ruleset requires the `Checks` status, a *skipped* check
  never reports, and the deploy PR would then be unmergeable for ever. Note `config/changelog.yaml`
  and `config/tech_profile.yaml` are data the application reads, not documentation — they are
  `.yaml`, so they keep triggering the run, which is what you want.
- **Concurrency is grouped by branch**, `github.head_ref || github.ref`. The fallback matters:
  `github.head_ref` is empty on a push, and the original `github.run_id` made every run its own
  group — `cancel-in-progress` silently did nothing, and three pushes in ten minutes ran three full
  jobs, two of them already superseded.
- **It uses `TEST_TOKEN=ci`**, so the migrations land in `<database>_test**ci**`. A CI runner does not
  need the isolation; a developer replaying the sequence locally to debug a red run does, because
  otherwise it clobbers their own `_test` database. The ParaTest step then works on
  `_test1`…`_test4`, ParaTest numbering its processes through the same variable - copies that
  `app:test:parallel-databases` rewrites whole each time.

The old template workflow (`deprecated.yaml`, wired to a `backuped` branch with every real step
commented out) was deleted rather than left alongside: it was also called `CI` and also triggered on
`pull_request`, so both would have shown up under the same name. `git log` still has it.

CI now gates `main` as well as `staging`, through the required status check on the deploy PR — see the
rulesets table above. It is the same single job in both cases; what changed is that on `main` it runs
*before* the merge rather than after.

**Test coverage is no longer thin** — 2 354 tests across 265 files, where there were 334 on
2026-08-10: unit tests over pure services, one test per Voter (`tests/Security/Voter/`), and a
functional smoke test (`tests/Functional/`) that requests each main screen as a student / teacher /
admin / tutor and pins the answer. Run them with
`docker compose exec -e APP_ENV=test php bin/phpunit`; **`tests/README.md` explains the one-off test-database
setup they need**. Feature work is still verified in a real browser — the `browser-verify` skill drives
a headless Chrome against the dev app, and `beaup-sqs-check` polls the Courrier pro queues.

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

**Type at the boundary, never cast further in.** Five objects hold the answer, and a new endpoint
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
- `App\Service\QueryValue` — the same reading off the query string
  (`int`/`nullableInt`/`string`/`trimmed`/`bool`), and the only correct way to read a filter. See the
  gotcha below: `InputBag::getInt()` answers a **400** to the empty string, which is exactly what a
  filter bar submits.
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
test suite does not cover.

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
