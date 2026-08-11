import { Controller } from '@hotwired/stimulus';

/**
 * A Zone question during passation - click the right zone(s) of the support
 * (templates/program/_quiz_zone_take.html.twig). Mounted on the answer <form>. Clicking a zone
 * toggles its selection; the selected ids are mirrored into hidden zones[] fields so the form
 * posts the same shape whatever the support kind. The server re-validates every id against the
 * question's own config (ProgramQuizAttemptController::answer()).
 *
 * The "Indice" button dims every zone whose id is not in hintIdsValue - the value is only
 * rendered in entraînement, so an évaluation page simply carries no hint data at all.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['zone', 'fields'];
    // fieldName is overridden by the "Tester" tab, which namespaces by question id
    // ("zones[42]") since every question of the template sits on one page.
    static values = { hintIds: Array, fieldName: { type: String, default: 'zones' } };

    zoneClicked(event) {
        event.currentTarget.classList.toggle('is-selected');
        this.syncFields();
    }

    syncFields() {
        // Stimulus Array values re-parse on every access - not relevant here, but the selected
        // set is read once per sync for the same reason: one pass, one truth.
        const selected = this.zoneTargets.filter((zone) => zone.classList.contains('is-selected'));

        this.fieldsTarget.innerHTML = '';
        selected.forEach((zone) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${this.fieldNameValue}[]`;
            input.value = zone.dataset.zoneId;
            this.fieldsTarget.appendChild(input);
        });
    }

    toggleHint() {
        const hintIds = this.hintIdsValue;
        const showing = this.element.classList.toggle('is-hinting');

        this.zoneTargets.forEach((zone) => {
            const kept = hintIds.includes(zone.dataset.zoneId);
            zone.classList.toggle('cm-zone--dimmed', showing && !kept);
        });
    }
}
