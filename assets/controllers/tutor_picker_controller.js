import { Controller } from '@hotwired/stimulus';

// Toggles InternshipAlternanceType's "Tuteur existant" / "Nouveau tuteur" radio segmented control
// (32a/32b) between the existing-tutor ajax search panel and the 4 plain new-tutor fields - same
// mutually-exclusive-panel idea as enterprise_picker_controller.js, driven by a radio group
// instead of a placeholder-select since the mockup uses a segmented toggle here, not a dropdown.
export default class extends Controller {
    static targets = ['mode', 'existingPanel', 'newPanel'];

    connect() {
        this.toggle();
    }

    toggle() {
        const isExisting = this.modeTargets.find((radio) => radio.checked)?.value !== 'new';
        this.existingPanelTarget.classList.toggle('d-none', !isExisting);
        this.newPanelTarget.classList.toggle('d-none', isExisting);
    }
}
