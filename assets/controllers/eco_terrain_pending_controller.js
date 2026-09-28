import { Controller } from '@hotwired/stimulus';

/**
 * While the IGN's reading of a parcours is pending (app:eco:read-terrain runs every minute), asks
 * every 10 s whether it is done and reloads the screen once it is. The loop only waits: closing
 * the tab loses nothing, the analysis is written all the same.
 */
export default class extends Controller {
    static values = { url: String, intervalMs: { type: Number, default: 10000 } };

    connect() {
        this.timer = setInterval(() => this.check(), this.intervalMsValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    check() {
        fetch(this.urlValue, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (data && data.pending === false) {
                    clearInterval(this.timer);
                    window.location.reload();
                }
            })
            .catch(() => {});
    }
}
