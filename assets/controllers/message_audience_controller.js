import { Controller } from '@hotwired/stimulus';

// The audienceTypes checkboxes (program/allStudents/allTeachers/allStaff/manual - see
// App\Entity\AudienceTargetable) all exist in the form at once, with no server-side conditional
// rendering: only the "programmes + rôles" panel (for program) and the recipients picker (for
// manual) are meaningful, and only when their own box is ticked. Several may be ticked together,
// so both panels can be open at the same time.
//
// The "types" target scopes the read: those panels contain checkboxes of their own, and a
// document-wide `input[type=checkbox]:checked` would count a picked programme as an audience.
// Same reasoning as assignment_audience_controller.js, generalized to a set of values.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['types', 'programField', 'recipientsField'];

    connect() {
        this.toggle();
    }

    toggle() {
        const checked = Array.from(
            this.typesTarget.querySelectorAll('input[type="checkbox"]:checked'),
            (input) => input.value,
        );

        this.programFieldTarget.classList.toggle('d-none', !checked.includes('program'));
        this.recipientsFieldTarget.classList.toggle('d-none', !checked.includes('manual'));
    }
}
