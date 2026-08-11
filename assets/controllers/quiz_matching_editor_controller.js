import { Controller } from '@hotwired/stimulus';

/**
 * The Apparier panel of the question editor (templates/library/_quiz_matching_editor.html.twig):
 * add, remove and reorder the pair rows. Deliberately thinner than quiz_zone_editor_controller.js -
 * there is nothing to parse here, the rows *are* the definition - and it uses the same no-drag
 * approach as the answers list (quiz_question_editor_controller.js): a couple of ▲▼ buttons rather
 * than a sortable dependency.
 *
 * Row indices only have to be unique within the submitted request (the server iterates the rows in
 * order and re-derives everything from their own hidden id), which is why removing a row never
 * renumbers the others.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['rows', 'row', 'rowTemplate', 'count', 'idInput'];

    connect() {
        this.nextIndex = this.rowTargets.length;
        this.refreshCount();
    }

    addRow() {
        const fragment = this.rowTemplateTarget.content.cloneNode(true);
        const index = this.nextIndex++;

        fragment.querySelectorAll('[data-name-template]').forEach((element) => {
            element.setAttribute('name', element.getAttribute('data-name-template').replace('__INDEX__', String(index)));
        });

        this.rowsTarget.appendChild(fragment);
        this.refreshCount();
    }

    removeRow(event) {
        event.currentTarget.closest('[data-quiz-matching-editor-target="row"]').remove();
        this.refreshCount();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-quiz-matching-editor-target="row"]');
        const previous = row.previousElementSibling;
        if (previous) {
            this.rowsTarget.insertBefore(row, previous);
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-quiz-matching-editor-target="row"]');
        const next = row.nextElementSibling;
        if (next) {
            this.rowsTarget.insertBefore(next, row);
        }
    }

    // "n paires" under the list - the same live counter the blanks and zones editors show, and the
    // only feedback a teacher gets that the question is big enough to be worth answering.
    refreshCount() {
        if (!this.hasCountTarget) {
            return;
        }

        const template = this.countTarget.dataset.countTemplate || '';
        this.countTarget.textContent = template.replace('%count%', String(this.rowTargets.length));
    }
}
