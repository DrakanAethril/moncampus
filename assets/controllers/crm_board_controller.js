import { Controller } from '@hotwired/stimulus';

/**
 * A formation's board: the situation groups of a table fold and unfold (the waiting and closed
 * ones start folded), and the journal of a dossier shows its entries or not.
 */
export default class extends Controller {
    toggle(event) {
        const button = event.currentTarget;
        const row = button.closest('tr');
        const group = row.dataset.group;
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        row.classList.toggle('is-closed', !open);
        row.closest('tbody').querySelectorAll(`tr[data-in-group="${group}"]`).forEach((line) => {
            line.classList.toggle('is-hidden', !open);
        });
    }

    entries(event) {
        this.element.querySelector('.cm-crm-journal')?.classList.toggle('is-all', event.currentTarget.checked);
    }
}
