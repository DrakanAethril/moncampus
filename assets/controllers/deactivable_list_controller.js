import { Controller } from '@hotwired/stimulus';

// A fully server-rendered list that can be reordered by drag-and-drop: this handles the two bits
// sortable_reorder_controller.js does not, a client-side "show inactive" filter and deactivate.
//
// Not DataTables-backed, and it cannot be: drag-and-drop needs every row in the DOM at once, and
// binding a Stimulus controller to a subtree DataTables rewraps is the recurring bug this codebase
// warns about. Server-side paging buys nothing on lists of this size anyway.
//
// This is the generic version of group_type_list_controller.js and
// evaluation_period_group_list_controller.js, which predate it and do exactly the same thing under
// their own names; migrate them here when one of those screens is next touched.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row', 'includeInactive'];

    static values = {
        deactivateUrlTemplate: String,
        token: String,
        deactivateConfirmMessage: String,
        errorMessage: String,
    };

    toggleInactive() {
        const showInactive = this.includeInactiveTarget.checked;

        for (const row of this.rowTargets) {
            if (row.dataset.inactive === 'true') {
                row.hidden = !showInactive;
            }
        }
    }

    deactivate(event) {
        if (!window.confirm(this.deactivateConfirmMessageValue)) {
            return;
        }

        fetch(this.deactivateUrlTemplateValue.replace('__ID__', event.currentTarget.dataset.id), {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                window.location.reload();
            })
            .catch(() => window.alert(this.errorMessageValue));
    }
}
