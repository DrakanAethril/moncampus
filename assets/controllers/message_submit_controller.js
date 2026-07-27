import { Controller } from '@hotwired/stimulus';

// "Envoi en cours" state (design/design_handoff_messagerie #7) for the compose and reply forms -
// disables the submit button and swaps its label so a slow upload (attachments, broadcast fan-out)
// can't be double-submitted by an impatient click.
export default class extends Controller {
    static targets = ['button'];
    static values = { sendingLabel: String };

    connect() {
        this.element.addEventListener('submit', this._onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this._onSubmit);
    }

    _onSubmit = () => {
        if (!this.hasButtonTarget || this.buttonTarget.disabled) {
            return;
        }
        this._originalLabel = this.buttonTarget.textContent;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = this.sendingLabelValue;
    };
}
