import { Controller } from '@hotwired/stimulus';

// "Personnaliser" reveal for a per-option HugeRTE block (design/design_campus_manager
// README.md 8b pattern) - an empty option shows a status line instead of an editor; clicking
// "Personnaliser" swaps it for a real HugeRTE instance. The editor isn't instantiated at all
// until this point (setting data-controller on the textarea here is what makes Stimulus's own
// mutation observer pick it up and connect hugerte_editor_controller.js, same as if the
// attribute had been present in the server-rendered HTML from the start).
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['empty', 'body', 'textarea'];
    static values = { height: { type: Number, default: 0 }, seed: { type: String, default: '' } };

    // seedValue (e.g. a ContractType's center-level default HTML) pre-fills the textarea the
    // first time it's customized, for override screens where "surcharger" starts from a copy of
    // the inherited text (design_handoff_ufa's "une surcharge est une copie" rule) - left unset,
    // this behaves exactly like the plain per-option blocks (empty start).
    customize() {
        this.emptyTarget.classList.add('d-none');
        this.bodyTarget.classList.remove('d-none');
        if ('' === this.textareaTarget.value && '' !== this.seedValue) {
            this.textareaTarget.value = this.seedValue;
        }
        this.textareaTarget.setAttribute('data-controller', 'hugerte-editor');
        if (this.hasHeightValue && this.heightValue > 0) {
            this.textareaTarget.setAttribute('data-hugerte-editor-height-value', this.heightValue);
        }
    }
}
