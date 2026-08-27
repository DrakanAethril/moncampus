import { Controller } from '@hotwired/stimulus';

/**
 * The relevé creation form: the type decides which fields it carries.
 *
 * An attendance pass covers a stretch of time and needs a periodicity; a council happens on a day
 * and a label is the whole of it. Showing both at once would ask a professeur principal to answer
 * a question their document does not have.
 */
export default class extends Controller {
    static targets = ['type', 'periodicity'];

    connect() {
        this.typeChanged();
    }

    typeChanged() {
        const isAttendance = this.hasTypeTarget && this.typeTarget.value === 'attendance';

        if (this.hasPeriodicityTarget) {
            this.periodicityTarget.hidden = !isAttendance;
            this.periodicityTarget.querySelectorAll('input').forEach((input) => {
                input.disabled = !isAttendance;
            });
        }
    }
}
