import { Controller } from '@hotwired/stimulus';

/*
 * Step 1 of the création — the two gestures the browser has to carry, and nothing else.
 *
 * Everything else on this feature is server-rendered and query-parameter driven: the stepper, the
 * selected document, the Suivi tabs, the open row. What is left here is genuinely local:
 *
 * - **removing a validateur's chip**, which only removes its hidden input. Nothing is saved until
 *   the form is submitted, so a mistaken click is undone by leaving the page - which is the right
 *   behaviour for a field, and would not be for a POST of its own.
 * - **switching between « Classes / groupes » and « Étudiants »**. Both lists stay in the DOM; only
 *   their visibility moves. Emptying the hidden one would silently drop what it had ticked, and the
 *   two lists are one submission.
 * - **revealing the validateur picker**, which the handoff draws as a dashed chip and nothing else
 *   until it is clicked.
 *
 * `el.hidden` rather than a class: the panels have no display rule of their own to fight with.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['validatorChip', 'validatorPicker', 'targetMode', 'targetPanel'];

    removeValidator(event) {
        event.target.closest('.cm-dd-chip')?.remove();
    }

    toggleValidatorPicker() {
        if (!this.hasValidatorPickerTarget) {
            return;
        }

        // The target is a wrapping <div>, not the <select>: Tom Select replaces the element it is
        // mounted on, so hiding that element stops hiding anything once it has connected.
        this.validatorPickerTarget.hidden = !this.validatorPickerTarget.hidden;
    }

    showTargets(event) {
        const mode = event.currentTarget.dataset.mode;

        this.targetModeTargets.forEach((button) => {
            const on = button.dataset.mode === mode;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        this.targetPanelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.mode !== mode;
        });
    }
}
