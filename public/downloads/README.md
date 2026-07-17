# Distribution hors stores

Drop the built binaries here, named to match `config/packages/app_resources.yaml`:

- `moncampus.apk`, `moncampus.ipa`
- `eco.apk`, `eco.ipa`

Served as plain static files by Caddy (no route, no auth) - same convention as `public/hugerte/`.
The iOS install manifests (`/downloads/moncampus.plist`, `/downloads/eco.plist`) are generated
dynamically by `App\Controller\ResourcesController` from the same config, not stored here.

The `.apk`/`.ipa` files ARE committed here (not gitignored), unlike most build artifacts - this
app's only deployment mechanism is `git clone` + `docker compose build` on the server
(docs/production.md), so anything not in git never reaches production. They're normally written
here by the `release-mobile-apps` skill, not edited by hand.

Tracked via **Git LFS** (`.gitattributes`: `public/downloads/*.apk`/`*.ipa`) rather than as regular
blobs - GitHub already flags a single APK for exceeding their 50MB recommended size, and every
release adds another full pair on top of ordinary git history. `git lfs install` must have been run
once on any machine that clones this repo and needs the real file content (the production server
included - `git clone`/`docker compose build` there needs `git-lfs` installed and `git lfs pull` to
run, or Git LFS's smudge filter does it automatically on checkout if the package is present).
