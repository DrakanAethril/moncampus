import { Controller } from '@hotwired/stimulus';

// Pédagogique > Périodes d'évaluation - édition (design 12b). Row add/remove follows Symfony's
// documented CollectionType "manual prototype" pattern (data-prototype/__name__ on
// periodsContainer, see templates/settings/evaluation_period_group_new.html.twig) since, unlike
// QuizQuestionType's answers list, these rows have no reordering/complex-field needs that would
// justify fighting CollectionType with a raw-array approach instead.
//
// The overlap check re-implements App\Entity\EvaluationPeriodGroup::validateNoOverlappingPeriods()
// client-side as a same-page preview only - the server-side #[Assert\Callback] is still the real
// guard (see form_errors(form.periods) in the template), since JS can always be bypassed.
export default class extends Controller {
    static targets = ['periodsContainer', 'periodRow', 'startInput', 'endInput', 'overlapAlert', 'overlapAlertText', 'submitButton'];

    static values = {
        overlapMessage: String,
    };

    connect() {
        this.index = this.periodRowTargets.length;
        this.checkOverlaps();
    }

    addRow() {
        const html = this.periodsContainerTarget.dataset.prototype.replaceAll(this.periodsContainerTarget.dataset.prototypeName, String(this.index));
        this.index += 1;
        this.periodsContainerTarget.insertAdjacentHTML('beforeend', html);
        this.checkOverlaps();
    }

    removeRow(event) {
        event.currentTarget.closest('[data-evaluation-period-group-form-target~="periodRow"]').remove();
        this.checkOverlaps();
    }

    checkOverlaps() {
        // ISO yyyy-mm-dd <input type="date"> values sort correctly as plain strings - no need to
        // parse into Date objects just to compare order.
        const rows = this.periodRowTargets
            .map((row, index) => ({
                name: row.querySelector('input[type="text"]')?.value.trim() || `#${index + 1}`,
                start: row.querySelector('[data-evaluation-period-group-form-target~="startInput"]')?.value,
                end: row.querySelector('[data-evaluation-period-group-form-target~="endInput"]')?.value,
            }))
            .filter((row) => row.start && row.end);

        let overlap = null;
        for (let i = 0; i < rows.length && !overlap; i += 1) {
            for (let j = i + 1; j < rows.length; j += 1) {
                const a = rows[i];
                const b = rows[j];
                if (a.start <= b.end && b.start <= a.end) {
                    overlap = { a, b };
                    break;
                }
            }
        }

        this.overlapAlertTarget.hidden = !overlap;
        if (overlap) {
            this.overlapAlertTextTarget.textContent = this.overlapMessageValue
                .replace('__A__', overlap.b.name)
                .replace('__B__', overlap.a.name)
                .replace('__BSTART__', this.formatDate(overlap.a.start))
                .replace('__BEND__', this.formatDate(overlap.a.end));
        } else {
            this.overlapAlertTextTarget.textContent = '';
        }

        this.submitButtonTarget.disabled = !!overlap;
    }

    formatDate(isoDate) {
        const [year, month, day] = isoDate.split('-');

        return `${day}/${month}/${year}`;
    }
}
