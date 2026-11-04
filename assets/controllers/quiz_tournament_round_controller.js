import { Controller } from '@hotwired/stimulus';

// A round in class, projected (design/validated/quiz-tournament.md §8 and §10, mockup H-ronde).
//
// The stage is drawn by the server (templates/quiz_tournament/_round_board.html.twig); this page
// only asks for it again. Over Mercure it follows the round's topic
// (App\Service\QuizTournament\QuizTournamentLive::roundTopic()): « roster » when a student joins or
// leaves - the stage is asked again -, and a summary of each duel as it moves, whose scores and
// question are written into its card at once (a duel that ends asks for the stage, which names the
// winner). The stage is also asked again at a steady pace, which is what drops a student whose page
// stopped beating, and what keeps the screen right without the hub; it stops once every duel is over.
const JOIN_REFRESH_MS = 10000;
const DUELS_REFRESH_MS = 15000;
const DEBOUNCE_MS = 400;

export default class extends Controller {
    static targets = ['stage'];
    static values = { boardUrl: String, hubUrl: String, topic: String, questionTemplate: String };

    connect() {
        if (this.hubUrlValue && this.topicValue) {
            const url = new URL(this.hubUrlValue, window.location.origin);
            url.searchParams.append('topic', this.topicValue);
            this.source = new EventSource(url, { withCredentials: true });
            this.source.onmessage = (event) => this.#onEvent(event.data);
        }
        this.#schedulePolling();
    }

    disconnect() {
        this.source?.close();
        window.clearInterval(this.poll);
        window.clearTimeout(this.debounce);
    }

    async refresh() {
        try {
            const response = await fetch(this.boardUrlValue, { headers: { Accept: 'text/html' } });
            if (!response.ok) return;
            this.stageTarget.innerHTML = await response.text();
            this.#schedulePolling();
        } catch {
            // The next pass tries again.
        }
    }

    #onEvent(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        if (data.event === 'roster') {
            this.#refreshSoon();
        } else if (data.event === 'duel') {
            this.#applyDuel(data);
        }
    }

    #applyDuel(data) {
        const card = this.stageTarget.querySelector(`[data-duel-id="${data.duel}"]`);
        if (!card || data.phase === 'finished') {
            this.#refreshSoon();
            return;
        }

        const scores = data.scores ?? {};
        this.#write(card, '[data-score="a"]', this.#score(scores[card.dataset.playerA]));
        this.#write(card, '[data-score="b"]', this.#score(scores[card.dataset.playerB]));
        if (Number.isInteger(data.rank) && Number.isInteger(data.total) && data.total > 0) {
            const number = data.rank + 1;
            const fill = card.querySelector('[data-progress]');
            if (fill) fill.style.width = `${Math.round((number / data.total) * 100)}%`;
            this.#write(card, '[data-status]', this.questionTemplateValue.replace('%number%', number).replace('%total%', data.total));
        }
    }

    #write(card, selector, text) {
        const element = card.querySelector(selector);
        if (element && text !== null) element.textContent = text;
    }

    #score(value) {
        if (typeof value !== 'number') return null;
        return Number.isInteger(value) ? String(value) : value.toFixed(1).replace('.', ',');
    }

    #refreshSoon() {
        window.clearTimeout(this.debounce);
        this.debounce = window.setTimeout(() => this.refresh(), DEBOUNCE_MS);
    }

    #schedulePolling() {
        window.clearInterval(this.poll);
        const inner = this.stageTarget.querySelector('[data-state]');
        const state = inner?.dataset.state;
        if (state === 'join') {
            this.poll = window.setInterval(() => this.refresh(), JOIN_REFRESH_MS);
        } else if (state === 'duels' && inner.dataset.allFinished !== 'true') {
            this.poll = window.setInterval(() => this.refresh(), DUELS_REFRESH_MS);
        }
    }
}
