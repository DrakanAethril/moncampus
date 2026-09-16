import { Controller } from '@hotwired/stimulus';

/*
 * « + Groupe » — the button reveals its own field before it submits anything.
 *
 * The handoff draws one ghost button and no field, so the field cannot simply sit next to it. The
 * first click opens the input and swallows the submit; the second one is a real submit, and the
 * `required` on the input is what refuses an empty title without a round trip.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input'];

    open(event) {
        if (!this.hasInputTarget || !this.inputTarget.hidden) {
            return;
        }

        event.preventDefault();
        this.inputTarget.hidden = false;
        this.inputTarget.focus();
    }
}
