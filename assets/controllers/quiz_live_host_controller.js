import { Controller } from '@hotwired/stimulus';

/**
 * Drives the teacher's projector screen (Turns 1q lobby / 1u countdown / 1h question / 1i
 * reveal+classement / 1j podium) - one page, phases swapped via plain DOM show/hide, all state
 * pushed by App\Service\QuizLiveSessionService over Mercure (private "host" topic - see
 * App\Controller\QuizLiveHostController::projector()'s docblock for the cookie-based auth).
 *
 * The advance/"Suivant" button is a fire-and-forget POST: it never touches the DOM itself, the
 * resulting state change always arrives back through this controller's own SSE subscription
 * (same "server is the single source of truth" split as quiz_passation_controller.js's timer).
 */
export default class extends Controller {
    static targets = [
        'lobbyPhase', 'countdownPhase', 'questionPhase', 'revealPhase', 'finishedPhase',
        'participantCount', 'participantChips',
        'countdownNumber',
        'questionIndex', 'questionLabel', 'questionImage', 'questionImageWrap', 'questionAnswers', 'questionTimer', 'questionAnsweredCount', 'questionParticipantCount',
        'revealCorrectLabel', 'revealLeaderboard', 'revealNextLabel',
        'podiumFirst', 'podiumSecond', 'podiumThird', 'finishedLeaderboard',
        'advanceButton',
    ];

    static values = {
        hubUrl: String,
        topic: String,
        advanceUrl: String,
        csrfToken: String,
        initialState: Object,
    };

    connect() {
        this.apply(this.initialStateValue);

        const url = new URL(this.hubUrlValue);
        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventSource.onmessage = (event) => this.apply(JSON.parse(event.data));
    }

    disconnect() {
        this.eventSource?.close();
        clearInterval(this.countdownInterval);
        clearInterval(this.questionTimerInterval);
    }

