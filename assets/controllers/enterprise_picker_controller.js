import { Controller } from '@hotwired/stimulus';

// Shows the inline "new enterprise" fields only while the existing-enterprise dropdown is left
// on its placeholder ("create new") option - keeps InternshipTutorLinkType's two mutually
// exclusive paths (pick an existing Enterprise vs create one inline) on a single page/submit.
export default class extends Controller {
    static targets = ['select', 'newFields'];

    connect() {
        this.toggle();
    }

    toggle() {
        this.newFieldsTarget.classList.toggle('d-none', this.selectTarget.value !== '');
    }
}
