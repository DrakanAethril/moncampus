import { Controller } from '@hotwired/stimulus';

/**
 * A last word before a submit button posts its form: the message is shown, and a "cancel" leaves
 * the page exactly as it was.
 *
 * Bound on the button rather than on the form on purpose - a form can carry several submit buttons
 * (the séquence import's review screen has "Appliquer ces décisions" next to "Créer la séquence"),
 * and only one of them is the point of no return.
 *
 * The message is a value rather than a fixed string so the template decides *whether* there is
 * anything to confirm: an empty value submits straight through.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        message: String,
    };

    confirm(event) {
        if (this.messageValue === '') {
            return;
        }

        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
