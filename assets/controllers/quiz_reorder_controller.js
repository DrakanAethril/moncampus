import { Controller } from '@hotwired/stimulus';

/**
 * The "ordre" question as the student answers it - templates/program/_quiz_ordre_take.html.twig.
 *
 * Two ways of ranking, and both are needed rather than one replacing the other: the row can be
 * dragged, and it carries an up and a down arrow so the ranking stays doable from the keyboard.
 * Same shape as the survey's own ranking question (assets/controllers/survey_order_controller.js).
 *
 * The controller sits on the *container*, never on a row: dragging moves the rows, and a
 * controller bound to one would be torn off with it.
 *
 * Submission order of the hidden answers[] inputs *is* the student's proposed sequence -
 * App\Controller\ProgramQuizAttemptController::answer() and App\Service\QuizAttemptGrader compare
 * it against each answer's true orderIndex. The rank shown on screen is only a reading of that
 * position, recomputed after every move.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row', 'rank'];

    connect() {
        this.refresh();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-quiz-reorder-target="row"]');
        const previous = row?.previousElementSibling;
        if (previous) {
            row.parentElement.insertBefore(row, previous);
            this.refresh();
            event.currentTarget.focus();
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-quiz-reorder-target="row"]');
        const next = row?.nextElementSibling;
        if (next) {
            row.parentElement.insertBefore(next, row);
            this.refresh();
            event.currentTarget.focus();
        }
    }

    dragStart(event) {
        this.dragged = event.target.closest('[data-quiz-reorder-target="row"]');
        this.dragged?.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Firefox starts no drag at all unless something is written to the transfer.
        event.dataTransfer.setData('text/plain', '');
    }

    dragOver(event) {
        event.preventDefault();
        const over = event.target.closest('[data-quiz-reorder-target="row"]');
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

    /** 1-based on screen; the answer that counts is the row's position among its siblings. */
    refresh() {
        this.rankTargets.forEach((rank, index) => {
            rank.textContent = String(index + 1);
        });
    }
}
