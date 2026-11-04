import { Controller } from '@hotwired/stimulus';

// The « Défier » sheet of a tournament's standings (design/validated/quiz-tournament.md §4.1, mockup
// H-defier): each « Défier » names the address of its sheet, drawn by the server
// (app_quiz_tournament_challenge_sheet) - the opponent, the enjeu, the reminders, the reason when
// the rules refuse - and this controller shows it in the page's <dialog>, as a bottom sheet. The
// form inside is an ordinary POST: Turbo follows its redirect to « Mes défis ».
export default class extends Controller {
    static targets = ['dialog', 'content'];

    async open(event) {
        const url = event.params.url;
        if (!url) return;
        this.trigger = event.currentTarget;

        let html;
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok) return;
            html = await response.text();
        } catch {
            return;
        }

        this.contentTarget.innerHTML = html;
        if (!this.dialogTarget.open) this.dialogTarget.showModal();
    }

    close() {
        if (this.dialogTarget.open) this.dialogTarget.close();
    }

    // A click on the dimmed backdrop lands on the <dialog> itself, never on its content.
    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    closed() {
        this.contentTarget.innerHTML = '';
        this.trigger?.focus();
    }
}
