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
        autoRefresh: Boolean,
    };

    // Long enough that a booting machine gets somewhere between two polls, short enough that the
    // screen still feels alive.
    static IDLE_PAUSE_MS = 5000;

    // How often a page showing a deployment in progress brings its view up to date.
    //
    // The work is done on the server - a pass advances a machine whether or not anybody is looking
    // at this page - so this is a *view* refreshing, not a loop driving anything. A tab that is only
    // watching may simply reload, having nothing to lose; the tab that is driving the deployment
    // swaps the card in place instead, on the same beat.
    static REFRESH_MS = 5000;

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

    connect() {
        this.#scheduleRefresh();
    }

    disconnect() {
        this.#cancelRefresh();
        this.#stopLiveRefresh();
    }

    async deploy() {
        // This tab is about to drive the deployment itself, so it must not reload: a reload landing
        // in the middle would abandon the loop exactly where the batch screen used to be found
        // stuck. The view still has to move - a deployment nobody can see advance reads as a frozen
        // screen - so the card refreshes *in place* instead, which the loop survives.
        this.#cancelRefresh();
        this.#startLiveRefresh();
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
                this.lastProgressLabel = this.#progressLabel(answer);
                this.progressTarget.textContent = this.lastProgressLabel;

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
            this.#stopLiveRefresh();
            // Reloaded rather than patched: every row's status, VMID and address just moved.
            window.location.reload();
        }
    }

    #scheduleRefresh() {
        if (!this.autoRefreshValue) {
            return;
        }

        this.refreshTimer = window.setTimeout(() => window.location.reload(), this.constructor.REFRESH_MS);
    }

    #cancelRefresh() {
        if (this.refreshTimer) {
            window.clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    /**
     * The same view refresh as `#scheduleRefresh()`, for the tab that is driving the deployment.
     *
     * It cannot reload - that would kill the loop pressing the server - so it re-fetches this very
     * page and swaps the card's contents. What the server renders is the whole truth of the batch:
     * the status badges, every row's VMID and address, and the install log of the machine being
     * worked on. Only the element carrying the controller survives, which is what keeps the loop
     * and its `this` alive across a swap.
     */
    #startLiveRefresh() {
        if (this.liveTimer) {
            return;
        }

        this.liveTimer = window.setInterval(() => this.#refreshInPlace(), this.constructor.REFRESH_MS);
    }

    #stopLiveRefresh() {
        if (this.liveTimer) {
            window.clearInterval(this.liveTimer);
            this.liveTimer = null;
        }
    }

    async #refreshInPlace() {
        // A pass can take longer than the refresh interval, and so can this fetch: without the
        // guard two answers could land out of order and show a state older than the one on screen.
        if (this.refreshing) {
            return;
        }

        this.refreshing = true;

        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html' } });

            if (!response.ok) {
                return;
            }

            const fresh = new DOMParser()
                .parseFromString(await response.text(), 'text/html')
                .querySelector('[data-controller~="vm-batch"]');

            if (!fresh) {
                return;
            }

            this.element.innerHTML = fresh.innerHTML;

            // The swap brought back a fresh button and a server-rendered count. This tab is still
            // deploying, so both have to say so again.
            if (this.hasButtonTarget) {
                this.buttonTarget.disabled = true;
            }

            if (this.lastProgressLabel && this.hasProgressTarget) {
                this.progressTarget.textContent = this.lastProgressLabel;
            }
        } catch {
            // A refresh that fails is a refresh missed, nothing more - the next one is in five
            // seconds, and the deployment itself is not driven from here.
        } finally {
            this.refreshing = false;
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
