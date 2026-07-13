import { Controller } from '@hotwired/stimulus';

// MessageComposeType's audienceType radios (programStudents/programTeachers/schoolWide/manual -
// see App\Form\MessageComposeType) all exist in the form at once (no server-side conditional
// rendering) - only "program" (for the two program-scoped types) or "recipients" (for manual) is
// actually meaningful for the chosen audience. Same reasoning as assignment_audience_controller.js,
// generalized to more than one value sharing a target.
export default class extends Controller {
    static targets = ['programField', 'recipientsField'];

    connect() {
        this.toggle();
    }

    toggle() {
        const value = this.element.querySelector('input[type="radio"]:checked')?.value;
        this.programFieldTarget.classList.toggle('d-none', !['program_students', 'program_teachers'].includes(value));
        this.recipientsFieldTarget.classList.toggle('d-none', value !== 'manual');
    }
}
