import { Controller } from '@hotwired/stimulus';

/**
 * Screen 4a's ten month columns scroll horizontally, and the design asks for the current month to
 * be centred on load ("au chargement, scroll centré sur le mois en cours"). A server-rendered page
 * cannot position a scrollbar, so this is the one thing that has to happen client-side.
 *
 * Runs once on connect and never listens to scroll afterwards - re-centring while the teacher is
 * browsing another month would fight them for control of the scrollbar.
 */
export default class extends Controller {
    static targets = ['current'];

    connect() {
        if (!this.hasCurrentTarget) {
            return;
        }

        const column = this.currentTarget;
        this.element.scrollLeft = Math.max(0, column.offsetLeft - (this.element.clientWidth - column.offsetWidth) / 2);
    }
}
