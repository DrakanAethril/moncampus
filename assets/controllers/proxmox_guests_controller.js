import { Controller } from '@hotwired/stimulus';

/**
 * The four power actions of the machines list, and the polling that follows them.
 *
 * Polling rather than a queue, and not by preference: this repository's Messenger has no worker,
 * so routing a long operation to `async` would mean never processing it. Proxmox hands back a task
 * id and expects to be asked - so the row asks, every two seconds, and gives up after five minutes
 * rather than polling a dead host for ever.
 *
 * « Forcer » confirms and « Arrêter » does not, deliberately: one is a request the guest can honour
 * and the other is a power cut.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row', 'actions', 'pending'];

    static values = {
        token: String,
        confirmStop: String,
        intervalMs: { type: Number, default: 2000 },
        ceilingMs: { type: Number, default: 300000 },
    };

    connect() {
        // Rows that already carried an operation when the page was rendered keep being followed:
        // reloading the screen must not orphan a task somebody is waiting on.
        this.pendingTargets.forEach((element) => this.#follow(element, element.dataset.statusUrl));
    }

    async run(event) {
        const button = event.currentTarget;
        const cell = button.closest('[data-proxmox-guests-target="actions"]');

        if (button.dataset.confirm && !window.confirm(this.confirmStopValue)) {
            return;
        }

        const previous = cell.innerHTML;
        cell.textContent = '…';

        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue },
            });

            if (!response.ok) {
                throw new Error(`Unexpected response status: ${response.status}`);
            }

            const answer = await response.json();

            if (!answer.ok) {
                // A refusal is an answer, not a broken request: the perimeter, a lock held by
                // another administrator, or the hypervisor's own words.
                cell.innerHTML = previous;
                this.#say(cell, answer.message, 'var(--cm-action-danger)');
                return;
            }

            this.#follow(cell, answer.statusUrl);
        } catch {
            cell.innerHTML = previous;
        }
    }

    #follow(element, statusUrl) {
        if (!statusUrl) {
            return;
        }

        const startedAt = Date.now();

        const tick = async () => {
            if (Date.now() - startedAt > this.ceilingMsValue) {
                this.#say(element, '', 'var(--cm-muted)');
                return;
            }

            try {
                const answer = await (await fetch(statusUrl)).json();

                if (answer.settled) {
                    // The list is re-read rather than patched: the machine's state, its memory and
                    // its uptime all moved, and only the hypervisor knows what they are now.
                    window.location.reload();
                    return;
                }

                element.textContent = answer.label;
            } catch {
                // A failed poll is not a verdict - the server decides when silence becomes
                // "unknown", and it decides on elapsed time rather than on one lost request.
            }

            window.setTimeout(tick, this.intervalMsValue);
        };

        element.textContent = '…';
        window.setTimeout(tick, this.intervalMsValue);
    }

    #say(element, message, color) {
        const note = document.createElement('div');
        note.className = 'cm-guest__sub';
        note.style.color = color;
        note.textContent = message;
        element.appendChild(note);
    }
}
