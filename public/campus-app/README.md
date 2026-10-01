# Campus Beaupeyrat, version web (PWA)

The moncampus-mobile Flutter app (`/Users/Shared/Projets/Flutter/moncampus-mobile`) compiled for the
browser, served at **`/campus-app/`** as plain static files by Caddy - no route, no login of its
own (the app signs in through `/api/login` like on the phone). Everything in this folder but this
README is a build output: never edit it here, run the mobile repo's `tool/build_pwa.sh`, which
rebuilds it and replaces it whole (the `release-mobile-apps` skill should run it alongside the APK).

Why it lives here rather than anywhere else: the API it calls is this server's, so serving the
app from the same origin means no CORS and no URL compiled in (the app reads its own origin) - and
the Mercure hub the live quiz listens to is on that origin too.

What the web build changes, and nothing else does (`lib/services/platform_bridge_web.dart` +
`web/campus_web.js` in the mobile repo):

- **the live quiz stream** is read through `fetch`, as it arrives: `package:http` hands a response
  back only once it has ended in a browser, and `EventSource` cannot send the subscriber token's
  `Authorization` header;
- **Courrier pro attachments** are handed to the browser as a download instead of « open with »,
  and picked files travel as bytes (a browser gives no path);
- **the magic link** opens `/campus-app/?login=<token>` instead of `campusmanager://` - the app
  declares `client: moncampus-web` when it asks for one (`MagicLoginController`), which is also
  how « Mon profil » lists its sessions apart (`MobileApp::CampusWeb`);
- no biometric gate, no capture blocking (`FLAG_SECURE` has no browser equivalent);
- **the service worker** is the app's own (`campus_sw.js`): Flutter 3.22's caches nothing under a
  sub-path. It only keeps the app openable quickly and offline; the API is never cached.
- **CanvasKit** is served from here, not from Google's CDN.

Caddy (`frankenphp/Caddyfile`) redirects `/campus-app` to `/campus-app/`, serves `index.html` for
the folder, and sends `Cache-Control: no-cache` on the whole folder: the file names do not change
between builds (`main.dart.js`), so each fetch revalidates.

Committed to git (not LFS): about 18 MB, of which the two CanvasKit `.wasm` change only with a
Flutter upgrade, so a release adds mostly `main.dart.js`.
