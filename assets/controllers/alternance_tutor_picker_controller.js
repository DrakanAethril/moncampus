import { Controller } from '@hotwired/stimulus';

// Copies the tom-select-ajax-picked existing tutor's id (an InternshipTutorLink id - see
// InternshipTutorLinkRepository::searchDistinctTutors()) into InternshipAlternanceType's unmapped
// hidden `existingTutorLinkId` field, which the form's SUBMIT listener resolves back into real
// tutor/entreprise fields server-side.
export default class extends Controller {
    static targets = ['hidden'];

    pick(event) {
        this.hiddenTarget.value = event.target.value;
    }
}
