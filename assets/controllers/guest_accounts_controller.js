import { Controller } from '@hotwired/stimulus';

/**
 * The accounts of one machine: apply the difference, declare a fixed account, remove one, keep one,
 * reset a password.
 *
 * The passwords are the reason this is a controller rather than a set of forms. They come back
 * **once** - nothing stores them, and there is no endpoint that could hand them over again - so
 * they are written into the page and the page is what gets printed or read out. A reload loses
 * them for good, which is why the warning sits above the button rather than after it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['passwords', 'passwordTable', 'newLogin'];

    static values = {
        token: String,
        applyUrl: String,
        declareUrl: String,
        removeUrl: String,
        keepUrl: String,
        resetUrl: String,
        removeConfirm: String,
        passwordTitle: String,
        passwordWarning: String,
    };

    async apply() {
        const answer = await this.#post(this.applyUrlValue, {});

        if (!answer?.ok) {
            return;
        }

        this.#showPasswords(answer.passwords ?? {});
    }

    async declare() {
        const login = this.newLoginTarget.value.trim();

        if (login === '') {
            return;
        }

        const answer = await this.#post(this.declareUrlValue, { login });

        if (answer?.ok) {
            window.location.reload();
        }
    }

    async remove(event) {
        if (!window.confirm(this.removeConfirmValue)) {
            return;
        }

        const answer = await this.#post(this.removeUrlValue, { login: event.currentTarget.dataset.login });

        if (answer?.ok) {
            window.location.reload();
        }
    }

    async keep(event) {
        const answer = await this.#post(this.keepUrlValue, { login: event.currentTarget.dataset.login });

        if (answer?.ok) {
            window.location.reload();
        }
    }

    async reset(event) {
        const answer = await this.#post(this.resetUrlValue, { login: event.currentTarget.dataset.login });

        if (!answer?.ok) {
            return;
        }

        // Shown in place rather than reloading: a reload would lose the one copy of it.
        this.#showPasswords({ [answer.login]: answer.password });
    }

    #showPasswords(passwords) {
        const entries = Object.entries(passwords);

        if (entries.length === 0) {
            return;
        }

        this.passwordTableTarget.innerHTML = '';

        for (const [login, password] of entries) {
            const row = this.passwordTableTarget.insertRow();
            const loginCell = row.insertCell();
            const passwordCell = row.insertCell();

            loginCell.className = 'cm-mono';
            loginCell.textContent = login;
            passwordCell.className = 'cm-mono';
            passwordCell.style.fontWeight = '600';
            passwordCell.textContent = password;
        }

        this.passwordsTarget.hidden = false;
        this.passwordsTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async #post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                throw new Error(`Unexpected response status: ${response.status}`);
            }

            const answer = await response.json();

            if (!answer.ok) {
                window.alert(answer.message);
            }

            return answer;
        } catch {
            return null;
        }
    }
}
