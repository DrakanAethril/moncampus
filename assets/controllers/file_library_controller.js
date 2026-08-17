import { Controller } from '@hotwired/stimulus';

// The library's screen: importing files into the current folder, renaming, replacing, and the
// full-frame preview.
//
// The upload goes through the **same two steps as every other field of the platform**
// (design/validated/file-library.md): the bytes leave on their own XHR to `/uploads/stage`, then a
// second, tiny request hands the token to the library and gets the created row back. That is why
// there is a progress bar here at all, and why an oversized or refused file is answered while the
// teacher is still looking at the folder.
//
// The table itself is never repainted: a save reloads the screen. Three things change at once on a
// move or an upload - the rail, the table and the quota bar - and rebuilding three from one answer
// is how they come to disagree.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'uploads', 'table', 'viewer', 'viewerBody', 'viewerName', 'viewerCount', 'viewerDownload',
        'usages', 'usagesTitle', 'usagesBody', 'usagesFoot'];

    static values = {
        folderId: String,
        stageUrl: String,
        uploadUrl: String,
        folderUrl: String,
        renameUrlTemplate: String,
        replaceUrlTemplate: String,
        usagesUrlTemplate: String,
        deleteUrlTemplate: String,
        maxBytes: Number,
        stageToken: String,
        token: String,
        labels: Object,
    };

    connect() {
        this.labels = { ...this.labelsValue };
        this.pending = [];
        // Set when the picker is opened by "Remplacer" on a row rather than by "Importer" - the same
        // input serves both, and this is what tells them apart when the file comes back.
        this.replacingNodeId = null;
        this.element.addEventListener('dragover', this.allowDrop);
        this.element.addEventListener('drop', this.acceptDrop);
    }

    disconnect() {
        this.element.removeEventListener('dragover', this.allowDrop);
        this.element.removeEventListener('drop', this.acceptDrop);
    }

    // ---- Choosing files -----------------------------------------------------------------------

    pick() {
        this.replacingNodeId = null;
        this.inputTarget.multiple = true;
        this.inputTarget.click();
    }

    replace(event) {
        this.replacingNodeId = event.currentTarget.closest('[data-node-id]').dataset.nodeId;
        // One file replaces one file: offering several here would leave the second with nowhere to go.
        this.inputTarget.multiple = false;
        this.inputTarget.click();
    }

    selected() {
        const files = [...this.inputTarget.files];
        this.inputTarget.value = '';
        files.forEach((file) => this.upload(file));
    }

    // A file dragged from the desktop onto the screen lands in the folder being browsed. The rail's
    // own drop handling is a different gesture (a row onto a folder) and stops the event before it
    // reaches here.
    allowDrop = (event) => {
        if (!event.dataTransfer?.types?.includes('Files')) return;
        event.preventDefault();
    };

    acceptDrop = (event) => {
        if (!event.dataTransfer?.types?.includes('Files')) return;
        event.preventDefault();
        this.replacingNodeId = null;
        [...event.dataTransfer.files].forEach((file) => this.upload(file));
    };

    // ---- Uploading ----------------------------------------------------------------------------

    upload(file) {
        const row = this.buildUploadRow(file.name);
        this.uploadsTarget.hidden = false;
        this.pending.push(row);

        if (file.size > this.maxBytesValue) {
            this.fail(row, this.labels.networkError);

            return;
        }

        const payload = new FormData();
        payload.append('file', file, file.name);
        payload.append('maxBytes', String(this.maxBytesValue));

        const request = new XMLHttpRequest();

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) this.paint(row, event.loaded / event.total);
        });
        request.upload.addEventListener('load', () => this.paint(row, 1));
        request.addEventListener('load', () => this.staged(row, request, file));
        request.addEventListener('error', () => this.fail(row, this.labels.networkError));

        request.open('POST', this.stageUrlValue);
        request.setRequestHeader('X-CSRF-Token', this.stageTokenValue);
        request.send(payload);
    }

    // The bytes are in the bucket; the second request is what makes them a file of this library.
    async staged(row, request, file) {
        const data = this.parseJson(request.responseText);

        if (request.status < 200 || request.status >= 300 || !data?.token) {
            this.fail(row, data?.message ?? this.labels.networkError);

            return;
        }

        const duration = await this.readDuration(file);
        const claimed = await this.claim(data.token, duration);

        if (claimed?.conflict) {
            // Neither an error nor a silent rename: the screen asks, and the answer travels with the
            // second attempt. Nothing has been written, so declining leaves nothing behind.
            const replace = window.confirm(claimed.conflict.message);
            const again = await this.claim(data.token, duration, replace ? 'replace' : 'keep-both');

            if (!again || again.error) {
                this.fail(row, again?.message ?? this.labels.networkError);

                return;
            }
        } else if (!claimed || claimed.error) {
            this.fail(row, claimed?.message ?? this.labels.networkError);

            return;
        }

        window.location.reload();
    }

    async claim(token, duration, onConflict = '') {
        const payload = new FormData();
        payload.append('token', token);
        payload.append('folder', this.folderIdValue);
        payload.append('onConflict', onConflict);
        if (duration) payload.append('duration', String(duration));

        const url = this.replacingNodeId
            ? this.replaceUrlTemplateValue.replace('__NODE_ID__', this.replacingNodeId)
            : this.uploadUrlValue;

        try {
            const response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': this.tokenValue }, body: payload });

            return await response.json();
        } catch {
            return null;
        }
    }

    // The duration of an audio or video file, read by handing the local file to a hidden element -
    // the server has no ffprobe and does not want one. Zero when the browser cannot read it: an
    // unknown duration costs a player its scale, never the file itself.
    readDuration(file) {
        const audio = file.type.startsWith('audio/');
        const video = file.type.startsWith('video/');

        if (!audio && !video) return Promise.resolve(0);

        return new Promise((resolve) => {
            const probe = document.createElement(video ? 'video' : 'audio');
            const url = URL.createObjectURL(file);
            const done = (seconds) => {
                URL.revokeObjectURL(url);
                resolve(seconds);
            };

            probe.preload = 'metadata';
            probe.onloadedmetadata = () => done(Number.isFinite(probe.duration) ? Math.round(probe.duration) : 0);
            probe.onerror = () => done(0);
            probe.src = url;
        });
    }

    // ---- Folders and names --------------------------------------------------------------------

    promptFolder(event) {
        const name = window.prompt(this.labels.folderPrompt);

        if (!name || name.trim() === '') return;

        this.post(this.folderUrlValue, { name: name.trim(), parent: event.currentTarget.dataset.parentId ?? '' });
    }

    promptRename(event) {
        const row = event.currentTarget.closest('[data-node-id]');
        const name = window.prompt(this.labels.renamePrompt, row.dataset.nodeName);

        if (!name || name.trim() === '') return;

        this.post(this.renameUrlTemplateValue.replace('__NODE_ID__', row.dataset.nodeId), { name: name.trim() });
    }

    // A real form submission rather than a fetch: these POSTs redirect, which is the rule in this
    // repository, and a redirect is what refreshes the rail and the table together.
    post(action, fields) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.hidden = true;

        Object.entries({ ...fields, _token: this.tokenValue }).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }

    // ---- Usages, and the deletion that goes through them ---------------------------------------

    // The panel: where this file is used, by name, with a link to each. Read-only.
    usages(event) {
        this.openUsages(event.currentTarget.closest('[data-node-id]'), false);
    }

    // The same list, with « Supprimer partout (11 utilisations) » under it. The teacher sees what
    // they are about to remove before they remove it - which is the whole handling of the one danger
    // the reference model has.
    confirmDelete(event) {
        this.openUsages(event.currentTarget.closest('[data-node-id]'), true);
    }

    async openUsages(row, deleting) {
        const nodeId = row.dataset.nodeId;

        this.usagesTitleTarget.textContent = row.dataset.nodeName;
        this.usagesBodyTarget.textContent = '';
        this.usagesFootTarget.replaceChildren();
        this.usagesTarget.hidden = false;

        let data;

        try {
            const response = await fetch(this.usagesUrlTemplateValue.replace('__NODE_ID__', nodeId), { headers: { Accept: 'application/json' } });
            data = await response.json();
        } catch {
            this.usagesBodyTarget.textContent = this.labels.networkError;

            return;
        }

        const list = document.createElement('ul');
        list.className = 'cm-flib__uselist';

        for (const usage of data.usages ?? []) {
            const item = document.createElement('li');
            item.appendChild(this.el('span', 'where', usage.where));

            if (usage.url) {
                const link = this.el('a', 'what', usage.what);
                link.href = usage.url;
                item.appendChild(link);
            } else {
                item.appendChild(this.el('span', 'what', usage.what));
            }

            list.appendChild(item);
        }

        this.usagesBodyTarget.replaceChildren(list);

        if (!deleting) {
            const close = this.el('button', 'cm-btn cm-btn--sm cm-btn--outline', this.labels.close);
            close.type = 'button';
            close.addEventListener('click', () => this.closeUsages());
            this.usagesFootTarget.replaceChildren(close);

            return;
        }

        // One line, and only one: since deletion became deferred this button means "the links go
        // now, the file goes to the corbeille, the bytes go in thirty days". Anything more turns a
        // confirmation into a lecture.
        this.usagesBodyTarget.appendChild(this.el('p', 'cm-flib__usenotice', data.deleteNotice ?? ''));

        const cancel = this.el('button', 'cm-btn cm-btn--sm cm-btn--outline', this.labels.cancel);
        cancel.type = 'button';
        cancel.addEventListener('click', () => this.closeUsages());

        const confirm = this.el('button', 'cm-btn cm-btn--sm cm-btn--danger', `${this.labels.deleteEverywhere} (${data.count})`);
        confirm.type = 'button';
        confirm.addEventListener('click', () => this.post(this.deleteUrlTemplateValue.replace('__NODE_ID__', nodeId), {}));

        this.usagesFootTarget.replaceChildren(cancel, confirm);
    }

    closeUsages() {
        this.usagesTarget.hidden = true;
    }

    // ---- Preview ------------------------------------------------------------------------------

    preview(event) {
        const row = event.currentTarget.closest('[data-node-id]');
        this.previewable = [...this.element.querySelectorAll('[data-preview-url]')];
        this.previewIndex = this.previewable.indexOf(row);
        this.showPreview();
    }

    nextPreview() {
        if (!this.previewable?.length) return;
        this.previewIndex = (this.previewIndex + 1) % this.previewable.length;
        this.showPreview();
    }

    previousPreview() {
        if (!this.previewable?.length) return;
        this.previewIndex = (this.previewIndex - 1 + this.previewable.length) % this.previewable.length;
        this.showPreview();
    }

    closeViewer() {
        this.viewerTarget.hidden = true;
        this.viewerBodyTarget.replaceChildren();
    }

    showPreview() {
        const row = this.previewable[this.previewIndex];

        if (!row) return;

        const url = row.dataset.previewUrl;
        const name = row.dataset.nodeName;
        const extension = (name.split('.').pop() ?? '').toLowerCase();

        this.viewerNameTarget.textContent = name;
        // The counter reads over the *previewable* files of the folder, not over its rows: "3 / 6"
        // where the table has eight lines is the honest number, since two of them cannot be opened.
        this.viewerCountTarget.textContent = `${this.previewIndex + 1} / ${this.previewable.length}`;
        this.viewerDownloadTarget.href = url;

        this.viewerBodyTarget.replaceChildren(this.buildPreviewElement(url, extension, name));
        this.viewerTarget.hidden = false;
    }

    buildPreviewElement(url, extension, name) {
        if (['mp4', 'webm', 'mov'].includes(extension)) {
            const video = document.createElement('video');
            video.src = url;
            video.controls = true;

            return video;
        }

        if (['mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac'].includes(extension)) {
            const audio = document.createElement('audio');
            audio.src = url;
            audio.controls = true;

            return audio;
        }

        if (extension === 'pdf') {
            const frame = document.createElement('iframe');
            frame.src = url;
            frame.title = name;

            return frame;
        }

        const image = document.createElement('img');
        image.src = url;
        image.alt = name;

        return image;
    }

    // ---- The upload strip ---------------------------------------------------------------------

    buildUploadRow(name) {
        const row = document.createElement('div');
        row.className = 'cm-flib__upload';

        const label = document.createElement('span');
        label.className = 'cm-flib__upload-name';
        label.textContent = name;
        row.appendChild(label);

        const track = document.createElement('span');
        track.className = 'cm-flib__upload-track';
        track.setAttribute('role', 'progressbar');
        track.setAttribute('aria-label', this.labels.uploading);
        const fill = document.createElement('span');
        fill.className = 'cm-flib__upload-fill';
        track.appendChild(fill);
        row.appendChild(track);

        const state = document.createElement('span');
        state.className = 'cm-flib__upload-state';
        state.textContent = this.labels.uploading;
        row.appendChild(state);

        this.uploadsTarget.appendChild(row);

        return { element: row, fill, state };
    }

    paint(row, ratio) {
        row.fill.style.width = `${Math.round(ratio * 100)}%`;
    }

    fail(row, message) {
        row.element.classList.add('is-failed');
        row.state.textContent = message;
    }

    parseJson(text) {
        try {
            const data = JSON.parse(text);

            return data !== null && typeof data === 'object' ? data : null;
        } catch {
            return null;
        }
    }
}
