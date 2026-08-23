import { Controller } from '@hotwired/stimulus';

// The quiz library's screen: creating and renaming a folder, filing a quiz through
// « Déplacer vers… », and the two acts the old DataTable's buttons carried - duplicate and delete.
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
        quizMoveUrlTemplate: String,
        duplicateUrlTemplate: String,
        removeUrlTemplate: String,
        token: String,
        duplicateToken: String,
        removeToken: String,
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
    // there is one rule about where a quiz may go and not two.
    openMove(event) {
        const row = event.currentTarget.closest('[data-quiz-id], [data-folder-id]');

        this.moving = row.dataset.quizId
            ? { kind: 'quiz', id: row.dataset.quizId }
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

        const template = this.moving.kind === 'quiz' ? this.quizMoveUrlTemplateValue : this.folderMoveUrlTemplateValue;
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

    // ---- The two acts the old table's buttons carried ------------------------------------------

    async duplicate(event) {
        const id = event.currentTarget.closest('[data-quiz-id]').dataset.quizId;

        if (!window.confirm(this.labels.duplicateConfirm)) return;

        try {
            const response = await fetch(this.duplicateUrlTemplateValue.replace('__ID__', id), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.duplicateTokenValue },
            });

            if (!response.ok) throw new Error('duplicate failed');

            const data = await response.json();
            // The copy opens on its question editor, exactly where the old table's button led.
            window.location.assign(data.redirectUrl);
        } catch {
            window.alert(this.labels.duplicateError);
        }
    }

    async remove(event) {
        const id = event.currentTarget.closest('[data-quiz-id]').dataset.quizId;

        if (!window.confirm(this.labels.removeConfirm)) return;

        try {
            const response = await fetch(this.removeUrlTemplateValue.replace('__ID__', id), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.removeTokenValue },
            });

            if (!response.ok) throw new Error('remove failed');
        } catch {
            window.alert(this.labels.removeError);

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
