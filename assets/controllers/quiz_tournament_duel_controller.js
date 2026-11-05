import { Controller } from '@hotwired/stimulus';

// A duel of a quiz tournament (design/validated/quiz-tournament.md §5.2, mockup H-question).
//
// The server holds the clock (App\Service\QuizTournament\QuizTournamentDuelEngine): every state it
// answers says the phase, the question in play, who has answered, right or wrong for each once a
// question closed, the scores, and `nextChangeAt` - when what the screen shows changes on its own.
// This page draws that state and asks again at that moment (and every few seconds, in case a timer
// was throttled); the other player's answers, a question both answered, the end, arrive at once over
// Mercure (the duel's private topic). The timer reads the server's deadline, corrected by the gap
// between the server's clock and this one (`serverTime`): a reload never restarts it. That deadline
// is the player's own (`clock`, extra time included), sent only in the answers to their own requests:
// the published state carries nobody's.
//
// What is ticked (a QCM à réponses multiples) or typed (a numérique) without « Valider » is sent as a
// draft at each change; the server makes it the answer when the player's time runs out.
//
// A question's correction never reaches the page: the published state has none, and the player's
// own pick comes back only in the answers to their own requests (`mine`).
const SAFETY_POLL_MS = 5000;
const TICK_MS = 250;
const DRAFT_DELAY_MS = 300;
const DRAFT_FLUSH_MS = 1500;
const PHASE_ORDER = { waiting: 0, question: 1, reveal: 2 };

export default class extends Controller {
    static targets = [
        'myScore', 'opponentScore', 'counter', 'steps', 'waiting', 'waitingNumber', 'play',
        'timer', 'timerValue', 'type', 'opponentChip', 'label', 'image', 'sticker', 'answers',
        'numeric', 'value', 'unit', 'suffix', 'submit', 'foot', 'refusal',
    ];

    static values = {
        stateUrl: String,
        answerUrl: String,
        draftUrl: String,
        token: String,
        hubUrl: String,
        topic: String,
        me: Number,
        opponent: Number,
        state: Object,
        texts: Object,
    };

    connect() {
        this.texts = this.textsValue;
        this.offset = 0;
        this.state = null;
        this.mine = null;
        this.clock = null;
        this.renderedRank = null;
        this.drafts = Promise.resolve();
        this.draftTimer = null;
        this.selected = new Set();
        this.sending = false;
        this.leaving = false;

        if (this.hubUrlValue && this.topicValue) {
            const url = new URL(this.hubUrlValue, window.location.origin);
            url.searchParams.append('topic', this.topicValue);
            this.source = new EventSource(url, { withCredentials: true });
            this.source.onmessage = (event) => this.#onPublished(event.data);
        }

        this.#apply(this.stateValue, true);
        this.ticker = window.setInterval(() => this.#tick(), TICK_MS);
        this.poller = window.setInterval(() => this.#fetchState(), SAFETY_POLL_MS);
    }

    disconnect() {
        window.clearInterval(this.ticker);
        window.clearInterval(this.poller);
        window.clearTimeout(this.changeTimer);
        window.clearTimeout(this.leaveTimer);
        window.clearTimeout(this.ownTimer);
        window.clearTimeout(this.draftTimer);
        this.source?.close();
    }

    // A click on a choice: a single-answer question is sent at once, a multiple one is ticked.
    choose(event) {
        const question = this.state?.question;
        if (!question || this.#locked()) return;
        const id = Number(event.currentTarget.dataset.choiceId);

        if (question.type === 'qcm_multi') {
            if (this.selected.has(id)) this.selected.delete(id);
            else this.selected.add(id);
            this.#renderSelection();
            this.submitTarget.disabled = this.selected.size === 0;
            this.#queueDraft(0);
            return;
        }

        this.selected = new Set([id]);
        this.#renderSelection();
        this.#send({ rank: question.rank, choices: [id] });
    }

    // A number being typed: kept as a draft once the typing pauses.
    typed() {
        if (!this.state?.question || this.#locked()) return;
        this.#queueDraft(DRAFT_DELAY_MS);
    }

    // « Valider » - a multiple-answer QCM, or a number (Entrée in its field too).
    submit(event) {
        event?.preventDefault();
        const question = this.state?.question;
        if (!question || this.#locked()) return;
        window.clearTimeout(this.draftTimer);
        this.draftTimer = null;

        if (question.type === 'numerique') {
            const value = this.valueTarget.value.trim();
            if (value === '') return;
            this.#send({ rank: question.rank, value, unit: question.unitRequired ? this.unitTarget.value.trim() : '' });
            return;
        }
        if (this.selected.size === 0) return;
        this.#send({ rank: question.rank, choices: [...this.selected] });
    }

    async #send(body) {
        this.sending = true;
        this.#renderInputs();
        try {
            // A draft still on its way must not be answered after the answer.
            await this.drafts;
            const response = await fetch(this.answerUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await response.json();
            this.#showRefusal(data.refusal ?? null);
            this.#apply(data, true);
        } catch {
            this.#showRefusal(this.texts.error);
        } finally {
            this.sending = false;
            this.#renderInputs();
        }
    }

    // What is ticked or typed now, as the body of an answer: nothing when nothing is.
    #pending() {
        const question = this.state?.question;
        if (!question) return null;
        if (question.type === 'numerique') {
            return { rank: question.rank, value: this.valueTarget.value.trim(), unit: question.unitRequired ? this.unitTarget.value.trim() : '' };
        }
        return { rank: question.rank, choices: [...this.selected] };
    }

    #queueDraft(delay) {
        window.clearTimeout(this.draftTimer);
        this.draftTimer = window.setTimeout(() => this.#flushDraft(), delay);
    }

    // Drafts go one after the other, so the last one saved is the last one made.
    #flushDraft() {
        window.clearTimeout(this.draftTimer);
        this.draftTimer = null;
        const body = this.#pending();
        // Not #locked(): a last change made as the time ran out still goes - the server judges.
        if (!body || this.mine !== null || this.state?.phase !== 'question') return;
        this.drafts = this.drafts.then(() => this.#sendDraft(body));
    }

    async #sendDraft(body) {
        if (this.mine !== null || this.state?.question?.rank !== body.rank) return;
        try {
            const response = await fetch(this.draftUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue, Accept: 'application/json' },
                body: JSON.stringify(body),
            });
            // A refused draft (too late, already answered) says nothing: the state tells the rest.
            this.#apply(await response.json(), true);
        } catch {
            // The next change sends it again.
        }
    }

    async #fetchState() {
        if (this.leaving) return;
        try {
            const response = await fetch(this.stateUrlValue, { headers: { Accept: 'application/json' } });
            if (response.ok) this.#apply(await response.json(), true);
        } catch {
            // The next poll tries again.
        }
    }

