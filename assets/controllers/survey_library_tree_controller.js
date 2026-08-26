import { Controller } from '@hotwired/stimulus';

// The survey library's rail - the quiz library's tree controller (quiz_library_tree_controller.js),
// under this screen's own names.
//
// What is dragged is a **row of the listing**, which is outside this element, or a folder of the rail
// itself; what receives it is always a folder - or the rail's root entry, which files a model back out
// of every folder. There is no re-order here, so a drop means "into this folder" and nothing else.
//
// It owns this subtree exclusively - nothing else may rewrite it, which is the trap recorded for
// DataTables and applies to any DOM-rewriting library.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['root', 'node'];

    static values = {
        folderMoveUrlTemplate: String,
        templateMoveUrlTemplate: String,
        token: String,
        refusedMessage: String,
    };

    connect() {
        this.dragged = null;
        this.dropTarget = null;

        // **The drag starts outside this controller's element**: what is dragged is a row of the
        // listing, and what receives it is a folder of the rail. A `data-action` on the rail only
        // ever sees events raised inside the rail, so the row's dragstart would never reach it.
        this.screen = this.element.closest('.cm-slib') ?? document;
        this.screen.addEventListener('dragstart', this.onDragStart);
        this.screen.addEventListener('dragend', this.onDragEnd);
    }

    disconnect() {
        this.screen.removeEventListener('dragstart', this.onDragStart);
        this.screen.removeEventListener('dragend', this.onDragEnd);
    }

    onDragStart = (event) => this.dragStart(event);

    onDragEnd = () => this.dragEnd();

    // ---- Folding ------------------------------------------------------------------------------

    toggleBranch(event) {
        event.preventDefault();
        const node = event.currentTarget.closest('.cm-slib__node');
        const collapsed = node.classList.toggle('is-collapsed');
        event.currentTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    // ---- Dragging -----------------------------------------------------------------------------

    // A folder of the rail, a folder row of the listing, or a model row: one handler, because "what is
    // being dragged" is answered by the attribute it carries and not by where it came from.
    dragStart(event) {
        const source = event.target.closest('[data-template-id], [data-folder-id]');

        if (!source) return;

        const templateId = source.dataset.templateId;
        const folderId = source.dataset.folderId;

        // The rail's root entry carries an empty folder id: it is a drop target and a link, never a
        // thing that can itself be moved.
        if (!templateId && !folderId) return;

        this.dragged = templateId ? { kind: 'template', id: templateId } : { kind: 'folder', id: folderId };
        source.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Some browsers refuse to start a drag at all without payload, even one nobody reads.
        event.dataTransfer.setData('text/plain', this.dragged.id);
    }

    dragEnd() {
        this.screen.querySelectorAll('.is-dragging').forEach((node) => node.classList.remove('is-dragging'));
        this.clearDropTarget();
        this.dragged = null;
    }

    dragOver(event) {
        const node = event.target.closest('.cm-slib__node');

        if (!node || !this.dragged || this.isSelf(node)) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (this.dropTarget !== node) {
            this.clearDropTarget();
            this.dropTarget = node;
            node.classList.add('is-drop-target');
        }
    }

    dragLeave(event) {
        // A dragleave fires for every child the pointer crosses; only the one leaving the rail itself
        // means anything.
        if (!this.element.contains(event.relatedTarget)) this.clearDropTarget();
    }

    async drop(event) {
        const node = event.target.closest('.cm-slib__node');
        this.clearDropTarget();

        if (!this.dragged || (node && this.isSelf(node))) return;

        event.preventDefault();
        // Dropped on the rail but outside any folder means the root, same as the root entry itself.
        await this.move(node?.dataset.folderId ?? '');
    }

    isSelf(node) {
        return this.dragged.kind === 'folder' && node.dataset.folderId === this.dragged.id;
    }

    async move(parentId) {
        const template = this.dragged.kind === 'template' ? this.templateMoveUrlTemplateValue : this.folderMoveUrlTemplateValue;
        const url = template.replace('__ID__', this.dragged.id).replace('__FOLDER_ID__', this.dragged.id);
        const payload = new FormData();
        payload.append('parent', parentId);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue },
                body: payload,
            });

            if (!response.ok) throw new Error('move failed');
        } catch {
            // A folder dropped into its own descendant answers 409. Said rather than silently
            // ignored: the author did something deliberate and nothing moved.
            window.alert(this.refusedMessageValue);

            return;
        }

        // Reloaded rather than repainted: the move changes the rail *and* the listing *and* the
        // breadcrumb, and rebuilding three things from one answer is how they come to disagree.
        window.location.reload();
    }

    clearDropTarget() {
        if (this.dropTarget) this.dropTarget.classList.remove('is-drop-target');
        this.dropTarget = null;
    }
}
