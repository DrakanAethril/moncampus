import { Controller } from '@hotwired/stimulus';

/**
 * The creation form of a Gestion > Matériel type: « Suivi à l'unité » decides what the quantity
 * field means. Ticked, it is « Nombre d'exemplaires » - one row and one label code per piece, capped
 * at the batch maximum; unticked, it is « Quantité initiale ».
 *
 * Only the wording and the browser's `max` move here. The server reads the box, not the label, and
 * enforces the cap itself (App\Form\EquipmentTypeType).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'label', 'help', 'quantity'];
    static values = { unitLabel: String, quantityLabel: String, unitHelp: String, quantityHelp: String, max: Number };

    connect() {
        this.refresh();
    }

    refresh() {
        const unit = this.toggleTarget.checked;

        this.labelTarget.textContent = unit ? this.unitLabelValue : this.quantityLabelValue;
        if (this.hasHelpTarget) {
            this.helpTarget.textContent = unit ? this.unitHelpValue : this.quantityHelpValue;
        }
        if (unit) {
            this.quantityTarget.setAttribute('max', String(this.maxValue));
        } else {
            this.quantityTarget.removeAttribute('max');
        }
    }
}
