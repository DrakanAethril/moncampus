import { Controller } from '@hotwired/stimulus';

// Annuaire > Utilisateurs - the "Adresses Courrier école" section of
// templates/directory/user_form.html.twig. Row add/remove follows Symfony's documented
// CollectionType "manual prototype" pattern (data-prototype/__name__ on the container), same as
// evaluation_period_group_form_controller.js.
//
// Removing a row means removing it from the DOM: CollectionType reads a row missing from the
// submission as a deletion (allow_delete), so there is no flag to set. Non-administrable addresses
// have no remove button - and the controller puts them back server-side should a hand-crafted
// submission make them disappear anyway.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'rows', 'row', 'empty'];

    connect() {
        // The already-rendered rows are keyed 0..n-1; new rows carry on above that count, so a
        // removal followed by an addition never reuses a key already posted on the same page.
        this.index = this.rowTargets.length;
    }

    addRow() {
        const html = this.rowsTarget.dataset.prototype.replaceAll(this.rowsTarget.dataset.prototypeName, String(this.index));
        this.index += 1;
        this.rowsTarget.insertAdjacentHTML('beforeend', html);

        const addedRow = this.rowTargets[this.rowTargets.length - 1];

        // A student with no address has nothing to designate: the first one created for them
        // becomes the default outright, otherwise they would leave the screen with no sending
        // address.
        if (!this.checkedRadio()) {
            const radio = addedRow.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
        }

        this.refreshEmptyState();
        addedRow.querySelector('input[type="text"]')?.focus();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-email-alias-list-target~="row"]');
        const wasPrimary = row.querySelector('input[type="radio"]')?.checked;
        row.remove();

        // The removed row carried the designation: it moves to the first one left, failing which
        // the submission would name none and the server would decide in the user's stead.
        if (wasPrimary && !this.checkedRadio()) {
            const radio = this.rowsTarget.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
        }

        this.refreshEmptyState();
    }

    checkedRadio() {
        return this.rowsTarget.querySelector('input[type="radio"]:checked');
    }

    refreshEmptyState() {
        const hasRows = this.rowTargets.length > 0;
        this.listTarget.classList.toggle('d-none', !hasRows);
        this.emptyTarget.classList.toggle('d-none', hasRows);
    }
}
