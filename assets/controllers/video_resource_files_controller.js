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
//  3. The transfer is driven by XMLHttpRequest, not by fetch. fetch reports nothing at all about how
//     much of the *request* body has gone out, and a two hundred megabyte upload behind a silent
//     screen reads as a frozen app - the teacher reloads the page and starts the transfer over.
//     `xhr.upload.progress` is the only thing in the platform that answers that question.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'input', 'pickButton', 'pickLabel', 'warning', 'probe'];

    static values = {
        files: Array,
        maxBytes: Number,
        uploadUrl: String,
        deleteUrlTemplate: String,
        cuesUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        // Stimulus Array/Object values re-parse on every access: a working copy is taken at connect(),
        // or every mutation would be silently lost.
        this.files = JSON.parse(JSON.stringify(this.filesValue));
        this.uploading = false;
        // The row painted while a file is on its way: name, weight and how far it has got. Null when
        // nothing is being sent, which is what render() reads to decide whether to draw it at all.
        this.pending = null;
        this.request = null;
        this.render();
    }

    disconnect() {
        // Leaving the screen mid-transfer: the request outlives the controller otherwise, and its
        // load handler would then paint into a card that is no longer in the page.
        this.request?.abort();
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
        this.upload(file, await this.readDuration(file));
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

    upload(file, durationSeconds) {
        this.uploading = true;
        this.pickLabelTarget.textContent = this.labelsValue.uploadingLabel;
        this.pickButtonTarget.disabled = true;
        this.pending = { name: file.name, size: file.size, ratio: 0, sent: false };
        this.render();

        const payload = new FormData();
        payload.append('video', file, file.name);
        payload.append('duration', String(durationSeconds));

        const request = new XMLHttpRequest();
        this.request = request;

        request.upload.addEventListener('progress', (event) => {
            // A ratio of null draws a bar with no scale: a proxy that hides the total is a bar that
            // cannot be measured, never a reason to stop showing that something is happening.
            this.updateProgress(event.lengthComputable ? event.loaded / event.total : null);
        });

        // The last byte is out, and the server now has to write a video to S3 - sixteen seconds for
        // twenty megabytes, measured. A bar left at 100 % during that is read as a stall, so the row
        // switches to "Traitement…" instead.
        request.upload.addEventListener('load', () => this.markSent());

        request.addEventListener('load', () => this.finishRequest(request));
        request.addEventListener('error', () => {
            this.warn(this.labelsValue.networkErrorMessage);
            this.finishUpload();
        });
        // Aborting is the teacher's own doing (the row's "Annuler"), or a page they have left: it
        // needs no message.
        request.addEventListener('abort', () => this.finishUpload());

        request.open('POST', this.uploadUrlValue);
        request.setRequestHeader('X-CSRF-Token', this.csrfTokenValue);
        request.send(payload);
    }

    cancelUpload() {
        this.request?.abort();
    }

    /**
     * What the server answered. The controller replies JSON on every path it takes, so a body that
     * does not parse is a reply the controller never wrote - the request was turned away above it,
     * on a size limit of the server's own (FrankenPHP answers an oversized POST with an HTML page,
     * and a 200 at that). Reading `data.file` off it is what used to throw
     * "Cannot read properties of null".
     */
    finishRequest(request) {
        const data = this.parseJson(request.responseText);

        if (request.status >= 200 && request.status < 300 && data?.file) {
            this.files.push(data.file);
            this.finishUpload();

            return;
        }

        // The server names what it refused - too large, not an MP4 - and that is what the teacher is
        // told; a reply with no name to it is one that never reached the application.
        this.warn(data?.error ? this.messageFor(data.error) : this.labelsValue.serverRefusedMessage);
        this.finishUpload();
    }

    finishUpload() {
        this.uploading = false;
        this.pending = null;
        this.request = null;
        this.progressFill = null;
        this.progressPercent = null;
        this.progressCancel = null;

        // disconnect() aborts a transfer in flight, and the abort handler lands here once the card
        // has left the page: there is nothing left to paint then.
        if (!this.hasPickLabelTarget) return;

        this.pickLabelTarget.textContent = this.labelsValue.addLabel;
        this.pickButtonTarget.disabled = false;
        this.render();
    }

    // ---- Progress ---------------------------------------------------------------------------------

    // The bar is written into the nodes it already has rather than through render(): progress fires
    // dozens of times a second, and rebuilding the whole list at that rate is how a card starts to
    // flicker.
    updateProgress(ratio) {
        if (!this.pending || this.pending.sent) return;

        this.pending.ratio = ratio;
        this.paintProgress();
    }

    markSent() {
        if (!this.pending) return;

        this.pending.sent = true;
        this.pending.ratio = 1;
        this.paintProgress();
    }

    paintProgress() {
        const fill = this.progressFill;
        const percent = this.progressPercent;

        if (!this.pending || !fill || !percent) return;

        const { ratio, sent } = this.pending;

        // An unknown total: the bar sweeps rather than fills, and no percentage is claimed.
        fill.parentElement.classList.toggle('is-indeterminate', null === ratio);
        fill.style.width = null === ratio ? '' : `${Math.round(ratio * 100)}%`;
        percent.textContent = sent
            ? this.labelsValue.processingLabel
            : (null === ratio ? '' : this.formatPercent(ratio));

        // Cancelling stops being offered once the last byte is out: the server writes the file
        // whether the browser is still listening or not, so an abort from here would only hide a
        // video that does exist - the teacher would then upload it a second time.
        if (this.progressCancel) this.progressCancel.disabled = sent;
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
        const rows = this.files.map((file) => this.buildFileRow(file));

        if (this.pending) {
            rows.push(this.buildProgressRow());
        } else if (rows.length === 0) {
            rows.push(this.el('div', 'text-secondary', this.labelsValue.emptyMessage));
        }

        this.listTarget.replaceChildren(...rows);
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

        // The way into the interactive layer (créas 5B). Per file rather than per set: a timeline
        // belongs to one video, and a set of three has three of them.
        const questions = this.el('a', 'cm-audio-ghost', this.labelsValue.questionsLabel);
        questions.href = this.cuesUrlTemplateValue.replace('__FILE_ID__', String(file.id));
        questions.style.marginLeft = 'auto';
        row.appendChild(questions);

        const remove = this.el('button', 'cm-audio-file__delete');
        remove.type = 'button';
        remove.title = this.labelsValue.deleteTitle;
        remove.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 13));
        remove.addEventListener('click', () => this.deleteFile(file));
        row.appendChild(remove);

        return row;
    }

    // The row of a file on its way up: the same shape as a finished one - icon, name, weight - with
    // the bar taking the place the "Questions" link holds on the others, and "Annuler" the bin's.
    buildProgressRow() {
        const row = this.el('div', 'cm-audio-file cm-video-upload');

        const icon = this.el('span', 'cm-video-file__icon');
        icon.appendChild(this.icon('M12 16V4|m6 10 6-6 6 6|M4 20h16', 16));
        row.appendChild(icon);
        row.appendChild(this.el('span', 'cm-audio-file__name', this.pending.name));
        row.appendChild(this.el('span', 'cm-audio-file__duration', this.formatSize(this.pending.size)));

        const track = this.el('span', 'cm-video-upload__track');
        // Announced rather than merely drawn: an upload nobody can see the end of is exactly the
        // case a screen reader needs told.
        track.setAttribute('role', 'progressbar');
        track.setAttribute('aria-label', this.labelsValue.uploadingLabel);
        this.progressFill = this.el('span', 'cm-video-upload__fill');
        track.appendChild(this.progressFill);
        row.appendChild(track);

        this.progressPercent = this.el('span', 'cm-video-upload__percent');
        row.appendChild(this.progressPercent);

        this.progressCancel = this.el('button', 'cm-audio-ghost', this.labelsValue.cancelLabel);
        this.progressCancel.type = 'button';
        this.progressCancel.addEventListener('click', () => this.cancelUpload());
        row.appendChild(this.progressCancel);

        this.paintProgress();

        return row;
    }

    // ---- Helpers --------------------------------------------------------------------------------

    // The controller answers JSON on every path; anything else is a reply it did not write.
    parseJson(text) {
        try {
            const data = JSON.parse(text);

            return null !== data && 'object' === typeof data ? data : null;
        } catch {
            return null;
        }
    }

    // Through Intl rather than by hand: French writes "37 %" and English "37%", and the page already
    // says which one it is.
    formatPercent(ratio) {
        return new Intl.NumberFormat(document.documentElement.lang || 'fr', { style: 'percent' }).format(ratio);
    }

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
