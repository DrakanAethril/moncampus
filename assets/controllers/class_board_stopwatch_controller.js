import WidgetController, { pad } from '../class_board/widget_controller.js';

// Chronomètre of the virtual board: elapsed time to the tenth. Nothing of it is saved.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['digits', 'tenths', 'playIcon', 'pauseIcon'];

    setup() {
        this.accumulated = 0;
        this.startedAt = 0;
        this.running = false;
        this.render();
    }

    tick() {
        if (this.running) {
            this.render();
        }
    }

    toggle() {
        if (this.running) {
            this.accumulated += Date.now() - this.startedAt;
            this.running = false;
        } else {
            this.startedAt = Date.now();
            this.running = true;
        }
        this.render();
    }

    reset() {
        this.accumulated = 0;
        this.startedAt = Date.now();
        this.render();
    }

    render() {
        const elapsed = this.accumulated + (this.running ? Date.now() - this.startedAt : 0);
        const seconds = Math.floor(elapsed / 1000);
        const minutes = Math.floor(seconds / 60);
        this.digitsTarget.textContent = minutes >= 60
            ? `${Math.floor(minutes / 60)}:${pad(minutes % 60)}:${pad(seconds % 60)}`
            : `${pad(minutes)}:${pad(seconds % 60)}`;
        this.tenthsTarget.textContent = `,${Math.floor((elapsed % 1000) / 100)}`;
        this.playIconTarget.hidden = this.running;
        this.pauseIconTarget.hidden = !this.running;
    }
}
