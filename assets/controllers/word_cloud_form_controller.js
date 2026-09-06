import { Controller } from '@hotwired/stimulus';

// The creation form of a word cloud - the browser half of the reusable component described in
// templates/word_cloud/_form.html.twig.
//
// It decides nothing. Every rule it enforces is enforced again on the server (the class must be one
// the teacher teaches, an option must belong to it, the quota and the period gate the submission):
// what happens here is only that the screen stops offering what would be refused, and shows the
// class's own view of the question while it is being written.
//
// Three controls are drawn rather than native, and each writes a hidden field the form actually
// submits: the stepper writes "mots par étudiant" (emptied by « Illimités », because null is what
// unlimited means throughout the tool), the « Ouverture manuelle » chip writes a checkbox, and the
// two other shortcuts write the four date and time boxes.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'program', 'optionsField', 'optionChip',
        'period', 'opensDate', 'opensTime', 'closesDate', 'closesTime',
        'manual', 'manualChip', 'lessonShortcut',
        'stepper', 'words', 'wordsValue', 'unlimitedChip',
        'question', 'previewQuestion', 'previewBoxes',
    ];

    static values = { programOptions: Object, placeholder: String };

    // The stepper's floor and ceiling. One is the least a question can ask for; twenty is well past
    // anything a class writes in ten minutes, and « Illimités » is there for the rest.
    static MIN_WORDS = 1;
    static MAX_WORDS = 20;

    connect() {
        this.refreshOptions();
        this.refreshManual();
        this.refreshStepper();
        this.refreshPreview();
    }

    programChanged() {
        this.refreshOptions();
    }

    questionChanged() {
        this.refreshPreview();
    }

    // -- Targeted options -------------------------------------------------

    refreshOptions() {
        const programId = this.hasProgramTarget ? String(this.programTarget.value) : '';
        const allowed = this.programOptionsValue[programId] || [];
        let visible = 0;

        for (const chip of this.optionChipTargets) {
            const matches = allowed.includes(Number(chip.dataset.option));
            chip.hidden = !matches;
            // A hidden option stays in the DOM, unticked: the server would drop it anyway, but the
            // screen would lie for the length of the round trip.
            if (!matches) chip.querySelector('input').checked = false;
            if (matches) visible += 1;
        }

        if (this.hasOptionsFieldTarget) this.optionsFieldTarget.hidden = visible === 0;
    }

    // -- Availability period ----------------------------------------------

    openNowTenMinutes() {
        const now = new Date();
        this.#setManual(false);
        this.#writePeriod(now, new Date(now.getTime() + 10 * 60 * 1000));
    }

    // The class's current slot, when the timetable knows of one - the server puts its bounds on the
    // button, and a class with no lesson running leaves it disabled rather than guessing an hour.
    currentLesson(event) {
        const { opens, closes } = event.currentTarget.dataset;
        if (!opens || !closes) return;

        this.#setManual(false);
        this.#writePeriod(new Date(opens.replace(' ', 'T')), new Date(closes.replace(' ', 'T')));
    }

    toggleManual() {
        this.#setManual(!this.manualTarget.checked);
    }

    #setManual(on) {
        this.manualTarget.checked = on;
        this.refreshManual();
    }

    refreshManual() {
        const on = this.hasManualTarget && this.manualTarget.checked;
        if (this.hasManualChipTarget) this.manualChipTarget.classList.toggle('is-on', on);
        // The dates stay on screen and stop being reachable: they are what the cloud falls back to
        // if the teacher changes their mind, and clearing them would lose what was typed.
        if (this.hasPeriodTarget) this.periodTarget.classList.toggle('is-manual', on);
    }

    #writePeriod(from, to) {
        this.#writeDateTime(this.opensDateTarget, this.opensTimeTarget, from);
        this.#writeDateTime(this.closesDateTarget, this.closesTimeTarget, to);
    }

    #writeDateTime(dateField, timeField, moment) {
        const pad = (value) => String(value).padStart(2, '0');
        dateField.value = `${moment.getFullYear()}-${pad(moment.getMonth() + 1)}-${pad(moment.getDate())}`;
        timeField.value = `${pad(moment.getHours())}:${pad(moment.getMinutes())}`;
    }

    // -- Words per student -------------------------------------------------

    increment() {
        this.#setWords(Math.min(this.constructor.MAX_WORDS, this.#currentWords() + 1));
    }

    decrement() {
        this.#setWords(Math.max(this.constructor.MIN_WORDS, this.#currentWords() - 1));
    }

    toggleUnlimited() {
        // An empty field is the unlimited case. The stepper keeps whatever number it was showing,
        // so switching back restores the teacher's own choice rather than a default.
        this.#setWords(this.wordsTarget.value === '' ? this.#currentWords() : '');
    }

    #currentWords() {
        const shown = Number(this.wordsValueTarget.textContent);

        return Number.isFinite(shown) && shown > 0 ? shown : 3;
    }

    #setWords(value) {
        this.wordsTarget.value = value === '' ? '' : String(value);
        if (value !== '') this.wordsValueTarget.textContent = String(value);
        this.refreshStepper();
        this.refreshPreview();
    }

    refreshStepper() {
        const unlimited = this.hasWordsTarget && this.wordsTarget.value === '';
        if (this.hasStepperTarget) this.stepperTarget.classList.toggle('is-off', unlimited);
        if (this.hasUnlimitedChipTarget) this.unlimitedChipTarget.classList.toggle('is-on', unlimited);
        if (!unlimited && this.hasWordsTarget && this.wordsTarget.value !== '') {
            this.wordsValueTarget.textContent = this.wordsTarget.value;
        }
    }

    // -- « Vue étudiant » --------------------------------------------------

    refreshPreview() {
        if (!this.hasPreviewBoxesTarget) return;

        if (this.hasPreviewQuestionTarget && this.hasQuestionTarget) {
            this.previewQuestionTarget.textContent = this.questionTarget.value;
        }

        // As many boxes as words allowed, the last one drawn empty so the card shows what an
        // unfilled box looks like. Unlimited draws a single one - that is what the student's screen
        // does too, one word at a time.
        const quota = this.wordsTarget.value === '' ? 1 : Number(this.wordsTarget.value);
        const boxes = Math.max(1, Math.min(quota, 6));

        this.previewBoxesTarget.replaceChildren(...Array.from({ length: boxes }, (unused, index) => {
            const box = document.createElement('div');
            box.className = index === boxes - 1 ? 'cm-wc-preview__box cm-wc-preview__box--empty' : 'cm-wc-preview__box';
            box.textContent = index === boxes - 1
                ? this.placeholderValue.replace('%number%', String(index + 1))
                : '';

            return box;
        }));
    }
}
