import { Controller } from '@hotwired/stimulus';

/**
 * The relevé's grid: three states, one click each, saved as it goes.
 *
 * Deliberately not a form. A statement is complete the moment it is opened - everybody is net - so
 * there is nothing to submit and no way to leave one half-stated; each card posts its own answer
 * and the counters above the grid follow. A refusal (the period closed while the screen was open)
 * puts the card back where it was rather than pretending the click landed.
 */
export default class extends Controller {
    static targets = ['card', 'tally'];
    static values = { url: String, token: String, closedMessage: String, labels: Object };

    connect() {
        this.cardTargets.forEach((card) => card.addEventListener('click', this.onClick));
    }

    disconnect() {
        this.cardTargets.forEach((card) => card.removeEventListener('click', this.onClick));
    }

    onClick = (event) => {
        const card = event.currentTarget;

        if (card.disabled) {
            return;
        }

        this.save(card, this.nextState(card.dataset.state));
    };

    // net -> pas net -> hors comptage -> net. The same order the server walks, kept here so the
    // card answers instantly rather than after a round trip.
    nextState(state) {
        return { clean: 'not_clean', not_clean: 'out_of_scope', out_of_scope: 'clean' }[state] ?? 'clean';
    }

    async save(card, state) {
        const previous = card.dataset.state;
        this.paint(card, state);
        card.disabled = true;

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ student: Number(card.dataset.student), state }),
            });

            if (!response.ok) {
                this.paint(card, previous);
                if (response.status === 409) {
                    window.alert(this.closedMessageValue);
                }

                return;
            }

            const payload = await response.json();
            this.paint(card, payload.state);
            this.repaintTally(payload.tally);
        } catch (error) {
            this.paint(card, previous);
        } finally {
            card.disabled = false;
        }
    }

    paint(card, state) {
        card.dataset.state = state;
        card.className = `cm-att-card cm-att-card--${state}`;
        card.querySelector('.cm-att-card__mark').textContent = { clean: '✓', not_clean: '✕', out_of_scope: '–' }[state] ?? '';
        // Stimulus Object values are re-parsed on every access, so it is read once into a local.
        const labels = this.labelsValue;
        if (labels[state]) {
            card.querySelector('.cm-att-card__state').textContent = labels[state];
        }
    }

    repaintTally(tally) {
        this.tallyTargets.forEach((badge) => {
            const count = tally[badge.dataset.state];
            if (count !== undefined) {
                badge.textContent = badge.textContent.replace(/^\s*\d+/, String(count));
            }
        });
    }
}
