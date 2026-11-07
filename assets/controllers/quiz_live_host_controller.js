import { Controller } from '@hotwired/stimulus';
import { paintFaces, roomChip, roomScreen } from '../quiz_live/render.js';
import { confetti, countUp, play } from '../quiz_live/effects.js';

/**
 * Drives the room's screen of a live contest - Kahoot's run of a question (App\Enum\LiveSessionStatus):
 * the waiting room with its QR code when the contest is public, the countdown, then for each question
 * the statement alone, the propositions and the clock, what the room answered, the scoreboard; the
 * podium at the end. The screen is a 1280×720 stage scaled to the window, in the contest's theme
 * (design/validated/quiz-live-public.md); its markup is assets/quiz_live/render.js, the same the
 * theme gallery draws.
 *
 * Every button is a fire-and-forget POST naming the step it was pressed on: the resulting change
 * always arrives back through the host topic (App\Controller\QuizLive\HostController::projector()).
 * This screen also closes a question when its clock runs out, and moves on after the countdown - the
 * server closes a question itself once everybody has answered.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['room', 'sound'];

    static values = {
        hubUrl: String,
        topic: String,
        advanceUrl: String,
        removeUrl: String,
        stateUrl: String,
        csrfToken: String,
        initialState: Object,
        context: Object,
        theme: Object,
    };

    // Payload type => the status the server calls that step.
    static STEPS = {
        'countdown-started': 'countdown',
        'question-reading': 'reading',
        'question-opened': 'question',
        results: 'results',
        scoreboard: 'scoreboard',
    };

    connect() {
        const theme = this.themeValue;
        this.ctx = { ...this.contextValue, words: theme.words || {}, slug: theme.slug };
        this.step = null;
        this.questionIndex = null;
        this.shownKey = null;
        this.previousOrder = [];
        this.currentOrder = [];
        try {
            this.sound = 'on' === window.localStorage.getItem('quiz-live-sound');
        } catch {
            this.sound = false;
        }
        this.paintSound();

        this.fit = () => {
            const scale = Math.min(window.innerWidth / 1280, window.innerHeight / 720);
            this.roomTarget.style.setProperty('--ql-scale', String(scale));
        };
        this.fit();
        window.addEventListener('resize', this.fit);

        this.apply(this.initialStateValue);

        const url = new URL(this.hubUrlValue, window.location.origin);
        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventSource.onmessage = (event) => this.apply(JSON.parse(event.data));
        // After a network drop the browser reconnects by itself, but what was published meanwhile
        // is gone: the board asks for its phase again.
        this.eventSource.onerror = () => { this.streamLost = true; };
        this.eventSource.onopen = () => {
            if (this.streamLost) {
                this.streamLost = false;
                this.resync();
            }
        };
        this.onVisibility = () => { if ('visible' === document.visibilityState) this.resync(); };
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnect() {
        this.eventSource?.close();
        this.stopTimers();
        window.removeEventListener('resize', this.fit);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    resync() {
        fetch(this.stateUrlValue, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body) => { if (body && body.state) this.apply(body.state); })
            .catch(() => {});
    }

    advance(event) {
        const button = event?.currentTarget;
        if (button) {
            button.disabled = true;
        }
        this.post().finally(() => { if (button) button.disabled = false; });
    }

    // The step this screen shows rides along: a click and the clock arriving together move the
    // room once, and a request about a step already past is ignored by the server.
    post() {
        return fetch(this.advanceUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify({ from: this.step, questionIndex: this.questionIndex }),
        }).catch(() => {});
    }

    /**
     * Taking a player out of the waiting room is two clicks on their name - the first arms it
     * (« Retirer ? »), never a confirm() dialog.
     */
    remove(event) {
        const chip = event.currentTarget;
        if (!chip.classList.contains('is-armed')) {
            chip.classList.add('is-armed');
            chip.dataset.label = chip.querySelector('span:last-child')?.textContent || '';
            chip.querySelector('span:last-child').textContent = this.ctx.labels.removeConfirm;
            setTimeout(() => {
                if (chip.isConnected && chip.classList.contains('is-armed')) {
                    chip.classList.remove('is-armed');
                    chip.querySelector('span:last-child').textContent = chip.dataset.label;
                }
            }, 3000);

            return;
        }
        chip.disabled = true;
        fetch(this.removeUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify({ participantId: Number(chip.dataset.id) }),
        }).catch(() => { chip.disabled = false; });
    }

    toggleSound() {
        this.sound = !this.sound;
        try {
            window.localStorage.setItem('quiz-live-sound', this.sound ? 'on' : 'off');
        } catch {
            // Remembered for this page only.
        }
        this.paintSound();
        if (this.sound) {
            play('join', this.themeValue.wave);
        }
    }

    paintSound() {
        if (this.hasSoundTarget) {
            this.soundTarget.setAttribute('aria-pressed', String(this.sound));
        }
    }

    tune(name) {
        if (this.sound) {
            play(name, this.themeValue.wave);
        }
    }

    apply(state) {
        // Partial updates: the room stays on its screen.
        if ('answer-received' === state.type) {
            this.bind('answered', state.answeredCount);

            return;
        }
        if ('participant-count' === state.type) {
            this.bind('count', state.participantCount);

            return;
        }
        if ('participant-joined' === state.type && 'participant-joined' === this.shownType) {
            this.syncLobby(state);

            return;
        }
        if ('session-cancelled' === state.type) {
            window.location.href = this.ctx.indexUrl;

            return;
        }

        const key = `${state.type}:${state.questionIndex ?? ''}`;
        if (key === this.shownKey) {
            return; // the same step asked again (a resync): nothing to replay
        }
        this.shownKey = key;
        this.shownType = state.type;
        this.stopTimers();
        this.step = this.constructor.STEPS[state.type] ?? null;
        this.questionIndex = undefined !== state.questionIndex ? state.questionIndex : null;

        if ('results' === state.type) {
            this.previousOrder = this.currentOrder;
            this.currentOrder = (state.leaderboard || []).map((row) => row.participantId);
        }

        this.roomTarget.innerHTML = roomScreen(state, this.ctx, { previousOrder: this.previousOrder });
        paintFaces(this.roomTarget);
        countUp(this.roomTarget);

        switch (state.type) {
            case 'countdown-started':
                this.runCountdown(state);
                break;
            case 'question-opened':
                this.runClock(state);
                break;
            case 'results':
                this.tune('reveal');
                break;
            case 'session-finished':
                this.tune('fanfare');
                this.celebration = setTimeout(() => confetti(this.roomTarget, this.themeValue.confetti, 220), 2900);
                break;
        }
    }

    bind(name, value) {
        this.roomTarget.querySelectorAll(`[data-bind="${name}"]`).forEach((element) => { element.textContent = String(value ?? 0); });
    }

    /** New players pop in, players taken out go - the waiting room is never redrawn whole. */
    syncLobby(state) {
        this.bind('count', state.participantCount);
        const grid = this.roomTarget.querySelector('[data-players]');
        if (!grid) {
            return;
        }
        const ids = (state.participants || []).map((participant) => String(participant.id));
        grid.querySelectorAll('[data-id]').forEach((chip) => {
            if (!ids.includes(chip.dataset.id)) {
                chip.classList.add('ql-poof');
                setTimeout(() => chip.remove(), 420);
            }
        });
        (state.participants || []).forEach((participant) => {
            if (!grid.querySelector(`[data-id="${participant.id}"]`)) {
                grid.insertAdjacentHTML('beforeend', roomChip(participant));
                this.tune('join');
            }
        });
        paintFaces(grid);
    }

    // 4, 3, 2, 1, the theme's « Go » - then this screen moves the room to the first question.
    runCountdown(state) {
        const deadline = new Date(state.serverTime).getTime() + state.countdownSeconds * 1000;
        let shown = null;
        let advanced = false;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const text = remaining > 1 ? String(remaining - 1) : (this.ctx.words.go || 'Go');
            if (text !== shown) {
                shown = text;
                const host = this.roomTarget.querySelector('[data-cd]');
                if (host) {
                    host.innerHTML = `<div class="fd ql-cd ql-hero ql-glow"></div>`;
                    host.firstElementChild.textContent = text;
                }
                this.tune(remaining > 1 ? 'tick' : 'go');
            }
            if (remaining <= 0 && !advanced) {
                advanced = true;
                this.post();
            }
        };
        tick();
        this.timer = setInterval(tick, 200);
    }

    // An untimed question (its own « Illimité ») shows ∞: only everybody answering, or « Passer »,
    // closes it.
    runClock(state) {
        if (null === state.secondsPerQuestion || undefined === state.secondsPerQuestion) {
            return;
        }
        const total = state.secondsPerQuestion * 1000;
        const deadline = new Date(state.phaseStartedAt).getTime() + total;
        let closed = false;
        const tick = () => {
            const left = Math.max(0, deadline - Date.now());
            const ring = this.roomTarget.querySelector('[data-bind="clock"]');
            if (ring) {
                ring.setAttribute('stroke-dashoffset', (326.7 * (1 - left / total)).toFixed(1));
                ring.setAttribute('stroke', left < 5000 ? 'var(--ko)' : 'var(--accent)');
            }
            this.bind('clockn', Math.ceil(left / 1000));
            if (left <= 0 && !closed) {
                closed = true;
                this.post();
            }
        };
        tick();
        this.timer = setInterval(tick, 200);
    }

    stopTimers() {
        clearInterval(this.timer);
        clearTimeout(this.celebration);
    }
}
