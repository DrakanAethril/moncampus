import { Controller } from '@hotwired/stimulus';

// « Murs collaboratifs », the list: the « Nouveau mur » dialog. A native <dialog> holding a plain
// form - the browser brings the backdrop and the Escape key, the form is answered by a redirect.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['dialog', 'title'];

    open() {
        this.dialogTarget.showModal();
        this.titleTarget.focus();
    }

    close() {
        this.dialogTarget.close();
    }
}
