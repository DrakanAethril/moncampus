import { Controller } from '@hotwired/stimulus';

/**
 * The wiki's rail: dragging a page onto a folder files it there, dragging it onto the rail's
 * background moves it back to the root.
 *
 * Native HTML5 drag-and-drop rather than a library, deliberately: this controller has to own its
 * subtree exclusively (the trap already recorded here for DataTables applies to anything that
 * rewrites the DOM), and the whole interaction is one drop on one target - a sortable library
 * would bring its own DOM rewriting for no gain.
 *
 * The move itself is a plain POST that redirects, which is what re-renders the rail: the tree is
 * server-rendered, so the redirect *is* the update. The endpoint names the moved node in the body
 * rather than in the path - a route parameter filled from a template would need a placeholder, and
 * a `\d+` requirement against one is a 500 on the whole screen.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['root', 'node'];
    static values = {
        moveUrl: String,
        token: String,
        editable: { type: Boolean, default: false },
    };

    connect() {
        this.draggedId = null;

        if (!this.editableValue) {
            // Read-only is not a thing on a wiki today, but a rail rendered for somebody who
            // cannot write must not offer a gesture the server would refuse.
            this.nodeTargets.forEach((node) => node.removeAttribute('draggable'));
        }
    }

    dragStart(event) {
        const node = event.target.closest('[data-node-id]');

        if (!node || !this.editableValue) {
            return;
        }

        this.draggedId = node.dataset.nodeId;
        event.dataTransfer.effectAllowed = 'move';
        // Firefox ignores a drag that sets no data at all.
        event.dataTransfer.setData('text/plain', this.draggedId);
        node.classList.add('is-dragging');
    }

    dragEnd(event) {
        event.target.closest('[data-node-id]')?.classList.remove('is-dragging');
        this.element.querySelectorAll('.is-drop-target').forEach((node) => node.classList.remove('is-drop-target'));
        this.draggedId = null;
    }

    dragOver(event) {
        if (null === this.draggedId) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const target = event.target.closest('[data-node-id]');

        this.element.querySelectorAll('.is-drop-target').forEach((node) => node.classList.remove('is-drop-target'));

        if (target && target.dataset.nodeId !== this.draggedId) {
            target.classList.add('is-drop-target');
        }
    }

    drop(event) {
        if (null === this.draggedId) {
            return;
        }

        event.preventDefault();

        const target = event.target.closest('[data-node-id]');
        const parentId = target && target.dataset.nodeId !== this.draggedId ? target.dataset.nodeId : '';

        this.submitMove(this.draggedId, parentId);
    }

    submitMove(nodeId, parentId) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = this.moveUrlValue;
        form.hidden = true;

        for (const [name, value] of [['_token', this.tokenValue], ['node', nodeId], ['parent', parentId]]) {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            field.value = value;
            form.appendChild(field);
        }

        document.body.appendChild(form);
        form.requestSubmit();
    }
}
