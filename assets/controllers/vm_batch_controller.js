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
 * A failure stops the loop only once there is nothing left but failures. The batch is not atomic,
 * so one machine the hypervisor refused must not end the pass for the twenty-three that were doing
 * fine - which is what stopping on the first `failed` did, and it is why a batch could come back
 * with one refusal and twenty-three machines cloned and never configured.
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

    // How many passes in a row may fail to answer before the loop gives up.
    //
    // Not one. A pass is a POST that talks to a hypervisor and to a machine over SSH, so a single
    // 502, a reload of the worker or one timeout is an ordinary event - and treating it as the end
    // of the deployment left the class exactly where the batch screen shows it stuck: a machine
    // cloned, never configured, and nothing pressing again. The retries are spaced by the same
    // idle pause as anything else.
    static MAX_FAILED_PASSES = 3;

    // ~15 minutes of nothing moving at all, counted in passes of IDLE_PAUSE_MS. It has to outlast a
    // clone, not a boot: the hypervisor copies a template's disk in its own time, and a batch that
    // is abandoned mid-clone leaves machines cloned but never configured - no address, no account -
    // which is exactly what they look like when nobody notices for a week. Any pass that moves
    // something resets the count, so this is only ever reached by a batch that is genuinely stuck.
    static MAX_IDLE_PASSES = 180;

    async deploy() {
        this.buttonTarget.disabled = true;

        try {
            let remaining = Infinity;
            let blocked = 0;
            let idlePasses = 0;
            let failedPasses = 0;

            while (remaining > blocked) {
                const answer = await this.#pass();

                if (!answer?.ok) {
                    // A refusal the server states (`ok: false` with a message) is final - it is a
                    // batch that names no host, and pressing again cannot fix that. A pass that did
                    // not answer at all is not: retry it.
                    if (answer?.message || ++failedPasses >= this.constructor.MAX_FAILED_PASSES) {
                        this.progressTarget.textContent = answer?.message ?? this.labelsValue.stalled ?? '⚠';
                        return;
                    }

                    await new Promise((resolve) => setTimeout(resolve, this.constructor.IDLE_PAUSE_MS));
                    continue;
                }

                failedPasses = 0;

                remaining = answer.remaining;
                blocked = answer.blocked ?? 0;
                this.progressTarget.textContent = this.#progressLabel(answer);

                if (remaining === 0 || remaining <= blocked) {
                    // Either everything is done, or everything still outstanding has refused. The
                    // successes stand, and what refused will refuse again until somebody looks.
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
