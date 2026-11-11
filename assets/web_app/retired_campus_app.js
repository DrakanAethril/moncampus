/*
 * Clears what the retired Campus web app left in this browser (design/validated/quiz-tournament.md,
 * §13): /campus-app/ was the Flutter app compiled for the browser, with its own service worker
 * (scope /campus-app/) and its own caches (`campus-app-<build>`). The site is the installable app
 * now (« Formatool »), and every /campus-app/ address answers a 301 to /.
 *
 * The kill-switch worker left at /campus-app/campus_sw.js only runs once the browser checks the old
 * worker for an update - and Chrome does not check on a navigation that a redirect takes out of the
 * worker's scope, which is exactly what opening the old app's icon now is (measured: the old worker
 * stayed registered, its cache full, the page on /login). So the site does it itself, on every page,
 * where that redirect lands: the old registration is unregistered and its caches deleted. Only
 * those: the Cache Storage is the whole origin's, and the e-CO app keeps its own in it.
 *
 * Nothing is registered here: the site has no service worker of its own.
 */
async function clearRetiredCampusApp() {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    for (const registration of await navigator.serviceWorker.getRegistrations()) {
        if ('/campus-app/' === new URL(registration.scope).pathname) {
            await registration.unregister();
        }
    }
    if (!('caches' in window)) {
        return;
    }
    for (const name of await window.caches.keys()) {
        if (name.startsWith('campus-app-')) {
            await window.caches.delete(name);
        }
    }
}

clearRetiredCampusApp().catch(() => {});
