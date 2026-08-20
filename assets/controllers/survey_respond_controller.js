import { Controller } from '@hotwired/stimulus';

/**
 * « Question 7 sur 12 » under the respondent's form.
 *
 * The total counts the *answerable* questions only - an intertitle is a line in the ordering and
 * nothing else, and a counter including it would never reach its maximum (surveys.md §7.13). The
 * server already excludes it, so this simply counts the question blocks the page holds.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['question', 'progress'];
    static values = { answeredLabel: String };

    connect() {
        this.refresh();
    }

    answered() {
        this.refresh();
    }

    refresh() {
        const done = this.questionTargets.filter((question) => this.isAnswered(question)).length;
        this.progressTarget.textContent = this.answeredLabelValue
            .replace('%done%', String(done))
            .replace('%count%', String(this.questionTargets.length));
    }

    isAnswered(question) {
        if (question.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked')) {
            return true;
        }
        // A ranking question is answered as soon as it is shown: its rows always carry an order.
        if (question.querySelector('.cm-survey-order')) {
            return true;
        }
        const text = question.querySelector('textarea');

        return Boolean(text && text.value.trim().length > 0);
    }
}
