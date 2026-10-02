import { Controller } from '@hotwired/stimulus';

// « Tableau virtuel », the list (design/validated/tableau-virtuel.md, §2): the panels that open
// inside the page - « Nouveau tableau », the rename row under a board, the delete confirmation.
// One open at a time; every form inside them is a plain POST answered by a redirect.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['newPanel', 'newName', 'newProgram', 'newButton', 'renameRow', 'deleteRow'];

    openNew() {
        this.closeInline();
        this.newPanelTarget.hidden = false;
        this.syncLink();
        this.newNameTarget.focus();
        this.newNameTarget.select();
    }

    closeNew() {
        this.newPanelTarget.hidden = true;
        this.newButtonTarget.focus();
    }

    // « Sans classe » disables the class list rather than hiding it: the choice stays readable.
    syncLink() {
        if (!this.hasNewProgramTarget) {
            return;
        }
        const linked = this.newPanelTarget.querySelector('input[name="link"]:checked')?.value === 'class';
        this.newProgramTarget.disabled = !linked;
        this.newProgramTarget.required = linked;
    }

    openRename(event) {
        this.openRow(this.renameRowTargets, event.currentTarget.dataset.boardId);
    }

    openDelete(event) {
        this.openRow(this.deleteRowTargets, event.currentTarget.dataset.boardId);
    }

    closeInline() {
        [...this.renameRowTargets, ...this.deleteRowTargets].forEach((row) => { row.hidden = true; });
    }

    openRow(rows, boardId) {
        this.closeInline();
        this.newPanelTarget.hidden = true;
        const row = rows.find((candidate) => candidate.dataset.boardId === boardId);
        if (!row) {
            return;
        }
        row.hidden = false;
        row.querySelector('input[type="text"], button[type="submit"]')?.focus();
    }
}
