// e-CO's offline queue in the browser - the PWA's counterpart of the phone's SQLite table
// (lib/services/offline_queue_storage_sqflite.dart). Loaded twice, and that is its reason to be
// a plain script rather than Dart:
//  - by index.html, before the app: lib/services/offline_queue_storage_web.dart calls it, and the
//    app's QueueProcessor sends the queue exactly as it does on the phone;
//  - by the service worker (eco_sw.js), which sends what is left when the browser fires a
//    background sync - with the page frozen behind a locked screen, or closed.
//
// Two senders on one queue would post the same items twice (the server keeps every position it
// is given), so a pass of either holds the same Web Lock. A pass removes an item only once the
// server has answered it, and stops a kind of item at its first failure to keep the order - the
// rules of QueueProcessor, which flush() below repeats for the service worker.
(function (scope) {
    'use strict';

    const DB_NAME = 'eco_offline_queue';
    const STORE = 'queue_item';
    const LOCK = 'eco-queue-flush';
    const SYNC_TAG = 'eco-queue';
    const SEQUENTIAL = ['scan', 'sos', 'app_event'];

    let database = null;

    function open() {
        if (database) return database;
        database = new Promise((resolve, reject) => {
            const request = scope.indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const store = request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                store.createIndex('type', 'type');
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => {
                database = null;
                reject(request.error);
            };
        });

        return database;
    }

    async function run(mode, operation) {
        const db = await open();

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(STORE, mode);
            const request = operation(transaction.objectStore(STORE));
            transaction.oncomplete = () => resolve(request.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    }

    function enqueue(type, payload, token) {
        return run('readwrite', (store) => store.add({
            type,
            payload: JSON.parse(payload),
            token: token ?? null,
            createdAt: new Date().toISOString(),
        })).then(() => undefined);
    }

    // In recording order: the key grows with every add, and an index lists equal keys by it.
    async function items(type) {
        const rows = await run('readonly', (store) => (type ? store.index('type').getAll(type) : store.getAll()));

        return rows;
    }

    async function pending(type) {
        return JSON.stringify((await items(type)).map(({ id, type, payload, createdAt }) => ({ id, type, payload, createdAt })));
    }

    function remove(id) {
        return run('readwrite', (store) => store.delete(id)).then(() => undefined);
    }

    function count() {
        return run('readonly', (store) => store.count());
    }

    // Without Web Locks (Safari before 15.4) there is no background sync either, hence no second
    // sender: running the pass straight away is then safe.
    function exclusive(pass) {
        const locks = scope.navigator && scope.navigator.locks;

        return locks ? locks.request(LOCK, () => pass()) : Promise.resolve().then(pass);
    }

    function requestSync() {
        if (!('serviceWorker' in scope.navigator) || !scope.document) return;
        scope.navigator.serviceWorker.ready
            .then((registration) => registration.sync && registration.sync.register(SYNC_TAG))
            .catch(() => {});
    }

    function syncIfPending() {
        count().then((left) => left > 0 && requestSync()).catch(() => {});
    }

    async function post(origin, path, body) {
        const response = await fetch(origin + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`${path} answered ${response.status}`);
    }

    // Positions go in one call per runner, scans / SOS / app events one by one - as
    // QueueProcessor does. The page sends with its session's token; here each item carries the
    // token it was recorded under.
    async function flushPositions(origin) {
        const byToken = new Map();
        for (const item of await items('position')) {
            if (!item.token) continue;
            if (!byToken.has(item.token)) byToken.set(item.token, []);
            byToken.get(item.token).push(item);
        }
        for (const [token, group] of byToken) {
            try {
                await post(origin, '/api/eco/runner/positions', { token, points: group.map((item) => item.payload) });
            } catch (error) {
                continue;
            }
            for (const item of group) await remove(item.id);
        }
    }

    async function flushSequential(origin, type) {
        for (const item of await items(type)) {
            if (!item.token) return;
            try {
                if ('scan' === type) {
                    const { code, method, latitude, longitude, scannedAt } = item.payload;
                    await post(origin, '/api/eco/runner/scan', {
                        token: item.token,
                        code,
                        method: method ?? 'qr_scan',
                        ...(latitude != null ? { latitude } : {}),
                        ...(longitude != null ? { longitude } : {}),
                        ...(scannedAt != null ? { scannedAt } : {}),
                    });
                } else if ('sos' === type) {
                    await post(origin, '/api/eco/runner/sos', { token: item.token });
                } else {
                    await post(origin, '/api/eco/runner/app-events', { token: item.token, type: item.payload.type });
                }
            } catch (error) {
                return;
            }
            await remove(item.id);
        }
    }

    // The service worker's pass. Resolves with whether the queue is now empty: a sync that leaves
    // something behind fails, which is what makes the browser try it again later.
    function flush(origin) {
        return exclusive(async () => {
            await flushPositions(origin);
            for (const type of SEQUENTIAL) await flushSequential(origin, type);

            return (await count()) === 0;
        });
    }

    scope.ecoQueue = { enqueue, pending, remove, count, exclusive, syncIfPending, flush, SYNC_TAG };

    if (scope.document) {
        // Asks the browser not to evict the queue under storage pressure. Granted silently to an
        // installed PWA by Chrome; Safari keeps an installed PWA's storage anyway.
        if (scope.navigator.storage && scope.navigator.storage.persist) {
            scope.navigator.storage.persist().catch(() => {});
        }
        // The page is put away - it may be frozen before its next pass: whatever is still queued is
        // handed to background sync now. Unconditionally, because the « left » event the app
        // records on this very change may not be written yet; a sync on an empty queue does nothing.
        scope.document.addEventListener('visibilitychange', () => {
            if (scope.document.visibilityState === 'hidden') requestSync();
        });
    }
})(self);
