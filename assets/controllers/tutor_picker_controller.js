import { Controller } from '@hotwired/stimulus';

// Toggles InternshipAlternanceType's "Tuteur existant" / "Nouveau tuteur" radio segmented control
// (32a/32b) between the existing-tutor ajax search panel and the 4 plain new-tutor fields - same
// mutually-exclusive-panel idea as enterprise_picker_controller.js, driven by a radio group
// instead of a placeholder-select since the mockup uses a segmented toggle here, not a dropdown.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['mode', 'existingPanel', 'newPanel'];

    connect() {
        this.toggle();
    }

    // A third mode, 'none', exists on the optional secondary tutor's block: both panels fold away.
    toggle() {
        const mode = this.modeTargets.find((radio) => radio.checked)?.value ?? 'existing';
        this.existingPanelTarget.classList.toggle('d-none', mode !== 'existing');
        this.newPanelTarget.classList.toggle('d-none', mode !== 'new');
    }
}
