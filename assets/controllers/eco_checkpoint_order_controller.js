import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * The order of a « Balises spécifiques » race (templates/eco/_course_mode_fields.html.twig): the
 * ticked flags stand first, in the order of the race, and are dragged by their handle to reorder
 * it; the unticked ones follow in the parcours' order. Ticking a flag puts it last in the race,
 * unticking it sends it back among the others.
 *
 * The checkboxes are what the form reads for the selection; this controller only keeps the hidden
 * order field in step (the ids of the ticked rows, in their order) and numbers the ticked rows.
 * Nothing is sent from here: the order is saved with the form.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'row', 'order'];

    connect() {
        this.sortable = Sortable.create(this.listTarget, {
            handle: '.cm-eco-order__handle',
            draggable: '.cm-eco-order__row.is-selected',
            animation: 150,
            // Only among the ticked flags: an unticked one has no place in the race.
            onMove: (event) => event.related.classList.contains('is-selected'),
            onEnd: () => this.refresh(),
        });
        this.refresh();
    }

    disconnect() {
        this.sortable?.destroy();
    }

    toggle(event) {
        const row = event.target.closest('.cm-eco-order__row');
        if (!row) {
            return;
        }

        row.classList.toggle('is-selected', event.target.checked);
        const selected = this.rowTargets.filter((other) => other !== row && other.classList.contains('is-selected'));

        if (event.target.checked) {
            // Last of the race.
            const last = selected.at(-1);
            if (last) {
                last.after(row);
            } else {
                this.listTarget.prepend(row);
            }
        } else {
            // Back to its place among the unticked flags, in the parcours' order.
            const rank = Number(row.dataset.parcoursRank);
            const before = this.rowTargets.find((other) => other !== row
                && !other.classList.contains('is-selected')
                && Number(other.dataset.parcoursRank) > rank);
            if (before) {
                before.before(row);
            } else {
                this.listTarget.append(row);
            }
        }

        this.refresh();
    }

    refresh() {
        const rows = Array.from(this.listTarget.querySelectorAll('.cm-eco-order__row'));
        let rank = 0;
        const ids = [];

        rows.forEach((row) => {
            const selected = row.classList.contains('is-selected');
            const label = row.querySelector('.cm-eco-order__rank');
            if (selected) {
                rank += 1;
                ids.push(row.dataset.id);
            }
            if (label) {
                label.textContent = selected ? String(rank) : '';
            }
        });

        this.orderTarget.value = ids.join(',');
    }
}
