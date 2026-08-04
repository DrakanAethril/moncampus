import { Controller } from '@hotwired/stimulus';

/**
 * Screens 2c/2d - answering a texte à trous. Mounted on the passation <form> so the blanks, the
 * word bank and the "n / m trous remplis" counter in the footer are all in one scope.
 *
 * Word bank (2c): click a word, then a blank, to place it; click a placed word to send it back.
 * Deliberately not drag and drop - the handoff asks for the same gesture on desktop and on touch,
 * and a click pair is the one interaction that works identically on both without a drag library.
 * The bank keeps every word in place once used (struck through, not removed) so the layout never
 * shifts under the student's finger mid-answer.
 *
 * Free input (2d): the fields are the answer, nothing to place - only the counter and the
 * content-sized width are driven from here.
 *
 * Either way the submitted shape is identical: one blanks[n] field per blank, in text order.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['slot', 'field', 'word', 'counter'];
    static values = { mode: String, countTemplate: String, emptyLabel: String };

    connect() {
        this.selectedWord = null;
        this.activeSlotIndex = this.modeValue === 'banque' && this.slotTargets.length > 0 ? 0 : null;
        this.render();
    }

    wordClicked(event) {
        const word = event.currentTarget;
        if (word.classList.contains('is-used')) {
            return;
        }

        // Clicking the already-selected word deselects it, so a mis-tap is undoable without
        // having to place the word somewhere first.
        this.selectedWord = this.selectedWord === word ? null : word;

        // With a word in hand and a blank already highlighted, one more tap finishes the job -
        // but placing immediately would rob the student of the choice of blank, so we only
        // pre-aim at the first empty one.
        if (this.selectedWord) {
            this.activeSlotIndex = this.firstEmptySlotIndex() ?? this.activeSlotIndex;
        }

        this.render();
    }

    slotClicked(event) {
        const index = Number(event.currentTarget.dataset.blankIndex);
        const field = this.fieldFor(index);

        if (field.value !== '') {
            // A filled blank hands its word back to the bank, whatever is currently selected.
            field.value = '';
            this.activeSlotIndex = index;
        } else if (this.selectedWord) {
            field.value = this.selectedWord.textContent.trim();
            this.selectedWord = null;
            this.activeSlotIndex = this.firstEmptySlotIndex();
        } else {
            this.activeSlotIndex = index;
        }

        this.render();
    }

    inputChanged() {
        this.render();
    }

    render() {
        if (this.modeValue === 'banque') {
            this.renderSlots();
            this.renderBank();
        } else {
            this.fieldTargets.forEach((field) => this.sizeToContent(field));
        }

        this.renderCounter();
    }

    renderSlots() {
        this.slotTargets.forEach((slot) => {
            const index = Number(slot.dataset.blankIndex);
            const value = this.fieldFor(index).value;

            // A non-breaking space keeps an empty slot at its full height instead of collapsing.
            slot.textContent = value === '' ? ' ' : value;
            slot.classList.toggle('is-filled', value !== '');
            slot.classList.toggle('is-active', value === '' && index === this.activeSlotIndex);
        });
    }

    renderBank() {
        // A bank can legitimately hold the same word twice (two blanks with the same answer, or a
        // distractor equal to an answer), so "used" is a count, not a lookup: place one copy and
        // exactly one bank chip greys out, leaving the other still available.
        const placedCounts = new Map();
        this.fieldTargets.forEach((field) => {
            if (field.value !== '') {
                placedCounts.set(field.value, (placedCounts.get(field.value) || 0) + 1);
            }
        });

        this.wordTargets.forEach((word) => {
            const label = word.textContent.trim();
            const remaining = placedCounts.get(label) || 0;
            const isUsed = remaining > 0;

            if (isUsed) {
                placedCounts.set(label, remaining - 1);
            }

            word.classList.toggle('is-used', isUsed);
            word.classList.toggle('is-selected', word === this.selectedWord && !isUsed);
        });
    }

    renderCounter() {
        if (!this.hasCounterTarget) {
            return;
        }

        const filled = this.fieldTargets.filter((field) => field.value.trim() !== '').length;
        this.counterTarget.textContent = this.countTemplateValue
            .replace('%filled%', String(filled))
            .replace('%total%', String(this.fieldTargets.length));
    }

    // Free-input blanks grow with what is typed instead of staying at a fixed width, so the
    // sentence keeps reading as a sentence (screen 2d).
    sizeToContent(field) {
        const length = Math.max(field.value.length, String(field.placeholder || '').length);
        field.style.width = `${Math.min(length + 2, 40)}ch`;
    }

    firstEmptySlotIndex() {
        const empty = this.slotTargets.find((slot) => this.fieldFor(Number(slot.dataset.blankIndex)).value === '');

        return empty ? Number(empty.dataset.blankIndex) : null;
    }

    fieldFor(index) {
        return this.fieldTargets[index];
    }
}
