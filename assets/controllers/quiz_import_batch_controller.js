import { Controller } from '@hotwired/stimulus';

/**
 * The rail of the batch verification screen: one entry per quiz, one panel shown at a time.
 *
 * Switching happens here rather than through a round trip because the verification IS a reading -
 * a teacher walks the twelve questions of the first quiz, then the second's, and a page load per
 * glance would cost more than the whole import. Every panel's fields are in the one form the whole
 * time, so what is confirmed is the batch as it was read, in the order the documents arrived.
 *
 * Taking a quiz out of the batch is a checkbox on its own panel and nothing more: the rail entry
 * dims, the count on the confirm button drops, and the payload stays exactly where it was in case
 * the teacher changes their mind.
 */
export default class extends Controller {
    static targets = ['entry', 'panel', 'include', 'submit', 'empty'];

    static values = { oneLabel: String, manyLabel: String };

    connect() {
        this.show(0);
        this.refresh();
    }

    select(event) {
        this.show(Number(event.params.index));
    }

    show(index) {
        this.panelTargets.forEach((panel, position) => {
            panel.hidden = position !== index;
        });
        this.entryTargets.forEach((entry, position) => {
            entry.classList.toggle('is-active', position === index);
            entry.setAttribute('aria-current', position === index ? 'true' : 'false');
        });
    }

    /** The rail's struck-through entries and the button's count, both read off the checkboxes. */
    refresh() {
        let kept = 0;

        this.includeTargets.forEach((checkbox, position) => {
            const entry = this.entryTargets[position];
            if (entry) {
                entry.classList.toggle('is-off', !checkbox.checked);
            }
            if (checkbox.checked) {
                kept += 1;
            }
        });

        const label = kept === 1 ? this.oneLabelValue : this.manyLabelValue;
        this.submitTarget.textContent = label.replace('%count%', String(kept));
        this.submitTarget.disabled = kept === 0;

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = kept > 0;
        }
    }
}
