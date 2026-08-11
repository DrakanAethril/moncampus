import { Controller } from '@hotwired/stimulus';

/**
 * Copies the text content of its source target to the clipboard - the "Copier le prompt" button
 * of the interactive quiz import, and any future copy-this-block need. Sibling of
 * resource_copy_link_controller.js, which copies a value rather than a DOM block.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source'];

    async copy(event) {
        const button = event.currentTarget;
        await navigator.clipboard.writeText(this.sourceTarget.innerText.trim());

        const original = button.textContent;
        button.textContent = event.params.doneLabel || original;
        setTimeout(() => {
            button.textContent = original;
        }, 1800);
    }
}
