import { Controller } from '@hotwired/stimulus';

// Fills the read-only "Formation (UFA)" field (32a, section 1) straight from the tom-select-ajax
// -picked student's own `formation` payload field (see
// UfaAlternanceController::studentSearch()) - avoids a second round-trip just to know which
// Program the picked student belongs to. Tom Select attaches itself to the wrapped <select> as
// `element.tomselect`, so the full option object (not just its value) is reachable from here.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['formation'];

    pick(event) {
        const tomSelect = event.target.tomselect;
        const option = tomSelect ? tomSelect.options[event.target.value] : null;
        this.formationTarget.value = option ? option.formation : '';
    }
}
