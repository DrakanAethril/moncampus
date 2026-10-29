import { Controller } from '@hotwired/stimulus';

/**
 * The ticket bench of a game of the « scénario » mechanic (design/validated/serious-games-neko-con.md
 * §8, lot 2), in Paramètres › Serious games: the game's own bench page runs in a sandboxed frame,
 * like the game, and checks each ticket's rendering - the buggy code fails, the repaired code
 * passes. The server's half of the tickets, with their right answers, never sits in the game's
 * package: this page hands it to the frame once the frame says it listens.
 *
 *   bench → host  { sg: 1, type: 'bench.ready' } · { sg: 1, type: 'bench.done', total, failures }
 *   host → bench  { sg: 1, type: 'bench.scenario', scenario, only }
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['frame', 'status'];

    static values = { src: String, scenario: Object, only: String, doneLabel: String, errorLabel: String };

    connect() {
        this.onMessage = (event) => this.receive(event);
        window.addEventListener('message', this.onMessage);
        // The frame gets its address once its messages have somewhere to go.
        this.frameTarget.setAttribute('src', this.srcValue);
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
    }

    receive(event) {
        // The frame has an opaque origin: what proves the sender is the window itself.
        if (event.source !== this.frameTarget.contentWindow) {
            return;
        }
        const message = event.data;
        if (!message || 1 !== message.sg) {
            return;
        }
        if ('bench.ready' === message.type) {
            // Read once into a local: a Stimulus Object value is parsed again at every access.
            const scenario = this.scenarioValue;
            this.frameTarget.contentWindow?.postMessage({ sg: 1, type: 'bench.scenario', scenario, only: this.onlyValue }, '*');
        } else if ('bench.done' === message.type) {
            const total = Number.parseInt(message.total, 10) || 0;
            const failures = Number.parseInt(message.failures, 10);
            this.statusTarget.textContent = failures < 0
                ? this.errorLabelValue
                : this.doneLabelValue.replace('%total%', String(total)).replace('%failures%', String(failures));
            this.statusTarget.classList.toggle('text-danger', 0 !== failures);
            this.statusTarget.classList.toggle('text-success', 0 === failures);
        }
    }
}
