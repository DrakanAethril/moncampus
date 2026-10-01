import WidgetController from '../class_board/widget_controller.js';

// Feu of the virtual board: red, orange or green. The colour is saved.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['lamp'];

    choose(event) {
        const color = event.currentTarget.dataset.color;
        this.lampTargets.forEach((lamp) => lamp.setAttribute('aria-pressed', String(lamp.dataset.color === color)));
        this.store({ color });
    }
}
