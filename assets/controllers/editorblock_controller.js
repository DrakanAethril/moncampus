import { Controller } from '@hotwired/stimulus';

// "Personnaliser" reveal for a per-option HugeRTE block (design/design_campus_manager
// README.md 8b pattern) - an empty option shows a status line instead of an editor; clicking
// "Personnaliser" swaps it for a real HugeRTE instance. The editor isn't instantiated at all
// until this point (setting data-controller on the textarea here is what makes Stimulus's own
// mutation observer pick it up and connect hugerte_editor_controller.js, same as if the
// attribute had been present in the server-rendered HTML from the start).
export default class extends Controller {
    static targets = ['empty', 'body', 'textarea'];
    static values = { height: { type: Number, default: 0 } };

    customize() {
        this.emptyTarget.classList.add('d-none');
        this.bodyTarget.classList.remove('d-none');
        this.textareaTarget.setAttribute('data-controller', 'hugerte-editor');
        if (this.hasHeightValue && this.heightValue > 0) {
            this.textareaTarget.setAttribute('data-hugerte-editor-height-value', this.heightValue);
        }
    }
}
