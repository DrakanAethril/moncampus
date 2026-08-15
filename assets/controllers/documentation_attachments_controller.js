import { Controller } from '@hotwired/stimulus';

// Removing an already-saved attachment from the documentation editor (écran 2d): the row goes
// away and a hidden input takes its place inside the same form, so the deletion happens on save
// like every other change - never on the click itself, which would drop a file from an article
// the author then decides not to save.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    remove(event) {
        const row = event.currentTarget.closest('[data-documentation-attachments-target="row"]');

        if (!row) {
            return;
        }

        const marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = 'removedAttachments[]';
        marker.value = event.currentTarget.dataset.attachmentId;
        this.element.appendChild(marker);

        row.remove();
    }
}
