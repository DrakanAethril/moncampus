import { Controller } from '@hotwired/stimulus';

/**
 * The e-CO course form's two dependent blocks (screen 1g, and the edit screen):
 *
 * - the flag picker and the order choice, only for « Balises spécifiques »;
 * - the time allowance, only for a race not run in order - Ordre libre, Course au score, and
 *   « Balises spécifiques » in the order of one's choice. A race run in order is ranked on time.
 *
 * Two radio groups decide the second block, which is why radio_reveal (one group) is not enough.
 * Purely an affordance, like radio_reveal: the blocks stay in the DOM and EcoCourseType drops on
 * submit whatever the chosen mode does not use.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['mode', 'order', 'specificPanel', 'timePanel'];

    connect() {
        this.refresh();
    }

    refresh() {
        const mode = this.modeTargets.find((radio) => radio.checked)?.value;
        const ordered = this.orderTargets.find((radio) => radio.checked)?.value !== '0';
        const specific = 'specific_checkpoints' === mode;
        const timed = ('free_order' === mode || 'score' === mode) || (specific && !ordered);

        this.specificPanelTargets.forEach((panel) => panel.classList.toggle('d-none', !specific));
        this.timePanelTargets.forEach((panel) => panel.classList.toggle('d-none', !timed));
    }
}
