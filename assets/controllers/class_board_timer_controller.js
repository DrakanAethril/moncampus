import WidgetController, { beep, minutesSeconds } from '../class_board/widget_controller.js';

// Minuteur of the virtual board: a countdown with a ring, red under the last minute, an alarm and a
// flashing frame at zero. The duration is saved; the time left never is.
const RING = 2 * Math.PI * 44;

/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['arc', 'digits', 'status', 'playIcon', 'pauseIcon'];

    setup() {
        this.total = this.config.durationSeconds || 300;
        this.remaining = this.total;
        this.running = false;
        this.endsAt = 0;
        this.arcTarget.setAttribute('stroke-dasharray', String(RING));
        this.render();
    }

    tick() {
        if (!this.running) {
            return;
        }
        this.remaining = Math.max(0, (this.endsAt - Date.now()) / 1000);
        if (this.remaining === 0) {
            this.running = false;
            this.element.classList.add('is-ringing');
            beep();
        }
        this.render();
    }

    shortcut(key) {
        if (key === 'toggle') {
            this.toggle();
        }
    }

    toggle() {
        this.element.classList.remove('is-ringing');
        if (this.running) {
            this.running = false;
        } else {
            if (this.remaining === 0) {
                this.remaining = this.total;
            }
            this.running = true;
            this.endsAt = Date.now() + this.remaining * 1000;
        }
        this.render();
    }

    preset(event) {
        this.element.classList.remove('is-ringing');
        this.total = parseInt(event.currentTarget.dataset.minutes, 10) * 60;
        this.remaining = this.total;
        this.running = false;
        this.store({ durationSeconds: this.total });
        this.render();
    }

    adjust(event) {
        this.element.classList.remove('is-ringing');
        const delta = parseInt(event.currentTarget.dataset.seconds, 10);
        this.remaining = Math.max(0, this.remaining + delta);
        this.total = Math.max(60, this.total + delta, Math.ceil(this.remaining));
        if (this.running) {
            this.endsAt = Date.now() + this.remaining * 1000;
        }
        this.store({ durationSeconds: this.total });
        this.render();
    }

    reset() {
        this.element.classList.remove('is-ringing');
        this.running = false;
        this.remaining = this.total;
        this.render();
    }

    render() {
        this.digitsTarget.textContent = minutesSeconds(this.remaining);
        this.arcTarget.setAttribute('stroke-dashoffset', String(RING * (1 - (this.total ? this.remaining / this.total : 0))));
        this.arcTarget.classList.toggle('is-last-minute', this.remaining <= 60);
        const status = this.statusTarget.dataset;
        if (this.running) {
            this.statusTarget.textContent = status.running.replace('__MIN__', String(Math.round(this.total / 60)));
        } else {
            this.statusTarget.textContent = this.remaining === 0 ? status.done : status.paused;
        }
        this.playIconTarget.hidden = this.running;
        this.pauseIconTarget.hidden = !this.running;
    }
}
