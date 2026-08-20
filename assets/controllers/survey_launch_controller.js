import { Controller } from '@hotwired/stimulus';

/**
 * The launch screen - keeps the number of people aimed at on the button, and hides the « travail à
 * faire » fieldset when no class is targeted.
 *
 * That second rule is not cosmetic: Assignment.program_id is NOT NULL, so a campaign aiming at
 * « tous les enseignants » has no travail à faire at all and must not offer one (surveys.md §7.9).
 * The server decides the same thing again - this only spares the author a promise the launch would
 * not keep.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['form', 'programs', 'includes', 'assignments', 'count', 'submit'];
    static values = { previewUrl: String };

    connect() {
        this.audienceChanged();
    }

    audienceChanged() {
        this.toggleAssignments();
        this.schedulePreview();
    }

    toggleAssignments() {
        const programsPicked = this.programsTarget.querySelectorAll('input:checked').length > 0;
        const students = this.includesTarget.querySelector('input[name*="includeStudents"]')?.checked;

        this.assignmentsTarget.classList.toggle('d-none', !(programsPicked && students));
    }

    /** Debounced: ticking three classes in a row is one question to the server, not three. */
    schedulePreview() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.preview(), 250);
    }

    async preview() {
        const response = await fetch(this.previewUrlValue, {
            method: 'POST',
            body: new FormData(this.formTarget),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            return;
        }

        // Both wordings come back already pluralised: they are ICU plural strings, and only the
        // server can pick the right branch for a number it has just computed.
        const { buttonLabel, summary } = await response.json();
        this.countTarget.textContent = summary;
        this.submitTarget.textContent = buttonLabel;
    }
}
