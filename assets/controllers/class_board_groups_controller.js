import WidgetController from '../class_board/widget_controller.js';

// Groupes of the virtual board. The groups themselves are drawn by the group creation tool's own
// controller, nested in the widget's body (templates/class_board/widgets/_groups.html.twig); this
// one is only the widget's side of it: it keeps the panel's settings with the board, and shows or
// hides that panel - once the groups are drawn, the class reads the cards, not the settings.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['root', 'panelToggle'];

    keepSettings(event) {
        this.store(event.detail);
    }

    togglePanel() {
        const shown = this.rootTarget.classList.toggle('is-panel-hidden') === false;
        this.panelToggleTarget.setAttribute('aria-pressed', String(shown));
        this.store({ panel: shown });
    }
}
