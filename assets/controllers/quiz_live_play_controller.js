import { Controller } from '@hotwired/stimulus';

/**
 * Drives a student's own live-quiz screen (waiting room / countdown / question / reveal / podium),
 * the player-side counterpart of quiz_live_host_controller.js - see
 * App\Controller\ProgramQuizLiveController::play()'s docblock for the SSE auth. Answer buttons
 * show shape/color only, never a label (confirmed product decision - see
 * App\Service\QuizLiveSessionService's class docblock on why this diverges from the mockup's
 * Turn 1l, which shows text).
 */
export default class extends Controller {
    static targets = [
        'lobbyPhase', 'countdownPhase', 'questionPhase', 'revealPhase', 'finishedPhase',
        'lobbyParticipantCount',
        'countdownNumber',
        'questionIndex', 'questionTimer', 'questionAnswers', 'questionSentNotice',
        'revealResultBadge', 'revealMyScore', 'revealMyRank',
        'podiumMyRank', 'podiumMyScore',
    ];

    static values = {
        hubUrl: String,
        topic: String,
        answerUrl: String,
        csrfToken: String,
        participantId: Number,
        initialState: Object,
    };

    connect() {
        this.answered = false;
        this.selectedShapeIndex = null;
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

    submitAnswer(event) {
        if (this.answered) {
            return;
        }
        const answerId = Number(event.currentTarget.dataset.answerId);
        this.answered = true;
        this.selectedShapeIndex = Number(event.currentTarget.dataset.shapeIndex);
        this.questionAnswersTarget.querySelectorAll('button').forEach((button) => {
            button.disabled = true;
            button.classList.toggle('cm-quiz-live-shape--selected', Number(button.dataset.answerId) === answerId);
        });
        this.questionSentNoticeTarget.hidden = false;

        fetch(this.answerUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': this.csrfTokenValue,
            },
            body: `answerId=${encodeURIComponent(answerId)}`,
        });
    }

    apply(state) {
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
                window.location.href = this.element.dataset.quizLivePlayHomeUrl;
                break;
        }
    }

    hidePhases() {
        [this.lobbyPhaseTarget, this.countdownPhaseTarget, this.questionPhaseTarget, this.revealPhaseTarget, this.finishedPhaseTarget]
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

    showQuestion(state) {
        this.answered = false;
        this.selectedShapeIndex = null;
        this.questionPhaseTarget.hidden = false;
        this.questionSentNoticeTarget.hidden = true;
        this.questionIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;

        this.questionAnswersTarget.innerHTML = '';
        state.answers.forEach((answer) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `cm-quiz-live-shape cm-quiz-live-shape--${answer.color}`;
            button.dataset.answerId = answer.answerId;
            button.dataset.shapeIndex = answer.shapeIndex;
            button.dataset.action = 'click->quiz-live-play#submitAnswer';
            button.textContent = this.shapeGlyph(answer.shape);
            this.questionAnswersTarget.appendChild(button);
        });

        const deadline = new Date(state.phaseStartedAt).getTime() + state.secondsPerQuestion * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            this.questionTimerTarget.textContent = remaining;
            if (remaining <= 0) {
                this.questionAnswersTarget.querySelectorAll('button').forEach((button) => { button.disabled = true; });
            }
        };
        tick();
        this.questionTimerInterval = setInterval(tick, 250);
    }

    showReveal(state) {
        this.revealPhaseTarget.hidden = false;
        const mine = state.leaderboard.find((row) => row.participantId === this.participantIdValue);

        // this.selectedShapeIndex only survives within the same page load (set in submitAnswer());
        // after a reconnect it's null and we simply skip the correct/wrong badge rather than
        // guess - same "degrade gracefully on reconnect" convention as the rest of this feature.
        this.revealResultBadgeTarget.textContent = null === this.selectedShapeIndex
            ? ''
            : (this.selectedShapeIndex === state.correctShapeIndex
                ? this.element.dataset.quizLivePlayCorrectLabel
                : this.element.dataset.quizLivePlayWrongLabel);
        this.revealMyScoreTarget.textContent = mine ? mine.score.toLocaleString('fr-FR') : '0';
        this.revealMyRankTarget.textContent = mine ? `#${mine.rank}` : '—';
    }

    showFinished(state) {
        this.finishedPhaseTarget.hidden = false;
        const mine = state.finalLeaderboard.find((row) => row.participantId === this.participantIdValue);
        this.podiumMyRankTarget.textContent = mine ? `#${mine.rank}` : '—';
        this.podiumMyScoreTarget.textContent = mine ? mine.score.toLocaleString('fr-FR') : '0';
    }

    shapeGlyph(shape) {
        return { triangle: '▲', square: '■', circle: '●', diamond: '◆' }[shape] || '';
    }
}
