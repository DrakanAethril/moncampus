import { Controller } from '@hotwired/stimulus';

/**
 * The council's one-pass entry: a keystroke per mention, saved as it lands.
 *
 * Not a form, and deliberately: a council is thirty decisions taken out loud in an hour, and a
 * screen that has to be submitted at the end is a screen somebody leaves without submitting. Each
 * mention and each comment posts on its own; the points column shows the value of what was just
 * placed and nothing else about the student.
 *
 * The keyboard is the point of the screen. With focus anywhere in a row, X / F / C / E / N / A place
 * a mention and Enter moves to the next student - so the whole class is entered without a mouse and
 * without ever changing page.
 */
export default class extends Controller {
    static targets = ['row', 'points', 'stated'];
    static values = { url: String, token: String, lockedMessage: String, shortcuts: Object };

    connect() {
        this.element.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown = (event) => {
        const row = event.target.closest('[data-game-council-target="row"]');

        if (!row) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            this.focusNext(row);

            return;
        }

        // A comment field takes its letters as letters; the shortcuts belong to the row itself.
        if (event.target.matches('.cm-council__comment')) {
            return;
        }

        // Read once into a local: Stimulus Object values are re-parsed on every access.
        const shortcuts = this.shortcutsValue;
        const mention = shortcuts[event.key.toUpperCase()];

        if (!mention) {
            return;
        }

        event.preventDefault();
        const input = row.querySelector(`input[type="radio"][value="${mention}"]`);

        if (input && !input.disabled) {
            input.checked = true;
            this.send(row, { mention });
        }
    };

    mention(event) {
        const row = event.target.closest('[data-game-council-target="row"]');
        this.send(row, { mention: event.target.value });
    }

    comment(event) {
        const row = event.target.closest('[data-game-council-target="row"]');
        clearTimeout(this.commentTimer);
        // Typed text is saved on a short pause rather than on every character: a comment is a
        // sentence, not thirty requests.
        this.commentTimer = setTimeout(() => this.send(row, { comment: event.target.value }), 500);
    }

    async send(row, body) {
        if (!row) {
            return;
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ student: Number(row.dataset.student), mention: '', comment: '', ...body }),
            });

            if (response.status === 409) {
                window.alert(this.lockedMessageValue);

                return;
            }

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const cell = row.querySelector('[data-game-council-target="points"]');

            if (cell && payload.points !== undefined) {
                cell.textContent = payload.points > 0 ? `+${payload.points}` : String(payload.points);
            }

            // « 18 / 30 saisies » follows every keystroke: it is the only progress a professeur
            // principal has while the class is being entered.
            if (this.hasStatedTarget && payload.stated !== undefined) {
                this.statedTarget.textContent = String(payload.stated);
            }
        } catch (error) {
            // A dropped request leaves the row as the teacher set it; the next keystroke retries.
        }
    }

    focusNext(row) {
        const rows = this.rowTargets;
        const next = rows[rows.indexOf(row) + 1];

        if (next) {
            (next.querySelector('input[type="radio"]:not([disabled])') ?? next).focus();
        }
    }
}
