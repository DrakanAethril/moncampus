import { Controller } from '@hotwired/stimulus';

/*
 * Autosaves the mail being written (design_handoff_stage_alternance, screen 3d: "Draft saved" in
 * the compose header).
 *
 * A plain fetch rather than a form submit, on purpose: Turbo would navigate away on a form POST,
 * and saving a draft must never take the student out of what they are writing. The server answers
 * with the draft id, which the hidden field then carries so every later save updates the same row
 * instead of piling up drafts.
 *
 * Attachments are deliberately not part of a draft: a browser cannot put a file back into a file
 * field, so promising to keep them would be a lie.
 */
export default class extends Controller {
    static targets = ['form', 'draftId', 'status'];

    static values = { saveUrl: String, savedLabel: String, delay: { type: Number, default: 1500 } };

    connect() {
        this.dirty = false;
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    schedule() {
        this.dirty = true;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.save(), this.delayValue);
    }

    async save() {
        if (!this.dirty) {
            return;
        }

        const body = new FormData();
        body.append('_token', this.element.dataset.draftToken ?? '');
        body.append('to', this.element.querySelector('[name="to"]')?.value ?? '');
        body.append('subject', this.element.querySelector('[name="subject"]')?.value ?? '');
        body.append('body', this.element.querySelector('[name="body"]')?.value ?? '');
        body.append('draft', this.draftIdTarget.value);
        body.append('reply', this.element.querySelector('[name="reply"]')?.value ?? '');

        try {
            const response = await fetch(this.saveUrlValue, { method: 'POST', body });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this.draftIdTarget.value = data.draft ?? '';
            this.dirty = false;
            this.statusTarget.textContent = data.draft ? this.savedLabelValue : '';
        } catch (error) {
            // A failed save is not worth interrupting anyone: the next keystroke schedules another.
        }
    }

    /** Sending makes the draft moot - the server deletes it, so nothing must be saved after this. */
    stop() {
        clearTimeout(this.timer);
        this.dirty = false;
    }
}
