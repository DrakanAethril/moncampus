// Kill switch for the retired Campus web app (design/validated/quiz-tournament.md, §13).
//
// /campus-app/ used to be the moncampus-mobile Flutter app compiled for the browser, with its own
// service worker at this very address (registered as campus_sw.js?v=<build>, scope /campus-app/).
// The site itself is now the installable app (« beaup-app », scope /), and /campus-app/ answers a
// 301 to / (App\Controller\CampusAppRetiredController) - except this file, which Caddy serves as it
// is. Whenever a browser that still holds the old worker checks this URL for an update (its daily
// check, any reg.update()), it gets this script, and this script undoes the old one. Opening the
// old app's icon does NOT trigger that check - Chrome skips it on a navigation a redirect takes out
// of scope - so the site does the same cleanup itself on the page that redirect lands on
// (assets/web_app/retired_campus_app.js). Both were measured in Chromium 156:
//
// - it takes over at once (skipWaiting + claim) instead of waiting for every old tab to close;
// - it deletes the caches the old worker filled - only those (`campus-app-…`): the Cache Storage
//   is shared by the whole origin, and the e-CO app keeps its own there;
// - it unregisters itself, so nothing is left controlling /campus-app/;
// - it sends every window it controlled to /, where the redirect would have led anyway.
//
// No fetch handler: from the moment it is active, every request goes to the network. Never
// rebuild the Flutter web app into this folder again (moncampus-mobile's tool/build_pwa.sh would
// replace this file and bring the old app back).
'use strict';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        await self.clients.claim();
        for (const name of await caches.keys()) {
            if (name.startsWith('campus-app-')) {
                await caches.delete(name);
            }
        }
        await self.registration.unregister();
        for (const client of await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) {
            client.navigate('/').catch(() => {});
        }
    })());
});
