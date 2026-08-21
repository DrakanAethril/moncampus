import { Controller } from '@hotwired/stimulus';

/**
 * « Mes machines virtuelles » - the three things somebody may do to a machine they hold an account
 * on: start it, shut it down, choose a password on it.
 *
 * Everything answers JSON and the message lands beside the machine it concerns rather than at the
 * top of the page: a student may have several, and « Échec » with no machine named is a sentence
 * that helps nobody.
 *
 * The password field is emptied whatever happens - on success because it has been used, on failure
 * because retyping it is the shortest way to be sure of what is being sent. It is never read back
 * out of the DOM afterwards, and nothing here logs it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['message'];

    static values = {
        token: String,
        workingMessage: String,
        failedMessage: String,
    };

    async power(event) {
        const button = event.currentTarget;
        const accountId = button.dataset.account;

        this.#say(accountId, this.workingMessageValue);
        button.disabled = true;

        const answer = await this.#post(button.dataset.url);

        if (!answer?.ok) {
            this.#say(accountId, answer?.message ?? this.failedMessageValue, true);
            button.disabled = false;
            return;
        }

        // Reloaded rather than patched: the machine's state, and therefore which of the two buttons
        // is available, is the whole of what this page shows.
        window.location.reload();
    }

    async changePassword(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const field = form.querySelector('input[name="password"]');
        const accountId = form.closest('.card').querySelector('[data-my-machines-target="message"]').dataset.account;

        this.#say(accountId, this.workingMessageValue);

        const answer = await this.#post(form.dataset.url, { password: field.value });

        field.value = '';
        this.#say(accountId, answer?.message ?? this.failedMessageValue, !answer?.ok);
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

    #say(accountId, message, isFailure = false) {
        const target = this.messageTargets.find((element) => element.dataset.account === accountId);

        if (target) {
            target.textContent = message;
            target.style.color = isFailure ? 'var(--cm-action-danger)' : 'var(--cm-muted)';
        }
    }
}
