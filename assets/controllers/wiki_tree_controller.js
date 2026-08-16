import { Controller } from '@hotwired/stimulus';

/**
 * The wiki's rail: dragging a page onto the middle of another files it under it, dragging it onto
 * the upper or lower edge of another drops it *beside* it, and dragging it onto the rail's
 * background moves it back to the root.
 *
 * The edge zones are what make a page reachable in first position at all - with "drop onto" as the
 * only gesture, a node could change parent but never change rank, so the first row of the rail was
 * whichever page happened to be created first, for ever.
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

    /** Share of a row's height, top and bottom, that means "beside this one" rather than "into it". */
    static EDGE_RATIO = 0.3;

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
        this.clearMarks();
        this.draggedId = null;
    }

    dragOver(event) {
        if (null === this.draggedId) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const drop = this.dropAt(event);

        this.clearMarks();

        if (null === drop) {
            return;
        }

        drop.node.classList.add({
            before: 'is-drop-before',
            after: 'is-drop-after',
            inside: 'is-drop-target',
        }[drop.mode]);
    }

    drop(event) {
        if (null === this.draggedId) {
            return;
        }

        event.preventDefault();

        const drop = this.dropAt(event);
        const dragged = this.draggedId;

        this.clearMarks();
        this.draggedId = null;

        if (null === drop) {
            // The rail's background: back to the root, at the end.
            this.submitMove(dragged, '', '');

            return;
        }

        if ('inside' === drop.mode) {
            this.submitMove(dragged, drop.node.dataset.nodeId, '');

            return;
        }

        this.submitMove(dragged, drop.node.dataset.parentId ?? '', String(this.rankBeside(drop.node, drop.mode)));
    }

    /**
     * Where this pointer would drop: onto a node, or beside it, or nowhere.
     *
     * Measured against the node's own row rather than the whole <li>, which also contains the
     * children - on a folder with a long subtree the two are nothing alike.
     */
    dropAt(event) {
        const node = event.target.closest('[data-node-id]');

        if (!node || node.dataset.nodeId === this.draggedId) {
            return null;
        }

        const row = node.querySelector('.cm-wiki__node-row') ?? node;
        const box = row.getBoundingClientRect();

        if (event.clientY > box.bottom) {
            // Below the row but still inside the <li>: the gutter of its own subtree, which reads
            // as "into this folder" rather than as the rail's background.
            return { node, mode: 'inside' };
        }

        const edge = box.height * this.constructor.EDGE_RATIO;

        if (event.clientY < box.top + edge) {
            return { node, mode: 'before' };
        }

        if (event.clientY > box.bottom - edge) {
            return { node, mode: 'after' };
        }

        return { node, mode: 'inside' };
    }

    /**
     * The 1-based rank the dragged node should take among the target's siblings.
     *
     * The dragged node is excluded from the count on purpose: the server re-inserts it into the list
     * of the *others*, so counting it here would shift everything by one whenever a node is moved
     * down inside the list it already belongs to.
     */
    rankBeside(target, mode) {
        const siblings = Array.from(target.parentElement?.children ?? [])
            .filter((node) => node.dataset.nodeId && node.dataset.nodeId !== this.draggedId);

        const index = siblings.indexOf(target);

        return 'before' === mode ? index + 1 : index + 2;
    }

    clearMarks() {
        this.element.querySelectorAll('.is-drop-target, .is-drop-before, .is-drop-after')
            .forEach((node) => node.classList.remove('is-drop-target', 'is-drop-before', 'is-drop-after'));
    }

    submitMove(nodeId, parentId, position) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = this.moveUrlValue;
        form.hidden = true;

        const fields = [['_token', this.tokenValue], ['node', nodeId], ['parent', parentId], ['position', position]];

        for (const [name, value] of fields) {
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
