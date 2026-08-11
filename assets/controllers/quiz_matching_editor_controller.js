import { Controller } from '@hotwired/stimulus';

/**
 * The Apparier panel of the question editor (templates/library/_quiz_matching_editor.html.twig):
 * add, remove and reorder the pair rows, switch a column between text and images, and preview an
 * upload before it is saved. Deliberately thinner than quiz_zone_editor_controller.js - there is
 * nothing to parse here, the rows *are* the definition - and it uses the same no-drag approach as
 * the answers list (quiz_question_editor_controller.js): a couple of ▲▼ buttons rather than a
 * sortable dependency.
 *
 * Row indices only have to be unique within the submitted request (the server iterates the rows in
 * order and re-derives everything from their own hidden id), which is why removing a row never
 * renumbers the others.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'rows', 'row', 'rowTemplate', 'count', 'idInput', 'side',
        'leftKindInput', 'rightKindInput', 'textDistractors', 'imageDistractors', 'distractorList',
        'textLeft', 'textRight', 'preview',
    ];
    static values = { altPlaceholder: String, textPlaceholderLeft: String, textPlaceholderRight: String };

    connect() {
        this.nextIndex = this.rowTargets.length;
        this.refreshCount();
        this.applyKinds();
    }

    addRow() {
        const fragment = this.rowTemplateTarget.content.cloneNode(true);
        const index = this.nextIndex++;

        fragment.querySelectorAll('[data-name-template]').forEach((element) => {
            element.setAttribute('name', element.getAttribute('data-name-template').replace('__INDEX__', String(index)));
        });

        this.rowsTarget.appendChild(fragment);
        this.refreshCount();
        this.applyKinds();
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

    kindChanged() {
        this.applyKinds();
    }

    /**
     * Shows, per column, the widget its kind calls for. Both are always in the DOM - switching a
     * column is a class toggle, never a rebuild, so a teacher who flips to images and back finds
     * everything they had typed still there. On an image column the text field stays visible: it
     * is the item's alternative text, so it only changes its placeholder.
     */
    applyKinds() {
        const kindOf = (inputs) => inputs.find((input) => input.checked)?.value ?? 'texte';
        const kinds = {
            left: kindOf(this.leftKindInputTargets),
            right: kindOf(this.rightKindInputTargets),
        };

        this.sideTargets.forEach((side) => {
            const isImage = kinds[side.dataset.side] === 'image';
            side.classList.toggle('is-image', isImage);
            const text = side.querySelector('input[type="text"]');
            if (text) {
                text.placeholder = isImage
                    ? this.altPlaceholderValue
                    : (side.dataset.side === 'left' ? this.textPlaceholderLeftValue : this.textPlaceholderRightValue);
            }
        });

        // Only one decoy field can apply: a list of words cannot stand in for an image column.
        if (this.hasTextDistractorsTarget) {
            this.textDistractorsTarget.classList.toggle('d-none', kinds.right === 'image');
        }
        if (this.hasImageDistractorsTarget) {
            this.imageDistractorsTarget.classList.toggle('d-none', kinds.right !== 'image');
        }
    }

    previewImage(event) {
        const input = event.currentTarget;
        const file = input.files[0];
        if (!file) {
            return;
        }

        const preview = input.closest('.cm-match-editor__image')?.querySelector('img');
        if (preview) {
            preview.src = URL.createObjectURL(file);
            preview.hidden = false;
        }
    }

    // Dropping a decoy removes its hidden input, which is what the server reads as "no longer
    // kept" - the object itself is deleted on save (QuizLibraryController::applyMatching()).
    removeDistractor(event) {
        event.currentTarget.closest('.cm-match-editor__thumb').remove();
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
