import { Controller } from '@hotwired/stimulus';

// The survey library's Modèles tab: creating and renaming a folder, and filing a model through
// « Déplacer vers… ».
//
// The quiz library's controller (quiz_library_controller.js) minus its duplicate/delete pair: on this
// side both are ordinary forms that already redirect, so there is nothing for a fetch to do.
//
// The listing is never repainted: a save reloads the screen. A move changes the rail, the listing and
// the breadcrumb at once, and rebuilding three things from one answer is how they come to disagree.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['move', 'moveTitle'];

    static values = {
        folderUrl: String,
        folderRenameUrlTemplate: String,
        folderMoveUrlTemplate: String,
        templateMoveUrlTemplate: String,
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

    // The list of folders, opened from a row's menu. It answers the same endpoint the drag does, so
    // there is one rule about where a model may go and not two.
    openMove(event) {
        const row = event.currentTarget.closest('[data-template-id], [data-folder-id]');

        this.moving = row.dataset.templateId
            ? { kind: 'template', id: row.dataset.templateId }
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

        const template = this.moving.kind === 'template' ? this.templateMoveUrlTemplateValue : this.folderMoveUrlTemplateValue;
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
