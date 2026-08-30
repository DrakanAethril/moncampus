import { Controller } from '@hotwired/stimulus';

// The sequence library's rail - the quiz library's tree controller (quiz_library_tree_controller.js),
// narrowed to the one thing this library drags onto it: **a folder**.
//
// A séquence row is deliberately not draggable here. It already carries a drag - the ⠿ handle that
// reorders the folder (sortable_reorder_controller.js) - and a row that meant "reorder" from one
// grip and "file elsewhere" from the rest of its surface would fire both on the same drop. Filing a
// séquence is « Déplacer vers… », which the quiz library offers alongside its drag anyway because it
// is the gesture that works on a touch screen, from the keyboard, and onto a collapsed branch.
//
// It owns this subtree exclusively - nothing else may rewrite it, which is the trap recorded for
// DataTables and applies to any DOM-rewriting library.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['root', 'node'];

    static values = {
        folderMoveUrlTemplate: String,
        token: String,
        refusedMessage: String,
    };

    connect() {
        this.dragged = null;
        this.dropTarget = null;

        // **The drag can start outside this controller's element**: a folder row of the listing is
        // dragged onto a folder of the rail. A `data-action` on the rail only ever sees events raised
        // inside the rail, so that row's dragstart would never reach it.
        this.screen = this.element.closest('.cm-seqlib') ?? document;
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
        const node = event.currentTarget.closest('.cm-seqlib__node');
        const collapsed = node.classList.toggle('is-collapsed');
        event.currentTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    // ---- Dragging -----------------------------------------------------------------------------

    // Folders only, and the selector is what says so: a séquence row carries `data-sequence-id` and
    // no folder id, so the reorder drag started from its ⠿ handle finds nothing here and is left to
    // the controller that owns it.
    dragStart(event) {
        const source = event.target.closest('[data-folder-id]');

        if (!source) return;

        const folderId = source.dataset.folderId;

        // The rail's root entry carries an empty folder id: it is a drop target and a link, never a
        // thing that can itself be moved.
        if (!folderId) return;

        this.dragged = { id: folderId };
        source.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Some browsers refuse to start a drag at all without payload, even one nobody reads.
        event.dataTransfer.setData('text/plain', folderId);
    }

    dragEnd() {
        this.screen.querySelectorAll('.is-dragging').forEach((node) => node.classList.remove('is-dragging'));
        this.clearDropTarget();
        this.dragged = null;
    }

    dragOver(event) {
        const node = event.target.closest('.cm-seqlib__node');

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
        const node = event.target.closest('.cm-seqlib__node');
        this.clearDropTarget();

        if (!this.dragged || (node && this.isSelf(node))) return;

        event.preventDefault();
        // Dropped on the rail but outside any folder means the root, same as the root entry itself.
        await this.move(node?.dataset.folderId ?? '');
    }

    isSelf(node) {
        return node.dataset.folderId === this.dragged.id;
    }

    async move(parentId) {
        const url = this.folderMoveUrlTemplateValue.replace('__FOLDER_ID__', this.dragged.id);
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
            // ignored: the teacher did something deliberate and nothing moved.
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
