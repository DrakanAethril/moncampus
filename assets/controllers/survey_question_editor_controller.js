import { Controller } from '@hotwired/stimulus';

/**
 * The right-hand panel of Outils > Sondages > éditeur - shows only what the selected type actually
 * means, and keeps the rank of each proposed answer visible.
 *
 * Three rules from design/validated/surveys.md are held here:
 *
 *  - « Ces réponses forment une échelle » exists on the single choice only, min/max choices on the
 *    multiple choice only, and neither is *emptied* when out of scope - it is simply hidden, so
 *    switching a question back finds its settings where it left them.
 *  - An intertitre (« titre ») asks nothing: no help text, no "required", no answers.
 *  - On a scale, the rank displayed beside each answer IS the value, 0 being the low pole. That is
 *    what lets the results compute an average without a weight column, so the editor shows it
 *    rather than leaving the author to count rows.
 *
 * Rows are submitted as plain answers[] fields, read back by
 * App\Controller\Survey\LibraryController::applyAnswers() - the same choice as the quiz editor, and
 * for the same reason: a Symfony CollectionType buys nothing here but its locked-row traps.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['answerable', 'scale', 'bounds', 'answers', 'answerList', 'answerRow', 'answerTemplate', 'answersHint', 'rank'];
    static values = { type: String };

    connect() {
        this.applyType();
    }

    typeChanged(event) {
        this.typeValue = event.target.value;
        this.applyType();
    }

    typeValueChanged() {
        if (this.hasAnswersTarget) {
            this.applyType();
        }
    }

    applyType() {
        const type = this.typeValue;
        const hasAnswers = ['unique', 'multiple', 'ordre'].includes(type);

        // .d-none rather than the hidden attribute: Bootstrap's own display utilities carry
        // !important and would win over it (a gotcha this repository has already paid for).
        this.toggle(this.answerableTarget, 'titre' !== type);
        this.toggle(this.scaleTarget, 'unique' === type);
        this.toggle(this.boundsTarget, 'multiple' === type);
        this.toggle(this.answersTarget, hasAnswers);

        if (this.hasAnswersHintTarget) {
            this.answersHintTarget.textContent = this.hintFor(type);
        }

        this.refreshRanks();
    }

    hintFor(type) {
        return this.answersHintTarget.dataset[`hint${type.charAt(0).toUpperCase()}${type.slice(1)}`] || '';
    }

    toggle(element, shown) {
        element.classList.toggle('d-none', !shown);
    }

    addAnswer() {
        const fragment = this.answerTemplateTarget.content.cloneNode(true);
        this.answerListTarget.appendChild(fragment);
        this.refreshRanks();
        const inputs = this.answerListTarget.querySelectorAll('input[type="text"]');
        inputs[inputs.length - 1].focus();
    }

    removeAnswer(event) {
        const row = event.target.closest('.cm-answer-row');
        if (row) {
            row.remove();
            this.refreshRanks();
        }
    }

    /** 0-based, because that is the stored order_index and, on a scale, the value itself. */
    refreshRanks() {
        this.rankTargets.forEach((rank, index) => {
            rank.textContent = rank.dataset.template
                ? rank.dataset.template.replace('%rank%', String(index))
                : String(index);
        });
    }
}
