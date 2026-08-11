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
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['typeSelect', 'answerList', 'answerRow', 'answerTemplate', 'addAnswerButton', 'hintText', 'imageInput', 'imagePreview', 'classicSection', 'blanksSection', 'zoneSection', 'matchingSection', 'numericSection', 'shortAnswerSection', 'imageSection', 'labelField', 'labelText', 'labelHint', 'blankCount'];
    static values = { trueLabel: String, falseLabel: String, hintDefault: String, hintOrdre: String, labelEnonce: String, labelBlanks: String, labelCalculee: String, hintCalculee: String };

    connect() {
        this.nextIndex = this.answerRowTargets.length;
        // The blanks hint is the one rendered server-side; remembered here because a calculée swaps
        // it for its own and switching back has to restore it.
        this.blanksHint = this.labelHintTarget.textContent;
        this.applyTypeMode();
    }

    typeChanged() {
        this.syncVraiFaux();
        this.applyTypeMode();
        // Tell the zone editor (a sibling controller on the wrapper div) the type changed - it
        // re-renders its rows, whose fields differ between zone and légende.
        this.dispatch('typeChanged');
    }

    applyTypeMode() {
        const isOrdre = this.typeSelectTarget.value === 'ordre';
        const isMulti = this.typeSelectTarget.value === 'qcm_multi';
        const isVraiFaux = this.typeSelectTarget.value === 'vrai_faux';
        const isBlanks = this.typeSelectTarget.value === 'texte_a_trous';
        const isZone = this.typeSelectTarget.value === 'zone' || this.typeSelectTarget.value === 'legende';
        const isMatching = this.typeSelectTarget.value === 'apparier';
        const isCalculee = this.typeSelectTarget.value === 'calculee';
        const isNumeric = isCalculee || this.typeSelectTarget.value === 'numerique';
        const isShortAnswer = this.typeSelectTarget.value === 'reponse_courte';

        // Texte à trous, the zones types and apparier have no answer rows - each swaps the lower
        // half of the editor for its own panel. The sections are toggled with d-none only, never
        // with the hidden attribute: any Bootstrap display utility on the same element would
        // out-!important it.
        this.classicSectionTarget.classList.toggle('d-none', isBlanks || isZone || isMatching || isNumeric || isShortAnswer);
        this.blanksSectionTarget.classList.toggle('d-none', !isBlanks);
        if (this.hasZoneSectionTarget) {
            this.zoneSectionTarget.classList.toggle('d-none', !isZone);
        }
        if (this.hasMatchingSectionTarget) {
            this.matchingSectionTarget.classList.toggle('d-none', !isMatching);
        }
        if (this.hasNumericSectionTarget) {
            this.numericSectionTarget.classList.toggle('d-none', !isNumeric);
        }
        if (this.hasShortAnswerSectionTarget) {
            this.shortAnswerSectionTarget.classList.toggle('d-none', !isShortAnswer);
        }
        // The image field serves the classic types AND a zone question whose support is the image
        // itself - both controllers read the same zones[kind] radios, so there is no state to sync.
        // An apparier question has no illustration of its own: its two columns are the statement.
        if (this.hasImageSectionTarget) {
            const zoneKind = this.element.querySelector('input[name="zones[kind]"]:checked');
            this.imageSectionTarget.classList.toggle('d-none', isBlanks || isMatching || isNumeric || isShortAnswer || (isZone && (!zoneKind || zoneKind.value !== 'image')));
        }

        // Same field, two readings: "Énoncé" for every other type, "Texte à compléter" here, with
        // the hint that says how a blank is typed and the live "n trous détectés" counter under it.
        // Same field, three readings: "Énoncé" normally, "Texte à compléter" for a texte à trous,
        // and "Énoncé avec variables" for a calculée - whose statement is where the {v} markers the
        // variable table is built from actually live.
        this.labelFieldTarget.classList.toggle('is-blanks', isBlanks);
        this.labelTextTarget.textContent = isBlanks
            ? this.labelBlanksValue
            : (isCalculee ? this.labelCalculeeValue : this.labelEnonceValue);
        this.labelHintTarget.textContent = isCalculee ? this.hintCalculeeValue : this.blanksHint;
        this.labelHintTarget.classList.toggle('d-none', !isBlanks && !isCalculee);
        this.blankCountTarget.classList.toggle('d-none', !isBlanks);

        if (isBlanks || isZone || isMatching || isNumeric || isShortAnswer) {
            return;
        }

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
