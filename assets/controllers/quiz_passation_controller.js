import { Controller } from '@hotwired/stimulus';

// Screen 1e - per-question countdown chip. Client-side only, driving UX (auto-advance so a
// student can't stall on one question forever) - server-side enforcement is the separate global
// time budget (QuizAttempt::isPastTimeLimit(), lazily checked on every request that touches the
// attempt), same split as the QCM anti-cheat design this reuses: "client-side countdown drives
// UX and auto-POSTs at expiry, but enforcement is server-side".
export default class extends Controller {
    static targets = ['timerText'];

    static values = {
        seconds: Number,
        formId: String,
    };

    connect() {
        this.remaining = this.secondsValue;
        this.render();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        this.remaining -= 1;
        if (this.remaining <= 0) {
            clearInterval(this.interval);
            document.getElementById(this.formIdValue)?.requestSubmit();

            return;
        }
        this.render();
    }

    render() {
        const minutes = Math.floor(this.remaining / 60);
        const seconds = this.remaining % 60;
        this.timerTextTarget.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }
}
