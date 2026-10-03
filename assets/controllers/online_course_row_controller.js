import { Controller } from '@hotwired/stimulus';

/**
 * One row of a teacher's public page (design/validated/cours-en-ligne.md, §5): the courses on a
 * single line, as many as it holds.
 *
 * The grid decides how many columns fit (`repeat(auto-fill, minmax(…))`); this counts them, hides
 * the cards beyond - `hidden`, so a keyboard does not walk into cards nobody sees - and moves the
 * arrow to the row's own page into the last card shown, where hovering that card reveals it.
 * Counted again whenever the row changes width.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['track', 'next'];

    connect() {
        this.observer = new ResizeObserver(() => this.layout());
        this.observer.observe(this.trackTarget);
        this.layout();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    layout() {
        const cards = Array.from(this.trackTarget.children).filter((child) => child !== this.nextTarget);
        const columns = getComputedStyle(this.trackTarget).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
        const shown = Math.min(columns, cards.length);

        cards.forEach((card, index) => { card.hidden = index >= shown; });

        const last = cards[shown - 1];
        if (last) {
            last.append(this.nextTarget);
            this.nextTarget.hidden = false;
        }
    }
}
