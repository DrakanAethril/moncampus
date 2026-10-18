import { Controller } from '@hotwired/stimulus';

/**
 * The host page of a serious game (design/validated/serious-games.md §6.1): the game runs in an
 * `<iframe sandbox="allow-scripts">` with no `allow-same-origin` - an opaque origin that reaches
 * neither the cookies nor this page - and talks to Formatool only by `postMessage`.
 *
 * This controller is the relay. It accepts a message only from that very frame, only of a known
 * type and method, and calls the SDK's routes itself, with the session cookie and the CSRF token
 * the game never sees. The game receives the JSON answer, or `{error}`; after a call that granted
 * collection pieces, it is sent `reward.granted` to play its scene.
 *
 *   game → host  { sg: 1, type: 'call', id, method, params }
 *   host → game  { sg: 1, type: 'result', id, ok, result | error }
 *   host → game  { sg: 1, type: 'reward.granted', rewards }
 *   game → host  { sg: 1, type: 'exit' }  - back to the catalogue
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['frame'];

    static values = {
        api: String,
        csrf: String,
        exitUrl: String,
    };

    connect() {
        this.onMessage = (event) => this.receive(event);
        window.addEventListener('message', this.onMessage);
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
    }

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

        const route = this.route(message.method, message.params && 'object' === typeof message.params ? message.params : {});
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
                this.post({ sg: 1, type: 'result', id: message.id, ok: false, error: body.error ?? `http-${response.status}` });

                return;
            }
            this.post({ sg: 1, type: 'result', id: message.id, ok: true, result: body });
            // Only the end of a session grants pieces; the progress also lists the ones already held.
            if (route.grants && Array.isArray(body.rewards) && body.rewards.length > 0) {
                this.post({ sg: 1, type: 'reward.granted', rewards: body.rewards });
            }
        } catch {
            this.post({ sg: 1, type: 'result', id: message.id, ok: false, error: 'network' });
        }
    }

    route(method, params) {
        const session = Number.parseInt(params.session, 10);
        const sessionPath = (action) => (Number.isInteger(session) && session > 0 ? { method: 'POST', path: `/sessions/${session}/${action}` } : null);

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

                return null === route ? null : { ...route, grants: true };
            }
            default: return null;
        }
    }

    post(message) {
        // An opaque origin cannot be named: '*' is the only target, and the frame is the only window.
        this.frameTarget.contentWindow?.postMessage(message, '*');
    }
}
