import { Controller } from '@hotwired/stimulus';

/**
 * Follows the directory queue draining, on the class import's third screen.
 *
 * The script on the server picks the queue up every minute and takes a few seconds per account, so
 * a thirty-account import settles in two or three minutes - under the operator's eyes. That is what
 * makes this screen worth watching rather than merely worth revisiting.
 *
 * It asks every 5 seconds while a line is still waiting, stops the moment nothing is, and gives up
 * after 15 minutes of an open page: past that, something is wrong on the server side and a polling
 * loop is not the way to find out. The "Actualiser" button takes over.
 */
export default class extends Controller {
    static targets = ['state', 'log', 'retry', 'pendingNote', 'pendingCount', 'createdCount', 'failedCount', 'refreshed'];
    static values = { url: String, pending: Number };

    static INTERVAL_MS = 5000;
    static GIVE_UP_MS = 15 * 60 * 1000;

    connect() {
        if (this.pendingValue <= 0) {
            return;
        }

        this.startedAt = Date.now();
        this.timer = window.setInterval(() => this.poll(), this.constructor.INTERVAL_MS);
    }

    disconnect() {
        this.stop();
    }

    stop() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    async poll() {
        if (Date.now() - this.startedAt > this.constructor.GIVE_UP_MS) {
            this.stop();
            return;
        }

        let payload;
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            payload = await response.json();
        } catch {
            // A blip in the network is not a reason to stop watching: the next tick tries again.
            return;
        }

        let created = 0;
        let failed = 0;

        for (const line of payload.lines) {
            const row = this.element.querySelector(`[data-class-import-status-line="${line.id}"]`);
            if (!row) {
                continue;
            }

            if (line.state === 2) {
                created += 1;
            }
            if (line.state === 3) {
                failed += 1;
            }

            const badge = row.querySelector('[data-class-import-status-target="state"]');
            if (badge && line.label !== null) {
                badge.textContent = line.label;
                badge.className = `badge ${line.cssClass}`;
            }

            const log = row.querySelector('[data-class-import-status-target="log"]');
            if (log) {
                log.textContent = line.state === 3 ? (line.log ?? '') : '';
            }

            const retry = row.querySelector('[data-class-import-status-target="retry"]');
            if (retry) {
                retry.classList.toggle('d-none', !line.retryable);
            }
        }

        this.setCount(this.pendingCountTarget, payload.pending);
        this.setCount(this.createdCountTarget, created);
        this.setCount(this.failedCountTarget, failed);
        this.failedCountTarget.classList.toggle('cm-stat__value--danger', failed > 0);

        if (this.hasRefreshedTarget) {
            this.refreshedTarget.textContent = new Date().toLocaleTimeString();
        }

        if (payload.pending === 0) {
            this.stop();
            if (this.hasPendingNoteTarget) {
                this.pendingNoteTarget.remove();
            }
        }
    }

    setCount(target, value) {
        if (target) {
            target.textContent = String(value);
        }
    }
}
