import { Controller } from '@hotwired/stimulus';

/**
 * A submit button that asks for a second click instead of opening a confirm() dialog.
 *
 * The first click is swallowed and the button's label becomes the `label` value (« Confirmer la
 * suppression »); the second, within a few seconds, goes through. Left alone, the button goes back
 * to what it said - an armed button that stays armed is one somebody presses by accident ten
 * minutes later.
 *
 * Bound on the button, like confirm-submit, since a form can carry several of them.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        label: String,
        delay: { type: Number, default: 4000 },
    };

    disconnect() {
        this.disarm();
    }

    arm(event) {
        if (this.armed) {
            return;
        }

        event.preventDefault();
        this.armed = true;
        this.original = this.element.textContent;
        this.element.textContent = this.labelValue;
        this.element.classList.add('is-armed');
        this.timeout = setTimeout(() => this.disarm(), this.delayValue);
    }

    disarm() {
        clearTimeout(this.timeout);

        if (!this.armed) {
            return;
        }

        this.armed = false;
        this.element.textContent = this.original;
        this.element.classList.remove('is-armed');
    }
}