    advance() {
        this.advanceButtonTarget.disabled = true;
        fetch(this.advanceUrlValue, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.csrfTokenValue },
        }).finally(() => {
            this.advanceButtonTarget.disabled = false;
        });
    }

    apply(state) {
        // answer-received is a partial update (live "N/M répondu" tally) while staying in the
        // question phase - it must not reset the timer/re-hide phases like every other event.
        if ('answer-received' === state.type) {
            this.questionAnsweredCountTarget.textContent = state.answeredCount;
            this.questionParticipantCountTarget.textContent = state.participantCount;
            return;
        }

        this.hidePhases();
        clearInterval(this.countdownInterval);
        clearInterval(this.questionTimerInterval);

        switch (state.type) {
            case 'participant-joined':
                this.showLobby(state);
                break;
            case 'countdown-started':
                this.showCountdown(state);
                break;
            case 'question-opened':
                this.showQuestion(state);
                break;
            case 'reveal':
                this.showReveal(state);
                break;
            case 'session-finished':
                this.showFinished(state);
                break;
            case 'session-cancelled':
                window.location.reload();
                break;
        }
    }

    hidePhases() {
        [this.lobbyPhaseTarget, this.countdownPhaseTarget, this.questionPhaseTarget, this.revealPhaseTarget, this.finishedPhaseTarget]
            .forEach((el) => { el.hidden = true; });
    }

    showLobby(state) {
        this.lobbyPhaseTarget.hidden = false;
        this.participantCountTarget.textContent = state.participantCount;
        this.participantChipsTarget.innerHTML = '';
        state.participants.forEach((participant) => {
            const chip = document.createElement('span');
            chip.className = 'cm-quiz-live-chip';
            chip.textContent = participant.displayName;
            this.participantChipsTarget.appendChild(chip);
        });
    }

    // Once the 5s countdown elapses, the host's own client is what triggers the Countdown->Question
    // transition (a plain authenticated POST to advanceUrl, same endpoint the "Suivant" button
    // uses) - no cron/scheduled task involved, matching the "client ticks, server anchors, a POST
    // is what actually moves the state machine" convention used throughout this feature.
    showCountdown(state) {
        this.countdownPhaseTarget.hidden = false;
        const deadline = new Date(state.serverTime).getTime() + state.countdownSeconds * 1000;
        let advanced = false;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            this.countdownNumberTarget.textContent = remaining;
            if (remaining <= 0 && !advanced) {
                advanced = true;
                clearInterval(this.countdownInterval);
                fetch(this.advanceUrlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue } });
            }
        };
        tick();
        this.countdownInterval = setInterval(tick, 250);
    }

    showQuestion(state) {
        this.questionPhaseTarget.hidden = false;
        this.questionIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.questionLabelTarget.textContent = state.label;
        this.questionAnsweredCountTarget.textContent = '0';
        this.questionParticipantCountTarget.textContent = state.participantCount;

        if (state.imageUrl) {
            this.questionImageTarget.src = state.imageUrl;
            this.questionImageWrapTarget.hidden = false;
        } else {
            this.questionImageWrapTarget.hidden = true;
        }

        this.questionAnswersTarget.innerHTML = '';
        state.answers.forEach((answer) => {
            const row = document.createElement('div');
            row.className = `cm-quiz-live-answer cm-quiz-live-answer--${answer.color}`;
            row.innerHTML = `<span class="cm-quiz-live-answer__shape">${this.shapeGlyph(answer.shape)}</span><span class="cm-quiz-live-answer__label">${answer.label}</span>`;
            this.questionAnswersTarget.appendChild(row);
        });

        const deadline = new Date(state.phaseStartedAt).getTime() + state.secondsPerQuestion * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            this.questionTimerTarget.textContent = remaining;
        };
        tick();
        this.questionTimerInterval = setInterval(tick, 250);
    }

    showReveal(state) {
        this.revealPhaseTarget.hidden = false;
        this.revealNextLabelTarget.textContent = state.isLastQuestion
            ? this.element.dataset.quizLiveHostFinishLabel
            : this.element.dataset.quizLiveHostNextLabel;

        const correctCount = null !== state.correctShapeIndex ? (state.answerCounts[state.correctShapeIndex] || 0) : 0;
        const totalAnswers = state.answerCounts.reduce((sum, count) => sum + count, 0);
        this.revealCorrectLabelTarget.innerHTML = null !== state.correctShapeIndex
            ? `${this.element.dataset.quizLiveHostCorrectAnswerLabel} <b>${this.shapeGlyph(this.shapeForIndex(state.correctShapeIndex))}</b> · ${correctCount} / ${totalAnswers}`
            : '';

        this.revealLeaderboardTarget.innerHTML = '';
        state.leaderboard.slice(0, 8).forEach((row) => {
            const maxScore = state.leaderboard[0]?.score || 1;
            const width = Math.max(6, Math.round((row.score / maxScore) * 100));
            const item = document.createElement('div');
            item.className = 'cm-quiz-live-rank';
            item.innerHTML = `<span class="cm-quiz-live-rank__position">${row.rank}</span><span class="cm-quiz-live-rank__name">${row.displayName}</span><span class="cm-quiz-live-rank__bar-wrap"><span class="cm-quiz-live-rank__bar" style="width:${width}%">${row.score.toLocaleString('fr-FR')}</span></span>`;
            this.revealLeaderboardTarget.appendChild(item);
        });
    }

    showFinished(state) {
        this.finishedPhaseTarget.hidden = false;
        const [first, second, third] = state.podium;
        this.renderPodiumSlot(this.podiumFirstTarget, first);
        this.renderPodiumSlot(this.podiumSecondTarget, second);
        this.renderPodiumSlot(this.podiumThirdTarget, third);

        this.finishedLeaderboardTarget.innerHTML = '';
        state.finalLeaderboard.forEach((row) => {
            const item = document.createElement('div');
            item.className = 'cm-quiz-live-rank';
            item.innerHTML = `<span class="cm-quiz-live-rank__position">${row.rank}</span><span class="cm-quiz-live-rank__name">${row.displayName}</span><span class="cm-quiz-live-rank__score">${row.score.toLocaleString('fr-FR')}</span>`;
            this.finishedLeaderboardTarget.appendChild(item);
        });
    }

    renderPodiumSlot(target, row) {
        if (!target) {
            return;
        }
        if (!row) {
            target.hidden = true;
            return;
        }
        target.hidden = false;
        const nameEl = target.querySelector('[data-quiz-live-host-target="podiumName"]');
        const scoreEl = target.querySelector('[data-quiz-live-host-target="podiumScore"]');
        if (nameEl) {
            nameEl.textContent = row.displayName;
        }
        if (scoreEl) {
            scoreEl.textContent = `${row.score.toLocaleString('fr-FR')} pts`;
        }
    }

    shapeGlyph(shape) {
        return { triangle: '▲', square: '■', circle: '●', diamond: '◆' }[shape] || '';
    }

    // Matches App\Service\QuizLiveSessionService::SHAPES's fixed index order - the reveal payload
    // only carries the winning shapeIndex (0-3), not the shape name itself (that only travels in
    // question-opened, already gone by the time reveal arrives).
    shapeForIndex(index) {
        return ['triangle', 'square', 'circle', 'diamond'][index] || '';
    }
}
