import { Controller } from '@hotwired/stimulus';

/**
 * Copies the value carried by the clicked button - the IP address of each row of the machines
 * list. Declared once on the table rather than once per row: the value travels as an action
 * param, so a hundred rows still make one controller instance.
 *
 * Sibling of copy_block_controller.js (copies a DOM block) and resource_copy_link_controller.js
 * (one button, one value held in a controller value). This one is the many-buttons case, where
 * the feedback is an icon swap rather than a label change.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    async copy(event) {
        const button = event.currentTarget;
        const text = event.params.text;

        if (!text) {
            return;
        }

        await navigator.clipboard.writeText(text);

        // The button carries both icons and CSS shows one of them; the class is the whole state.
        button.classList.add('cm-copybtn--done');
        window.setTimeout(() => {
            button.classList.remove('cm-copybtn--done');
        }, 1500);
    }
}
