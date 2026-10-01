import WidgetController from '../class_board/widget_controller.js';

// Consignes of the virtual board: the pictograms lit for the activity under way. Saved.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['item'];

    toggle(event) {
        const item = event.currentTarget;
        item.setAttribute('aria-pressed', String(item.getAttribute('aria-pressed') !== 'true'));
        this.store({
            on: this.itemTargets.filter((candidate) => candidate.getAttribute('aria-pressed') === 'true').map((candidate) => candidate.dataset.rule),
        });
    }
}
