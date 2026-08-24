import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the account-operation banner of a user's fiche live while the tab is open.
 *
 * The cron on the domain controller picks the queue up every minute and the script takes a few
 * seconds, so an operation settles in well under a minute and a half - short enough to watch, which
 * is why the wait is a banner on the fiche and not a modal: the fiche stays usable, and the banner
 * survives a refresh because the server renders it.
 *
 * This controller adds nothing to that except freshness. It asks every 2 seconds, stops the moment
 * the script has had its say (state 2 or 3), and gives up after 5 minutes with the banner saying the
 * operation is still under way rather than a wheel spinning at nothing. Nothing is lost when it
 * gives up, or when the tab is closed: app:ldap:apply-account-requests runs every minute and is what
 * actually carries the work.
 *
 * The server sends back the rendered partial rather than fields to stitch together - one template,
 * one truth about what the banner says.
 */
export default class extends Controller {
    static targets = ['banner'];
    static values = { url: String, done: Boolean };

    static INTERVAL_MS = 2000;
    static GIVE_UP_MS = 5 * 60 * 1000;

    connect() {
        if (this.doneValue) {
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
            this.markStillRunning();
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

        if (typeof payload.html === 'string' && payload.html !== '') {
            this.element.innerHTML = payload.html;
        }

        if (payload.status && payload.status.done) {
            this.stop();
        }
    }

    /**
     * Five minutes without the queue moving means something is wrong on the server side, and a
     * polling loop is not how anyone will find out. The banner says so in words rather than going on
     * spinning: the operation is not lost, it is just not being watched any more.
     */
    markStillRunning() {
        const detail = this.element.querySelector('.cm-accountband__detail');
        if (detail && detail.dataset.stillRunningLabel) {
            detail.textContent = detail.dataset.stillRunningLabel;
        }
    }
}
