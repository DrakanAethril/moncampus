import { Controller } from '@hotwired/stimulus';

// Autoévaluation - student entry (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9,
// screen 5b). The total is computed as the user types and « Valider mon autoévaluation » stays
// closed as long as a question is unanswered: either all of them are, or no submission.
// The server rechecks completeness - the disabled button is only an affordance.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'total', 'answered', 'hint', 'validate'];

    static values = {
        scale: Number,
        questionCount: Number,
        labels: Object,
    };

    connect() {
        this.refresh();
    }

    refresh() {
        let total = 0;
        let answered = 0;

        for (const input of this.inputTargets) {
            const value = this.read(input);
            // The pending question flags itself in amber, as on the mockup.
            input.classList.toggle('is-missing', value === null);
            if (value === null) continue;

            answered += 1;
            total += value;
        }

        this.totalTarget.textContent = answered ? String(Math.round(total * 100) / 100) : '—';

        const expected = this.questionCountValue || 1;
        this.answeredTarget.textContent = this.labelsValue.answeredLabel
            .replace('%count%', answered)
            .replace('%total%', expected);

        const complete = answered === expected;
        this.hintTarget.textContent = complete ? this.labelsValue.completeLabel : this.labelsValue.missingLabel;
        this.validateTarget.disabled = !complete;
    }

    // An estimate outside the scale (negative or above the question's maximum) does not count: the
    // server would bring it back within bounds anyway.
    read(input) {
        const raw = String(input.value).trim().replace(',', '.');
        if (raw === '' || Number.isNaN(Number(raw))) return null;

        const max = parseFloat(input.dataset.max);
        return Math.max(0, Math.min(max, Number(raw)));
    }
}
