import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the « une mise à jour est en cours » banner honest on a tab that is already open.
 *
 * The banner is rendered by the server on every page, so anybody who navigates during a deploy sees
 * it without this controller. What this adds is the person who does not navigate - the one writing
 * a cahier de texte entry, or halfway through a quiz - which is exactly the person the warning is
 * for, and the one who would otherwise meet the restart with no notice at all.
 *
 * A poll rather than Mercure, deliberately: the hub is served by the very container the deploy
 * replaces, so a subscription would drop at the moment it mattered and would have to be rebuilt
 * anyway. A minute is plenty against a window measured in quarters of an hour, and a request that
 * fails - which it will, while the platform is down - simply changes nothing until the next one.
 *
 * The server sends the banner already rendered; this only puts it in place or takes it away.
 */
export default class extends Controller {
    static values = { url: String };

    static INTERVAL_MS = 60000;

    connect() {
        this.timer = window.setInterval(() => this.poll(), this.constructor.INTERVAL_MS);
    }

    disconnect() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    async poll() {
        // A hidden tab is a tab nobody is losing work in; it will ask again when it comes back.
        // Safe to skip now that this request no longer carries a session - while it did, this
        // early return was the difference between a tab whose login never expired and a tab whose
        // login expired in 24 minutes, which is not a rule anybody wrote.
        if (document.hidden) {
            return;
        }

        let payload;

        try {
            // `credentials: 'omit'` is what keeps this poll out of the session entirely: with no
            // cookie there is nothing for App\EventSubscriber\LocaleSubscriber to open and nothing
            // for the firewall to reload, so a banner check every 60s per tab neither takes the
            // session's exclusive lock nor keeps postponing its expiry. The endpoint is public and
            // deliberately anonymous (App\Controller\DeploymentNoticeController) - it says nothing
            // a visitor cannot already see on the page it sits on.
            //
            // The one thing the cookie carried and this has to replace is the reader's language,
            // hence the parameter: the answer is the banner already rendered, and it must not
            // start speaking French to somebody the page next to it is speaking English to.
            const url = new URL(this.urlValue, window.location.href);
            url.searchParams.set('locale', document.documentElement.lang);

            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'omit' });
            if (!response.ok) {
                return;
            }
            payload = await response.json();
        } catch {
            // The platform going away mid-deploy is the expected case here, not an error worth
            // reporting: the banner already on screen is the right thing to leave on screen.
            return;
        }

        const html = payload.deploying ? payload.html : '';

        if (this.element.innerHTML.trim() !== html.trim()) {
            this.element.innerHTML = html;
        }
    }
}
