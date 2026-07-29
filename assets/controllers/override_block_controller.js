import { Controller } from '@hotwired/stimulus';

// Turn-24 collapsible override block (design_handoff_ufa 24c/24d): the block is either
// "surchargé" (open, HugeRTE editor visible, green badge + "Rétablir le défaut") or "hérité"
// (collapsed, no editor, grey badge + "Surcharger" switch). Flipping the switch on seeds the
// editor with the inherited default HTML ("une surcharge est une copie" rule) and expands the
// block; flipping it back off collapses the block and disables the textarea so nothing is
// submitted (the save action treats a missing/blank value as "no override"). Complements
// editorblock_controller.js (the 8b flat pattern the Program > Paramétrage path still uses).
export default class extends Controller {
    static targets = ['body', 'textarea', 'switch'];
    static values = { seed: { type: String, default: '' }, height: { type: Number, default: 200 } };

    toggle(event) {
        // Clicks on the state controls (switch label, reset submit) shouldn't fold the block.
        if (event.target.closest('.cm-collapse__state')) {
            return;
        }
        // Inherited & not yet surchargé: there is no body content to reveal.
        if (this.hasSwitchTarget && !this.switchTarget.checked) {
            return;
        }
        this.element.classList.toggle('is-open');
    }

    toggleOverride() {
        if (this.switchTarget.checked) {
            this.activate();
        } else {
            this.textareaTarget.disabled = true;
            this.element.classList.remove('is-open');
        }
    }

    // Setting data-controller here is what makes Stimulus's mutation observer connect
    // hugerte_editor_controller.js, same as if the attribute had been server-rendered
    // (see editorblock_controller.js).
    activate() {
        this.textareaTarget.disabled = false;
        if ('' === this.textareaTarget.value && '' !== this.seedValue) {
            this.textareaTarget.value = this.seedValue;
        }
        this.textareaTarget.setAttribute('data-hugerte-editor-height-value', this.heightValue);
        this.textareaTarget.setAttribute('data-controller', 'hugerte-editor');
        this.element.classList.add('is-open');
    }
}
