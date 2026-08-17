import { Controller } from '@hotwired/stimulus';

// The one upload widget of the application - App\Form\FilePickerType, mockup 5 of
// design_handoff_bibliotheque_fichiers.
//
// It exists because no form-shaped upload can report progress: nothing in the browser says how much
// of a *request body* has gone out - fetch() does not, and a plain form submission certainly does
// not - and `xhr.upload.onprogress`, the only API that answers, needs a request carrying one file.
// So each file leaves on its own XHR the moment it is chosen, and the form submits signed tokens.
//
// Three things about this controller are worth reading before changing it:
//
//  1. **The rows are painted here, not by Twig.** A file appears in the list before the server has
//     ever heard of it, and its state changes three times on the way (envoi, analyse, verdict). A
//     re-render would mean losing the transfer.
//  2. **The name and the size come out of the token**, not out of a second request. The token's
//     first segment is base64url JSON that the server signed and that this page already holds -
//     reading it is what lets a form redisplayed after a validation error paint its rows again with
//     no round trip. Nothing is *trusted* from it: the signature is what the server re-checks, and
//     what is read here only ever draws a label.
//  3. **The refusal arrives during the upload, not after the form.** The server answers the staging
//     request with the violation message, and the row keeps it - a teacher learns that `.exe` is
//     refused while they are still writing the title.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'rows', 'tokens', 'drop', 'tabUpload', 'tabLibrary', 'libraryPane', 'libraryTree', 'libraryFiles', 'librarySearch'];

    static values = {
        multiple: Boolean,
        maxBytes: Number,
        url: String,
        discardUrl: String,
        libraryUrl: String,
        csrfToken: String,
        labels: Object,
    };

    // How a linked file is written into the field, beside the signed tokens - the server's own
    // prefix (App\Form\DataTransformer\StagedUploadTransformer::LIBRARY_PREFIX).
    static LIBRARY_PREFIX = 'lib:';

    connect() {
        // Stimulus Object values re-parse on every access: a working copy, or every read pays for a
        // JSON.parse and no mutation would survive.
        this.labels = { ...this.labelsValue };
        // One entry per row: {token, name, size, state, ratio, message, request}.
        this.files = this.restore();
        this.render();
    }

    disconnect() {
        // A transfer outlives the controller otherwise, and its load handler would then paint into
        // a widget that has left the page.
        for (const file of this.files) file.request?.abort();
    }

    // A form redisplayed after a validation error still carries its tokens: the rows are rebuilt
    // from them so the teacher does not upload the same three files a second time.
    restore() {
        const raw = this.tokensTarget.value;

        if (!raw) return [];

        try {
            return JSON.parse(raw).map((token) => {
                // A library entry carries no payload to decode: it is an id, and the name it had was
                // drawn from the modal. After a validation error the row keeps its id and says so.
                if (token.startsWith(this.constructor.LIBRARY_PREFIX)) {
                    return { token, name: this.labels.fromLibrary, size: 0, state: 'library' };
                }

                const payload = this.readToken(token);

                return { token, name: payload?.n ?? '', size: payload?.s ?? 0, state: 'done', ratio: 1 };
            });
        } catch {
            return [];
        }
    }

    // ---- The Bibliothèque tab -----------------------------------------------------------------

    showUpload() {
        this.dropTarget.hidden = !this.multipleValue && this.files.length > 0;
        if (this.hasLibraryPaneTarget) this.libraryPaneTarget.hidden = true;
        this.tabUploadTarget.classList.add('cm-filepick__tab--on');
        if (this.hasTabLibraryTarget) this.tabLibraryTarget.classList.remove('cm-filepick__tab--on');
    }

    showLibrary() {
        this.dropTarget.hidden = true;
        this.libraryPaneTarget.hidden = false;
        this.tabUploadTarget.classList.remove('cm-filepick__tab--on');
        this.tabLibraryTarget.classList.add('cm-filepick__tab--on');
        this.loadLibrary();
    }

    searchLibrary() {
        clearTimeout(this.searchTimer);
        // Debounced: the field is typed into, and one request per keystroke would be a request per
        // keystroke.
        this.searchTimer = setTimeout(() => this.loadLibrary({ q: this.librarySearchTarget.value.trim() }), 250);
    }

    openLibraryFolder(event) {
        this.libraryFolderId = event.currentTarget.dataset.folderId ?? '';
        this.librarySearchTarget.value = '';
        this.loadLibrary();
    }

    async loadLibrary({ q = '' } = {}) {
        const url = new URL(this.libraryUrlValue, window.location.origin);
        if (q) url.searchParams.set('q', q);
        else if (this.libraryFolderId) url.searchParams.set('folder', this.libraryFolderId);

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            this.paintLibrary(await response.json());
        } catch {
            this.libraryFilesTarget.textContent = this.labels.networkError;
        }
    }

    paintLibrary(data) {
        const tree = document.createElement('ul');
        tree.className = 'cm-filepick__library-list';
        tree.appendChild(this.buildFolderRow({ id: '', name: this.labels.libraryRoot }, 0));
        (data.folders ?? []).forEach((folder) => this.appendFolder(tree, folder, 1));
        this.libraryTreeTarget.replaceChildren(tree);

        const files = data.files ?? [];

        if (files.length === 0) {
            this.libraryFilesTarget.replaceChildren(this.el('p', 'cm-filepick__library-empty', this.labels.libraryEmpty));

            return;
        }

        this.libraryFilesTarget.replaceChildren(...files.map((file) => {
            const row = this.el('button', 'cm-filepick__library-file');
            row.type = 'button';
            row.appendChild(this.extensionChip(file.name));
            row.appendChild(this.el('span', 'cm-filepick__library-file-name', file.name));
            row.appendChild(this.el('span', 'cm-filepick__meta', file.size));
            row.addEventListener('click', () => this.link(file));

            return row;
        }));
    }

    appendFolder(list, folder, depth) {
        list.appendChild(this.buildFolderRow(folder, depth));
        (folder.children ?? []).forEach((child) => this.appendFolder(list, child, depth + 1));
    }

    buildFolderRow(folder, depth) {
        const item = document.createElement('li');
        const button = this.el('button', 'cm-filepick__library-folder', folder.name);
        button.type = 'button';
        button.style.paddingLeft = `${6 + depth * 12}px`;
        button.dataset.folderId = folder.id ?? '';
        button.addEventListener('click', (event) => this.openLibraryFolder(event));
        item.appendChild(button);

        return item;
    }

    // Choosing a library file adds a chip **visibly not the same thing** as an uploaded one: the gold
    // pill says so, because deleting that file from the library later will remove it from here.
    link(file) {
        if (!this.multipleValue) this.files = [];

        this.files.push({
            token: this.constructor.LIBRARY_PREFIX + file.id,
            name: file.name,
            size: 0,
            sizeLabel: file.size,
            state: 'library',
        });
        this.render();
        this.showUpload();
    }

    // The token's payload segment, base64url-encoded JSON. Null when it is not one - a token this
    // page did not get from the server, which the server will refuse anyway.
    readToken(token) {
        try {
            const segment = token.split('.')[0].replace(/-/g, '+').replace(/_/g, '/');

            return JSON.parse(atob(segment + '='.repeat((4 - (segment.length % 4)) % 4)));
        } catch {
            return null;
        }
    }

    // ---- Choosing files ---------------------------------------------------------------------

    pick(event) {
        event.preventDefault();
        this.inputTarget.click();
    }

    selected() {
        const chosen = [...this.inputTarget.files];
        // Reset straight away, so picking the same file twice in a row still fires a change event.
        this.inputTarget.value = '';
        this.accept(chosen);
    }

    dragOver(event) {
        event.preventDefault();
        this.dropTarget.classList.add('is-hot');
    }

    dragLeave() {
        this.dropTarget.classList.remove('is-hot');
    }

    dropped(event) {
        event.preventDefault();
        this.dropTarget.classList.remove('is-hot');
        this.accept([...(event.dataTransfer?.files ?? [])]);
    }

    accept(chosen) {
        if (chosen.length === 0) return;

        // A single-file field takes the last one chosen and says so, rather than silently keeping
        // the first: the teacher who drops two files on it meant the second at least as much.
        const files = this.multipleValue ? chosen : chosen.slice(-1);

        if (!this.multipleValue && this.files.length > 0) {
            this.files = [];
        }

        for (const file of files) this.upload(file);
    }

    // ---- The transfer -----------------------------------------------------------------------

    upload(file) {
        const row = { token: null, name: file.name, size: file.size, state: 'uploading', ratio: 0 };
        this.files.push(row);
        this.render();

        // The size is checked here too, before a byte leaves: a 200 Mo transfer that ends in "trop
        // volumineux" is a minute of the teacher's life for an answer the browser already had. The
        // server checks again - this one is a courtesy, not a control.
        if (file.size > this.maxBytesValue) {
            this.settle(row, 'refused', this.labels.tooLarge);

            return;
        }

        const payload = new FormData();
        payload.append('file', file, file.name);
        payload.append('maxBytes', String(this.maxBytesValue));

        const request = new XMLHttpRequest();
        row.request = request;

        request.upload.addEventListener('progress', (event) => {
            // A ratio of null draws a bar with no scale: a proxy that hides the total is a bar that
            // cannot be measured, never a reason to stop showing that something is happening.
            row.ratio = event.lengthComputable ? event.loaded / event.total : null;
            this.paint(row);
        });

        // The last byte is out and the server now scans and writes to S3. A bar left at 100 % during
        // that reads as a stall, so the row says "analyse…" instead.
        request.upload.addEventListener('load', () => {
            row.state = 'scanning';
            row.ratio = 1;
            this.paint(row);
        });

        request.addEventListener('load', () => this.finish(row, request));
        request.addEventListener('error', () => this.settle(row, 'refused', this.labels.networkError));
        request.addEventListener('abort', () => this.remove(row, false));

        request.open('POST', this.urlValue);
        request.setRequestHeader('X-CSRF-Token', this.csrfTokenValue);
        request.send(payload);
    }

    finish(row, request) {
        const data = this.parseJson(request.responseText);

        if (request.status >= 200 && request.status < 300 && data?.token) {
            row.token = data.token;
            row.name = data.name ?? row.name;
            row.size = data.size ?? row.size;
            this.settle(row, 'done', null);

            return;
        }

        // The server names what it refused, in the reader's own language, and that message is the
        // row's. A reply with no message is one that never reached the application - which under
        // FrankenPHP is what an oversized POST looks like.
        this.settle(row, 'refused', data?.message ?? this.labels.networkError);
    }

    settle(row, state, message) {
        row.state = state;
        row.message = message;
        row.request = null;
        this.render();
    }

    // ---- Removing --------------------------------------------------------------------------

    remove(row, tellTheServer = true) {
        row.request?.abort();
        this.files = this.files.filter((candidate) => candidate !== row);
        this.render();

        // The object goes now rather than waiting for the nightly purge, which is what a teacher
        // who has just removed a 180 Mo video would expect. Best effort: it is scheduled either way.
        // A library file was never staged: there is nothing to discard, and asking the server to
        // would only be asking it to refuse.
        if (tellTheServer && row.token && !row.token.startsWith(this.constructor.LIBRARY_PREFIX)) {
            const payload = new FormData();
            payload.append('token', row.token);
            fetch(this.discardUrlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue }, body: payload }).catch(() => {});
        }
    }

    // ---- Rendering --------------------------------------------------------------------------

    render() {
        this.tokensTarget.value = this.files.some((file) => file.token)
            ? JSON.stringify(this.files.filter((file) => file.token).map((file) => file.token))
            : '';

        this.rowsTarget.replaceChildren(...this.files.map((file) => this.buildRow(file)));
        // A single-file field stops offering to add one once it holds it: the drop zone would then
        // be the only thing on screen suggesting two are allowed.
        this.dropTarget.hidden = !this.multipleValue && this.files.length > 0;
    }

    buildRow(row) {
        const element = this.el('div', 'cm-filepick__row');

        element.appendChild(this.extensionChip(row.name));

        const grow = this.el('span', 'cm-filepick__grow');
        grow.appendChild(this.el('span', 'cm-filepick__name', row.name));
        grow.appendChild(this.el('span', 'cm-filepick__meta', row.message ?? row.sizeLabel ?? this.formatSize(row.size)));

        if (row.state === 'uploading' || row.state === 'scanning') {
            const track = this.el('span', 'cm-filepick__track');
            track.setAttribute('role', 'progressbar');
            track.setAttribute('aria-label', this.labels.uploading);
            row.fill = this.el('span', 'cm-filepick__fill');
            track.appendChild(row.fill);
            grow.appendChild(track);
        }

        element.appendChild(grow);

        const state = this.el('span', `cm-filepick__state cm-filepick__state--${this.stateModifier(row)}`, this.stateLabel(row));
        row.stateNode = state;
        element.appendChild(state);

        const remove = this.el('button', 'cm-filepick__remove', '✕');
        remove.type = 'button';
        remove.title = this.labels.remove;
        remove.setAttribute('aria-label', `${this.labels.remove} — ${row.name}`);
        remove.addEventListener('click', () => this.remove(row));
        element.appendChild(remove);

        this.paint(row);

        return element;
    }

    // The bar is written into the nodes it already has rather than through render(): progress fires
    // dozens of times a second, and rebuilding the list at that rate is how a widget starts to
    // flicker.
    paint(row) {
        if (!row.fill || !row.stateNode) return;

        row.fill.parentElement.classList.toggle('is-indeterminate', row.ratio === null);
        row.fill.style.width = row.ratio === null ? '' : `${Math.round(row.ratio * 100)}%`;
        row.stateNode.textContent = this.stateLabel(row);
    }

    stateModifier(row) {
        if (row.state === 'done') return 'ok';
        if (row.state === 'refused') return 'ko';
        if (row.state === 'library') return 'lib';

        return 'go';
    }

    stateLabel(row) {
        if (row.state === 'done') return this.labels.done;
        if (row.state === 'refused') return this.labels.refused;
        if (row.state === 'library') return this.labels.fromLibrary;
        if (row.state === 'scanning') return this.labels.uploading;

        return row.ratio === null ? this.labels.uploading : this.formatPercent(row.ratio);
    }

    // The extension, as the mockup draws it: a small coloured tile rather than an icon, because it
    // is the one thing about a file a teacher reads before its name.
    extensionChip(name) {
        const extension = (name.split('.').pop() ?? '').toLowerCase();
        const family = {
            pdf: 'pdf',
            jpg: 'img', jpeg: 'img', png: 'img', webp: 'img', gif: 'img', avif: 'img',
            mp4: 'vid', webm: 'vid', mov: 'vid',
            doc: 'doc', docx: 'doc', odt: 'doc', ppt: 'doc', pptx: 'doc', odp: 'doc', xls: 'doc', xlsx: 'doc', ods: 'doc', csv: 'doc',
            zip: 'zip', '7z': 'zip', tar: 'zip', gz: 'zip', tgz: 'zip',
        }[extension];

        return this.el('span', `cm-filepick__ext${family ? ` cm-filepick__ext--${family}` : ''}`, extension.slice(0, 4).toUpperCase() || '?');
    }

    // ---- Helpers ----------------------------------------------------------------------------

    parseJson(text) {
        try {
            const data = JSON.parse(text);

            return data !== null && typeof data === 'object' ? data : null;
        } catch {
            return null;
        }
    }

    // Through Intl rather than by hand: French writes "37 %" and English "37%", and the page already
    // says which one it is.
    formatPercent(ratio) {
        return new Intl.NumberFormat(document.documentElement.lang || 'fr', { style: 'percent' }).format(ratio);
    }

    formatSize(bytes) {
        const megabytes = bytes / 1024 / 1024;

        if (megabytes < 1) return `${Math.max(1, Math.round(bytes / 1024))} Ko`;

        return `${megabytes < 10 ? megabytes.toFixed(1) : Math.round(megabytes)} Mo`;
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }
}
