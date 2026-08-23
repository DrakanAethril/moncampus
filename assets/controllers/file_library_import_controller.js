import { Controller } from '@hotwired/stimulus';

// The "Importer des fichiers" button lives in the page action bar, which the layout renders in
// `page_header` - outside the screen's own `.cm-flib` element. A `data-action` written there binds
// to nothing at all: Stimulus only wires actions to a controller whose scope contains the element,
// so the button was silently inert on every folder, root included.
//
// This is the bridge, and it follows the pattern the rest of the repository already uses for an
// action-bar control (`templates/program/lesson_logs.html.twig`): the button carries its own
// controller, and it talks to the library through a window event.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    pick() {
        this.dispatch('pick', { prefix: 'file-library' });
    }
}
