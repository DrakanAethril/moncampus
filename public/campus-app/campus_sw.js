// The PWA's service worker, registered by flutter_bootstrap.js in place of Flutter's own. One job:
// open the app quickly, and without network at all - to say « hors ligne » rather than show the
// browser's own error page.
//
// Flutter 3.22's flutter_service_worker.js cannot do it here: it keys its cache on the path from
// the site's root, so served under /campus-app/ it looks up "campus-app/main.dart.js" in a list
// that says "main.dart.js" and lets every request through. Hence this cache, one per build (the ?v=
// Flutter appends changes with every build): the shell is fetched at install, everything else the
// app loads under the scope as it loads it - plus the fallback fonts the engine fetches from
// fonts.gstatic.com (Roboto, Noto for the odd symbol).
//
// The API (/api/…), Mercure and the files' delivery URLs are outside the scope: never cached,
// never answered from here. Same design as e-CO's eco_sw.js, minus its offline queue.
'use strict';

const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE_PREFIX = 'campus-app-';
const CACHE = CACHE_PREFIX + VERSION;
const SCOPE_PATH = new URL(self.registration.scope).pathname;

// Small on purpose: Flutter's loader waits at most 4 s for this worker before starting the app
// without it. main.dart.js and CanvasKit are cached as the app loads them (and see 'campus-warm').
const SHELL = ['./', 'flutter_bootstrap.js', 'manifest.json', 'campus_web.js', 'icons/splash.png', 'favicon.png'];

// What names the build: always asked of the network first, the cache answering only offline.
const NETWORK_FIRST = new Set(['', 'index.html', 'flutter_bootstrap.js', 'manifest.json', 'version.json', 'campus_web.js']);

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL.map((path) => new Request(path, { cache: 'reload' })))),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const name of await caches.keys()) {
            if (name.startsWith(CACHE_PREFIX) && name !== CACHE) await caches.delete(name);
        }
        await self.clients.claim();
    })());
});

// Versioned URLs, so a cached copy can never be stale.
const FONT_ORIGIN = 'https://fonts.gstatic.com';

function inScope(url) {
    return url.origin === self.location.origin && url.pathname.startsWith(SCOPE_PATH);
}

function cacheable(url) {
    return inScope(url) || url.origin === FONT_ORIGIN;
}

async function networkFirst(request, key) {
    const cache = await caches.open(CACHE);
    try {
        // A navigation request refuses any init; the server's no-cache revalidates it anyway.
        const response = await (request.mode === 'navigate' ? fetch(request) : fetch(request, { cache: 'no-cache' }));
        if (response.ok) await cache.put(key, response.clone());

        return response;
    } catch (error) {
        const cached = await cache.match(key, { ignoreSearch: true });
        if (cached) return cached;
        throw error;
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request, { ignoreSearch: true });
    if (cached) return cached;
    // Revalidated rather than taken from the HTTP cache: main.dart.js keeps its name across builds.
    const response = await fetch(request, { cache: 'no-cache' });
    if (response.ok) await cache.put(request, response.clone());

    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (!cacheable(url)) return;
    if (!inScope(url)) {
        event.respondWith(cacheFirst(request));

        return;
    }

    const relative = url.pathname.slice(SCOPE_PATH.length);
    if (request.mode === 'navigate' || NETWORK_FIRST.has(relative)) {
        // Every navigation lands on the app: it has no route of its own in the address. The magic
        // link's ?login= is the page's to read, not a different page to cache.
        const key = request.mode === 'navigate' || relative === '' ? new URL('./', self.registration.scope).href : request.url;
        event.respondWith(networkFirst(request, key));

        return;
    }
    event.respondWith(cacheFirst(request));
});

// Sent by index.html once the first frame is drawn: what the page has loaded so far. On a first
// visit the app may have started before this worker took control (the loader's 4 s), and what it
// loaded then never went through the cache - this is how it gets there anyway.
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type !== 'campus-warm' || !Array.isArray(data.urls)) return;
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        for (const href of data.urls) {
            const url = new URL(href);
            if (!cacheable(url)) continue;
            const relative = inScope(url) ? url.pathname.slice(SCOPE_PATH.length) : null;
            if (NETWORK_FIRST.has(relative) || await cache.match(url.href, { ignoreSearch: true })) continue;
            try {
                const response = await fetch(url.href, { cache: 'no-cache' });
                if (response.ok) await cache.put(url.href, response);
            } catch (error) {
                // Offline already: the next visit will try again.
            }
        }
    })());
});
