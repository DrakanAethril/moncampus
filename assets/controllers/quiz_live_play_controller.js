import { Controller } from '@hotwired/stimulus';

/**
 * Drives a student's own live-quiz screen (waiting room / countdown / « regardez le tableau » /
 * question / their verdict / their rank / podium), the player-side counterpart of
 * quiz_live_host_controller.js - see App\Controller\ProgramQuizLiveController::play()'s docblock for
 * the SSE auth. Answer buttons show shape/color only, never a label (confirmed product decision -
 * see App\Service\QuizLiveSessionService's class docblock on why this diverges from the mockup's
 * Turn 1l, which shows text).
 *
 * How a question is answered depends on its kind (App\Service\QuizLive\LiveAnswerKind): one tap
 * sends a choice or a vote; several shapes are ticked, or tapped in order, then « Valider »; a
 * number is slid or typed; a word is typed. The server checks the shape of every answer anyway.
 *
 * Built to be played from a phone, in beaup-app above all (the site installed as an app): the
 * screen is kept awake while the game runs (Screen Wake Lock), and the phase is asked again from the
 * server whenever the page comes back - from the background, from a lost network, from a stream
 * that reconnected - since the hub replays none of the events published meanwhile. An answer the
 * network lost is offered again rather than silently forgotten.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'lobbyPhase', 'countdownPhase', 'readingPhase', 'questionPhase', 'resultsPhase', 'scoreboardPhase', 'finishedPhase',
        'lobbyParticipantCount',
        'countdownNumber',
        'readingIndex', 'readingKind',
        'questionIndex', 'questionTimer', 'questionHint', 'questionAnswers', 'questionSentNotice', 'questionRetryNotice',
        'numberPanel', 'numberValue', 'numberSlider', 'numberInput', 'textPanel', 'textInput',
        'validateBar', 'validateButton', 'clearButton',
        'resultsBadge', 'resultsPoints', 'resultsScore', 'resultsRank',
        'scoreboardRank', 'scoreboardScore',
        'podiumMyRank', 'podiumMyScore',
    ];

    static values = {
        hubUrl: String,
        topic: String,
        answerUrl: String,
        stateUrl: String,
        csrfToken: String,
        participantId: Number,
        initialState: Object,
        labels: Object,
    };

    // Matches App\Service\QuizLiveSessionService::SHAPES.
    static GLYPHS = { triangle: '▲', square: '■', circle: '●', diamond: '◆', pentagon: '⬟', 'triangle-down': '▼' };

    connect() {
        this.labels = this.labelsValue;
        this.answered = false;
        this.shownQuestion = null;
        this.apply(this.initialStateValue);

        const url = new URL(this.hubUrlValue);
        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventSource.onmessage = (event) => this.apply(JSON.parse(event.data));
        // The browser reconnects by itself; what was published while it was away is gone.
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
        clearInterval(this.countdownInterval);
        clearInterval(this.questionTimerInterval);
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('online', this.onOnline);
        this.releaseAwake();
    }

    resync() {
        fetch(this.stateUrlValue, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((body) => { if (body && body.state) this.apply(body.state); })
            .catch(() => {});
    }

    // A phone that dims in the middle of a question is a student who misses it. Refused or
    // unsupported, nothing changes: the page works the same, the screen may just sleep.
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

    // A shape: sent at once for a choice, ticked for a QCM multiple, numbered for an order.
    tap(event) {
        if (this.answered) {
            return;
        }
        const button = event.currentTarget;
        const answerId = Number(button.dataset.answerId);

        if ('choice' === this.kind) {
            button.classList.add('cm-quiz-live-shape--selected');
            this.send({ answerIds: [answerId] });

            return;
        }

        if ('multi' === this.kind) {
            const at = this.picked.indexOf(answerId);
            if (at >= 0) {
                this.picked.splice(at, 1);
            } else {
                this.picked.push(answerId);
            }
        } else if ('order' === this.kind && !this.picked.includes(answerId)) {
            this.picked.push(answerId);
        }
        this.paintPicked();
    }

    clearOrder() {
        this.picked = [];
        this.paintPicked();
    }

    slide() {
        this.numberValueTarget.textContent = Number(this.numberSliderTarget.value).toLocaleString('fr-FR') + (this.unit ? ` ${this.unit}` : '');
    }

    validate() {
        if (this.answered) {
            return;
        }

        switch (this.kind) {
            case 'multi':
                if (this.picked.length > 0) {
                    this.send({ answerIds: this.picked });
                }
                break;
            case 'order':
                if (this.picked.length === this.answerCount) {
                    this.send({ order: this.picked });
                }
                break;
            case 'number': {
                const raw = this.range ? this.numberSliderTarget.value : this.numberInputTarget.value.replace(',', '.');
                if ('' !== raw && Number.isFinite(Number(raw))) {
                    this.send({ value: Number(raw) });
                }
                break;
            }
            case 'text': {
                const text = this.textInputTarget.value.trim();
                if ('' !== text) {
                    this.send({ text });
                }
                break;
            }
        }
    }

    send(body) {
        this.answered = true;
        this.lockQuestion();

        // A 409 is the server's « too late » or « already answered »: the question stays locked.
        // Anything else - no network, a server that did not answer - leaves the answer unsent, and
        // the student is offered to send it again.
        fetch(this.answerUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify(body),
        }).then((response) => {
            if (!response.ok && 409 !== response.status) {
                this.unlockQuestion();
            }
        }).catch(() => this.unlockQuestion());
    }

    lockQuestion() {
        this.questionAnswersTarget.querySelectorAll('button').forEach((button) => { button.disabled = true; });
        [this.validateButtonTarget, this.clearButtonTarget, this.numberSliderTarget, this.numberInputTarget, this.textInputTarget]
            .forEach((element) => { element.disabled = true; });
        this.questionSentNoticeTarget.hidden = false;
        this.questionRetryNoticeTarget.hidden = true;
    }

    unlockQuestion() {
        if (this.timeIsUp) {
            return;
        }
        this.answered = false;
        this.questionAnswersTarget.querySelectorAll('button').forEach((button) => { button.disabled = false; });
        [this.clearButtonTarget, this.numberSliderTarget, this.numberInputTarget, this.textInputTarget]
            .forEach((element) => { element.disabled = false; });
        this.questionAnswersTarget.querySelectorAll('.cm-quiz-live-shape--selected').forEach((button) => {
            if ('choice' === this.kind) button.classList.remove('cm-quiz-live-shape--selected');
        });
        this.validateButtonTarget.disabled = false;
        this.questionSentNoticeTarget.hidden = true;
        this.questionRetryNoticeTarget.hidden = false;
    }

    paintPicked() {
        this.questionAnswersTarget.querySelectorAll('button').forEach((button) => {
            const at = this.picked.indexOf(Number(button.dataset.answerId));
            button.classList.toggle('cm-quiz-live-shape--selected', at >= 0);
            const rank = button.querySelector('.cm-quiz-live-shape__rank');
            if (rank) {
                rank.textContent = 'order' === this.kind && at >= 0 ? String(at + 1) : '';
            }
        });
        this.validateButtonTarget.disabled = 'order' === this.kind ? this.picked.length !== this.answerCount : 0 === this.picked.length;
    }

    apply(state) {
        // The question already on screen, asked again (a resync): what the student has ticked,
        // ordered or typed so far stays - only whether it was sent is taken from the server.
        if ('question-opened' === state.type && this.shownQuestion === state.questionIndex && !this.questionPhaseTarget.hidden) {
            if (true === state.answered && !this.answered) {
                this.answered = true;
                this.lockQuestion();
            }

            return;
        }

        this.hidePhases();
        clearInterval(this.countdownInterval);
        clearInterval(this.questionTimerInterval);

        switch (state.type) {
            case 'lobby':
                this.showLobby(state);
                break;
            case 'countdown-started':
                this.showCountdown(state);
                break;
            case 'question-reading':
                this.showReading(state);
                break;
            case 'question-opened':
                this.showQuestion(state);
                break;
            case 'results':
                this.showResults(state);
                break;
            case 'scoreboard':
                this.showScoreboard(state);
                break;
            case 'session-finished':
                this.showFinished(state);
                break;
            case 'session-cancelled':
                window.location.href = this.element.dataset.quizLivePlayHomeUrl;
                break;
        }

        this.shownQuestion = 'question-opened' === state.type ? state.questionIndex : null;
        if ('session-finished' === state.type) {
            this.finished = true;
            this.releaseAwake();
        }
    }

    hidePhases() {
        [this.lobbyPhaseTarget, this.countdownPhaseTarget, this.readingPhaseTarget, this.questionPhaseTarget, this.resultsPhaseTarget, this.scoreboardPhaseTarget, this.finishedPhaseTarget]
            .forEach((el) => { el.hidden = true; });
    }

    showLobby(state) {
        this.lobbyPhaseTarget.hidden = false;
        if (undefined !== state.participantCount) {
            this.lobbyParticipantCountTarget.textContent = state.participantCount;
        }
    }

    showCountdown(state) {
        this.countdownPhaseTarget.hidden = false;
        const deadline = new Date(state.serverTime).getTime() + state.countdownSeconds * 1000;
        const tick = () => {
            this.countdownNumberTarget.textContent = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        };
        tick();
        this.countdownInterval = setInterval(tick, 250);
    }

    showReading(state) {
        this.readingPhaseTarget.hidden = false;
        this.readingIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.readingKindTarget.textContent = this.hintFor(state);
    }

    showQuestion(state) {
        this.kind = state.kind;
        this.picked = [];
        this.answerCount = state.answers.length;
        this.range = state.range || null;
        this.unit = state.unit || '';
        // A reloaded phone that already answered is told so, and offered nothing again.
        this.answered = true === state.answered;
        this.timeIsUp = false;

        this.questionPhaseTarget.hidden = false;
        this.questionSentNoticeTarget.hidden = true;
        this.questionRetryNoticeTarget.hidden = true;
        this.questionIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.questionHintTarget.textContent = this.hintFor(state);

        this.questionAnswersTarget.innerHTML = '';
        this.questionAnswersTarget.hidden = !['choice', 'multi', 'order'].includes(this.kind);
        // Two columns as soon as there are more than four shapes, like Kahoot's six.
        this.questionAnswersTarget.style.gridTemplateColumns = state.answers.length > 4 ? '1fr 1fr' : '';
        this.questionAnswersTarget.style.gridTemplateRows = `repeat(${state.answers.length > 4 ? Math.ceil(state.answers.length / 2) : state.answers.length}, 1fr)`;
        state.answers.forEach((answer) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `cm-quiz-live-shape cm-quiz-live-shape--${answer.color}`;
            button.dataset.answerId = answer.answerId;
            button.dataset.action = 'click->quiz-live-play#tap';
            button.append(this.glyph(answer.shape));
            const rank = document.createElement('span');
            rank.className = 'cm-quiz-live-shape__rank';
            button.append(rank);
            this.questionAnswersTarget.appendChild(button);
        });

        this.numberPanelTarget.hidden = 'number' !== this.kind;
        this.textPanelTarget.hidden = 'text' !== this.kind;
        this.validateBarTarget.hidden = 'choice' === this.kind;
        this.clearButtonTarget.hidden = 'order' !== this.kind;
        [this.validateButtonTarget, this.clearButtonTarget, this.numberSliderTarget, this.numberInputTarget, this.textInputTarget]
            .forEach((element) => { element.disabled = false; });
        this.validateButtonTarget.disabled = ['multi', 'order'].includes(this.kind);
        this.textInputTarget.value = '';
        this.numberInputTarget.value = '';

        if ('number' === this.kind) {
            this.numberSliderTarget.hidden = !this.range;
            this.numberInputTarget.hidden = !!this.range;
            this.numberValueTarget.hidden = !this.range;
            if (this.range) {
                this.numberSliderTarget.min = this.range.min;
                this.numberSliderTarget.max = this.range.max;
                this.numberSliderTarget.step = this.range.step;
                // Starts in the middle, like Kahoot's: the ends would read as an answer.
                const middle = this.range.min + Math.round((this.range.max - this.range.min) / 2 / this.range.step) * this.range.step;
                this.numberSliderTarget.value = middle;
                this.slide();
            }
        }

        if (this.answered) {
            this.lockQuestion();
        }

        // A null here is an untimed question (its own "Illimité" mode, or a quiz with no default
        // and questions following it) - the server's own isQuestionTimeUp() keeps accepting
        // answers, so the board must too. Arithmetic on null would have read as a deadline of
        // phaseStartedAt itself, i.e. every button disabled the instant the question opened.
        if (null === state.secondsPerQuestion || undefined === state.secondsPerQuestion) {
            this.questionTimerTarget.textContent = '∞';

            return;
        }

        const deadline = new Date(state.phaseStartedAt).getTime() + state.secondsPerQuestion * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            this.questionTimerTarget.textContent = remaining;
            if (remaining <= 0) {
                this.timeIsUp = true;
                this.answered = true;
                this.questionAnswersTarget.querySelectorAll('button').forEach((button) => { button.disabled = true; });
                [this.validateButtonTarget, this.clearButtonTarget, this.numberSliderTarget, this.numberInputTarget, this.textInputTarget]
                    .forEach((element) => { element.disabled = true; });
            }
        };
        tick();
        this.questionTimerInterval = setInterval(tick, 250);
    }

    // The verdict comes from the server, under this phone's participant id - it survives a reload,
    // which a choice remembered in this page did not.
    showResults(state) {
        this.resultsPhaseTarget.hidden = false;
        const outcome = (state.outcomes || {})[String(this.participantIdValue)] || { answered: false, correct: null, points: 0 };
        const mine = state.leaderboard.find((row) => row.participantId === this.participantIdValue);

        let badge;
        if (state.isVote) {
            badge = outcome.answered ? this.labels.voted : this.labels.missed;
        } else if (!outcome.answered) {
            badge = this.labels.missed;
        } else {
            badge = outcome.correct ? this.labels.correct : this.labels.wrong;
        }
        this.resultsBadgeTarget.textContent = badge;
        this.resultsBadgeTarget.classList.toggle('is-correct', true === outcome.correct);
        this.resultsBadgeTarget.classList.toggle('is-wrong', !state.isVote && true !== outcome.correct);
        this.resultsPointsTarget.textContent = state.isVote ? '' : (this.labels.points || '').replace('%points%', String(outcome.points || 0));
        this.resultsScoreTarget.textContent = mine ? mine.score.toLocaleString('fr-FR') : '0';
        this.resultsRankTarget.textContent = mine ? `#${mine.rank}` : '—';
    }

    showScoreboard(state) {
        this.scoreboardPhaseTarget.hidden = false;
        const mine = state.leaderboard.find((row) => row.participantId === this.participantIdValue);
        this.scoreboardRankTarget.textContent = mine ? `#${mine.rank}` : '—';
        this.scoreboardScoreTarget.textContent = mine ? mine.score.toLocaleString('fr-FR') : '0';
    }

    showFinished(state) {
        this.finishedPhaseTarget.hidden = false;
        const mine = state.finalLeaderboard.find((row) => row.participantId === this.participantIdValue);
        this.podiumMyRankTarget.textContent = mine ? `#${mine.rank}` : '—';
        this.podiumMyScoreTarget.textContent = mine ? mine.score.toLocaleString('fr-FR') : '0';
    }

    hintFor(state) {
        const kinds = this.labels.kinds || {};

        return state.isVote ? (kinds.vote || '') : (kinds[state.kind] || '');
    }

    glyph(shape) {
        return this.constructor.GLYPHS[shape] || '';
    }
}
