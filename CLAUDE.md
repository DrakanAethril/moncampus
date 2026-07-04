# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This is **Symfony Docker** (dunglas/symfony-docker) — a Docker-based installer/runtime template for
Symfony apps, built on [FrankenPHP](https://frankenphp.dev) and [Caddy](https://caddyserver.com). It is
currently a bare skeleton: `composer.json` is empty (`{}`) and there is no `src/`, `bin/console`, or
Symfony application code yet. The first `docker compose up` bootstraps a fresh Symfony project into this
directory via Symfony Flex; only then do normal Symfony commands (`bin/console`, `bin/phpunit`, etc.)
become available.

Until an app is bootstrapped, most work here is on the Docker/Caddy/FrankenPHP plumbing itself, not
application code.

## Commands

```console
# Build fresh images (dev target, via compose.override.yaml)
docker compose build --pull --no-cache

# Bootstrap/start the project (creates a Symfony app on first run if none exists)
docker compose up --wait

# Stop
docker compose down --remove-orphans

# Shell into the php (FrankenPHP) container
docker compose exec php bash

# Run Composer / Symfony console once an app exists
docker compose exec php composer <command>
docker compose exec php bin/console <command>

# Run tests once PHPUnit is installed
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --filter <TestName>   # single test
```

Production images use a separate compose overlay and must list `-f` flags in this order
(`compose.yaml` first, then `compose.prod.yaml`):

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

Plain `docker compose ...` (no `-f`) implicitly merges `compose.yaml` + `compose.override.yaml`, i.e. the
**dev** image — this is the default for local work and for CI.

Optional: `docs/makefile.md` documents a `make` target template (`make build`, `make up`, `make down`,
`make sh`, `make composer c='...'`, `make sf c='...'`, `make test c='...'`) that can be copy/pasted into a
`Makefile` at the repo root, but no `Makefile` exists in the repo yet.

## Architecture

**Single service (`php`)**: one FrankenPHP container serves both PHP execution and the HTTP(S)/HTTP3
Caddy front end — there's no separate nginx/php-fpm split. Ports and env wiring live in `compose.yaml`;
the Caddy behavior itself lives in `frankenphp/Caddyfile` (Mercure hub, Vulcain, worker mode, static file
serving) and is driven by env vars rather than edited directly for common tweaks — see
`docs/options.md` for the full table (`SERVER_NAME`, `CADDY_GLOBAL_OPTIONS`, `FRANKENPHP_CONFIG`,
`FRANKENPHP_WORKER_CONFIG`, `MERCURE_*`, etc.).

**Compose file layering** (Docker Compose merges these; order matters):
- `compose.yaml` — base service definition, ports, shared env (DB URL, Mercure JWT secrets), volumes.
- `compose.override.yaml` — dev overlay, auto-applied by plain `docker compose`: builds the
  `frankenphp_dev` target, bind-mounts the whole repo into `/app`, excludes `var/` (and optionally
  `vendor/`) from the bind mount for I/O performance on Mac/Windows, enables Xdebug via `XDEBUG_MODE`.
  Also where dev-only tooling containers live (`phpmyadmin`, Mailpit, `openldap` — a stand-in LDAP
  server for local auth testing, reachable from `php` at `ldap://openldap:1389`) since this file is
  never part of the prod build.
- `compose.prod.yaml` — prod overlay: builds the `frankenphp_prod` target, injects `APP_SECRET` /
  Mercure keys from the environment (no defaults — must be set).
- `.devcontainer/compose.devcontainer.yaml` — additional overlay layered on top of the dev compose files
  for VS Code / Dev Containers.

**Multi-stage `Dockerfile`** (stages build on each other):
- `frankenphp_upstream` → `frankenphp_base`: installs PHP extensions (`apcu`, `intl`, `opcache`, `zip`,
  Composer) via `install-php-extensions`; Symfony Flex recipes inject config between the
  `###> recipes ### / ###< recipes ###` markers — don't hand-edit inside those markers.
- `frankenphp_dev`: adds Xdebug, switches to `php.ini-development`, creates a `nonroot` user (used as
  `remoteUser` in the Dev Container).
- `frankenphp_prod_builder`: switches to `php.ini-production`, runs `composer install --no-dev`,
  dumps the autoloader/env, compiles the asset map if `importmap.php` exists.
- `frankenphp_prod`: a from-scratch `debian:13-slim` stage that copies only the built binaries,
  extensions, shared libs (auto-collected via `libtree`), and app files from the builder — no build
  toolchain ships in the final image. Runs as `www-data`, strips setuid/setgid bits.

**Symfony Flex integration markers**: several files contain `###> package/name ### … ###< package/name ###`
blocks (e.g. `symfony/mercure-bundle` in `compose.yaml`/`compose.override.yaml`, `dunglas/symfony-docker`
install-time vars). These are managed by Composer/Flex recipes when packages are added/removed — avoid
manually restructuring them, as recipe installs/removals rewrite content between the markers.

**CI** (`.github/workflows/ci.yaml`): builds the dev image via `docker/bake-action`, starts it with
`docker compose up --wait --no-build`, and curls the container to check HTTP/Mercure reachability.
Steps for PHPUnit, Doctrine schema validation, and DB creation/migrations are present but commented out
pending those packages being installed. A separate `lint` job runs `super-linter/slim`; linter configs
are `.github/linters/actionlint.yaml` (GitHub Actions) and `.github/linters/zizmor.yaml` (workflow
security).

**Dev Containers / AI agents** (`docs/agents.md`, `.devcontainer/`): no coding agent is installed by
default. Agents (OpenCode, Claude Code, etc.) are opted into by editing
`.devcontainer/devcontainer.json` (features + VS Code extensions) and rebuilding. An optional network
firewall (`.devcontainer/init-firewall.sh`, iptables + ipset + dnsmasq allowlisting) can be added to
sandbox autonomous/"YOLO" agent runs — see `docs/agents.md` for the full setup (adds `NET_ADMIN` cap,
sudoers rule for the firewall script, and a `postStartCommand`). Only run an agent with
`--dangerously-skip-permissions` / `bypassPermissions` when this firewall is in place.

**Authentication (LDAP-backed, DB-persisted users)**: users authenticate against LDAP, but every
authenticated identity is mirrored into a Doctrine `App\Entity\User` row (just-in-time provisioning) so
the rest of the app can attach relations to a stable local entity instead of a transient LDAP identity.
- `App\Security\LdapAuthenticator` (`custom_authenticators` in `config/packages/security.yaml`, firewall
  `main`) does the real work: binds to LDAP as the service account (`LDAP_SEARCH_DN`/`LDAP_SEARCH_PASSWORD`)
  to search `(uid=...)`, find-or-creates the matching `User` row and syncs `email`/`displayName`/`roles`
  from the LDAP entry and its `groupOfNames` memberships (`ROLE_<GROUP_CN>`) on every login, then verifies
  the submitted password with a second LDAP bind as the user's own DN (`CustomCredentials` badge) — no
  password hash is ever stored locally.
- The `app_user_provider` (`entity` provider on `App\Entity\User`, property `username`) only handles
  session/token refresh between requests; it never checks credentials.
- LDAP connection config (`Symfony\Component\Ldap\Ldap` + `ExtLdap\Adapter`, wired in `config/services.yaml`)
  is driven by `LDAP_HOST`/`LDAP_PORT`/`LDAP_BASE_DN`/`LDAP_SEARCH_DN`/`LDAP_SEARCH_PASSWORD` env vars —
  dev defaults in `.env` point at the dev-only `openldap` service; point them at the real corporate LDAP
  in prod via secrets/`.env.local.php`.
- `src/Controller/SecurityController.php` (`/login`, `/logout`) and `src/Controller/HomeController.php`
  (`/`, `ROLE_USER`-gated) handle auth routing; their templates are styled per Tabler (see Design below).

## Design

Screens/views for this application follow the [Tabler](https://tabler.io) admin dashboard template
(horizontal-navbar variant, chosen over the sidebar/condensed layouts). A full checkout of Tabler lives at
`design/tabler/` (gitignored, reference-only — not part of the app, not to be built/deployed). When asked
to design or build a screen, look for the closest matching static page under `design/tabler/demo/*.html`
(e.g. `sign-in.html`, `tables.html`, `cards-masonry.html`, `layout-horizontal.html`, `settings-plan.html`,
`profile.html`) and adapt its markup/classes/structure into Twig — but check with the user before picking
a specific demo page when more than one could plausibly fit (e.g. `sign-in.html` vs
`sign-in-illustration.html`), since that's a design judgment call, not something to guess.

Tabler's CSS/JS are vendored (not CDN-loaded) into `assets/vendor/tabler/{css,js}/tabler.min.css|js` and
wired into every page via `templates/base.html.twig` (along with the Inter font and
`data-bs-theme="light"`); copy any other needed static assets (illustrations, icons) from
`design/tabler/demo/static/` into `assets/vendor/tabler/` the same way, then reference via `asset()`.
Only the core `tabler.min.*` files are vendored — skip `tabler-flags`/`tabler-payments`/`tabler-vendors`
and `demo.*` (the latter is tabler.io's own showcase-site chrome, not reusable app UI).

`templates/layout/app.html.twig` is the shared authenticated app-shell (horizontal navbar + user dropdown
+ page-header/page-body regions) extending `base.html.twig`; new authenticated screens should extend it
and fill the `page_title`/`main` blocks rather than rebuilding the navbar. The public-facing `login`
template extends `base.html.twig` directly (it must not show the authenticated navbar).
Small interactive bits (e.g. the password show/hide toggle) are implemented as Stimulus controllers under
`assets/controllers/*_controller.js`, matching the project's existing convention — not inline JS.

## Conventions

- Indentation: spaces (size 4) by default; Dockerfile/Caddyfile/`*.sh` use tabs; compose/GitHub Actions
  YAML use 2-space indent (`.editorconfig`).
- `SERVER_NAME`, ports (`HTTP_PORT`/`HTTPS_PORT`/`HTTP3_PORT`), Symfony version/stability, and Caddy
  behavior are meant to be configured through environment variables (`.env` at repo root) rather than
  editing `compose.yaml`/`Caddyfile` directly — see `docs/options.md`.
