import { Controller } from '@hotwired/stimulus';

/**
 * Waits on the server for something a scheduled command does - France compétences' fiche being
 * read by app:rncp:fetch - and reloads the page the moment the state moves.
 *
 * It asks every 2 seconds and gives up after 15 minutes of an open page: past that, the worker is
 * not running and a loop in the browser is not the way to find out. The page says so and offers a
 * manual refresh. The browser never carries the work - closing the tab loses nothing.
 */
export default class extends Controller {
    static values = { url: String, state: String };
    static targets = ['giveUp'];

    static INTERVAL_MS = 2000;
    static GIVE_UP_MS = 15 * 60 * 1000;

    connect() {
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
            if (this.hasGiveUpTarget) {
                this.giveUpTarget.hidden = false;
            }
            return;
        }

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (payload.state !== this.stateValue) {
                this.stop();
                window.location.reload();
            }
        } catch {
            // A network blip is not a reason to stop waiting: the next tick asks again.
        }
    }
}
