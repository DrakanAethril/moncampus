import { Controller } from '@hotwired/stimulus';

/**
 * Shows a block while a given choice is selected in a radio group - the quiz picker, which only
 * means anything for the "Quiz en ligne" nature (mockup 2b).
 *
 * Same stance as checkbox_reveal_controller: purely an affordance, the block stays in the DOM (a
 * class, not `hidden`) and the server is what decides what it keeps - here, resetting the quiz to
 * null once the nature changed.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['radio', 'panel'];
    static values = { value: String };

    connect() {
        this.refresh();
    }

    refresh() {
        const checked = this.radioTargets.find((radio) => radio.checked)?.value;

        this.panelTargets.forEach((panel) => {
            // A panel can name its own value (data-radio-reveal-for) when several natures each
            // have their block - the quiz and the self-assessment. Failing that it follows the
            // controller's single value, as before. Several space-separated values mean "either
            // one": e-CO's time allowance applies to two of the three course modes.
            const expected = (panel.dataset.radioRevealFor ?? this.valueValue).split(' ');
            panel.classList.toggle('d-none', !expected.includes(checked));
        });
    }
}
