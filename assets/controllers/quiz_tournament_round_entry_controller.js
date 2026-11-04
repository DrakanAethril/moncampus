import { Controller } from '@hotwired/stimulus';

// The round card of a student's tournament screen (design/validated/quiz-tournament.md §8):
// « Rejoindre la ronde », then « Tu as rejoint » while the teacher waits for everybody.
//
// It listens to the student's own counter topic (App\Service\QuizTournament\QuizTournamentLive),
// which every tournament screen of theirs already follows: `{"event": "round"}` - a round that
// expects them opened, was cancelled, or started without them - asks for the card again, and
// `{"event": "duel", "url": …}` - the pairing gave them a duel - goes to it. While they have joined,
// the page beats (POST …/beat): that is what says they are still in the room when the teacher
// pairs, and its answer carries their duel too, should the hub have said nothing.
const BEAT_MS = 10000;

export default class extends Controller {
    static targets = ['content'];
    static values = { cardUrl: String, hubUrl: String, topic: String };

    connect() {
        if (this.hubUrlValue && this.topicValue) {
            const url = new URL(this.hubUrlValue, window.location.origin);
            url.searchParams.append('topic', this.topicValue);
            this.source = new EventSource(url, { withCredentials: true });
            this.source.onmessage = (event) => this.#onEvent(event.data);
        }
        this.#syncBeat();
    }

    disconnect() {
        this.source?.close();
        window.clearInterval(this.beat);
    }

    async refresh() {
        try {
            const response = await fetch(this.cardUrlValue, { headers: { Accept: 'text/html' } });
            if (!response.ok) return;
            this.contentTarget.innerHTML = await response.text();
            this.#syncBeat();
        } catch {
            // The next event or beat tries again.
        }
    }

    #onEvent(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        if (data.event === 'duel' && data.url) {
            this.#go(data.url);
        } else if (data.event === 'round') {
            this.refresh();
        }
    }

    #syncBeat() {
        window.clearInterval(this.beat);
        const card = this.contentTarget.querySelector('[data-beat-url]');
        if (!card) return;
        this.#sendBeat(card);
        this.beat = window.setInterval(() => this.#sendBeat(card), BEAT_MS);
    }

    async #sendBeat(card) {
        try {
            const response = await fetch(card.dataset.beatUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': card.dataset.beatToken, Accept: 'application/json' },
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data.duelUrl) {
                this.#go(data.duelUrl);
            } else if (data.state !== 'joined') {
                this.refresh();
            }
        } catch {
            // The next beat tries again.
        }
    }

    #go(url) {
        if (this.going) return;
        this.going = true;
        window.clearInterval(this.beat);
        window.location.assign(url);
    }
}
