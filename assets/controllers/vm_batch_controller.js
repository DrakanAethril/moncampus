import { Controller } from '@hotwired/stimulus';

/**
 * Deploying a batch, one pass at a time.
 *
 * A pass rather than the whole thing, because a browser request is what triggers this and
 * twenty-four clones do not fit in one. The server attempts only the outstanding items, so pressing
 * again is safe by construction - and this keeps pressing until nothing is left, which is the
 * behaviour somebody watching a class deploy actually wants.
 *
 * A failure stops the loop rather than retrying for ever: the batch is not atomic, the successes
 * stand, and whatever refused will refuse again until somebody looks at it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['button', 'progress'];

    static values = {
        token: String,
        deployUrl: String,
    };

    async deploy() {
        this.buttonTarget.disabled = true;

        try {
            let remaining = Infinity;

            while (remaining > 0) {
                const answer = await this.#pass();

                if (!answer?.ok) {
                    this.progressTarget.textContent = answer?.message ?? '⚠';
                    return;
                }

                remaining = answer.remaining;
                this.progressTarget.textContent = `${answer.created} / ${answer.attempted} — ${remaining}`;

                if (answer.failed > 0) {
                    // The successes stand; whatever refused will refuse again until somebody looks.
                    break;
                }
            }
        } finally {
            // Reloaded rather than patched: every row's status, VMID and address just moved.
            window.location.reload();
        }
    }

    async #pass() {
        const response = await fetch(this.deployUrlValue, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue },
        });

        if (!response.ok) {
            return null;
        }

        return response.json();
    }
}
