import WidgetController from '../class_board/widget_controller.js';

// Dés of the virtual board: one to six dice and their total. Only how many dice is saved.
const PIPS = { 1: [4], 2: [0, 8], 3: [0, 4, 8], 4: [0, 2, 6, 8], 5: [0, 2, 4, 6, 8], 6: [0, 2, 3, 5, 6, 8] };

/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['tray', 'count', 'total'];

    setup() {
        this.number = Math.min(6, Math.max(1, this.config.count || 2));
        this.values = Array.from({ length: this.number }, () => 1);
        this.render(false);
    }

    count(event) {
        this.number = Math.min(6, Math.max(1, this.number + parseInt(event.currentTarget.dataset.step, 10)));
        this.values = Array.from({ length: this.number }, (_, index) => this.values[index] || 1);
        this.store({ count: this.number });
        this.render(false);
    }

    roll() {
        this.values = Array.from({ length: this.number }, () => 1 + Math.floor(Math.random() * 6));
        this.render(!this.reducedMotion);
    }

    render(rolling) {
        this.trayTarget.innerHTML = this.values.map((value) => `<div class="cm-cb-die${rolling ? ' is-rolling' : ''}">${
            Array.from({ length: 9 }, (_, index) => `<i class="${PIPS[value].includes(index) ? 'is-on' : ''}"></i>`).join('')
        }</div>`).join('');
        const labels = this.countTarget.dataset;
        this.countTarget.textContent = this.number > 1 ? labels.many.replace('__N__', String(this.number)) : labels.one;
        this.totalTarget.textContent = this.number > 1
            ? this.totalTarget.dataset.label.replace('__N__', String(this.values.reduce((sum, value) => sum + value, 0)))
            : '';
    }
}
