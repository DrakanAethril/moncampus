import { Controller } from '@hotwired/stimulus';

/**
 * The soft edit lock's heartbeat.
 *
 * Every 60 seconds while the editor is open, this tells the server the page is still being worked
 * on. The server considers a lock stale after five minutes, so one missed beat is forgiven and a
 * closed tab frees the page on its own - which is the whole point: the lock removes the *silent*
 * overwrite, it never stops anybody from editing.
 *
 * On leaving, it releases the lock explicitly rather than waiting for it to go stale, so a
 * colleague opening the page a moment later sees no banner at all. `keepalive` is what makes that
 * request survive the page unloading.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
        intervalMs: { type: Number, default: 60000 },
    };

    connect() {
        this.beat();
        this.timer = window.setInterval(() => this.beat(), this.intervalMsValue);
        // A save navigates away too, and the lock the server drops on save makes this a no-op -
        // but a closed tab or a "back" is exactly what this catches.
        this.onUnload = () => this.release();
        window.addEventListener('pagehide', this.onUnload);
    }

    disconnect() {
        window.clearInterval(this.timer);
        window.removeEventListener('pagehide', this.onUnload);
        this.release();
    }

    beat() {
        this.post('heartbeat', false);
    }

    release() {
        this.post('release', true);
    }

    post(action, keepalive) {
        const body = new FormData();
        body.append('action', action);

        fetch(this.urlValue, {
            method: 'POST',
            body,
            keepalive,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': this.tokenValue },
            // A failed beat is not worth telling the writer about: the lock simply goes stale,
            // which is the state it is designed to end up in anyway.
        }).catch(() => {});
    }
}
