import { Controller } from '@hotwired/stimulus';

// Client-side show/hide filter for the dashboards (design_handoff_dashboards etu-e "n'afficher
// qu'une formation", staff-a "Toutes les formations"): a <select> picks a key, every element
// carrying data-dashboard-filter-target="item" data-filter-key="..." is hidden unless its key
// matches (empty selection shows everything). Purely visual - the server always renders all rows.
export default class extends Controller {
    static targets = ['item', 'select'];

    filter() {
        const selected = this.selectTarget.value;

        // A class (not the hidden attribute): an item carrying an inline display would win over
        // the UA stylesheet's [hidden] rule - .cm-filter-hidden uses !important instead.
        this.itemTargets.forEach((item) => {
            item.classList.toggle('cm-filter-hidden', selected !== '' && item.dataset.filterKey !== selected);
        });
    }
}
