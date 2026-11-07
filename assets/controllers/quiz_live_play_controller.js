import { Controller } from '@hotwired/stimulus';
import { esc, fmt, paintFaces, phoneMessage, phoneScreen } from '../quiz_live/render.js';
import { confetti, countUp } from '../quiz_live/effects.js';

/**
 * Drives a player's phone in a live contest (design/validated/quiz-live-public.md), an account or a
 * guest alike: waiting room, countdown, the statement, the question - its text, its picture and its
 * propositions, since a public contest may be played far from the projector -, their verdict, their
 * rank, their place on the podium. The markup is assets/quiz_live/render.js, in the contest's theme.
 *
 * How a question is answered depends on its kind (App\Service\QuizLive\LiveAnswerKind): one tap sends
 * a choice or a vote; several are ticked, or tapped in order, then « Valider »; a number is slid or
 * typed; a word is typed. The server checks the shape of every answer anyway.
 *
 * Built to be played from a phone, in beaup-app above all: the screen is kept awake while the game
 * runs (Screen Wake Lock), and the phase is asked again from the server whenever the page comes back
 * - from the background, from a lost network, from a stream that reconnected - since the hub replays
 * none of the events published meanwhile. An answer the network lost is offered again.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['screen'];

    static values = {
        hubUrl: String,
        topic: String,
        answerUrl: String,
        stateUrl: String,
        csrfToken: String,
        initialState: Object,
        context: Object,
        theme: Object,
    };

    connect() {
        const theme = this.themeValue;
        this.ctx = { ...this.contextValue, words: theme.words || {}, slug: theme.slug };
        this.memory = { sent: false, pick: null, streak: this.loadStreak(), previousRank: null, score: undefined };
        this.shownKey = null;
        this.apply(this.initialStateValue);

        const url = new URL(this.hubUrlValue, window.location.origin);
        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventSource.onmessage = (event) => this.apply(JSON.parse(event.data));
        this.eventSource.onerror = () => { this.streamLost = true; };
        this.eventSource.onopen = () => {
            if (this.streamLost) {
                this.streamLost = false;
                this.resync();
            }
        };
        this.onVisibility = () => {
            if ('visible' === document.visibilityState) {
                this.resync();
                this.keepAwake();
            }
        };
        this.onOnline = () => this.resync();
        document.addEventListener('visibilitychange', this.onVisibility);
        window.addEventListener('online', this.onOnline);
        this.keepAwake();
    }

    disconnect() {
        this.eventSource?.close();
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('online', this.onOnline);
        this.releaseAwake();
    }

    resync() {
        fetch(this.stateUrlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((body) => {
                if (!body) {
                    return;
                }
                if (body.removed) {
                    this.showRemoved();

                    return;
                }
                if (body.state) {
                    this.apply(body.state);
                }
            })
            .catch(() => {});
    }

    // A phone that dims in the middle of a question is a player who misses it. Refused or
    // unsupported, nothing changes.
    keepAwake() {
        if (!('wakeLock' in navigator) || this.finished || (this.wakeLock && !this.wakeLock.released)) {
            return;
        }
        navigator.wakeLock.request('screen').then((lock) => { this.wakeLock = lock; }).catch(() => {});
    }

    releaseAwake() {
        this.wakeLock?.release().catch(() => {});
        this.wakeLock = null;
    }

    // ------------------------------------------------------------------ answering

    tap(event) {
        if (this.memory.sent || this.timeIsUp) {
            return;
        }
        const button = event.currentTarget;
        const id = Number(button.dataset.answerId);
        const kind = this.state.isVote ? 'choice' : this.state.kind;

        if ('choice' === kind) {
            button.classList.add('is-sel');
            this.send({ answerIds: [id] }, [id]);

            return;
        }
        const at = this.picked.indexOf(id);
        if ('multi' === kind) {
            if (at >= 0) {
                this.picked.splice(at, 1);
            } else {
                this.picked.push(id);
            }
        } else if ('order' === kind) {
            if (at >= 0) {
                this.picked.splice(at, 1);
            } else {
                this.picked.push(id);
            }
        }
        this.paintPicked();
    }

    clearOrder() {
        this.picked = [];
        this.paintPicked();
    }

    slide(event) {
        this.showNumber(Number(event.currentTarget.value));
    }

    nudge(event) {
        const input = this.screenTarget.querySelector('[data-number]');
        if (!input) {
            return;
        }
        const step = Number(input.step) || 1;
        const value = Math.min(Number(input.max), Math.max(Number(input.min), Number(input.value) + Number(event.currentTarget.dataset.delta) * step));
        input.value = String(value);
        this.showNumber(value);
    }

    showNumber(value) {
        const out = this.screenTarget.querySelector('[data-bind="numout"]');
        if (out) {
            out.textContent = `${fmt(value)}${this.state.unit ? ` ${this.state.unit}` : ''}`;
        }
    }

    typed() {
        const field = this.screenTarget.querySelector('[data-text]');
        const button = this.screenTarget.querySelector('[data-validate]');
        if (field && button) {
            button.disabled = '' === field.value.trim();
        }
    }

    validate(event) {
        event?.preventDefault?.();
        if (this.memory.sent || this.timeIsUp) {
            return;
        }
        switch (this.state.kind) {
            case 'multi':
                if (this.picked.length > 0) this.send({ answerIds: this.picked }, this.picked);
                break;
            case 'order':
                if (this.picked.length === (this.state.answers || []).length) this.send({ order: this.picked }, null);
                break;
            case 'number': {
                const input = this.screenTarget.querySelector('[data-number]');
                const raw = input ? String(input.value).replace(',', '.') : '';
                if ('' !== raw && Number.isFinite(Number(raw))) this.send({ value: Number(raw) }, null);
                break;
            }
            case 'text': {
                const text = this.screenTarget.querySelector('[data-text]')?.value.trim() || '';
                if ('' !== text) this.send({ text }, null);
                break;
            }
        }
    }

    // A 409 is the server's « too late » or « already answered »: the question stays locked.
    // Anything else - no network, a server that did not answer - offers to send it again.
    send(body, pick) {
        this.memory.sent = true;
        this.memory.pick = pick;
        const question = this.state;
        this.render();
        fetch(this.answerUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify(body),
        }).then((response) => {
            if (!response.ok && 409 !== response.status) {
                this.lost(question);
            }
        }).catch(() => this.lost(question));
    }

    lost(question) {
        if (this.state !== question || this.timeIsUp) {
            return;
        }
        this.memory.sent = false;
        this.render();
        const notice = this.screenTarget.querySelector('[data-retry]');
        if (notice) {
            notice.hidden = false;
        }
    }

    paintPicked() {
        const order = 'order' === this.state.kind;
        this.screenTarget.querySelectorAll('[data-answer-id]').forEach((button) => {
            const at = this.picked.indexOf(Number(button.dataset.answerId));
            button.classList.toggle('is-sel', at >= 0);
            button.classList.toggle('is-num', order && at >= 0);
            button.setAttribute('aria-pressed', String(at >= 0));
            const badge = button.querySelector('.ql-badge');
            if (badge && order) {
                badge.textContent = at >= 0 ? String(at + 1) : '';
            }
        });
        const validate = this.screenTarget.querySelector('[data-validate]');
        if (validate) {
            validate.disabled = order ? this.picked.length !== (this.state.answers || []).length : 0 === this.picked.length;
        }
    }

    // ------------------------------------------------------------------ the phases

    apply(state) {
        if ('lobby' === state.type && 'lobby' === this.state?.type) {
            // Somebody came or went. Fewer players than before may mean this one was taken out.
            const before = this.state.participantCount ?? 0;
            this.state = state;
            this.screenTarget.querySelectorAll('[data-bind="count"]').forEach((element) => { element.textContent = String(state.participantCount ?? ''); });
            if ((state.participantCount ?? 0) < before) {
                this.resync();
            }

            return;
        }

        const key = `${state.type}:${state.questionIndex ?? ''}`;
        if (key === this.shownKey && 'question-opened' === state.type) {
            // The question already on screen, asked again: what was ticked stays, only whether it
            // was sent is taken from the server.
            if (true === state.answered && !this.memory.sent) {
                this.memory.sent = true;
                this.render();
            }

            return;
        }
        if (key === this.shownKey) {
            return;
        }

        if (key !== this.shownKey && 'question-opened' === state.type) {
            this.memory.sent = true === state.answered;
            this.memory.pick = null;
            this.picked = [];
            this.timeIsUp = false;
        }
        if ('results' === state.type) {
            this.settle(state);
        }
        if ('scoreboard' === state.type) {
            // The rank before this question, for « ▲ 2 places »: the previous board's.
            this.memory.previousRank = this.lastRank ?? null;
        }
        const row = (state.leaderboard || state.finalLeaderboard || []).find((entry) => entry.participantId === this.ctx.me.participantId);
        if (row) {
            this.memory.score = row.score;
            if ('scoreboard' === state.type || 'results' === state.type) {
                this.pendingRank = row.rank;
            }
        }
        if ('scoreboard' === state.type) {
            this.lastRank = this.pendingRank;
        }

        this.shownKey = key;
        this.state = state;
        this.render();

        if ('session-finished' === state.type) {
            this.finished = true;
            this.releaseAwake();
            setTimeout(() => confetti(this.screenTarget, this.themeValue.confetti, 120), 2900);
        }
    }

    /** The run of right answers this page has seen, kept for a reload of the same contest. */
    settle(state) {
        const outcome = (state.outcomes || {})[String(this.ctx.me.participantId)];
        if (!state.isVote) {
            this.memory.streak = outcome && outcome.correct ? this.memory.streak + 1 : 0;
            this.saveStreak();
        }
        if (outcome && outcome.correct && !state.isVote) {
            setTimeout(() => confetti(this.screenTarget, this.themeValue.confetti, 60), 250);
        }
    }

    render() {
        clearInterval(this.timer);
        this.screenTarget.innerHTML = phoneScreen(this.state, this.ctx, this.memory);
        paintFaces(this.screenTarget);
        countUp(this.screenTarget);

        if ('countdown-started' === this.state.type) {
            this.runCountdown(this.state);
        }
        if ('question-opened' === this.state.type) {
            this.runClock(this.state);
            if (!this.memory.sent) {
                this.paintPicked();
            }
        }
    }

    runCountdown(state) {
        const deadline = new Date(state.serverTime).getTime() + state.countdownSeconds * 1000;
        let shown = null;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const text = remaining > 1 ? String(remaining - 1) : (this.ctx.words.go || 'Go');
            if (text !== shown) {
                shown = text;
                const host = this.screenTarget.querySelector('[data-cd]');
                if (host) {
                    host.innerHTML = `<div class="fd ql-cd ql-hero ql-glow">${esc(text)}</div>`;
                }
            }
        };
        tick();
        this.timer = setInterval(tick, 200);
    }

    // A null limit is an untimed question: the bar stays full and the server keeps accepting.
    runClock(state) {
        if (null === state.secondsPerQuestion || undefined === state.secondsPerQuestion) {
            return;
        }
        const total = state.secondsPerQuestion * 1000;
        const deadline = new Date(state.phaseStartedAt).getTime() + total;
        const tick = () => {
            const left = Math.max(0, deadline - Date.now());
            const bar = this.screenTarget.querySelector('[data-bind="time"]');
            if (bar) {
                bar.style.width = `${(left / total) * 100}%`;
                bar.parentElement.classList.toggle('is-low', left < 5000);
            }
            if (left <= 0 && !this.timeIsUp) {
                this.timeIsUp = true;
                this.screenTarget.querySelectorAll('button, input').forEach((element) => { element.disabled = true; });
            }
        };
        tick();
        this.timer = setInterval(tick, 200);
    }

    showRemoved() {
        clearInterval(this.timer);
        this.eventSource?.close();
        this.screenTarget.innerHTML = phoneMessage(this.ctx.labels.removedTitle, this.ctx.labels.removedText, this.ctx, { href: this.ctx.entryUrl, label: this.ctx.labels.rejoin });
    }

    loadStreak() {
        try {
            return Number(window.sessionStorage.getItem(`quiz-live-streak-${this.topicValue}`)) || 0;
        } catch {
            return 0;
        }
    }

    saveStreak() {
        try {
            window.sessionStorage.setItem(`quiz-live-streak-${this.topicValue}`, String(this.memory.streak));
        } catch {
            // Kept for this page only.
        }
    }
}
