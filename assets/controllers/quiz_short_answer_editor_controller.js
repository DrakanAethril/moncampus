import { Controller } from '@hotwired/stimulus';

/**
 * The Réponse courte panel of the question editor
 * (templates/library/_quiz_short_answer_editor.html.twig): add and remove accepted variants.
 *
 * Deliberately the smallest controller of the family - the rows are a plain list posted as
 * blanks[answers][0][], with no ids, no ordering rule and nothing derived from the statement. The
 * only thing it really owns is the counter, which is what tells a teacher at a glance that they
 * have written one accepted spelling and not three.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['rows', 'row', 'count'];
    static values = { placeholder: String, removeLabel: String };

    connect() {
        // A question switched over to this type has no variant yet; an empty list with no field to
        // type in reads as a broken panel, so it always opens on one row.
        if (this.rowTargets.length === 0) {
            this.addRow();
        }
        this.refreshCount();
    }

    addRow() {
        const row = document.createElement('div');
        row.className = 'cm-answer-row';
        row.dataset.quizShortAnswerEditorTarget = 'row';
        row.innerHTML = `
            <span class="cm-qbank__index"></span>
            <input type="text" class="cm-answer-input" name="blanks[answers][0][]" placeholder="${this.placeholderValue}" data-action="input->quiz-short-answer-editor#refreshCount">
            <button type="button" class="cm-answer-remove" data-action="click->quiz-short-answer-editor#removeRow" title="${this.removeLabelValue}">✕</button>
        `;
        this.rowsTarget.appendChild(row);
        this.refreshCount();
        row.querySelector('input')?.focus();
    }

    removeRow(event) {
        event.currentTarget.closest('[data-quiz-short-answer-editor-target="row"]').remove();
        // Never leave the panel with nothing to type in.
        if (this.rowTargets.length === 0) {
            this.addRow();
        }
        this.refreshCount();
    }

    refreshCount() {
        // The first row is the reference spelling - the one the correction shows as "attendu".
        this.rowTargets.forEach((row, index) => {
            const badge = row.querySelector('.cm-qbank__index');
            if (badge) {
                badge.textContent = index === 0 ? '✓' : String(index + 1);
            }
        });

        if (!this.hasCountTarget) {
            return;
        }

        const filled = this.rowTargets.filter((row) => (row.querySelector('input')?.value ?? '').trim() !== '').length;
        this.countTarget.textContent = filled
            ? (this.countTarget.dataset.countTemplate || '').replace('%count%', String(filled))
            : (this.countTarget.dataset.emptyText || '');
    }
}
