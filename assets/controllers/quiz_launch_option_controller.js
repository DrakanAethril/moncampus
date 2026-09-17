import { Controller } from '@hotwired/stimulus';

/*
 * Keeps the « Visibilité » picker on the quiz launch screen (screen 1c) showing only the options of
 * the class currently selected.
 *
 * The choice list is built server-side over every class on offer, on purpose: the class is picked in
 * the same form, so a list narrowed at render time would be wrong the moment the teacher changes it
 * (same reasoning as group_parent_filter_controller.js). Each option carries its own classes in
 * data-programs, and this hides the rows that do not name the selection.
 *
 * The field is hidden entirely when the class has no option at all: « Tous les étudiants » would
 * then be the only thing to choose, which is not a choice.
 *
 * Purely an assistance - App\Form\QuizLaunchType refuses an option foreign to the class on submit,
 * and App\Service\QuizInstantiationService again when it builds the instance.
 */
export default class extends Controller {
    static targets = ['program', 'option', 'row'];

    connect() {
        this.refresh();
    }

    refresh() {
        const programId = this.programTarget.value;
        let available = 0;

        for (const option of this.optionTarget.options) {
            // The placeholder is « Tous les étudiants »: always valid, whatever the class.
            if (option.value === '') {
                continue;
            }

            const belongs = programId !== '' && (option.dataset.programs || '').split(' ').includes(programId);

            option.disabled = !belongs;
            option.hidden = !belongs;

            if (belongs) {
                available += 1;
            } else if (option.selected) {
                // Changing class drops a narrowing that no longer means anything, rather than
                // submitting one the server would refuse.
                this.optionTarget.value = '';
            }
        }

        if (this.hasRowTarget) {
            this.rowTarget.hidden = available === 0;
        }
    }
}