    #onPublished(raw) {
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            return;
        }
        this.#apply(data, false);
    }

    // Applies a state unless it is older than the one shown - a published update may arrive after
    // the answer to a later request.
    #apply(state, own) {
        if (!state || !state.phase) return;
        if (this.state && this.#progress(state) < this.#progress(this.state)) return;

        if (own) {
            this.offset = Date.parse(state.serverTime) - Date.now();
            this.mine = state.mine ?? null;
            this.clock = state.clock ?? null;
        } else if (this.state && state.rank !== this.state.rank) {
            // A new question reached by the hub: this player's own time on it comes from the server.
            this.mine = null;
            this.clock = null;
            this.#fetchState();
        }
        if (state.rank !== this.state?.rank) this.selected = new Set();

        this.state = state;
        this.#render();
        this.#scheduleChange();
    }

    #progress(state) {
        if (state.phase === 'finished') return Number.MAX_SAFE_INTEGER;
        if (state.phase === 'waiting') return -1;
        return state.rank * 3 + PHASE_ORDER[state.phase];
    }

    #scheduleChange() {
        window.clearTimeout(this.changeTimer);
        window.clearTimeout(this.ownTimer);
        const next = this.state?.nextChangeAt;
        if (!next) return;
        const delay = Math.max(0, Date.parse(next) - this.#now()) + 150;
        this.changeTimer = window.setTimeout(() => this.#fetchState(), delay);

        // This player's time may run out before the question closes (the other has extra time):
        // asked again then, the server answers with what was ticked made into the answer.
        const deadline = this.#deadline();
        if (deadline !== null && this.state.phase === 'question' && deadline < Date.parse(next)) {
            this.ownTimer = window.setTimeout(() => this.#fetchState(), Math.max(0, deadline - this.#now()) + 1150);
        }
    }

    // This player's deadline on the question in play, or null while it is not known.
    #deadline() {
        const { clock } = this;
        return clock && this.state?.question ? Date.parse(clock.deadlineAt) : null;
    }

    #outOfTime() {
        const deadline = this.#deadline();
        return deadline !== null && this.#now() >= deadline;
    }

    #now() {
        return Date.now() + this.offset;
    }

    #locked() {
        return this.sending || this.state?.phase !== 'question' || this.mine !== null || this.#outOfTime();
    }

    #render() {
        const { state } = this;
        this.myScoreTarget.textContent = this.#score(state.scores?.[this.meValue]);
        this.opponentScoreTarget.textContent = this.#score(state.scores?.[this.opponentValue]);
        this.counterTarget.textContent = state.rank === null || state.rank === undefined
            ? ''
            : this.texts.counter.replace('%rank%', String(state.rank + 1)).replace('%total%', String(state.total));
        this.#renderSteps();

        if (state.phase === 'finished') {
            this.#finish();
            return;
        }
        this.waitingTarget.hidden = state.phase !== 'waiting';
        this.playTarget.hidden = state.phase === 'waiting';
        if (state.phase === 'waiting' || !state.question) {
            this.#tick();
            return;
        }

        if (this.renderedRank !== state.question.rank) this.#renderQuestion(state.question);
        this.#renderOpponent();
        this.#renderReveal();
        this.#renderSelection();
        this.#renderInputs();
        this.#tick();
    }

    #renderSteps() {
        const steps = [...this.stepsTarget.children];
        const total = Math.max(this.state.total, 1);
        if (steps.length !== total) {
            this.stepsTarget.replaceChildren(...Array.from({ length: total }, () => {
                const step = document.createElement('li');
                step.className = 'qt-duel-step';
                return step;
            }));
            this.stepsTarget.style.setProperty('--qt-steps', String(total));
        }
        [...this.stepsTarget.children].forEach((step, index) => {
            const result = this.state.results?.[index] ?? null;
            const points = result ? result[this.meValue] : undefined;
            step.className = 'qt-duel-step';
            if (result) step.classList.add(this.#resultClass(points));
            else if (index === this.state.rank) step.classList.add('is-current');
        });
    }

    #resultClass(points) {
        if (points === null || points === undefined || points <= 0) return 'is-wrong';
        return points >= 1 ? 'is-right' : 'is-half';
    }

    #renderQuestion(question) {
        this.renderedRank = question.rank;
        this.selected = new Set();
        this.#showRefusal(null);
        this.typeTarget.textContent = this.texts.types[question.type] ?? '';
        this.labelTarget.textContent = question.label;
        if (question.image) {
            this.imageTarget.src = question.image;
            this.imageTarget.hidden = false;
        } else {
            this.imageTarget.removeAttribute('src');
            this.imageTarget.hidden = true;
        }

        const numeric = question.type === 'numerique';
        this.answersTarget.replaceChildren(...(numeric ? [] : question.choices.map((choice, index) => this.#choiceButton(choice, index))));
        this.answersTarget.hidden = numeric;
        this.numericTarget.hidden = !numeric;
        this.valueTarget.value = '';
        this.valueTarget.placeholder = this.texts.valuePlaceholder;
        this.unitTarget.value = '';
        this.unitTarget.placeholder = this.texts.unitPlaceholder;
        this.unitTarget.hidden = !(numeric && question.unitRequired);
        this.suffixTarget.hidden = !(numeric && question.unit && !question.unitRequired);
        this.suffixTarget.textContent = question.unit ?? '';
        this.submitTarget.hidden = !(numeric || question.type === 'qcm_multi');
        this.submitTarget.disabled = question.type === 'qcm_multi';
        if (numeric && this.mine === null) this.valueTarget.focus({ preventScroll: true });
    }

    #choiceButton(choice, index) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'qt-choice';
        button.dataset.choiceId = String(choice.id);
        button.dataset.action = 'quiz-tournament-duel#choose';
        const letter = document.createElement('span');
        letter.className = 'qt-choice__letter';
        letter.setAttribute('aria-hidden', 'true');
        letter.textContent = String.fromCharCode(65 + index);
        const text = document.createElement('span');
        text.className = 'qt-choice__text qt-multiline';
        text.textContent = choice.label;
        button.append(letter, text);
        return button;
    }

    #renderSelection() {
        const picked = this.mine ? new Set(this.mine.choices) : this.selected;
        const answered = this.mine !== null || this.state.phase !== 'question';
        this.answersTarget.querySelectorAll('.qt-choice').forEach((button) => {
            const on = picked.has(Number(button.dataset.choiceId));
            button.classList.toggle('is-picked', on);
            button.classList.toggle('is-dimmed', answered && !on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (this.mine && this.state.question?.type === 'numerique') {
            this.valueTarget.value = this.mine.value === null ? '' : String(this.mine.value).replace('.', ',');
            this.unitTarget.value = this.mine.unit ?? '';
        }
    }

    #renderInputs() {
        const locked = this.#locked();
        this.answersTarget.querySelectorAll('.qt-choice').forEach((button) => { button.disabled = locked; });
        this.valueTarget.disabled = locked;
        this.unitTarget.disabled = locked;
        if (locked) this.submitTarget.disabled = true;
        else if (this.state?.question?.type === 'numerique') this.submitTarget.disabled = false;
        else this.submitTarget.disabled = this.selected.size === 0;
    }

    #renderOpponent() {
        const chip = this.opponentChipTarget;
        chip.classList.remove('is-answered');
        if (this.state.phase === 'reveal') {
            const result = this.state.results?.[this.state.rank];
            const points = result ? result[this.opponentValue] : null;
            chip.textContent = points === null || points === undefined
                ? this.texts.opponentNone
                : (points >= 1 ? this.texts.opponentRight : this.texts.opponentWrong);
            return;
        }
        const answered = Boolean(this.state.answered?.[this.opponentValue]);
        chip.textContent = answered ? this.texts.opponentAnswered : this.texts.opponentThinking;
        chip.classList.toggle('is-answered', answered);
    }

    #renderReveal() {
        const sticker = this.stickerTarget;
        this.playTarget.classList.remove('is-right', 'is-wrong');
        if (this.state.phase !== 'reveal') {
            sticker.hidden = true;
            if (this.mine !== null) this.footTarget.textContent = this.texts.sent;
            else this.footTarget.textContent = this.#outOfTime() ? this.texts.timeUp : '';
            return;
        }
        const result = this.state.results?.[this.state.rank];
        const points = result ? result[this.meValue] : null;
        const right = points !== null && points !== undefined && points >= 1;
        sticker.textContent = right ? this.texts.right : this.texts.wrong;
        sticker.classList.toggle('is-right', right);
        sticker.classList.toggle('is-wrong', !right);
        sticker.hidden = false;
        this.playTarget.classList.add(right ? 'is-right' : 'is-wrong');
        if (right) this.footTarget.textContent = this.texts.footRight;
        else this.footTarget.textContent = points === null || points === undefined ? this.texts.footNone : this.texts.footWrong;
    }

    #tick() {
        const { state } = this;
        if (!state) return;
        if (state.phase === 'waiting') {
            const seconds = state.nextChangeAt ? Math.max(0, Math.ceil((Date.parse(state.nextChangeAt) - this.#now()) / 1000)) : 0;
            this.waitingNumberTarget.textContent = String(seconds);
            return;
        }
        const question = state.question;
        if (!question) return;

        const deadline = this.#deadline();
        const total = (this.clock?.seconds ?? 0) * 1000;
        const left = state.phase === 'question' && deadline !== null ? Math.max(0, deadline - this.#now()) : 0;
        const seconds = Math.ceil(left / 1000);
        // A number still being typed goes before the time runs out, not after the pause.
        if (this.draftTimer !== null && state.phase === 'question' && left <= DRAFT_FLUSH_MS) this.#flushDraft();
        // The time just ran out: inputs close, the foot says so.
        const out = this.#outOfTime();
        if (out !== this.wasOut) {
            this.wasOut = out;
            this.#renderInputs();
            this.#renderReveal();
        }
        this.timerValueTarget.textContent = String(seconds);
        this.timerTarget.style.setProperty('--qt-timer-left', String(total > 0 ? left / total : 0));
        this.timerTarget.setAttribute('aria-label', this.texts.timer.replace('%seconds%', String(seconds)));
        this.timerTarget.classList.toggle('is-short', state.phase === 'question' && seconds <= 5);
    }

    // The duel is over: the last « JUSTE ! » / « RATÉ » stays its two seconds, then the result.
    #finish() {
        if (this.leaving) return;
        this.leaving = true;
        window.clearInterval(this.poller);
        window.clearTimeout(this.changeTimer);
        this.footTarget.textContent = this.texts.finished;
        const url = this.state.resultUrl;
        if (url) this.leaveTimer = window.setTimeout(() => window.location.assign(url), this.playTarget.hidden ? 0 : 1200);
    }

    #score(value) {
        const number = Number(value ?? 0);
        return Number.isInteger(number) ? String(number) : number.toFixed(1).replace('.', ',');
    }

    #showRefusal(text) {
        this.refusalTarget.hidden = !text;
        this.refusalTarget.textContent = text ?? '';
    }
}
