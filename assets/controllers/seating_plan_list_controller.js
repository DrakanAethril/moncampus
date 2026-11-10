import { Controller } from '@hotwired/stimulus';

/**
 * « Plans de classe », the list: the « Nouveau plan de classe » dialog. It opens and closes the
 * <dialog>, says what the chosen room's disposition is, and proposes a name from the class and the
 * room for as long as the teacher has not typed one.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['dialog', 'program', 'room', 'name', 'roomInfo'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    touchName() {
        this.nameTouched = '' !== this.nameTarget.value.trim();
    }

    propose() {
        const program = this.programTarget.selectedOptions[0];
        const room = this.roomTarget.selectedOptions[0];

        if (room && '' !== room.value) {
            this.roomInfoTarget.textContent = room.dataset.info;
        }

        if (this.nameTouched || !program || '' === program.value || !room || '' === room.value) {
            return;
        }

        this.nameTarget.value = this.nameTarget.dataset.pattern
            .replace('__CLASS__', program.dataset.short)
            .replace('__ROOM__', room.dataset.name);
    }
}
