import { Controller } from '@hotwired/stimulus';

// The sequence library's screen: creating and renaming a folder, and filing a séquence or a folder
// through « Déplacer vers… ».
//
// It is quiz_library_controller.js minus the two acts that library carries on a quiz (duplicate,
// delete) - those live on the séquence's own screen here - and minus nothing else, so the two
// classements answer the same gestures.
//
// The listing is never repainted: a save reloads the screen. A move changes the rail, the listing and
// the breadcrumb at once, and rebuilding three things from one answer is how they come to disagree.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['move', 'moveTitle'];

    static values = {
        folderId: String,
        folderUrl: String,
        folderRenameUrlTemplate: String,
        folderMoveUrlTemplate: String,
        sequenceMoveUrlTemplate: String,
        token: String,
        labels: Object,
    };

    connect() {
        // Stimulus Object values re-parse on every access - read once into a local, per this
        // repository's own note.
        this.labels = { ...this.labelsValue };
        this.moving = null;
    }

    // ---- Folders ------------------------------------------------------------------------------

    promptFolder(event) {
        const name = window.prompt(this.labels.folderPrompt);

        if (!name || name.trim() === '') return;

        this.post(this.folderUrlValue, { name: name.trim(), parent: event.currentTarget.dataset.parentId ?? '' });
    }

    promptRenameFolder(event) {
        const row = event.currentTarget.closest('[data-folder-id]');
        const name = window.prompt(this.labels.renamePrompt, row.dataset.name);

        if (!name || name.trim() === '') return;

        this.post(this.folderRenameUrlTemplateValue.replace('__FOLDER_ID__', row.dataset.folderId), { name: name.trim() });
    }

    // ---- « Déplacer vers… » -------------------------------------------------------------------

    // The list of folders, opened from a row. Unlike the quiz and survey libraries this is the *only*
    // way to file a séquence: its row's drag already means "reorder this folder", and one row cannot
    // mean two things while being dragged.
    openMove(event) {
        const row = event.currentTarget.closest('[data-sequence-id], [data-folder-id]');

        this.moving = row.dataset.sequenceId
            ? { kind: 'sequence', id: row.dataset.sequenceId }
            : { kind: 'folder', id: row.dataset.folderId };
        this.moveTitleTarget.textContent = this.labels.movePrompt.replace('%name%', row.dataset.name);
        this.moveTarget.hidden = false;
    }

    closeMove() {
        this.moveTarget.hidden = true;
        this.moving = null;
    }

    async moveTo(event) {
        if (!this.moving) return;

        const template = this.moving.kind === 'sequence' ? this.sequenceMoveUrlTemplateValue : this.folderMoveUrlTemplateValue;
        const url = template.replace('__ID__', this.moving.id).replace('__FOLDER_ID__', this.moving.id);
        const payload = new FormData();
        payload.append('parent', event.currentTarget.dataset.targetId ?? '');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue },
                body: payload,
            });

            if (!response.ok) throw new Error('move failed');
        } catch {
            // A folder into its own descendant answers 409, and so does a folder of another library.
            window.alert(this.labels.moveRefused);
            this.closeMove();

            return;
        }

        window.location.reload();
    }

    // A real form submission rather than a fetch: these POSTs redirect, which is the rule in this
    // repository, and a redirect is what refreshes the rail and the listing together.
    post(action, fields) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.hidden = true;

        Object.entries({ ...fields, _token: this.tokenValue }).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}
