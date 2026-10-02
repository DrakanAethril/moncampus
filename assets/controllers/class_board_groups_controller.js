import WidgetController, { randomItem } from '../class_board/widget_controller.js';

// Groupes of the virtual board: a saved lot, read only. « Rapporteur » picks one member of a group,
// in the page only.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['group'];

    reporter(event) {
        const group = event.currentTarget.closest('[data-class-board-groups-target="group"]');
        const members = [...group.querySelectorAll(':scope > span')];
        members.forEach((member) => member.classList.remove('is-picked'));
        if (members.length > 0) {
            randomItem(members).classList.add('is-picked');
        }
    }
}
