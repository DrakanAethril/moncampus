import { Controller } from '@hotwired/stimulus';

// The library's rail - the wiki's tree controller, reduced to the one gesture this feature has.
//
// The wiki can re-parent *and* re-order, so it carries three drop states (into / before / after) and
// reads a rank off the DOM. The library sorts by name, date or size at the reader's choice, so there
// is no rank to place: **dropping means "into this folder" and nothing else**. That is why this is a
// smaller controller and not a copy of the other one.
//
// It owns this subtree exclusively - nothing else may rewrite it, which is the trap recorded for
// DataTables and applies to any DOM-rewriting library.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['root', 'node'];

    static values = {
        moveUrlTemplate: String,
        token: String,
        refusedMessage: String,
    };

    connect() {
        this.dragged = null;
        this.dropTarget = null;
    }

    // ---- Folding ------------------------------------------------------------------------------

    toggleBranch(event) {
        event.preventDefault();
        const node = event.currentTarget.closest('.cm-flib__node');
        const collapsed = node.classList.toggle('is-collapsed');
        event.currentTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    // ---- Dragging -----------------------------------------------------------------------------

    // A folder of the rail, or a row of the table: the table's rows carry the same data-node-id, and
    // the drop target is the same folder either way. One handler rather than two, because "what is
    // being dragged" is answered by the id it carries and not by where it came from.
    dragStart(event) {
        const source = event.target.closest('[data-node-id]');

        if (!source) return;

        this.dragged = source.dataset.nodeId;
        source.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Some browsers refuse to start a drag at all without payload, even one nobody reads.
        event.dataTransfer.setData('text/plain', this.dragged);
    }

    dragEnd() {
        this.element.querySelectorAll('.is-dragging').forEach((node) => node.classList.remove('is-dragging'));
        this.clearDropTarget();
        this.dragged = null;
    }

    dragOver(event) {
        const node = event.target.closest('.cm-flib__node');

        if (!node || node.dataset.nodeId === this.dragged) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (this.dropTarget !== node) {
            this.clearDropTarget();
            this.dropTarget = node;
            node.classList.add('is-drop-target');
        }
    }

    dragLeave(event) {
        // A dragleave fires for every child the pointer crosses; only the one leaving the rail
        // itself means anything.
        if (!this.element.contains(event.relatedTarget)) this.clearDropTarget();
    }

    async drop(event) {
        const node = event.target.closest('.cm-flib__node');
        this.clearDropTarget();

        if (!node || !this.dragged || node.dataset.nodeId === this.dragged) return;

        event.preventDefault();
        await this.move(this.dragged, node.dataset.nodeId);
    }

    // Dropping onto the rail but outside any folder means the root, which is the only way back out of
    // a folder without a "move to…" dialog this design does not have.
    async dropOnRoot(event) {
        if (event.target.closest('.cm-flib__node') || !this.dragged) return;

        event.preventDefault();
        this.clearDropTarget();
        await this.move(this.dragged, '');
    }

    async move(nodeId, parentId) {
        const payload = new FormData();
        payload.append('parent', parentId);

        try {
            const response = await fetch(this.moveUrlTemplateValue.replace('__NODE_ID__', nodeId), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue },
                body: payload,
            });

            if (response.status === 409) {
                // A folder dropped into its own descendant. Said rather than silently ignored: the
                // teacher did something deliberate and nothing moved.
                window.alert(this.refusedMessageValue);

                return;
            }

            if (!response.ok) throw new Error('move failed');
        } catch {
            window.alert(this.refusedMessageValue);

            return;
        }

        // Reloaded rather than repainted: the move changes the rail *and* the table *and* the
        // breadcrumb, and rebuilding three things from one answer is how they come to disagree.
        window.location.reload();
    }

    clearDropTarget() {
        if (this.dropTarget) this.dropTarget.classList.remove('is-drop-target');
        this.dropTarget = null;
    }
}
