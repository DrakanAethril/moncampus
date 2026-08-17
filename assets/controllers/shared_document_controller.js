import { Controller } from '@hotwired/stimulus';

/**
 * The « Partager à une classe » form: reveal the block belonging to the selected class, and the
 * date pair when the teacher asks for a window.
 *
 * Every class's block is already in the page - a teacher holds a handful of classes, so this is a
 * few dozen checkboxes rather than a round trip per change. What matters is that the hidden blocks
 * must not submit: `disabled` is what does that, and it is also what keeps their `required` matière
 * out of the browser's own validation. Hiding alone would post nine matières at once.
 */
export default class extends Controller {
    static targets = ['program', 'scope', 'window'];

    connect() {
        this.selectProgram();
        this.toggleWindow();
    }

    selectProgram() {
        const selected = this.programTarget.value;

        this.scopeTargets.forEach((scope) => {
            const active = scope.dataset.programId === selected;
            scope.hidden = !active;
            scope.querySelectorAll('input, select').forEach((field) => {
                field.disabled = !active;
            });
        });
    }

    toggleWindow() {
        const windowed = this.element.querySelector('input[name="visibility"]:checked')?.value === 'window';

        this.windowTarget.hidden = !windowed;
        // The two bounds stay enabled while hidden on purpose: both are optional, an empty one means
        // "that side is open", and disabling them would be indistinguishable from that.
    }
}
