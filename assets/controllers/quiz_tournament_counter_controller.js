import { Controller } from '@hotwired/stimulus';

// The « Défis (n) » counter of a tournament's tab bar (design/validated/quiz-tournament.md §7):
// received challenges + open rooms, drawn by the server, then kept current over Mercure while a
// tournament screen is open - a private topic per person and tournament
// (App\Service\QuizTournament\QuizTournamentLive::counterTopic()), subscribed through the bundle's
// cookie. An update of the page, never an alert: nothing else listens to it.
export default class extends Controller {
    static targets = ['count'];
    static values = { url: String, topic: String };

    connect() {
        if (!this.urlValue || !this.topicValue) return;

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.append('topic', this.topicValue);
        this.source = new EventSource(url, { withCredentials: true });
        this.source.onmessage = (event) => {
            let data;
            try {
                data = JSON.parse(event.data);
            } catch {
                return;
            }
            if (Number.isInteger(data.count)) this.render(data.count);
        };
    }

    disconnect() {
        this.source?.close();
    }

    render(count) {
        if (!this.hasCountTarget) return;
        this.countTarget.textContent = `\u00a0(${count})`;
        this.countTarget.hidden = count <= 0;
    }
}
