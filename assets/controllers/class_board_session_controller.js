import WidgetController from '../class_board/widget_controller.js';

// Séance du jour of the virtual board: the slot under way, else the next one of the day - and any
// other slot of the day, chosen here and forgotten at the next opening (nothing of it is saved).
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['slot'];

    choose(event) {
        const value = event.currentTarget.value;
        this.slotTargets.forEach((slot) => { slot.hidden = slot.dataset.slot !== value; });
    }
}
