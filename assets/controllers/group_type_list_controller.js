import { Controller } from '@hotwired/stimulus';

// Settings > Groupes > Types de groupe (/settings/groups/types) - a plain, fully server-rendered
// list (not DataTables-backed, since drag-and-drop reordering via sortable_reorder_controller.js
// needs every row present in the DOM at once - see App\Repository\GroupTypeRepository::findAllOrdered()).
// Handles only the two bits sortable-reorder doesn't: a client-side "show inactive" filter and
// deactivate, via the same confirm+fetch+CSRF idiom as evaluation_period_group_list_controller.js.
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
