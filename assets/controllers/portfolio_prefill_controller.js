import { Controller } from '@hotwired/stimulus';

/**
 * « Pré-remplir depuis mon alternance » on a new réalisation: copies the employer, the town and the
 * contract's dates into the form and switches it to « En milieu professionnel ». It only fills the
 * fields - the student reads them, changes them, and nothing is saved until they submit.
 */
export default class extends Controller {
    apply(event) {
        const option = event.target.selectedOptions[0];
        if (!option || option.value === '') {
            return;
        }

        const set = (name, value) => {
            const field = this.element.querySelector(`[name="${name}"]`);
            if (field && value) {
                field.value = value;
            }
        };

        set('organisation', option.dataset.organisation);
        set('place', option.dataset.place);
        set('startsOn', option.dataset.from);
        set('endsOn', option.dataset.until);

        const workplace = this.element.querySelector('input[name="setting"][value="workplace"]');
        if (workplace) {
            workplace.checked = true;
        }
    }
}
