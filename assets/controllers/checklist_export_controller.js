import { Controller } from '@hotwired/stimulus';

// The « Liste pour pointage » modal: as many free-text column names as the sheet can hold, added and
// removed one row at a time.
//
// The first row is server-rendered and cloned here, so the modal still submits one column with no JS
// at all. Nothing is stored: the form is a GET, and what the person typed leaves as the query string
// App\Service\ChecklistSheetOptions reads back.
//
// Removing the last remaining row empties it rather than deleting it - a modal with no field left
// and an « Ajouter » button as its only content reads as broken, and a blank name is dropped server
// side anyway.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['rows', 'row', 'addButton'];

    static values = { maxColumns: Number };

    connect() {
        this.refreshAddButton();
    }

    addRow() {
        if (this.rowTargets.length >= this.maxColumnsValue) {
            return;
        }

        const row = this.rowTargets[this.rowTargets.length - 1].cloneNode(true);
        const field = row.querySelector('input');
        field.value = '';
        this.rowsTarget.append(row);
        field.focus();

        this.refreshAddButton();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-checklist-export-target="row"]');

        if (this.rowTargets.length > 1) {
            row.remove();
        } else {
            row.querySelector('input').value = '';
        }

        this.refreshAddButton();
    }

    // Bootstrap's .d-flex and friends override the plain hidden attribute, so the button is disabled
    // rather than hidden - it also says why nothing more can be added.
    refreshAddButton() {
        this.addButtonTarget.disabled = this.rowTargets.length >= this.maxColumnsValue;
    }
}
