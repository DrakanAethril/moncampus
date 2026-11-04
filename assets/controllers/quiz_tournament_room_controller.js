import { Controller } from '@hotwired/stimulus';

// A duel's room (design/validated/quiz-tournament.md §4.3, mockup H-salle).
//
// « Je suis prêt » holds while the page is open: once ready, the page beats every 20 seconds
// (POST …/ready - the server forgets a « Prêt » older than 45 s), and says when it is left - a
// beacon on `pagehide`, and on `disconnect` since a Turbo visit to another screen fires no
// pagehide. While not ready, it asks the room's state at the same pace. The other player's
// « Prêt » arrives at once over Mercure (the room's private topic); the beats are what keeps the
// page right without it.
//
// The duel starts on the server, in whichever request sees both ready
// (QuizTournamentDuelStarter); the page learns it from that request's answer, from the room's
// « start » event, or from its next beat - then shows « C'est parti dans 3 » and goes to the duel.
const BEAT_MS = 20000;
const COUNTDOWN_FROM = 3;

export default class extends Controller {
    static targets = ['meCard', 'opponentCard', 'readyButton', 'waiting', 'rules', 'countdown', 'countdownNumber', 'refusal', 'actions', 'timeLeft'];
    static values = {
        readyUrl: String,
        leaveUrl: String,
        stateUrl: String,
        token: String,
        hubUrl: String,
        topic: String,
        me: Number,
        state: Object,
        leftTemplate: String,
        closedText: String,
    };

    connect() {
        this.current = this.stateValue;
        this.starting = false;
        this.onPageHide = () => this.#sendLeave();
        window.addEventListener('pagehide', this.onPageHide);

        if (this.hubUrlValue && this.topicValue) {
            const url = new URL(this.hubUrlValue, window.location.origin);
            url.searchParams.append('topic', this.topicValue);
            this.source = new EventSource(url, { withCredentials: true });
            this.source.onmessage = (event) => this.#onEvent(event.data);
        }

        this.beat = window.setInterval(() => this.#tick(), BEAT_MS);
        this.clock = window.setInterval(() => this.#renderTimeLeft(), 30000);
        this.#render();
        if (this.current.duelUrl) this.#start(this.current.duelUrl);
    }

    disconnect() {
        window.removeEventListener('pagehide', this.onPageHide);
        window.clearInterval(this.beat);
        window.clearInterval(this.clock);
        window.clearInterval(this.countdownTimer);
        this.source?.close();
        this.#sendLeave();
    }

    async ready() {
        this.readyButtonTarget.disabled = true;
        await this.#postReady();
        this.readyButtonTarget.disabled = false;
    }

    // « Quitter la salle » is a link to « Mes défis »: the leave is said on the way out.
    leave() {
        this.#sendLeave();
    }

    async #tick() {
        if (this.starting) return;
        if (this.current.me) {
            await this.#postReady();
            return;
        }
        try {
            const response = await fetch(this.stateUrlValue, { headers: { Accept: 'application/json' } });
            if (response.ok) this.#apply(await response.json());
        } catch {
            // The next beat tries again.
        }
    }

    async #postReady() {
        try {
            const response = await fetch(this.readyUrlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
            });
            const data = await response.json();
            this.#showRefusal(data.refusal ?? null);
            this.#apply(data);
        } catch {
            // The next beat tries again.
        }
    }

    #onEvent(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        if (data.event === 'start' && data.url) {
            this.#start(data.url);
        } else if (data.event === 'ready' && data.ready) {
            const mine = String(this.meValue);
            const other = Object.keys(data.ready).find((id) => id !== mine);
            this.#apply({ ...this.current, me: Boolean(data.ready[mine]), opponent: other ? Boolean(data.ready[other]) : false });
        }
    }

    #apply(state) {
        this.current = { ...this.current, ...state };
        if (this.current.me) this.left = false;
        this.#render();
        if (this.current.duelUrl) this.#start(this.current.duelUrl);
    }

    #render() {
        const { me, opponent, open } = this.current;
        this.meCardTarget.classList.toggle('is-ready', Boolean(me));
        this.opponentCardTarget.classList.toggle('is-ready', Boolean(opponent));
        this.readyButtonTarget.hidden = Boolean(me) || !open;
        this.waitingTarget.hidden = !me;
        if (!open && !this.current.duelUrl) this.#showRefusal(this.closedTextValue);
        this.#renderTimeLeft();
    }

    #renderTimeLeft() {
        if (!this.hasTimeLeftTarget || !this.current.closesAt) return;
        const seconds = Math.max(0, Math.floor((Date.parse(this.current.closesAt) - Date.now()) / 1000));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const text = hours >= 1 ? `${hours} h ${String(minutes).padStart(2, '0')}` : `${Math.max(minutes, 1)} min`;
        this.timeLeftTarget.textContent = this.leftTemplateValue.replace('%time%', text);
    }

    #showRefusal(text) {
        this.refusalTarget.hidden = !text;
        this.refusalTarget.textContent = text ?? '';
    }

    #start(url) {
        if (this.starting) return;
        this.starting = true;
        this.#showRefusal(null);
        this.meCardTarget.classList.add('is-ready');
        this.opponentCardTarget.classList.add('is-ready');
        this.actionsTarget.hidden = true;
        this.rulesTarget.hidden = true;
        this.countdownTarget.hidden = false;

        let left = COUNTDOWN_FROM;
        this.countdownNumberTarget.textContent = String(left);
        this.countdownTimer = window.setInterval(() => {
            left -= 1;
            if (left > 0) {
                this.countdownNumberTarget.textContent = String(left);
                return;
            }
            window.clearInterval(this.countdownTimer);
            window.location.assign(url);
        }, 1000);
    }

    #sendLeave() {
        if (this.starting || !this.current?.me || this.left) return;
        this.left = true;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        navigator.sendBeacon(this.leaveUrlValue, body);
    }
}
