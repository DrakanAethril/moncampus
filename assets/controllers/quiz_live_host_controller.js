import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * Drives the teacher's projector screen - Kahoot's run of a question since 2026-11-05: lobby,
 * countdown, then for each question the statement alone, the propositions and the clock, what the
 * room answered, the scoreboard; the podium at the end (App\Enum\LiveSessionStatus). One page,
 * phases swapped via plain DOM show/hide, all state pushed by App\Service\QuizLiveSessionService
 * over Mercure (private "host" topic - see App\Controller\QuizLiveHostController::projector()'s
 * docblock for the cookie-based auth).
 *
 * Every button is a fire-and-forget POST naming the step it was pressed on: it never touches the
 * DOM itself, the resulting state change always arrives back through this controller's own SSE
 * subscription (same "server is the single source of truth" split as quiz_passation_controller.js's
 * timer). This screen also closes a question when its clock runs out - the server closes it itself
 * as soon as everybody has answered.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'lobbyPhase', 'countdownPhase', 'readingPhase', 'questionPhase', 'resultsPhase', 'scoreboardPhase', 'finishedPhase',
        'participantCount', 'participantChips',
        'countdownNumber',
        'readingIndex', 'readingKind', 'readingLabel', 'readingImage', 'readingImageWrap',
        'questionIndex', 'questionLabel', 'questionImage', 'questionImageWrap', 'questionHint', 'questionAnswers', 'questionTimer', 'questionAnsweredCount', 'questionParticipantCount',
        'resultsIndex', 'resultsSummary', 'resultsLabel', 'resultsExpected', 'resultsRows', 'resultsNextLabel',
        'scoreboardProgress', 'scoreboardRows',
        'podiumFirst', 'podiumSecond', 'podiumThird', 'podiumAvatar', 'finishedLeaderboard',
        'advanceButton',
    ];

    static values = {
        hubUrl: String,
        topic: String,
        advanceUrl: String,
        csrfToken: String,
        initialState: Object,
        labels: Object,
    };

    // Payload type => the status the server calls that step (App\Enum\LiveSessionStatus).
    static STEPS = {
        'countdown-started': 'countdown',
        'question-reading': 'reading',
        'question-opened': 'question',
        results: 'results',
        scoreboard: 'scoreboard',
    };

    // Matches App\Service\QuizLiveSessionService::SHAPES.
    static GLYPHS = { triangle: '▲', square: '■', circle: '●', diamond: '◆', pentagon: '⬟', 'triangle-down': '▼' };

    connect() {
        this.labels = this.labelsValue;
        this.step = null;
        this.questionIndex = null;
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
        this.advanceButtonTargets.forEach((button) => { button.disabled = true; });
        this.post().finally(() => {
            this.advanceButtonTargets.forEach((button) => { button.disabled = false; });
        });
    }

    // The step this screen shows rides along: a click and the clock arriving together move the
    // room once, and a request about a step already past is ignored by the server.
    post() {
        return fetch(this.advanceUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify({ from: this.step, questionIndex: this.questionIndex }),
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
        this.step = this.constructor.STEPS[state.type] ?? null;
        this.questionIndex = undefined !== state.questionIndex ? state.questionIndex : null;

        switch (state.type) {
            case 'participant-joined':
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
                window.location.reload();
                break;
        }
    }

    hidePhases() {
        [this.lobbyPhaseTarget, this.countdownPhaseTarget, this.readingPhaseTarget, this.questionPhaseTarget, this.resultsPhaseTarget, this.scoreboardPhaseTarget, this.finishedPhaseTarget]
            .forEach((el) => { el.hidden = true; });
    }

    showLobby(state) {
        this.lobbyPhaseTarget.hidden = false;
        this.participantCountTarget.textContent = state.participantCount;
        this.participantChipsTarget.innerHTML = '';
        state.participants.forEach((participant) => {
            const chip = document.createElement('span');
            chip.className = 'cm-quiz-live-chip';
            chip.append(this.face('cm-quiz-live-face', participant), this.span('cm-quiz-live-chip__name', participant.displayName));
            this.participantChipsTarget.appendChild(chip);
        });
    }

    // Once the 5s countdown elapses, the host's own client is what moves the room on to the first
    // question - no cron/scheduled task involved, matching the "client ticks, server anchors, a
    // POST is what actually moves the state machine" convention used throughout this feature.
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
                this.post();
            }
        };
        tick();
        this.countdownInterval = setInterval(tick, 250);
    }

    showReading(state) {
        this.readingPhaseTarget.hidden = false;
        this.readingIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.readingKindTarget.textContent = this.kindHint(state);
        this.readingLabelTarget.textContent = state.label || '';
        this.showImage(this.readingImageWrapTarget, this.readingImageTarget, state.imageUrl);
    }

    showQuestion(state) {
        this.questionPhaseTarget.hidden = false;
        this.questionIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.questionLabelTarget.textContent = state.label || '';
        this.questionAnsweredCountTarget.textContent = state.answeredCount ?? 0;
        this.questionParticipantCountTarget.textContent = state.participantCount;
        this.questionHintTarget.textContent = this.kindHint(state);
        this.showImage(this.questionImageWrapTarget, this.questionImageTarget, state.imageUrl);

        this.questionAnswersTarget.innerHTML = '';
        this.questionAnswersTarget.classList.toggle('cm-quiz-live-answers--wide', state.answers.length > 4);
        state.answers.forEach((answer) => {
            const row = document.createElement('div');
            row.className = `cm-quiz-live-answer cm-quiz-live-answer--${answer.color}`;
            row.append(
                this.span('cm-quiz-live-answer__shape', this.glyph(answer.shape)),
                this.span('cm-quiz-live-answer__label', answer.label),
            );
            this.questionAnswersTarget.appendChild(row);
        });

        // Untimed question - see the same guard in quiz_live_play_controller.js. Only everybody
        // answering, or « Passer », closes it.
        if (null === state.secondsPerQuestion || undefined === state.secondsPerQuestion) {
            this.questionTimerTarget.textContent = '∞';

            return;
        }

        const deadline = new Date(state.phaseStartedAt).getTime() + state.secondsPerQuestion * 1000;
        let closed = false;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            this.questionTimerTarget.textContent = remaining;
            if (remaining <= 0 && !closed) {
                closed = true;
                clearInterval(this.questionTimerInterval);
                this.post();
            }
        };
        tick();
        this.questionTimerInterval = setInterval(tick, 250);
    }

    showResults(state) {
        this.resultsPhaseTarget.hidden = false;
        this.resultsIndexTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.resultsLabelTarget.textContent = state.label || '';
        this.resultsSummaryTarget.textContent = state.isVote
            ? this.fill(this.labels.voteCount, { '%answered%': state.answeredCount })
            : this.fill(this.labels.correctCount, { '%correct%': state.correctCount, '%answered%': state.participantCount });
        this.resultsNextLabelTarget.textContent = state.isLastQuestion
            ? this.labels.podium
            : (state.isVote ? this.labels.next : this.labels.scoreboard);

        this.resultsExpectedTarget.hidden = !state.expected;
        this.resultsExpectedTarget.textContent = state.expected ? `${this.labels.expected} ${state.expected}` : '';

        this.resultsRowsTarget.innerHTML = '';
        if ('order' === state.kind) {
            state.rows.forEach((row) => {
                const line = document.createElement('div');
                line.className = `cm-quiz-live-answer cm-quiz-live-answer--${row.color}`;
                line.append(
                    this.span('cm-quiz-live-answer__rank', `${row.rank}.`),
                    this.span('cm-quiz-live-answer__shape', this.glyph(row.shape)),
                    this.span('cm-quiz-live-answer__label', row.label),
                );
                this.resultsRowsTarget.appendChild(line);
            });

            return;
        }

        const max = Math.max(1, ...state.rows.map((row) => row.count));
        state.rows.forEach((row) => {
            const line = document.createElement('div');
            line.className = 'cm-quiz-live-result';
            // A vote has no right answer: nothing is greyed, nothing is ticked.
            if (!state.isVote) {
                line.classList.add(row.correct ? 'is-correct' : 'is-wrong');
            }
            const label = document.createElement('span');
            label.className = 'cm-quiz-live-result__label';
            if (row.shape) {
                label.append(this.span(`cm-quiz-live-result__shape cm-quiz-live-result__shape--${row.color}`, this.glyph(row.shape)));
            }
            label.append(this.span('', row.label));
            const bar = this.span(`cm-quiz-live-result__bar${row.color ? ` cm-quiz-live-result__bar--${row.color}` : ''}`, '');
            bar.style.width = `${Math.max(2, Math.round((row.count / max) * 100))}%`;
            const barWrap = this.span('cm-quiz-live-result__bar-wrap', '');
            barWrap.appendChild(bar);
            line.append(label, barWrap, this.span('cm-quiz-live-result__count', row.count), this.span('cm-quiz-live-result__mark', !state.isVote && row.correct ? '✓' : ''));
            this.resultsRowsTarget.appendChild(line);
        });
    }

    showScoreboard(state) {
        this.scoreboardPhaseTarget.hidden = false;
        this.scoreboardProgressTarget.textContent = `${state.questionIndex + 1} / ${state.totalQuestions}`;
        this.scoreboardRowsTarget.innerHTML = '';
        const maxScore = state.top[0]?.score || 1;
        state.top.forEach((row) => {
            const width = Math.max(6, Math.round((row.score / maxScore) * 100));
            const bar = this.span('cm-quiz-live-rank__bar', row.score.toLocaleString('fr-FR'));
            bar.style.width = `${width}%`;
            const barWrap = this.span('cm-quiz-live-rank__bar-wrap', '');
            barWrap.appendChild(bar);

            const item = document.createElement('div');
            item.className = 'cm-quiz-live-rank';
            item.append(
                this.span('cm-quiz-live-rank__position', row.rank),
                this.face('cm-quiz-live-face', row),
                this.span('cm-quiz-live-rank__name', row.displayName),
                barWrap,
            );
            this.scoreboardRowsTarget.appendChild(item);
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
            item.append(
                this.span('cm-quiz-live-rank__position', row.rank),
                this.face('cm-quiz-live-face', row),
                this.span('cm-quiz-live-rank__name', row.displayName),
                this.span('cm-quiz-live-rank__score', row.score.toLocaleString('fr-FR')),
            );
            this.finishedLeaderboardTarget.appendChild(item);
        });
    }

    // What the phones are asked to do, said on the board: tick several, put in order, slide…
    kindHint(state) {
        const kinds = this.labels.kinds || {};
        if (state.isVote) {
            return kinds.vote || '';
        }
        if ('number' === state.kind && state.range) {
            return this.fill(kinds.slider || '', { '%min%': this.number(state.range.min), '%max%': this.number(state.range.max) });
        }

        return kinds[state.kind] || '';
    }

    showImage(wrap, image, url) {
        if (url) {
            image.src = url;
            wrap.hidden = false;
        } else {
            wrap.hidden = true;
        }
    }

    fill(template, values) {
        return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(key, String(value)), template || '');
    }

    number(value) {
        return Number(value).toLocaleString('fr-FR');
    }

    /**
     * A <span> whose text is set as text, never as markup: a participant picks their own display
     * name in the mobile app (Api\QuizLiveController::join()), and it is shown here on the
     * teacher's screen - the one place where injected markup would run with a staff session.
     */
    span(className, text) {
        const element = document.createElement('span');
        element.className = className;
        element.textContent = String(text);

        return element;
    }

    /**
     * A participant's face: their photo, else the avatar they drew in the serious games (drawn by
     * the module the games share), else the first letter of the name they play under - the payload
     * carries `avatar: {photo, drawn}` (App\Service\QuizLiveSessionService::avatarOf()).
     */
    face(className, row) {
        const face = document.createElement('span');
        face.className = className;
        this.paintFace(face, row);

        return face;
    }

    paintFace(face, row) {
        const avatar = row.avatar || {};
        face.classList.toggle('cm-avatar-drawn', !avatar.photo && !!avatar.drawn);
        face.style.backgroundImage = avatar.photo ? `url(${JSON.stringify(avatar.photo)})` : '';
        face.textContent = avatar.photo ? '' : (Array.from((row.displayName || '').trim())[0]?.toUpperCase() || '?');
        if (!avatar.photo && avatar.drawn) {
            loadAvatarModule().then((module) => {
                face.innerHTML = module.svg(module.normalize(avatar.drawn), { size: 80, round: false });
            });
        }
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
        const avatarEl = target.querySelector('[data-quiz-live-host-target="podiumAvatar"]');
        if (avatarEl) {
            this.paintFace(avatarEl, row);
        }
        if (nameEl) {
            nameEl.textContent = row.displayName;
        }
        if (scoreEl) {
            scoreEl.textContent = `${row.score.toLocaleString('fr-FR')} pts`;
        }
    }

    glyph(shape) {
        return this.constructor.GLYPHS[shape] || '';
    }
}
