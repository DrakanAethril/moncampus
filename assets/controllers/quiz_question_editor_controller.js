import { Controller } from '@hotwired/stimulus';

/**
 * Screen 1b (question editor) - drives the "Réponses" row list: add/remove/reorder rows and the
 * exclusive/multi "correct answer" toggle, which depends on the selected question Type (qcm/
 * vrai_faux/image = exactly one correct answer; qcm_multi = any number; ordre = no correctness
 * toggle at all, row position IS the correct order, set via a per-row position <select> - see
 * refreshOrderPositions()/positionChanged()). Rows are submitted as raw
 * answers[N][label]/answers[N][correct] fields, resolved server-side by
 * App\Controller\QuizLibraryController::applyAnswers() - see App\Form\QuizQuestionType's docblock
 * for why this isn't a Symfony CollectionType. No drag library: reordering is a plain <select> of
 * target positions, since only "ordre"-type questions actually need it and a full sortable
 * dependency isn't worth it for that.
 *
 * vrai_faux is special-cased further: its two rows are always exactly "Vrai"/"Faux" (translated -
 * see trueLabelValue/falseLabelValue, filled server-side from the current locale), locked read-only
 * so only the correct-answer toggle stays interactive - see syncVraiFaux()/addAnswer().
 */
export default class extends Controller {
    static targets = ['typeSelect', 'answerList', 'answerRow', 'answerTemplate', 'addAnswerButton', 'hintText', 'imageInput', 'imagePreview'];
    static values = { trueLabel: String, falseLabel: String, hintDefault: String, hintOrdre: String };

    connect() {
        this.nextIndex = this.answerRowTargets.length;
        this.applyTypeMode();
    }

    typeChanged() {
        this.syncVraiFaux();
        this.applyTypeMode();
    }

    applyTypeMode() {
        const isOrdre = this.typeSelectTarget.value === 'ordre';
        const isMulti = this.typeSelectTarget.value === 'qcm_multi';
        const isVraiFaux = this.typeSelectTarget.value === 'vrai_faux';

        this.answerListTarget.classList.toggle('cm-answers--ordre', isOrdre);
        this.answerListTarget.classList.toggle('cm-answers--multi', isMulti);
        this.answerListTarget.classList.toggle('cm-answers--vraifaux', isVraiFaux);
        this.addAnswerButtonTarget.classList.toggle('d-none', isVraiFaux);
        this.hintTextTarget.textContent = isOrdre ? this.hintOrdreValue : this.hintDefaultValue;

        if (isOrdre) {
            this.refreshOrderPositions();
        }
    }

    // Only ever runs on an explicit user-driven type change (never on connect()), so an
    // already-saved vrai_faux question's real rows are never clobbered on page load - only
    // switching *into* vrai_faux resets the row list to the two locked, prefilled rows.
    syncVraiFaux() {
        if (this.typeSelectTarget.value !== 'vrai_faux') {
            return;
        }

        this.answerListTarget.innerHTML = '';
        this.nextIndex = 0;
        [this.trueLabelValue, this.falseLabelValue].forEach((label) => this.addAnswer(label));
    }

    // presetLabel is only ever a real string when called from syncVraiFaux() - when Stimulus
    // invokes this as a click action (the "+ Ajouter une réponse" button) it passes the click
    // Event instead, which is deliberately not a string.
    addAnswer(presetLabel) {
        const label = 'string' === typeof presetLabel ? presetLabel : null;

        if (null === label && this.typeSelectTarget.value === 'vrai_faux') {
            return;
        }

        const fragment = this.answerTemplateTarget.content.cloneNode(true);
        const index = this.nextIndex++;

        fragment.querySelectorAll('[data-name-template]').forEach((element) => {
            element.setAttribute('name', element.getAttribute('data-name-template').replace('__INDEX__', String(index)));
        });

        if (null !== label) {
            const input = fragment.querySelector('.cm-answer-input');
            input.value = label;
            input.setAttribute('readonly', 'readonly');
        }

        this.answerListTarget.appendChild(fragment);
        this.applyTypeMode();
    }

    removeAnswer(event) {
        event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]').remove();
        this.applyTypeMode();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]');
        const previous = row.previousElementSibling;
        if (previous) {
            this.answerListTarget.insertBefore(row, previous);
        }
        this.refreshOrderPositions();
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-quiz-question-editor-target="answerRow"]');
        const next = row.nextElementSibling;
        if (next) {
            this.answerListTarget.insertBefore(next, row);
        }
        this.refreshOrderPositions();
    }

    // Rebuilds every row's position <select> (options 1..N, selected = current DOM index) - called
    // whenever the "ordre" row set or its order changes (connect/add/remove/move/positionChanged).
    refreshOrderPositions() {
        const rows = this.answerRowTargets;

        rows.forEach((row, index) => {
            const select = row.querySelector('[data-quiz-question-editor-target="positionSelect"]');
            if (!select) {
                return;
            }

            select.innerHTML = rows.map((_, position) => `<option value="${position}">${position + 1}</option>`).join('');
            select.value = String(index);
        });
    }

    positionChanged(event) {
        const select = event.currentTarget;
        const row = select.closest('[data-quiz-question-editor-target="answerRow"]');
        const targetIndex = Number(select.value);
        const rows = this.answerRowTargets;
        const reference = rows[targetIndex];

        if (reference && reference !== row) {
            this.answerListTarget.insertBefore(row, rows.indexOf(reference) < rows.indexOf(row) ? reference : reference.nextElementSibling);
        }

        this.refreshOrderPositions();
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
