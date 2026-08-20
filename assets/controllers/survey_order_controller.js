import { Controller } from '@hotwired/stimulus';

/**
 * The "Ordre" question of a survey - the respondent ranks the proposed answers.
 *
 * Two things design/validated/surveys.md §7.12 insists on, and both are here:
 *
 *  - the ranking is doable **from the keyboard** as much as with the mouse, so every row carries an
 *    up and a down button beside its drag handle;
 *  - the controller sits on the *container*, never on the rows, because dragging moves the rows and
 *    a controller bound to one would be torn off with it.
 *
 * The submitted rank is the position of the row's hidden input in the form - never a separate
 * "rank" field the client and the server could contradict.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row', 'rank'];

    connect() {
        this.refresh();
    }

    moveUp(event) {
        const row = event.target.closest('.cm-survey-order__row');
        const previous = row?.previousElementSibling;
        if (previous) {
            row.parentNode.insertBefore(row, previous);
            this.refresh();
            event.target.focus();
        }
    }

    moveDown(event) {
        const row = event.target.closest('.cm-survey-order__row');
        const next = row?.nextElementSibling;
        if (next) {
            row.parentNode.insertBefore(next, row);
            this.refresh();
            event.target.focus();
        }
    }

    dragStart(event) {
        this.dragged = event.target.closest('.cm-survey-order__row');
        this.dragged?.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    }

    dragOver(event) {
        event.preventDefault();
        const over = event.target.closest('.cm-survey-order__row');
        if (!over || !this.dragged || over === this.dragged) {
            return;
        }
        const rows = [...this.rowTargets];
        const isAfter = rows.indexOf(this.dragged) < rows.indexOf(over);
        over.parentNode.insertBefore(this.dragged, isAfter ? over.nextSibling : over);
    }

    drop(event) {
        event.preventDefault();
        this.refresh();
    }

    dragEnd() {
        this.dragged?.classList.remove('is-dragging');
        this.dragged = null;
        this.refresh();
    }

    /** 1-based on screen; the stored rank is the position, computed server-side from the order. */
    refresh() {
        this.rankTargets.forEach((rank, index) => {
            rank.textContent = String(index + 1);
        });
    }
}
