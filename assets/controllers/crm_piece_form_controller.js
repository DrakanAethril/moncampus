import { Controller } from '@hotwired/stimulus';

/**
 * A piece's form in the settings: the values of a list show for a choice or a list, « dernière
 * valeur » for a list, the payment mode for an amount.
 */
export default class extends Controller {
    static targets = ['kind', 'choices', 'last', 'payment'];

    connect() {
        this.sync();
    }

    sync() {
        const kind = this.kindTarget.value;
        if (this.hasChoicesTarget) this.choicesTarget.hidden = kind !== 'list' && kind !== 'choice';
        if (this.hasLastTarget) this.lastTarget.hidden = kind !== 'list';
        if (this.hasPaymentTarget) this.paymentTarget.hidden = kind !== 'amount';
    }
}
