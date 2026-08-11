import { Controller } from '@hotwired/stimulus';

/**
 * A Légende question during passation - click a label chip, then the zone it belongs on
 * (templates/program/_quiz_legende_take.html.twig). Same click-not-drag interaction as the word
 * bank (quiz_blanks_take_controller.js), and for the same reasons: it works identically with a
 * finger, and needs no drag library. Clicking a zone that already carries a label takes the label
 * back. Placements are mirrored into hidden placements[zoneId] fields; the server re-validates
 * both sides of every pair (ProgramQuizAttemptController::answer()).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['zone', 'choice', 'fields', 'counter'];
    // fieldName is overridden by the "Tester" tab, which namespaces by question id
    // ("placements[42]") since every question of the template sits on one page.
    static values = { countTemplate: String, fieldName: { type: String, default: 'placements' } };

    connect() {
        // zoneId => { key, chip } - the single source the fields and the chip states render from.
        this.placements = new Map();
        this.activeChoice = null;
        this.render();
    }

    choiceClicked(event) {
        const chip = event.currentTarget;
        if (chip.classList.contains('is-used')) {
            return;
        }

        this.activeChoice = this.activeChoice === chip ? null : chip;
        this.render();
    }

    zoneClicked(event) {
        const zone = event.currentTarget;
        const zoneId = zone.dataset.zoneId;

        const existing = this.placements.get(zoneId);
        if (existing) {
            // Second click on a filled zone: give the label back, whatever chip is active.
            this.placements.delete(zoneId);
            this.render();
            return;
        }

        if (!this.activeChoice) {
            return;
        }

        this.placements.set(zoneId, { key: this.activeChoice.dataset.choiceKey, chip: this.activeChoice });
        this.activeChoice = null;
        this.render();
    }

    render() {
        const placedChips = new Set([...this.placements.values()].map((placement) => placement.chip));

        // Same chip states as the word bank (is-selected / is-used), so the existing styles apply.
        this.choiceTargets.forEach((chip) => {
            chip.classList.toggle('is-used', placedChips.has(chip));
            chip.classList.toggle('is-selected', chip === this.activeChoice);
        });

        this.zoneTargets.forEach((zone) => {
            const placement = this.placements.get(zone.dataset.zoneId);
            const slot = zone.querySelector('.cm-zone__placed');
            zone.classList.toggle('is-filled', Boolean(placement));
            if (slot) {
                slot.hidden = !placement;
                slot.textContent = placement ? placement.chip.textContent : '';
            }
        });

        this.fieldsTarget.innerHTML = '';
        this.placements.forEach((placement, zoneId) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${this.fieldNameValue}[${zoneId}]`;
            input.value = placement.key;
            this.fieldsTarget.appendChild(input);
        });

        if (this.hasCounterTarget && this.countTemplateValue) {
            this.counterTarget.textContent = this.countTemplateValue
                .replace('%placed%', String(this.placements.size))
                .replace('%total%', String(this.zoneTargets.length));
        }
    }
}
