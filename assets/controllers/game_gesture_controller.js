import { Controller } from '@hotwired/stimulus';

/**
 * The gesture form: choosing « malus » is what reveals its object and flips the three values from
 * + to −.
 *
 * The sign is never a field of its own. A teacher picks a kind and a magnitude, and the server
 * builds the signed value from the pair - so a malus that lost its object between the click and the
 * submit is refused rather than posted as a bonus.
 */
export default class extends Controller {
    static targets = ['object', 'value'];

    connect() {
        this.kindChanged();
    }

    kindChanged() {
        const malus = this.element.querySelector('input[name="kind"][value="malus"]')?.checked ?? false;

        if (this.hasObjectTarget) {
            this.objectTarget.hidden = !malus;
            this.objectTarget.querySelectorAll('input').forEach((input) => {
                input.disabled = !malus;
            });
        }

        this.valueTargets.forEach((label) => {
            label.textContent = `${malus ? '−' : '+'}${label.dataset.value}`;
        });
    }
}
