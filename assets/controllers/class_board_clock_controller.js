import WidgetController, { pad } from '../class_board/widget_controller.js';

// Horloge of the virtual board: the current time, or a fixed time the teacher typed, under an
// optional label. Several can sit on one board - start, end, « sortie autorisée »… Its settings
// arrive from the gear panel through configure().
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['face', 'label', 'time', 'seconds', 'date'];

    setup() {
        const face = this.faceTarget.dataset;
        this.days = face.days.split(',');
        this.months = face.months.split(',');
        this.first = face.first;
        this.configure();
    }

    configure() {
        const { label = '', mode = 'live', time = '', details = true } = this.config;
        this.live = mode !== 'fixed';
        this.details = details;
        this.labelTarget.textContent = label;
        this.labelTarget.hidden = label === '';
        this.faceTarget.classList.toggle('is-fixed', !this.live);
        this.secondsTarget.hidden = !this.live || !details;
        this.dateTarget.hidden = !this.live || !details;
        if (!this.live) {
            this.timeTarget.textContent = time || '--:--';
        }
        this.tick();
    }

    tick() {
        if (!this.live) {
            return;
        }
        const now = new Date();
        this.timeTarget.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        if (this.details) {
            this.secondsTarget.textContent = pad(now.getSeconds());
            const day = now.getDate() === 1 && this.first ? this.first : String(now.getDate());
            this.dateTarget.textContent = `${this.days[now.getDay()]} ${day} ${this.months[now.getMonth()]}`;
        }
    }
}
