import { Controller } from '@hotwired/stimulus';

// Carnet de notes - evaluation form (design/design_handoff_carnet_de_notes, screen 2).
// The only behavior to drive is the visibility for students: the designs present it as an
// Immédiate/Programmée segment, whereas the Symfony form carries a checkbox
// (hasScheduledVisibility) paired with a date-time field. The segment is therefore a pair of display
// radios that copies its state into the checkbox actually submitted and shows or hides the field.
// The rest of the form (type cards, modality/status segments) holds in CSS :has(), with no JS.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['scheduledFields', 'scheduledHint', 'scheduledCheckbox'];

    setVisibility(event) {
        const scheduled = event.target.value === 'scheduled';
        this.scheduledCheckboxTarget.checked = scheduled;
        this.scheduledFieldsTarget.hidden = !scheduled;
        this.scheduledHintTarget.hidden = !scheduled;
    }
}
