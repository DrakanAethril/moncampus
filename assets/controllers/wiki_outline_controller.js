import { Controller } from '@hotwired/stimulus';

/**
 * Shows and hides the page outline (« Sur cette page ») from the action bar.
 *
 * The outline is a third grid column worth 230px of reading width - a quarter of the text on a
 * 1400px screen - so it stays closed at load and costs nothing until somebody asks for it. The
 * choice is deliberately not remembered: no cookie, no user preference, every page opens closed,
 * which is the rule the rail's own folding already follows.
 *
 * The button lives in the page header and the grid in the page body - two separate subtrees of
 * layout/app.html.twig - so the grid is reached by selector rather than by a Stimulus target.
 * The class is only ever put on a page that has an outline, so the selector cannot match a screen
 * where the toggle would do nothing.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    toggle() {
        const layout = document.querySelector('.cm-wiki--with-outline');
        if (!layout) {
            return;
        }

        const open = layout.classList.toggle('is-outline-open');
        this.element.classList.toggle('is-active', open);
        this.element.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
}
