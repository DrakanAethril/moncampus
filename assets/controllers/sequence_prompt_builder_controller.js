import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the séquence import prompt in step with the tag labels chosen just above it.
 *
 * The labels are asked for before the copy because they travel *inside* the prompt: a model left to
 * invent them writes "BTS SIO 2ème année" next to the "BTS SIO 2" the teacher already uses, and the
 * library grows a duplicate tag that nothing will ever merge. Placed after the copy button they
 * would serve no purpose at all.
 *
 * Only one line of the prompt changes, so the body arrives with a placeholder in it and this
 * substitutes. The text itself stays in App\Service\SequencePromptCatalog - it is the text sent to
 * the model, not a message about it, and it is never translated.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['niveau', 'option', 'bloc', 'output'];

    static values = {
        body: String,
        placeholder: String,
        none: String,
        intro: String,
        niveauTemplate: String,
        optionTemplate: String,
        blocsTemplate: String,
    };

    connect() {
        this.refresh();
    }

    refresh() {
        const parts = [];
        const niveau = this.hasNiveauTarget ? this.niveauTarget.value.trim() : '';
        const option = this.hasOptionTarget ? this.optionTarget.value.trim() : '';
        const blocs = this.blocTargets.filter((input) => input.checked).map((input) => input.value.trim());

        if (niveau) {
            parts.push(this.niveauTemplateValue.replace('%label%', niveau));
        }
        if (option) {
            parts.push(this.optionTemplateValue.replace('%label%', option));
        }
        if (blocs.length > 0) {
            parts.push(this.blocsTemplateValue.replace('%labels%', blocs.map((label) => `« ${label} »`).join(', ')));
        }

        // A teacher who tagged nothing is a legitimate answer, and must not be handed a dangling
        // "Niveau « »" to send to a model.
        const line = parts.length === 0 ? this.noneValue : this.introValue + parts.join(' · ') + '.';

        this.outputTarget.textContent = this.bodyValue.replace(this.placeholderValue, line);
    }
}
