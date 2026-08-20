import { Controller } from '@hotwired/stimulus';

// The wiki creation form takes one of two shapes: compose an audience by hand, or turn a saved set
// of groups into one wiki per group. Switching hides the panel that does not apply AND disables its
// fields - a hidden-but-enabled <select> is still submitted, and the server decides which mode it is
// in purely from whether a groupBatch id arrived.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.choose({ params: { mode: 'audience' } });
    }

    choose(event) {
        const mode = event.params?.mode ?? 'audience';

        for (const panel of this.element.closest('form').querySelectorAll('[data-wiki-target-panel]')) {
            const applies = panel.dataset.wikiTargetPanel === mode;
            panel.hidden = !applies;

            for (const field of panel.querySelectorAll('input, select, textarea')) {
                field.disabled = !applies;
            }
        }
    }
}
