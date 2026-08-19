import { Controller } from '@hotwired/stimulus';

/**
 * Ticks the directory groups the chosen class hangs off, on step ① of the class import.
 *
 * A default, not a rule: everything stays editable afterwards, and nothing here reads the file's
 * own option columns - a directory group and an option of a class are two different notions.
 *
 * Only ever adds. Unticking a box the operator had ticked on purpose, because they then changed
 * class, would be the kind of help nobody asked for.
 */
export default class extends Controller {
    static targets = ['program'];

    connect() {
        this.select = this.programTarget.querySelector('select');
        if (!this.select) {
            return;
        }

        this.onChange = () => this.applySuggestion();
        this.select.addEventListener('change', this.onChange);
    }

    disconnect() {
        if (this.select && this.onChange) {
            this.select.removeEventListener('change', this.onChange);
        }
    }

    applySuggestion() {
        const option = this.select.selectedOptions[0];
        const groups = (option?.dataset.directoryGroups ?? '').split('|').filter((name) => name !== '');

        for (const name of groups) {
            // The choice type renders one checkbox per group name, and the name is what it carries
            // as a value - matching on it rather than on an index keeps this working when the list
            // of groups changes.
            const box = this.element.querySelector(`input[type="checkbox"][value="${CSS.escape(name)}"]`);
            if (box && !box.checked) {
                box.checked = true;
            }
        }
    }
}
