import { Controller } from '@hotwired/stimulus';

/**
 * Deploying a batch, one pass at a time.
 *
 * A pass rather than the whole thing, because a browser request is what triggers this and
 * twenty-four clones do not fit in one. The server advances each outstanding item by one step, so
 * pressing again is safe by construction - and this keeps pressing until nothing is left, which is
 * the behaviour somebody watching a class deploy actually wants.
 *
 * The loop has to tolerate *waiting*, which is most of what a deployment does: Proxmox finishes a
 * clone in its own time, and a machine that has just been started needs a minute before it answers
 * on SSH. A pass that moved nothing is therefore normal, not a stall - so the loop pauses instead of
 * hammering the server, and only gives up once nothing has moved for long enough that a person
 * should look at it.
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
        labels: Object,
    };

    // Long enough that a booting machine gets somewhere between two polls, short enough that the
    // screen still feels alive.
    static IDLE_PAUSE_MS = 5000;

    // ~5 minutes of nothing moving at all. A machine that has not answered by then is not slow.
    static MAX_IDLE_PASSES = 60;

    async deploy() {
        this.buttonTarget.disabled = true;

        try {
            let remaining = Infinity;
            let idlePasses = 0;

            while (remaining > 0) {
                const answer = await this.#pass();

                if (!answer?.ok) {
                    this.progressTarget.textContent = answer?.message ?? '⚠';
                    return;
                }

                remaining = answer.remaining;
                this.progressTarget.textContent = this.#progressLabel(answer);

                if (answer.failed > 0 || remaining === 0) {
                    // The successes stand; whatever refused will refuse again until somebody looks.
                    break;
                }

                if (answer.progressed > 0) {
                    idlePasses = 0;
                    continue;
                }

                if (++idlePasses >= this.constructor.MAX_IDLE_PASSES) {
                    this.progressTarget.textContent = this.labelsValue.stalled ?? '⚠';
                    break;
                }

                await new Promise((resolve) => setTimeout(resolve, this.constructor.IDLE_PAUSE_MS));
            }
        } finally {
            // Reloaded rather than patched: every row's status, VMID and address just moved.
            window.location.reload();
        }
    }

    #progressLabel(answer) {
        const template = answer.waiting > 0 ? this.labelsValue.waiting : this.labelsValue.progress;

        return (template ?? '%remaining%')
            .replace('%remaining%', answer.remaining)
            .replace('%waiting%', answer.waiting)
            .replace('%progressed%', answer.progressed);
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
