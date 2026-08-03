import { Controller } from '@hotwired/stimulus';

// Autoévaluation - saisie étudiante (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9,
// écran 5b). Le total se calcule à la frappe et « Valider mon autoévaluation » reste fermé tant
// qu'une question n'est pas renseignée : toutes le sont ou aucune validation.
// Le serveur revérifie la complétude - le bouton désactivé n'est qu'une affordance.
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
            // La question en attente se signale en ambre, comme sur la maquette.
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

    // Une estimation hors barème (négative ou au-dessus du maximum de la question) ne compte pas :
    // le serveur la ramènerait de toute façon dans les bornes.
    read(input) {
        const raw = String(input.value).trim().replace(',', '.');
        if (raw === '' || Number.isNaN(Number(raw))) return null;

        const max = parseFloat(input.dataset.max);
        return Math.max(0, Math.min(max, Number(raw)));
    }
}
