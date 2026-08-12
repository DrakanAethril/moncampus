import { Controller } from '@hotwired/stimulus';

// Videos - step 2 "Fichiers vidéo", the audio tool's step 2 ported to a medium that is uploaded
// rather than recorded (design_handoff_enregistrements_audio, screen 3, for every pixel of it).
//
// Rows are painted here rather than rendered by Twig, for the same reason as on the audio screen:
// adding or removing a file must change the card without reloading, and here a reload in the middle
// of a run would mean uploading a hundred megabytes twice.
//
// Two things the audio controller has no equivalent of:
//
//  1. The duration is read in the browser, off the very file about to be sent, and travels with it.
//     The server has no ffprobe and does not want one - the tracking is in percent, so a duration is
//     only ever used to draw a bar and to lay a retention map on a timeline.
//  2. The size and the type are checked before the upload starts, not only after. A hundred-megabyte
//     transfer that ends in "MP4 uniquement" is a minute of the teacher's life for an answer the
//     browser already had. The server checks again all the same
//     (App\Service\VideoUploadValidator) - this one is a courtesy, not a control.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'input', 'pickButton', 'pickLabel', 'warning', 'probe'];

    static values = {
        files: Array,
        maxBytes: Number,
        uploadUrl: String,
        deleteUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        // Stimulus Array/Object values re-parse on every access: a working copy is taken at connect(),
        // or every mutation would be silently lost.
        this.files = JSON.parse(JSON.stringify(this.filesValue));
        this.uploading = false;
        this.render();
    }

    pick() {
        if (this.uploading) return;
        this.inputTarget.click();
    }

    async selected() {
        const file = this.inputTarget.files[0];
        // The picker is reset straight away, so that picking the same file twice in a row still
        // fires a change event.
        this.inputTarget.value = '';

        if (!file) return;

        if (file.size > this.maxBytesValue) {
            this.warn(this.labelsValue.tooLargeMessage);

            return;
        }

        // A courtesy check: the dialog's `accept` filters what is offered, not what is dropped in.
        if (file.type && file.type !== 'video/mp4' && file.type !== 'video/quicktime') {
            this.warn(this.labelsValue.notMp4Message);

            return;
        }

        this.warningTarget.hidden = true;
        await this.upload(file, await this.readDuration(file));
    }

    // The duration, read by handing the local file to a hidden <video>. Zero when the browser cannot
    // read it: an unknown duration costs a progress bar its scale, never the tracking itself.
    readDuration(file) {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const probe = this.probeTarget;

            const done = (seconds) => {
                URL.revokeObjectURL(url);
                probe.removeAttribute('src');
                resolve(seconds);
            };

            probe.onloadedmetadata = () => done(Number.isFinite(probe.duration) ? Math.round(probe.duration) : 0);
            probe.onerror = () => done(0);
            probe.src = url;
        });
    }

    async upload(file, durationSeconds) {
        this.uploading = true;
        this.pickLabelTarget.textContent = this.labelsValue.uploadingLabel;
        this.pickButtonTarget.disabled = true;

        const payload = new FormData();
        payload.append('video', file, file.name);
        payload.append('duration', String(durationSeconds));

        let data;
        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
                body: payload,
            });
            data = await response.json().catch(() => null);
            if (!response.ok) throw new Error(data?.error ?? 'upload failed');
        } catch (e) {
            // The server names what it refused - too large, not an MP4 - and that is what the
            // teacher is told; anything else is a transfer that did not go through.
            this.warn(this.messageFor(e.message));
            this.finishUpload();

            return;
        }

        this.files.push(data.file);
        this.finishUpload();
    }

    finishUpload() {
        this.uploading = false;
        this.pickLabelTarget.textContent = this.labelsValue.addLabel;
        this.pickButtonTarget.disabled = false;
        this.render();
    }

    async deleteFile(file) {
        if (!window.confirm(this.labelsValue.deleteConfirmMessage)) return;

        try {
            const response = await fetch(this.deleteUrlTemplateValue.replace('__FILE_ID__', file.id), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
            });
            if (!response.ok) throw new Error('delete failed');
        } catch (e) {
            this.warn(this.labelsValue.networkErrorMessage);

            return;
        }

        this.files = this.files.filter((candidate) => candidate.id !== file.id);
        this.render();
    }

    // ---- Rendering ------------------------------------------------------------------------------

    render() {
        if (this.files.length === 0) {
            const empty = this.el('div', 'text-secondary', this.labelsValue.emptyMessage);
            this.listTarget.replaceChildren(empty);

            return;
        }

        this.listTarget.replaceChildren(...this.files.map((file) => this.buildFileRow(file)));
    }

    // The same row as an audio common file - play button aside, since nothing is played from this
    // screen: name, duration, weight, bin.
    buildFileRow(file) {
        const row = this.el('div', 'cm-audio-file');

        // A film glyph where the audio row has its play button: nothing is played from this screen,
        // and an empty tile reads as a thumbnail that failed to load.
        const icon = this.el('span', 'cm-video-file__icon');
        icon.appendChild(this.icon('M3 5h18v14H3z|M7 5v14|M17 5v14|M3 12h18', 16));
        row.appendChild(icon);
        row.appendChild(this.el('span', 'cm-audio-file__name', file.name));
        row.appendChild(this.el('span', 'cm-audio-file__duration', file.duration));
        row.appendChild(this.el('span', 'cm-audio-file__duration', this.formatSize(file.size)));

        const remove = this.el('button', 'cm-audio-file__delete');
        remove.type = 'button';
        remove.title = this.labelsValue.deleteTitle;
        remove.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 13));
        remove.addEventListener('click', () => this.deleteFile(file));
        row.appendChild(remove);

        return row;
    }

    // ---- Helpers --------------------------------------------------------------------------------

    messageFor(error) {
        if (error === 'videoUploadTooLargeError') return this.labelsValue.tooLargeMessage;
        if (error === 'videoUploadNotMp4Error') return this.labelsValue.notMp4Message;

        return this.labelsValue.networkErrorMessage;
    }

    // Kilobytes below a megabyte: a course video is always in the tens of megabytes, but a three
    // second clip rounded to "1 Mo" reads as a broken counter rather than as a small file.
    formatSize(bytes) {
        const megabytes = bytes / 1024 / 1024;

        if (megabytes < 1) return `${Math.max(1, Math.round(bytes / 1024))} Ko`;

        return `${megabytes < 10 ? megabytes.toFixed(1) : Math.round(megabytes)} Mo`;
    }

    warn(message) {
        this.warningTarget.textContent = message;
        this.warningTarget.hidden = false;
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

    icon(paths, size) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', size);
        svg.setAttribute('height', size);
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        for (const d of paths.split('|')) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        }

        return svg;
    }
}
