import { Controller } from '@hotwired/stimulus';

/**
 * Shortcut chips above a date-time field (mockup 2b: « Prochaine séance · 11 août », « Date et
 * heure… »).
 *
 * The native field stays the source of truth - it is what goes to the server, and it keeps working
 * on its own if this controller does not load. The chips only fill it in, and the active one is
 * inferred from its value rather than from a state kept alongside, which could diverge as soon as
 * the user types a date by hand.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['field', 'preset', 'custom'];

    connect() {
        this.refresh();
    }

    apply(event) {
        const { value } = event.currentTarget.dataset;

        this.fieldTarget.value = value;
        this.refresh();
    }

    // « Date et heure… » sets no value: it opens the field so the user can write.
    reveal() {
        this.customTarget.classList.remove('d-none');
        this.fieldTarget.focus();
        this.refresh();
    }

    refresh() {
        const current = this.fieldTarget.value;
        let matched = false;

        this.presetTargets.forEach((preset) => {
            const isActive = preset.dataset.value === current;
            preset.classList.toggle('is-selected', isActive);
            matched ||= isActive;
        });

        // A date matching no chip is necessarily free input: the field then stays visible, without
        // which the user would no longer see what they wrote.
        if (!matched) {
            this.customTarget.classList.remove('d-none');
        }
    }
}
