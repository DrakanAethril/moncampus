import { Controller } from '@hotwired/stimulus';

/**
 * Shows only the action button matching the currently active Bootstrap tab, so a
 * single button slot in the card header can serve several tab-panes (see
 * templates/settings/structure.html.twig).
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.onTabShown = (event) => this.sync(event.target.getAttribute('href'));
        document.addEventListener('shown.bs.tab', this.onTabShown);
    }

    disconnect() {
        document.removeEventListener('shown.bs.tab', this.onTabShown);
    }

    sync(activePaneHash) {
        const activePaneId = activePaneHash.replace('#', '');
        this.buttonTargets.forEach((button) => {
            button.classList.toggle('d-none', button.dataset.tabActionPane !== activePaneId);
        });
    }
}
