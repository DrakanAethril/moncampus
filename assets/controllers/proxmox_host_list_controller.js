import { Controller } from '@hotwired/stimulus';

/**
 * The host cards: re-test one, and deactivate or bring one back.
 *
 * Re-testing writes down what it found, so the badge and the three counters move here and only
 * here - nothing on this page probes anything while it renders, which is why the card says how
 * long ago it was tested rather than pretending to report the state right now.
 *
 * There is no delete action, and there is nowhere to add one: the application stops machines and
 * deactivates hosts, it never destroys anything.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['card'];

    static values = {
        token: String,
    };

    async check(event) {
        const card = event.target.closest('[data-proxmox-host-list-target="card"]');
        const feedback = card.querySelector('[data-proxmox-host-list-target="feedback"]');

        event.target.disabled = true;
        feedback.hidden = false;
        feedback.textContent = '…';

        try {
            const answer = await this.#post(event.target.dataset.url);

            feedback.textContent = [answer.message, ...(answer.warnings ?? [])].join(' · ');
            feedback.style.color = answer.ok ? 'var(--cm-positive-tx)' : 'var(--cm-action-danger)';

            // The counters are part of the recorded check, so refreshing them here keeps the card
            // consistent with what was just written - and a full reload would lose the message.
            this.#setText(card, 'nodeCount', answer.nodeCount);
            this.#setText(card, 'guestCount', answer.guestCount);
            this.#setText(card, 'runningCount', answer.runningCount);
        } catch {
            feedback.textContent = '⚠';
            feedback.style.color = 'var(--cm-action-danger)';
        } finally {
            event.target.disabled = false;
        }
    }

    async toggleActive(event) {
        if (!window.confirm(event.target.dataset.confirm)) {
            return;
        }

        try {
            await this.#post(event.target.dataset.url);
            window.location.reload();
        } catch {
            event.target.disabled = true;
        }
    }

    async #post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue },
        });

        if (!response.ok) {
            throw new Error(`Unexpected response status: ${response.status}`);
        }

        return response.json();
    }

    #setText(card, target, value) {
        const element = card.querySelector(`[data-proxmox-host-list-target="${target}"]`);

        if (element && value !== null && value !== undefined) {
            element.textContent = value;
        }
    }
}
