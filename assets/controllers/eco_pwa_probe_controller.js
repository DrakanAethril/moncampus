import { Controller } from '@hotwired/stimulus';

// The e-CO app's own cadence (LocationService: one fix every 5 s). The page slices the session into
// windows of that length and asks, for each, whether the browser handed over a fix measured inside
// it - the same count the app would have produced.
const WINDOW_MS = 5000;
// Three missed ticks in a row: below that a fix merely came late.
const GAP_MS = 15000;
// A fix delivered more than this after it was measured came out of a cache or a backlog.
const STALE_MS = 10000;
const SAVE_EVERY_MS = 5000;
const STORAGE_KEY = 'ecoPwaProbe.v1';
// Written every second while the page runs, apart from the (heavy) session: after a reload, the
// time between this and the reload is the time the page was not running at all.
const ALIVE_KEY = 'ecoPwaProbe.aliveAt';
const EVENT_LOG_SIZE = 60;

/**
 * « Test PWA » (App\Controller\EcoPwaProbeController): reads the GPS through watchPosition() and
 * records every fix, every visibility change and every wake-lock change in localStorage, then says
 * which share of the 5-second windows got a fix while the page was visible and while it was hidden.
 *
 * Every fix is kept, not only one per window: the windows are a reading of the record, and an
 * export carries the raw sequence. A session survives a reload (it resumes, as the e-CO app's
 * « reprise après crash » does) and ends only on « Arrêter ».
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'status', 'start', 'stop', 'wakeLock', 'wakeLockHint',
        'duration', 'fixCount', 'visibleCoverage', 'hiddenCoverage', 'hiddenTime', 'longestGap', 'accuracy', 'altitude',
        'gaps', 'events', 'capability', 'summaryItem',
    ];

    static values = { labels: Object };

    connect() {
        // Object values re-parse on every access: read once.
        this.labels = this.labelsValue;
        this.watchId = null;
        this.wakeLockSentinel = null;
        this.lastSavedAt = 0;
        this.geoErrorLoggedAt = {};
        this.startArmedUntil = 0;
        this.storageWorks = true;
        this.denied = false;
        this.stickyUntil = 0;

        this.listeners = [
            [document, 'visibilitychange', () => this.onVisibilityChange()],
            [document, 'freeze', () => this.event('freeze')],
            [document, 'resume', () => this.event('resume')],
            [window, 'pagehide', () => this.event('pagehide')],
            [window, 'pageshow', (event) => { if (event.persisted) this.event('pageshow'); }],
            [window, 'online', () => this.event('online')],
            [window, 'offline', () => this.event('offline')],
        ];
        this.listeners.forEach(([target, type, handler]) => target.addEventListener(type, handler));

        this.session = this.load();
        if (this.session && !this.session.stoppedAt) {
            const aliveAt = this.readAliveAt();
            if (aliveAt && aliveAt > this.session.startedAt) {
                this.session.events.push({ t: aliveAt, type: 'closed' });
            }
            this.event('reload');
            this.watch();
            if (this.session.wakeLockWanted) {
                this.wakeLockTarget.checked = true;
                this.acquireWakeLock();
            }
        }

        this.renderCapabilities();
        this.ticker = setInterval(() => this.tick(), 1000);
        this.render();
    }

    disconnect() {
        // Leaving the page through the app's own navigation: the session stays open, and coming back
        // resumes it exactly as a reload would.
        if (this.running()) {
            this.event('left');
        }
        this.unwatch();
        this.releaseWakeLock();
        clearInterval(this.ticker);
        this.listeners.forEach(([target, type, handler]) => target.removeEventListener(type, handler));
        this.save(true);
    }

    // ------------------------------------------------------------------ actions

    start() {
        if (!('geolocation' in navigator)) {
            this.setStatus(this.labels.statusUnsupported);
            return;
        }
        if (this.running()) {
            return;
        }

        // A second press within 4 s is what replaces a finished record - there is nothing else to
        // lose it to, and a browser dialog would be one more thing the test measures.
        const hasRecord = this.session && this.session.fixes.length > 0;
        if (hasRecord && Date.now() > this.startArmedUntil) {
            this.startArmedUntil = Date.now() + 4000;
            this.startTarget.textContent = this.labels.startConfirmLabel;
            setTimeout(() => { this.startTarget.textContent = this.labels.startLabel; }, 4000);
            return;
        }
        this.startTarget.textContent = this.labels.startLabel;
        this.denied = false;

        this.session = {
            startedAt: Date.now(),
            stoppedAt: null,
            wakeLockWanted: this.wakeLockTarget.checked,
            fixes: [],
            events: [],
        };
        this.event('start');
        this.watch();
        this.render();
    }

    stop() {
        if (!this.running()) {
            return;
        }
        this.event('stop');
        this.session.stoppedAt = Date.now();
        this.unwatch();
        this.save(true);
        this.render();
    }

    toggleWakeLock() {
        if (this.session) {
            this.session.wakeLockWanted = this.wakeLockTarget.checked;
        }
        if (this.wakeLockTarget.checked) {
            this.acquireWakeLock();
        } else {
            this.releaseWakeLock();
        }
    }

    async copySummary() {
        const lines = this.summaryItemTargets.map((item) => {
            const label = item.querySelector('.cm-stat__label')?.textContent.trim() ?? '';
            const value = item.querySelector('.cm-stat__value')?.textContent.trim() ?? '';
            return `${label} : ${value}`;
        });
        this.capabilityTargets.forEach((dd) => {
            lines.push(`${dd.previousElementSibling?.textContent.trim() ?? ''} : ${dd.textContent.trim()}`);
        });
        try {
            await navigator.clipboard.writeText(lines.join('\n'));
            this.setStatus(this.labels.statusCopied);
        } catch {
            this.setStatus(this.labels.statusCopyFailed);
        }
    }

    exportJson() {
        if (!this.session) return;
        const payload = {
            exportedAt: new Date().toISOString(),
            userAgent: navigator.userAgent,
            standalone: this.isStandalone(),
            windowMs: WINDOW_MS,
            session: this.session,
            reading: this.compute(),
        };
        this.download(`${this.fileStem()}.json`, 'application/json', JSON.stringify(payload, null, 2));
    }

    exportGpx() {
        if (!this.session) return;
        const points = this.session.fixes.map((fix) => {
            const ele = fix.alt === null ? '' : `<ele>${fix.alt.toFixed(1)}</ele>`;
            return `      <trkpt lat="${fix.lat}" lon="${fix.lon}">${ele}<time>${new Date(fix.ts).toISOString()}</time></trkpt>`;
        });
        const gpx = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<gpx version="1.1" creator="MonCampus e-CO test PWA" xmlns="http://www.topografix.com/GPX/1/1">',
            `  <trk><name>${this.fileStem()}</name><trkseg>`,
            ...points,
            '  </trkseg></trk>',
            '</gpx>',
        ].join('\n');
        this.download(`${this.fileStem()}.gpx`, 'application/gpx+xml', gpx);
    }

    // ------------------------------------------------------------------ recording

    running() {
        return Boolean(this.session && !this.session.stoppedAt);
    }

    watch() {
        if (this.watchId !== null || !('geolocation' in navigator)) return;
        this.watchId = navigator.geolocation.watchPosition(
            (position) => this.onFix(position),
            (error) => this.onGeoError(error),
            { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
        );
    }

    unwatch() {
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
        }
    }

    onFix(position) {
        if (!this.running()) return;
        const { latitude, longitude, accuracy, altitude } = position.coords;
        this.session.fixes.push({
            ts: position.timestamp,
            at: Date.now(),
            lat: Number(latitude.toFixed(7)),
            lon: Number(longitude.toFixed(7)),
            acc: typeof accuracy === 'number' ? Math.round(accuracy * 10) / 10 : null,
            alt: typeof altitude === 'number' ? Math.round(altitude * 10) / 10 : null,
            hidden: document.visibilityState === 'hidden',
        });
        this.save();
    }

    onGeoError(error) {
        if (!this.running()) return;
        if (error.code === error.PERMISSION_DENIED) {
            this.denied = true;
            this.event('geoError', 'permission');
            this.unwatch();
            return;
        }
        // A watch keeps running after an error, and a phone losing the sky alternates errors and fixes
        // every second: one line per kind and per minute, the count going to the session.
        const kind = error.code === error.TIMEOUT ? 'timeout' : 'unavailable';
        this.session.geoErrors = this.session.geoErrors ?? {};
        this.session.geoErrors[kind] = (this.session.geoErrors[kind] ?? 0) + 1;
        if (Date.now() - (this.geoErrorLoggedAt[kind] ?? 0) >= 60000) {
            this.geoErrorLoggedAt[kind] = Date.now();
            this.event('geoError', kind);
        }
    }

    onVisibilityChange() {
        const hidden = document.visibilityState === 'hidden';
        this.event(hidden ? 'hidden' : 'visible');
        if (hidden) {
            this.writeAliveAt();
            return;
        }
        // A wake lock is released by the browser whenever the page is hidden; it has to be asked for
        // again on the way back.
        if (this.wakeLockTarget.checked && (!this.wakeLockSentinel || this.wakeLockSentinel.released)) {
            this.acquireWakeLock();
        }
        this.render();
    }

    event(type, detail = null) {
        if (!this.session) return;
        this.session.events.push(detail === null ? { t: Date.now(), type } : { t: Date.now(), type, detail });
        this.save(true);
        this.renderEvents();
    }

    async acquireWakeLock() {
        if (!('wakeLock' in navigator)) {
            this.wakeLockTarget.checked = false;
            return;
        }
        try {
            this.wakeLockSentinel = await navigator.wakeLock.request('screen');
            this.event('wakeLockOn');
            this.wakeLockSentinel.addEventListener('release', () => this.event('wakeLockOff'));
        } catch (error) {
            this.event('wakeLockError', error?.name ?? String(error));
        }
    }

    releaseWakeLock() {
        if (this.wakeLockSentinel && !this.wakeLockSentinel.released) {
            this.wakeLockSentinel.release().catch(() => {});
        }
        this.wakeLockSentinel = null;
    }

    tick() {
        if (this.running()) {
            this.writeAliveAt();
        }
        this.render();
    }

    // ------------------------------------------------------------------ reading

    /**
     * Hidden intervals, from the visibility events: « closed » (the page was not running at all
     * between its last heartbeat and a reload) counts as hidden, the way a killed app would.
     */
    hiddenIntervals(end) {
        const intervals = [];
        let hiddenSince = null;
        [...this.session.events].sort((a, b) => a.t - b.t).forEach((event) => {
            if ((event.type === 'hidden' || event.type === 'closed') && hiddenSince === null) {
                hiddenSince = event.t;
            } else if ((event.type === 'visible' || event.type === 'reload') && hiddenSince !== null) {
                intervals.push([hiddenSince, event.t]);
                hiddenSince = null;
            }
        });
        if (hiddenSince !== null) {
            intervals.push([hiddenSince, end]);
        }
        return intervals;
    }

    compute() {
        const session = this.session;
        const start = session.startedAt;
        const end = session.stoppedAt ?? Date.now();
        const hidden = this.hiddenIntervals(end);
        const isHidden = (t) => hidden.some(([from, to]) => t >= from && t < to);
        const overlap = (from, to) => hidden.reduce((sum, [a, b]) => sum + Math.max(0, Math.min(to, b) - Math.max(from, a)), 0);

        const windowCount = Math.floor((end - start) / WINDOW_MS);
        const covered = new Set();
        session.fixes.forEach((fix) => {
            const index = Math.floor((fix.ts - start) / WINDOW_MS);
            if (index >= 0 && index < windowCount) covered.add(index);
        });
        const totals = { visible: 0, visibleCovered: 0, hidden: 0, hiddenCovered: 0 };
        for (let index = 0; index < windowCount; index++) {
            const side = isHidden(start + index * WINDOW_MS + WINDOW_MS / 2) ? 'hidden' : 'visible';
            totals[side]++;
            if (covered.has(index)) totals[`${side}Covered`]++;
        }

        const times = session.fixes.map((fix) => fix.ts).filter((t) => t >= start && t <= end).sort((a, b) => a - b);
        const anchors = [start, ...times, end];
        const gaps = [];
        let longestGap = 0;
        for (let i = 1; i < anchors.length; i++) {
            const length = anchors[i] - anchors[i - 1];
            longestGap = Math.max(longestGap, length);
            if (length > GAP_MS) {
                gaps.push({ from: anchors[i - 1], to: anchors[i], hiddenShare: overlap(anchors[i - 1], anchors[i]) / length });
            }
        }

        const accuracies = session.fixes.map((fix) => fix.acc).filter((acc) => acc !== null).sort((a, b) => a - b);
        const withAltitude = session.fixes.filter((fix) => fix.alt !== null).length;

        return {
            durationMs: end - start,
            fixCount: session.fixes.length,
            staleFixCount: session.fixes.filter((fix) => fix.at - fix.ts > STALE_MS).length,
            windowCount,
            coveredWindowCount: covered.size,
            ...totals,
            hiddenMs: overlap(start, end),
            longestGapMs: longestGap,
            gaps,
            medianAccuracy: accuracies.length ? accuracies[Math.floor(accuracies.length / 2)] : null,
            altitudeShare: session.fixes.length ? withAltitude / session.fixes.length : null,
        };
    }

    // ------------------------------------------------------------------ rendering

    render() {
        const running = this.running();
        this.startTarget.disabled = running;
        this.stopTarget.disabled = !running;

        if (!this.session) {
            this.renderEvents();
            return;
        }
        if (Date.now() >= this.stickyUntil) {
            let status = running ? this.labels.statusRunning : this.labels.statusStopped;
            if (this.denied) status = this.labels.statusDenied;
            if (!this.storageWorks) status += ` ${this.labels.statusNoStorage}`;
            this.statusTarget.textContent = status;
        }

        const reading = this.compute();
        this.durationTarget.textContent = this.clock(reading.durationMs);
        this.fixCountTarget.textContent = `${reading.fixCount}`;
        this.visibleCoverageTarget.textContent = this.coverage(reading.visibleCovered, reading.visible);
        this.hiddenCoverageTarget.textContent = this.coverage(reading.hiddenCovered, reading.hidden);
        this.hiddenTimeTarget.textContent = this.span(reading.hiddenMs);
        this.longestGapTarget.textContent = this.span(reading.longestGapMs);
        this.accuracyTarget.textContent = reading.medianAccuracy === null ? '—' : `${Math.round(reading.medianAccuracy)} m`;
        this.altitudeTarget.textContent = reading.altitudeShare === null ? '—' : `${Math.round(reading.altitudeShare * 100)} %`;

        this.gapsTarget.replaceChildren(...(reading.gaps.length
            ? reading.gaps.slice().reverse().map((gap) => this.logLine(
                `${this.time(gap.from)} → ${this.time(gap.to)}`,
                `${this.span(gap.to - gap.from)} · ${gap.hiddenShare >= 0.5 ? this.labels.gapHidden : this.labels.gapVisible} (${Math.round(gap.hiddenShare * 100)} %)`,
            ))
            : [this.logLine('', this.labels.noGap)]));
        this.renderEvents();
    }

    renderEvents() {
        const events = this.session ? this.session.events.slice(-EVENT_LOG_SIZE).reverse() : [];
        this.eventsTarget.replaceChildren(...(events.length
            ? events.map((event) => this.logLine(
                this.time(event.t),
                (this.labels[`event_${event.type}`] ?? event.type) + (event.detail ? ` (${event.detail})` : ''),
            ))
            : [this.logLine('', this.labels.noEvent)]));
    }

    async renderCapabilities() {
        const standalone = this.isStandalone();
        const values = {
            secureContext: window.isSecureContext ? this.labels.yes : this.labels.no,
            permission: '…',
            displayMode: standalone ? this.labels.displayStandalone : this.labels.displayBrowser,
            wakeLock: 'wakeLock' in navigator ? this.labels.yes : this.labels.no,
            barcodeDetector: 'BarcodeDetector' in window ? this.labels.yes : this.labels.no,
            vibrate: 'vibrate' in navigator ? this.labels.yes : this.labels.no,
            backgroundSync: 'serviceWorker' in navigator && 'SyncManager' in window ? this.labels.yes : this.labels.no,
            userAgent: navigator.userAgent,
        };
        const paint = () => this.capabilityTargets.forEach((dd) => { dd.textContent = values[dd.dataset.capability] ?? '—'; });
        paint();

        if (!('wakeLock' in navigator)) {
            this.wakeLockTarget.disabled = true;
            this.wakeLockHintTarget.textContent = this.labels.wakeLockUnsupported;
        }
        try {
            const status = await navigator.permissions.query({ name: 'geolocation' });
            const state = () => this.labels[`permission_${status.state}`] ?? status.state;
            values.permission = state();
            status.addEventListener('change', () => { values.permission = state(); paint(); });
        } catch {
            values.permission = '—';
        }
        paint();
    }

    logLine(time, text) {
        const item = document.createElement('li');
        if (time) {
            const stamp = document.createElement('span');
            stamp.className = 'cm-eco-probe__time';
            stamp.textContent = time;
            item.append(stamp, ' ');
        }
        item.append(text);
        return item;
    }

    // A one-off message (copied, unsupported…) holds for 5 s before the running state repaints over it.
    setStatus(text) {
        this.statusTarget.textContent = text;
        this.stickyUntil = Date.now() + 5000;
    }

    coverage(covered, total) {
        return total === 0 ? '—' : `${Math.round((covered / total) * 100)} % (${covered}/${total})`;
    }

    clock(ms) {
        const seconds = Math.floor(ms / 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return `${Math.floor(seconds / 3600)}:${pad(Math.floor(seconds / 60) % 60)}:${pad(seconds % 60)}`;
    }

    span(ms) {
        const seconds = Math.round(ms / 1000);
        if (seconds < 60) return `${seconds} s`;
        const minutes = Math.floor(seconds / 60);
        return minutes < 60 ? `${minutes} min ${seconds % 60} s` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    time(t) {
        return new Date(t).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    }

    fileStem() {
        const d = new Date(this.session.startedAt);
        const pad = (n) => String(n).padStart(2, '0');
        return `e-co-pwa-${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
    }

    download(name, type, content) {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    // ------------------------------------------------------------------ storage

    load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const session = raw ? JSON.parse(raw) : null;
            return session && Array.isArray(session.fixes) && Array.isArray(session.events) ? session : null;
        } catch {
            return null;
        }
    }

    save(force = false) {
        if (!this.session || (!force && Date.now() - this.lastSavedAt < SAVE_EVERY_MS)) return;
        this.lastSavedAt = Date.now();
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.session));
        } catch {
            this.storageWorks = false;
        }
    }

    readAliveAt() {
        try {
            return Number(localStorage.getItem(ALIVE_KEY)) || null;
        } catch {
            return null;
        }
    }

    writeAliveAt() {
        try {
            localStorage.setItem(ALIVE_KEY, String(Date.now()));
        } catch {
            // The session save reports storage failures; the heartbeat has nothing to add.
        }
    }
}
