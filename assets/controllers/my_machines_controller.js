import { Controller } from '@hotwired/stimulus';

/**
 * « Mes machines virtuelles » - the three things somebody may do to a machine they hold an account
 * on: start it, shut it down, choose a password on it.
 *
 * Everything answers JSON and the message lands in the footer of the card it concerns rather than
 * at the top of the page: a student may have several, and « Échec » with no machine named is a
 * sentence that helps nobody.
 *
 * The password field is emptied whatever happens - on success because it has been used, on failure
 * because retyping it is the shortest way to be sure of what is being sent. It is never read back
 * out of the DOM afterwards, and nothing here logs it.
 *
 * A machine on its way from one state to the other is the one thing this page cannot resolve on its
 * own, so while any card is in that state the page re-reads itself. It stops as soon as the server
 * stops saying so, which is what keeps a screen left open overnight from polling for ever.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['card', 'message', 'password', 'submit'];

    static values = {
        token: String,
        workingMessage: String,
        failedMessage: String,
        minLength: { type: Number, default: 12 },
        autoRefresh: Boolean,
    };

    // Long enough that a machine gets somewhere between two reads, short enough that the card does
    // not sit on « Démarrage… » after the machine is up.
    static REFRESH_MS = 5000;

    // The reference draws the confirmation with a tick in front of it; failures and waits carry no
    // icon, so it is added here rather than sitting in the markup.
    static TICK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"></path></svg>';

    connect() {
        if (this.autoRefreshValue) {
            this.refreshTimer = window.setTimeout(() => window.location.reload(), this.constructor.REFRESH_MS);
        }
    }

    disconnect() {
        if (this.refreshTimer) {
            window.clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    /** The twelve-character rule, enforced where it is announced: on the field's own button. */
    validate(event) {
        const field = event.currentTarget;
        const button = field.closest('form').querySelector('[data-my-machines-target="submit"]');

        button.disabled = field.value.length < this.minLengthValue;
    }

    async power(event) {
        const button = event.currentTarget;
        const accountId = button.dataset.account;

        this.#say(accountId, this.workingMessageValue, 'wait');
        button.disabled = true;

        const answer = await this.#post(button.dataset.url);

        if (!answer?.ok) {
            this.#say(accountId, answer?.message ?? this.failedMessageValue, 'ko');
            button.disabled = false;

            return;
        }

        // Reloaded rather than patched: the machine's state, and therefore its pill, its buttons
        // and whether a password may be set on it at all, is the whole of what this page shows.
        window.location.reload();
    }

    async changePassword(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const field = form.querySelector('input[name="password"]');
        const button = form.querySelector('[data-my-machines-target="submit"]');
        const accountId = form.closest('[data-my-machines-target="card"]').dataset.account;

        this.#say(accountId, this.workingMessageValue, 'wait');
        button.disabled = true;

        const answer = await this.#post(form.dataset.url, { password: field.value });

        field.value = '';
        // Back to disabled with the field: the rule is about what is in the box, and the box is
        // empty again.
        button.disabled = true;
        this.#say(accountId, answer?.message ?? this.failedMessageValue, answer?.ok ? 'ok' : 'ko');
    }

    async #post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, 'Content-Type': 'application/json' },
                body: JSON.stringify(body ?? {}),
            });

            return response.ok ? response.json() : null;
        } catch {
            return null;
        }
    }

    #say(accountId, message, kind) {
        const target = this.messageTargets.find((element) => element.dataset.account === accountId);

        if (!target) {
            return;
        }

        target.className = `cm-vmcard__note${'ok' === kind ? '' : ` cm-vmcard__note--${kind}`}`;
        // textContent for the message and innerHTML only for the tick, so nothing the server says
        // is ever parsed as markup.
        target.textContent = message;

        if ('ok' === kind) {
            target.insertAdjacentHTML('afterbegin', this.constructor.TICK);
        }
    }
}
