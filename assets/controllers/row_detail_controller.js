import { Controller } from '@hotwired/stimulus';

/**
 * Unfolds the detail row under a table row - the follow-up of a learning path, where a person's line
 * opens onto every step they opened and every attempt they made.
 *
 * A table cannot hold a <details> around two rows, hence this: the button names the detail row it
 * opens, and says whether it is open.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['detail'];

    toggle(event) {
        const id = String(event.params.id);
        const detail = this.detailTargets.find((row) => row.dataset.rowDetailId === id);

        if (!detail) {
            return;
        }

        detail.hidden = !detail.hidden;
        event.currentTarget.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true');
    }
}
