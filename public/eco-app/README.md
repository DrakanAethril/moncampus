# e-CO, version web (PWA)

The e-CO Flutter app (`/Users/Shared/Beaupeyrat/e-CO`, GitHub `e-co-mobile`) compiled for the
browser, served at **`/eco-app/`** as plain static files by Caddy - no route, no login, like
`public/downloads/`. Everything in this folder but this README is a build output: never edit it
here, run the e-CO repo's `tool/build_pwa.sh`, which rebuilds it and replaces it whole (the
`release-mobile-apps` skill runs it alongside the APKs).

Why it lives here rather than anywhere else: the API it calls is this server's, so serving the
app from the same origin means no CORS and no URL compiled in (the app reads its own origin), and
its service worker sends the offline queue to that same origin.

What the web build changes, and nothing else does:

- **the offline queue** is in IndexedDB (`eco_queue.js`) instead of SQLite, and is sent by the
  same `QueueProcessor` as on the phone - plus, through background sync, by the service worker
  (`eco_sw.js`) when the page is frozen or closed (Chrome on Android; Safari has none);
- **the service worker** is e-CO's own: Flutter 3.22's caches nothing under a sub-path. It keeps
  the app openable offline after one visit, CanvasKit and the fallback fonts included;
- **CanvasKit and the QR reader (ZXing)** are served from here, not from Google's CDN or unpkg.

Caddy (`frankenphp/Caddyfile`) redirects `/eco-app` to `/eco-app/`, serves `index.html` for the
folder, and sends `Cache-Control: no-cache` on the whole folder: the file names do not change
between builds (`main.dart.js`), so each fetch revalidates.

Committed to git (not LFS): about 18 MB, of which the two CanvasKit `.wasm` change only with a
Flutter upgrade, so a release adds mostly `main.dart.js` (≈ 2.6 MB).
