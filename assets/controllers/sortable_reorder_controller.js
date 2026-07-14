import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Generic drag-and-drop reordering for a small list (e.g. séances within a sequence, phases
 * within a séance in the library) - bound to the row container (<tbody> or similar); each row
 * needs data-sortable-reorder-id-param="<entity id>", and a drag handle inside each row needs
 * the "sortable-reorder-handle" class. On drop, POSTs the full new id order in one request rather
 * than persisting per-row, then reloads only on failure (the DOM already reflects the drop on
 * success - Sortable.js physically moves the dragged element itself).
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    connect() {
        this.sortable = Sortable.create(this.element, {
            handle: '.sortable-reorder-handle',
            animation: 150,
            onEnd: () => this.persist(),
        });
    }

    disconnect() {
        this.sortable?.destroy();
    }

    persist() {
        const ids = Array.from(this.element.children)
            .map((row) => row.dataset.sortableReorderIdParam)
            .filter(Boolean);

        fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.tokenValue,
            },
            body: JSON.stringify({ ids }),
        })
            .then((response) => {
                if (!response.ok) {
                    window.location.reload();
                }
            })
            .catch(() => window.location.reload());
    }
}
