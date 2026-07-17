import { Controller } from '@hotwired/stimulus';

/**
 * Screen 1b (question editor) - drives the "Réponses" row list: add/remove/reorder rows and the
 * exclusive/multi "correct answer" toggle, which depends on the selected question Type (qcm/
 * vrai_faux/image = exactly one correct answer; qcm_multi = any number; ordre = no correctness
 * toggle at all, row position IS the correct order). Rows are submitted as raw
 * answers[N][label]/answers[N][correct] fields, resolved server-side by
 * App\Controller\QuizLibraryController::applyAnswers() - see App\Form\QuizQuestionType's docblock
 * for why this isn't a Symfony CollectionType. No drag library: reordering is two plain move
 * buttons, since only "ordre"-type questions actually need it and a full sortable dependency isn't
 * worth it for that.
 */
export default class extends Controller {
    static targets = ['typeSelect', 'answerList', 'answerRow', 'answerTemplate', 'imageInput', 'imagePreview'];

    connect() {
        this.nextIndex = this.answerRowTargets.length;
        this.applyTypeMode();
    }

    typeChanged() {
        this.applyTypeMode();
    }

    applyTypeMode() {
        const isOrdre = this.typeSelectTarget.value === 'ordre';
        const isMulti = this.typeSelectTarget.value === 'qcm_multi';

        this.answerListTarget.classList.toggle('cm-answers--ordre', isOrdre);
        this.answerListTarget.classList.toggle('cm-answers--multi', isMulti);
    }

    addAnswer() {
        const fragment = this.answerTemplateTarget.content.cloneNode(true);
        const index = this.nextIndex++;

        fragment.querySelectorAll('[data-name-template]').forEach((element) => {
            element.setAttribute('name', element.getAttribute('data-name-template').replace('__INDEX__', String(index)));
        });

        this.answerListTarget.appendChild(fragment);
        this.applyTypeMode();
    }

    removeAnswer(event) {
        event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]').remove();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]');
        const previous = row.previousElementSibling;
        if (previous) {
            this.answerListTarget.insertBefore(row, previous);
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]');
        const next = row.nextElementSibling;
        if (next) {
            this.answerListTarget.insertBefore(next, row);
        }
    }

    toggleCorrect(event) {
        const row = event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]');
        const input = row.querySelector('[data-quiz-question-editor-target="correctInput"]');
        const nowCorrect = input.value !== '1';

        if (nowCorrect && !this.answerListTarget.classList.contains('cm-answers--multi')) {
            // Single-correct types (qcm/vrai_faux/image): selecting one clears every other row,
            // same UX as a radio group without fighting the per-row name="answers[N][correct]".
            this.answerRowTargets.forEach((otherRow) => {
                otherRow.querySelector('[data-quiz-question-editor-target="correctInput"]').value = '0';
                otherRow.querySelector('[data-quiz-question-editor-target="correctToggle"]').classList.remove('is-correct');
            });
        }

        input.value = nowCorrect ? '1' : '0';
        event.currentTarget.classList.toggle('is-correct', nowCorrect);
    }

    previewImage() {
        const file = this.imageInputTarget.files[0];
        if (!file) {
            return;
        }

        this.imagePreviewTarget.src = URL.createObjectURL(file);
        this.imagePreviewTarget.hidden = false;
    }
}
