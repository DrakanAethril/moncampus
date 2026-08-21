import { Controller } from '@hotwired/stimulus';

/**
 * What a row of the machines list offers, and the polling that follows a power action.
 *
 * Everything a row can do now hangs off one select - « Comptes » included, which navigates rather
 * than posting. The select is the input, so it opens on a placeholder and returns to it as soon as
 * something has been picked: an action is chosen once, and a field left showing « Arrêter » would
 * read as a state the machine is in.
 *
 * Polling rather than a queue, and not by preference: this repository's Messenger has no worker,
 * so routing a long operation to `async` would mean never processing it. Proxmox hands back a task
 * id and expects to be asked - so the row asks, every two seconds, and gives up after five minutes
 * rather than polling a dead host for ever.
 *
 * « Forcer » still confirms and « Arrêter » still does not: one is a request the guest can honour
 * and the other is a power cut. Sharing a list with the others is what the confirmation now has to
 * carry on its own, which is also why the two are never neighbours in it.
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
        const select = event.currentTarget;
        const option = select.selectedOptions[0];
        const url = option?.value;

        if (!url) {
            return;
        }

        // Put back before anything else, and before any dialog: whatever happens next - a refusal,
        // a confirmation declined, a navigation the reader comes back from - the field must be
        // showing its placeholder again rather than the last thing that was picked.
        select.selectedIndex = 0;

        if ('navigate' === option.dataset.method) {
            window.location.assign(url);

            return;
        }

        if (option.dataset.confirm && !window.confirm(this.confirmStopValue)) {
            return;
        }

        const cell = select.closest('[data-proxmox-guests-target="actions"]');
        const note = cell.querySelector('[data-proxmox-guests-target="pending"]');

        // Disabled rather than removed, because a refusal has to hand them back. Only the posting
        // entries: « Comptes » stays reachable while the hypervisor works, which is the whole
        // reason the note sits under the select instead of replacing it.
        const posts = [...select.querySelectorAll('option[data-method="post"]')];
        posts.forEach((entry) => { entry.disabled = true; });
        // Cleared as well as rewritten: a previous refusal left this line red, and a wait inherits
        // the colour of the thing it is not.
        note.style.color = '';
        note.textContent = '…';

        try {
            const response = await fetch(url, {
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
                posts.forEach((entry) => { entry.disabled = false; });
                this.#say(note, answer.message, 'var(--cm-action-danger)');

                return;
            }

            this.#follow(note, answer.statusUrl);
        } catch {
            posts.forEach((entry) => { entry.disabled = false; });
            note.textContent = '';
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

    /** The note under the select is the one place a row speaks, so this writes into it. */
    #say(element, message, color) {
        element.style.color = color;
        element.textContent = message;
    }
}
