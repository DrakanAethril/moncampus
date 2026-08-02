import { Controller } from '@hotwired/stimulus';

// Reused on the Program create/edit form for two independent sections: the "Gestion de l'emploi
// du temps" section (triggered by the timetableManagementEnabled checkbox) and the UFA section
// (triggered by the alternance Modality chip's own checkbox, tagged as this controller's
// "trigger" target in the template) - same shape, different single-checkbox trigger each time.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['trigger', 'section'];

    connect() {
        this.toggle();
    }

    toggle() {
        this.sectionTargets.forEach((section) => {
            section.classList.toggle('d-none', !this.triggerTarget.checked);
        });
    }
}
