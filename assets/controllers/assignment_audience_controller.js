import { Controller } from '@hotwired/stimulus';

// Assignment's audienceType radios (program/option/manual - see App\Form\AssignmentType) all
// exist in the form at once (no server-side conditional rendering), but only one of the
// option/manualRecipients fields is actually meaningful for the chosen audience - show only the
// relevant one so the form isn't confusing about which fields matter.
export default class extends Controller {
    static targets = ['optionField', 'recipientsField'];

    connect() {
        this.toggle();
    }

    toggle() {
        const value = this.element.querySelector('input[type="radio"]:checked')?.value;
        this.optionFieldTarget.classList.toggle('d-none', value !== 'option');
        this.recipientsFieldTarget.classList.toggle('d-none', value !== 'manual');
    }
}
