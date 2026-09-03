import { Controller } from '@hotwired/stimulus';

// The mode contrôle block, on « Lancer un quiz » and on « Modifier le quiz ». Three nested
// conditions, all of them cosmetic: the block only makes sense under Évaluation, its settings only
// under the checkbox, and the « après N sorties » count only under the third policy.
//
// None of this is the rule. The launch controller puts `supervised` back to false server-side when
// the mode is Entraînement (App\Controller\QuizLibraryController::launch()) - hiding a checkbox has
// never been a way of switching something off.
//
// A form carrying no mode radio at all is the edit screen, where the mode was frozen at launch and
// the question has already been answered server-side: the block is rendered there only on an
// évaluation, so its presence is the answer (App\Form\QuizInstanceEditType).
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['block', 'toggle', 'settings', 'submitAt', 'policy'];

    connect() {
        this.refresh();
    }

    refresh() {
        const modeInput = this.element.querySelector('input[type="radio"][value="evaluation"]');
        const isEvaluation = modeInput ? modeInput.checked : true;
        const isOn = isEvaluation && this.toggleTarget.checked;
        const autosubmit = this.policyTargets.find((input) => input.checked)?.value === 'autosubmit';

        // `d-none` rather than the `hidden` attribute: Bootstrap's own `.d-flex` is `!important`
        // and wins over `hidden`, so a hidden flex column would stay on screen.
        this.blockTarget.classList.toggle('d-none', !isEvaluation);
        this.settingsTarget.classList.toggle('d-none', !isOn);
        this.submitAtTarget.classList.toggle('d-none', !isOn || !autosubmit);
    }
}
