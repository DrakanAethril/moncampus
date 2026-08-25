import { Controller } from '@hotwired/stimulus';

/**
 * Says whether a typed login is free, while it is being typed.
 *
 * Against both sources at once, server-side - App\Service\LoginGenerator::loginTaken() reads
 * User::$username and ldap_manage_user.login - because a login reserved by a creation that never
 * went through is taken every bit as much as one somebody carries.
 *
 * It is advisory and nothing more: the POST re-runs every one of these checks
 * (App\Service\LdapAccountRequestService), because between typing and validating a login may well
 * have been taken. What it buys is that the administrator learns it before writing the rest of the
 * form, not after.
 *
 * Debounced at 300 ms, and every answer carries the login it is about: a slow reply for "cder" must
 * never overwrite a fresh one for "cderoux".
 */
export default class extends Controller {
    static targets = ['input', 'hint'];
    static values = { url: String, suggestion: String, labels: Object };

    static DEBOUNCE_MS = 300;

    connect() {
        this.pending = null;
    }

    disconnect() {
        this.cancel();
    }

    cancel() {
        if (this.pending) {
            window.clearTimeout(this.pending);
            this.pending = null;
        }
    }

    useSuggestion() {
        this.inputTarget.value = this.suggestionValue;
        this.inputTarget.focus();
        this.check();
    }

    check() {
        this.cancel();
        this.render('', null);
        this.pending = window.setTimeout(() => this.ask(this.inputTarget.value.trim()), this.constructor.DEBOUNCE_MS);
    }

    async ask(typed) {
        if (typed === '') {
            this.render('', null);
            return;
        }

        let payload;
        try {
            const response = await fetch(`${this.urlValue}?login=${encodeURIComponent(typed)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            payload = await response.json();
        } catch {
            // Silence rather than a wrong answer: the submission checks the same thing anyway.
            return;
        }

        // Stimulus Object values re-parse on every access - read once, as the repository's own
        // gotcha list says.
        const labels = this.labelsValue;
        this.render(labels[payload.state] ?? '', payload.state);
    }

    render(message, state) {
        this.hintTarget.textContent = message;
        this.hintTarget.dataset.state = state ?? '';
    }
}
