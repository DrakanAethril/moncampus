import { Controller } from '@hotwired/stimulus';

/**
 * A `<select>` whose options ARE addresses: picking one goes there.
 *
 * Not a GET form, because each option leads to a different route rather than to the same one with
 * another value - the cahier de texte's class picker moves between `/lesson-log` and
 * `/programs/{id}/lesson-log`, which no single form action can express.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    go(event) {
        const url = event.target.value;

        if (url) {
            window.location.assign(url);
        }
    }
}
