import { Controller } from '@hotwired/stimulus';

/**
 * Outils > École Directe: sign in to one's own École Directe account, answer its identity question,
 * read from it, send the cahier de texte and an evaluation's grades to it.
 *
 * **Every call to École Directe is a button.** Nothing reads on connect and nothing writes on its
 * own: signing in shows the account the login answered with, and every further read or send waits
 * for its own click - the two sends behind a preview the teacher has looked at.
 *
 * The sealed connection the server hands back lives in this controller's memory and nowhere else -
 * not in localStorage, not in a cookie: the platform promised to keep nothing of a teacher's École
 * Directe access, and a tab closed is a connection forgotten. The password is read from its field
 * when it is needed (the identity question replays the whole login) and the field is emptied once
 * the connection is open.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'error', 'loginPanel', 'identifiant', 'password', 'loginButton',
        'challengePanel', 'question', 'choices', 'answerButton',
        'connectedPanel', 'account', 'teacherPanels', 'from', 'to', 'lessonLogButton', 'slots',
        'path', 'exploreButton', 'raw',
        'sendFrom', 'sendTo', 'lessonLogPreviewButton', 'lessonLogPreview', 'lessonLogKey',
        'lessonLogSendBar', 'lessonLogSendButton', 'lessonLogResult',
        'gradebookTargetsButton', 'evaluation', 'gradebookTarget', 'gradebookPreviewButton',
        'gradebookPreview', 'gradebookSendBar', 'gradebookSendButton', 'gradebookResult',
    ];

    static values = {
        token: String,
        loginUrl: String,
        challengeUrl: String,
        lessonLogUrl: String,
        exploreUrl: String,
        lessonLogPreviewUrl: String,
        lessonLogSendUrl: String,
        gradebookTargetsUrl: String,
        gradebookPreviewUrl: String,
        gradebookSendUrl: String,
        unreachableMessage: String,
    };

    #session = null;
    #pending = null;

    disconnect() {
        this.#session = null;
        this.#pending = null;
    }

    async login(event) {
        event.preventDefault();

        const answer = await this.#post(this.loginUrlValue, this.#credentials(), this.loginButtonTarget);
        this.#settle(answer);
    }

    async answer(event) {
        event.preventDefault();

        const picked = this.choicesTarget.querySelector('input[type="radio"]:checked');
        if (!picked || !this.#pending) {
            return;
        }

        const answer = await this.#post(this.challengeUrlValue, {
            ...this.#credentials(),
            pending: this.#pending,
            choice: picked.value,
        }, this.answerButtonTarget);
        this.#settle(answer);
    }

    async readLessonLog(event) {
        event.preventDefault();

        const answer = await this.#read(this.lessonLogUrlValue, { from: this.fromTarget.value, to: this.toTarget.value }, this.lessonLogButtonTarget);
        if (answer) {
            this.slotsTarget.innerHTML = answer.html;
        }
    }

    async explore(event) {
        event.preventDefault();

        const answer = await this.#read(this.exploreUrlValue, { path: this.pathTarget.value }, this.exploreButtonTarget);
        if (answer) {
            this.rawTarget.textContent = answer.raw;
            this.rawTarget.hidden = false;
        }
    }

    /** Reads École Directe and the MonCampus cahier de texte, and shows what sending would change. */
    async previewLessonLog(event) {
        event.preventDefault();

        this.lessonLogSendBarTarget.hidden = true;
        this.lessonLogResultTarget.innerHTML = '';
        const answer = await this.#read(this.lessonLogPreviewUrlValue, this.#sendSpan(), this.lessonLogPreviewButtonTarget);
        if (answer) {
            this.lessonLogPreviewTarget.innerHTML = answer.html;
            this.lessonLogSendBarTarget.hidden = this.lessonLogKeyTargets.length === 0;
        }
    }

    /** Sends the ticked slots - the server reads École Directe again before writing anything. */
    async sendLessonLog() {
        const keys = this.lessonLogKeyTargets.filter((box) => box.checked).map((box) => box.value);
        if (keys.length === 0) {
            return;
        }

        const answer = await this.#read(this.lessonLogSendUrlValue, { ...this.#sendSpan(), keys }, this.lessonLogSendButtonTarget);
        if (answer) {
            this.lessonLogResultTarget.innerHTML = answer.html;
            this.lessonLogPreviewTarget.innerHTML = '';
            this.lessonLogSendBarTarget.hidden = true;
        }
    }

    /** Reads, on demand, where in École Directe an evaluation can go. */
    async loadGradebookTargets() {
        const answer = await this.#read(this.gradebookTargetsUrlValue, {}, this.gradebookTargetsButtonTarget);
        if (!answer) {
            return;
        }

        const select = this.gradebookTargetTarget;
        select.querySelectorAll('option:not([value=""])').forEach((option) => option.remove());
        for (const target of answer.options) {
            const option = document.createElement('option');
            option.value = target.key;
            option.textContent = target.label;
            select.append(option);
        }
    }

    async previewGradebook(event) {
        event.preventDefault();

        this.gradebookSendBarTarget.hidden = true;
        this.gradebookResultTarget.innerHTML = '';
        const answer = await this.#read(this.gradebookPreviewUrlValue, this.#gradebookChoice(), this.gradebookPreviewButtonTarget);
        if (answer) {
            this.gradebookPreviewTarget.innerHTML = answer.html;
            this.gradebookSendBarTarget.hidden = !answer.sendable;
        }
    }

    async sendGradebook() {
        const answer = await this.#read(this.gradebookSendUrlValue, this.#gradebookChoice(), this.gradebookSendButtonTarget);
        if (answer) {
            this.gradebookResultTarget.innerHTML = answer.html;
            this.gradebookPreviewTarget.innerHTML = '';
            this.gradebookSendBarTarget.hidden = true;
        }
    }

    forget() {
        this.#session = null;
        this.#pending = null;
        this.accountTarget.innerHTML = '';
        this.slotsTarget.innerHTML = '';
        this.lessonLogPreviewTarget.innerHTML = '';
        this.lessonLogResultTarget.innerHTML = '';
        this.lessonLogSendBarTarget.hidden = true;
        if (this.hasGradebookPreviewTarget) {
            this.gradebookPreviewTarget.innerHTML = '';
            this.gradebookResultTarget.innerHTML = '';
            this.gradebookSendBarTarget.hidden = true;
        }
        if (this.hasRawTarget) {
            this.rawTarget.textContent = '';
            this.rawTarget.hidden = true;
        }
        this.#show('login');
    }

    #settle(answer) {
        if (!answer?.ok) {
            return;
        }

        if (answer.step === 'challenge') {
            this.#pending = answer.pending;
            this.questionTarget.textContent = answer.question;
            this.choicesTarget.innerHTML = '';
            answer.choices.forEach((choice, index) => this.choicesTarget.append(this.#radio(choice, index)));
            this.#show('challenge');

            return;
        }

        this.#session = answer.session;
        this.#pending = null;
        this.passwordTarget.value = '';
        this.accountTarget.innerHTML = answer.html;
        this.teacherPanelsTarget.hidden = !answer.teacher;
        this.#show('connected');
    }

    /** A read carries the connection and gets it back resealed - or learns it is over. */
    async #read(url, body, button) {
        if (!this.#session) {
            this.forget();

            return null;
        }

        const answer = await this.#post(url, { ...body, session: this.#session }, button);
        if (answer?.ok) {
            this.#session = answer.session;

            return answer;
        }
        if (answer?.expired) {
            this.forget();
            this.#error(answer.message);
        }

        return null;
    }

    #sendSpan() {
        return { from: this.sendFromTarget.value, to: this.sendToTarget.value };
    }

    #gradebookChoice() {
        return { evaluation: this.evaluationTarget.value, target: this.gradebookTargetTarget.value };
    }

    #credentials() {
        return { identifiant: this.identifiantTarget.value, password: this.passwordTarget.value };
    }

    #radio(choice, index) {
        const id = `ecole-directe-choice-${index}`;
        const wrapper = document.createElement('label');
        wrapper.className = 'form-check';
        wrapper.htmlFor = id;

        const input = document.createElement('input');
        input.type = 'radio';
        input.className = 'form-check-input';
        input.name = 'ecole-directe-choice';
        input.id = id;
        input.value = choice.value;
        input.required = true;

        const text = document.createElement('span');
        text.className = 'form-check-label';
        text.textContent = choice.label;

        wrapper.append(input, text);

        return wrapper;
    }

    #show(step) {
        this.loginPanelTarget.hidden = step !== 'login';
        this.challengePanelTarget.hidden = step !== 'challenge';
        this.connectedPanelTarget.hidden = step !== 'connected';
    }

    #error(message) {
        this.errorTarget.textContent = message ?? '';
        this.errorTarget.hidden = !message;
    }

    async #post(url, body, button) {
        this.#error(null);
        button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                throw new Error(`Unexpected response status: ${response.status}`);
            }

            const answer = await response.json();
            if (!answer.ok && !answer.expired) {
                this.#error(answer.message);
            }

            return answer;
        } catch {
            this.#error(this.unreachableMessageValue);

            return null;
        } finally {
            button.disabled = false;
        }
    }
}
