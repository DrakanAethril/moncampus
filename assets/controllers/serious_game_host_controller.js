import { Controller } from '@hotwired/stimulus';

/**
 * The host page of a serious game (design/validated/serious-games.md §6.1): the game runs in an
 * `<iframe sandbox="allow-scripts">` with no `allow-same-origin` - an opaque origin that reaches
 * neither the cookies nor this page - and talks to Formatool only by `postMessage`.
 *
 * This controller is the relay. It accepts a message only from that very frame, only of a known
 * type and method, and calls the SDK's routes itself, with the session cookie and the CSRF token
 * the game never sees. The game receives the JSON answer, or `{error}`; after the end of a session
 * that granted collection pieces, it is sent `reward.granted` to play its scene.
 *
 *   game → host  { sg: 1, type: 'call', id, method, params }
 *   host → game  { sg: 1, type: 'result', id, ok, result | error }
 *   host → game  { sg: 1, type: 'reward.granted', rewards }
 *   game → host  { sg: 1, type: 'exit' }  - back to the catalogue
 *
 * A game of the « scénario » mechanic (design/validated/serious-games-neko-con.md §3.3) has its own
 * calls - the board, the tickets, their steps and Inès' help. A delivery is relayed as its result
 * and its failed criteria only: the code the student wrote never leaves the frame (§3.4).
 *
 * It also holds two things every game gets from the platform rather than from itself (the user's
 * decision of 2026-10-18):
 *
 * - **Full screen.** A game opens full screen - the browser's own full screen when it allows it
 *   (the « Jouer » click of the catalogue already asked for it, see sg_launch_controller.js), the
 *   whole window otherwise - and the host bar toggles it. Escape leaves it, like everywhere.
 * - **The time left.** As soon as a session the game opens carries a deadline (La Couronne), the
 *   host bar counts down to it, read against the server's clock rather than the player's, until
 *   that session ends. The server decides the time; this only shows it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['frame', 'toggle', 'toggleLabel', 'timer', 'timerValue', 'announce'];

    static values = {
        src: String,
        api: String,
        csrf: String,
        exitUrl: String,
        enterLabel: String,
        exitLabel: String,
        timeUpLabel: String,
        minutesLeftLabel: String,
    };

    connect() {
        this.onMessage = (event) => this.receive(event);
        this.onFullscreenChange = () => this.fullscreenChanged();
        window.addEventListener('message', this.onMessage);
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', this.onFullscreenChange);

        // Launched full screen: the window at least, the browser's full screen when allowed.
        this.enterFullscreen();

        // The game is loaded only now that its messages have somewhere to go: a frame given its
        // address by the template could load, and call, before this lazily fetched relay is
        // connected - and wait for answers that never come.
        if (this.frameTarget.getAttribute('src') !== this.srcValue) {
            this.frameTarget.setAttribute('src', this.srcValue);
        }
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', this.onFullscreenChange);
        this.stopTimer();
        // Leaving the game leaves its full screen: the catalogue is never shown in it.
        document.body.classList.remove('cm-sg-immersive');
        if (this.fullscreenElement()) {
            this.exitBrowserFullscreen();
        }
    }

    /* ---------- full screen ---------- */

    toggleFullscreen() {
        if (this.element.classList.contains('is-immersive')) {
            this.leaveFullscreen();
        } else {
            this.enterFullscreen();
        }
    }

    enterFullscreen() {
        this.setImmersive(true);
        const root = document.documentElement;
        if (!this.fullscreenElement()) {
            const request = root.requestFullscreen ?? root.webkitRequestFullscreen;
            // Refused without a user gesture (a page opened from its address): the game still
            // fills the window, and the toggle asks again with the gesture it needs.
            Promise.resolve(request?.call(root)).catch(() => {});
        }
        this.focusGame();
    }

    leaveFullscreen() {
        this.setImmersive(false);
        if (this.fullscreenElement()) {
            this.exitBrowserFullscreen();
        }
        this.focusGame();
    }

    fullscreenChanged() {
        // Escape - or the browser's own gesture - leaves the browser's full screen: the player
        // asked to leave, so the game goes back into the page too.
        if (!this.fullscreenElement() && this.element.classList.contains('is-immersive')) {
            this.setImmersive(false);
        }
    }

    setImmersive(immersive) {
        this.element.classList.toggle('is-immersive', immersive);
        document.body.classList.toggle('cm-sg-immersive', immersive);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-pressed', immersive ? 'true' : 'false');
            this.toggleLabelTarget.textContent = immersive ? this.exitLabelValue : this.enterLabelValue;
        }
    }

    fullscreenElement() {
        return document.fullscreenElement ?? document.webkitFullscreenElement ?? null;
    }

    exitBrowserFullscreen() {
        const exit = document.exitFullscreen ?? document.webkitExitFullscreen;
        Promise.resolve(exit?.call(document)).catch(() => {});
    }

    focusGame() {
        // The keyboard belongs to the game: give it back after a click on the host bar.
        requestAnimationFrame(() => this.frameTarget.focus());
    }

    /* ---------- the time left ---------- */

    watchTime(route, params, body) {
        if (route.ends) {
            if (this.timer && this.timer.session === Number.parseInt(params.session, 10)) {
                this.stopTimer();
            }

            return;
        }

        if ('string' !== typeof body.deadline || '' === body.deadline) {
            return;
        }

        const session = Number.parseInt(body.session ?? params.session, 10);
        const deadline = Date.parse(body.deadline);
        const serverNow = 'string' === typeof body.serverTime ? Date.parse(body.serverTime) : Date.now();
        if (Number.isNaN(deadline) || Number.isNaN(serverNow)) {
            return;
        }

        // The deadline in the player's clock: what the server says is left, from now.
        const localDeadline = Date.now() + (deadline - serverNow);
        if (this.timer && this.timer.session === session) {
            this.timer.deadline = localDeadline;

            return;
        }

        this.startTimer(session, localDeadline);
    }

    startTimer(session, deadline) {
        this.stopTimer();
        this.timer = { session, deadline, announced: new Set(), interval: null };
        this.timerTarget.hidden = false;
        this.tick();
        this.timer.interval = window.setInterval(() => this.tick(), 1000);
    }

    tick() {
        if (!this.timer) {
            return;
        }

        const left = Math.max(0, Math.round((this.timer.deadline - Date.now()) / 1000));
        this.timerValueTarget.textContent = 0 === left ? this.timeUpLabelValue : `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        this.timerTarget.classList.toggle('is-low', left <= 120);
        this.timerTarget.classList.toggle('is-over', 0 === left);

        // A screen reader hears the time at a few thresholds, not every second.
        for (const minutes of [10, 5, 1]) {
            if (left <= minutes * 60 && left > minutes * 60 - 2 && !this.timer.announced.has(minutes)) {
                this.timer.announced.add(minutes);
                this.announceTarget.textContent = this.minutesLeftLabelValue.replace('%count%', String(minutes));
            }
        }
    }

    stopTimer() {
        if (this.timer?.interval) {
            window.clearInterval(this.timer.interval);
        }
        this.timer = null;
        if (this.hasTimerTarget) {
            this.timerTarget.hidden = true;
        }
    }

    /* ---------- the relay ---------- */

    async receive(event) {
        // The frame has an opaque origin ('null'): what proves the sender is the window itself.
        if (event.source !== this.frameTarget.contentWindow) {
            return;
        }

        const message = event.data;
        if (!message || 1 !== message.sg || 'string' !== typeof message.type) {
            return;
        }

        if ('exit' === message.type) {
            window.location.assign(this.exitUrlValue);

            return;
        }

        if ('call' !== message.type || !['string', 'number'].includes(typeof message.id)) {
            return;
        }

        const params = message.params && 'object' === typeof message.params ? message.params : {};
        const route = this.route(message.method, params);
        if (null === route) {
            this.post({ sg: 1, type: 'result', id: message.id, ok: false, error: 'unknown-method' });

            return;
        }

        try {
            const response = await fetch(this.apiValue + route.path, {
                method: route.method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: 'GET' === route.method ? undefined : JSON.stringify(route.body ?? {}),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (route.ends) {
                    this.watchTime(route, params, {});
                }
                this.post({ sg: 1, type: 'result', id: message.id, ok: false, error: body.error ?? `http-${response.status}` });

                return;
            }
            this.watchTime(route, params, body);
            this.post({ sg: 1, type: 'result', id: message.id, ok: true, result: body });
            // Only the end of a session grants pieces; the progress also lists the ones already held.
            if (route.ends && Array.isArray(body.rewards) && body.rewards.length > 0) {
                this.post({ sg: 1, type: 'reward.granted', rewards: body.rewards });
            }
        } catch {
            this.post({ sg: 1, type: 'result', id: message.id, ok: false, error: 'network' });
        }
    }

    route(method, params) {
        const session = Number.parseInt(params.session, 10);
        const sessionPath = (action) => (Number.isInteger(session) && session > 0 ? { method: 'POST', path: `/sessions/${session}/${action}` } : null);
        const ticketPath = (action) => (Number.isInteger(session) && session > 0 ? { method: 'POST', path: `/ticket-sessions/${session}/${action}` } : null);

        switch (method) {
            case 'getUser': return { method: 'GET', path: '/user' };
            case 'getProgress': return { method: 'GET', path: '/progress' };
            case 'saveProgress': return { method: 'POST', path: '/progress', body: { data: params.data ?? {} } };
            case 'startSeries': return { method: 'POST', path: '/series', body: { unit: String(params.unit ?? '') } };
            case 'startFinal': return { method: 'POST', path: '/final' };
            case 'studyComet': return { method: 'POST', path: '/comets', body: { misconception: String(params.misconception ?? '') } };
            case 'nextQuestion': return sessionPath('next');
            case 'answer': {
                const route = sessionPath('answer');

                return null === route ? null : { ...route, body: { input: String(params.input ?? '').slice(0, 500) } };
            }
            case 'endSeries':
            case 'endFinal':
            case 'endComet':
            case 'endSession': {
                const route = sessionPath('end');

                return null === route ? null : { ...route, ends: true };
            }
            // The « scénario » mechanic.
            case 'getScenario': return { method: 'GET', path: '/scenario' };
            case 'markActSeen': return { method: 'POST', path: '/scenario/act-seen', body: { act: Number.parseInt(params.act, 10) || 0 } };
            case 'newGame': return { method: 'POST', path: '/scenario/new-game' };
            case 'startTicket': return { method: 'POST', path: '/tickets', body: { ticket: String(params.ticket ?? '').slice(0, 40) } };
            case 'submitStep':
            case 'submitGuide': {
                const route = ticketPath('submitStep' === method ? 'step' : 'guide');

                return null === route ? null : { ...route, body: { step: String(params.step ?? '').slice(0, 40), input: this.stepInput(params.input) } };
            }
            case 'useHint': return ticketPath('hint');
            case 'endTicket': {
                const route = ticketPath('end');

                return null === route ? null : { ...route, ends: true };
            }
            default: return null;
        }
    }

    /**
     * What is relayed of a step's input: an option's rank, a line's or a password's text, or a
     * delivery's result - `{passed, failed}`, never the files the game sends along with it.
     */
    stepInput(input) {
        if ('number' === typeof input) {
            return Number.isInteger(input) ? input : null;
        }
        if ('string' === typeof input) {
            return input.slice(0, 500);
        }
        if (input && 'object' === typeof input) {
            const failed = Array.isArray(input.failed) ? input.failed.filter((f) => 'string' === typeof f).slice(0, 30).map((f) => f.slice(0, 300)) : [];

            return { passed: true === input.passed, failed };
        }

        return null;
    }

    post(message) {
        // An opaque origin cannot be named: '*' is the only target, and the frame is the only window.
        this.frameTarget.contentWindow?.postMessage(message, '*');
    }
}
