import { Controller } from '@hotwired/stimulus';

/**
 * Shows a block of fields only while a checkbox is ticked - the séance form's "cette séance
 * contient une évaluation" and the nature select it gates.
 *
 * Purely an affordance: the server is what decides whether the gated fields are required (see
 * SequenceLibraryController::seanceForm()), so a panel hidden here is never a field silently
 * dropped. It stays in the DOM (a class, not `hidden`) so its value is still submitted and the
 * server can clear it deliberately rather than guess.
 */
export default class extends Controller {
    static targets = ['toggle', 'panel'];

    connect() {
        this.refresh();
    }

    refresh() {
        this.panelTargets.forEach((panel) => panel.classList.toggle('d-none', !this.toggleTarget.checked));
    }
}
