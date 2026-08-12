import { Controller } from '@hotwired/stimulus';

/**
 * The destination of an import: a new quiz, or one that already exists. Folds the panel that does
 * not apply and keeps the confirm button naming where the questions are actually going - "Ajouter 12
 * questions à « Réseaux — VLAN »", never a bare "Valider".
 *
 * The panels are toggled with `hidden` plus an explicit `display` reset: Bootstrap's own utilities
 * carry !important and would otherwise keep a hidden block on screen (documented gotcha).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['choice', 'newPanel', 'existingPanel', 'submit'];

    connect() {
        this.refresh();
        this.element.closest('form')?.addEventListener('change', (event) => {
            if (event.target.name?.endsWith('[targetTemplate]')) {
                this.refresh();
            }
        });
    }

    refresh() {
        const chosen = this.choiceTargets.find((input) => input.checked);
        const existing = chosen?.value === 'existing';

        this.newPanelTarget.hidden = existing;
        this.existingPanelTarget.hidden = !existing;

        if (!this.hasSubmitTarget) {
            return;
        }

        const select = this.element.closest('form')?.querySelector('[name$="[targetTemplate]"]');
        const name = select?.selectedIndex > 0 ? select.options[select.selectedIndex].text : null;
        this.submitTarget.textContent = existing && name
            ? this.submitTarget.dataset.existingLabel.replace('__NAME__', name)
            : this.submitTarget.dataset.newLabel;
    }
}
