import { Controller } from '@hotwired/stimulus';

/*
 * The "Rattacher..." form of the unlinked mails queue (design_handoff_stage_alternance, screen 5a).
 *
 * Two jobs, both small: reveal the form on demand, and fill the application list once a student is
 * picked. The applications cannot be rendered up front - they depend on a student who is only known
 * after the picker has answered, and rendering every student's applications on a queue page would
 * mean loading the whole platform to fill one optional field.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['form', 'student', 'application'];

    static values = { applicationsUrl: String, noneLabel: String };

    toggle() {
        this.formTarget.hidden = !this.formTarget.hidden;
    }

    async loadApplications() {
        const studentId = this.studentTarget.value;
        this.reset();

        if (!studentId) {
            return;
        }

        try {
            const response = await fetch(this.applicationsUrlValue.replace(/0$/, studentId), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            for (const application of await response.json()) {
                const option = document.createElement('option');
                option.value = application.id;
                option.textContent = application.text;
                this.applicationTarget.append(option);
            }
        } catch (error) {
            // Linking to a student alone stays possible: the application is optional by design.
        }
    }

    reset() {
        this.applicationTarget.replaceChildren();
        const none = document.createElement('option');
        none.value = '';
        none.textContent = this.noneLabelValue;
        this.applicationTarget.append(none);
    }
}
