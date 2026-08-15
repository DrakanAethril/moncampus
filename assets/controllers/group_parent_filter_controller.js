import { Controller } from '@hotwired/stimulus';

/*
 * Keeps the parent picker on Paramètres > Groupes honest about the one rule the server enforces:
 * a group hangs off a group of a *different* type (App\Entity\Group::validateParent()).
 *
 * The choice list is built server-side without that filter on purpose - the type is editable in the
 * same form, so which options are valid changes while the screen is open. This disables the
 * same-type ones live, and clears the selection when the type change invalidates what was already
 * picked, so the form is never submitted into an error the screen could see coming.
 *
 * Purely an assistance: the rule itself lives in the entity, never here.
 */
export default class extends Controller {
    static targets = ['type', 'parent'];

    connect() {
        this.refresh();
    }

    refresh() {
        const typeId = this.typeTarget.value;

        for (const option of this.parentTarget.options) {
            // The placeholder carries no type and stays selectable - "aucun parent" is always valid.
            if (option.value === '') {
                continue;
            }

            const sameType = typeId !== '' && option.dataset.groupType === typeId;

            option.disabled = sameType;
            option.hidden = sameType;

            if (sameType && option.selected) {
                this.parentTarget.value = '';
            }
        }

        // An optgroup whose every option is hidden would otherwise stay on screen as an empty heading.
        for (const optgroup of this.parentTarget.querySelectorAll('optgroup')) {
            optgroup.hidden = [...optgroup.children].every((option) => option.hidden);
        }
    }
}
