import { Controller } from '@hotwired/stimulus';

/**
 * Outils > École Directe: sign in to one's own École Directe account, answer its identity question,
 * then read from it.
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
        'connectedPanel', 'account', 'lessonLogPanel', 'from', 'to', 'lessonLogButton', 'slots',
        'path', 'exploreButton', 'raw',
    ];

    static values = {
        token: String,
        loginUrl: String,
        challengeUrl: String,
        lessonLogUrl: String,
        exploreUrl: String,
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

    forget() {
        this.#session = null;
        this.#pending = null;
        this.accountTarget.innerHTML = '';
        this.slotsTarget.innerHTML = '';
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
        this.lessonLogPanelTarget.hidden = !answer.teacher;
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
