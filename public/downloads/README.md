# Distribution hors stores

Drop the built binaries here, named to match `config/packages/app_resources.yaml`:

- `moncampus.apk`, `moncampus.ipa`
- `eco.apk`, `eco.ipa`

Served as plain static files by Caddy (no route, no auth) - same convention as `public/hugerte/`.
The iOS install manifests (`/downloads/moncampus.plist`, `/downloads/eco.plist`) are generated
dynamically by `App\Controller\ResourcesController` from the same config, not stored here.

This directory is gitignored except for this file - binaries are deployment artifacts, not
source control content.
