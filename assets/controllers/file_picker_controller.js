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
    static targets = [
        'input', 'rows', 'tokens', 'drop',
        'tabUpload', 'tabLibrary', 'tabLink',
        'libraryPane', 'libraryTree', 'libraryFiles', 'librarySearch', 'libraryBar', 'selectAll', 'addSelection',
        'linkPane', 'linkInput',
    ];

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
        // Which folders the reader has opened, by id. **Nothing is unfolded by default** - the tree
        // is a rail, not a listing, and a library three levels deep opened flat is unreadable. The
        // set survives a repaint (each folder click reloads the pane) the way the screen's own rail
        // survives a page load: what is open is what leads to the folder being browsed, plus what
        // the reader opened by hand.
        this.expanded = new Set();
        // The ids ticked in the current folder, for a multiple field. Cleared when the folder or the
        // search changes: a selection spanning two folders is one nobody can see in full.
        this.selection = new Set();
        this.render();

        // A form redisplayed after a validation error with a link in it opens on the link: the tab
        // that holds the value is the tab to be looking at.
        // Opened, not focused: a focus on connect scrolls the page to whichever picker mounted last,
        // which on a form carrying two of them is not where the reader was looking.
        if (this.hasLinkInputTarget && this.linkInputTarget.value.trim() !== '') this.showPane('link');
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

    // The three tabs are one question - where does this document come from? - so one method sets the
    // whole strip rather than each tab undoing what the others did.
    showPane(which) {
        this.dropTarget.hidden = which !== 'upload' || (!this.multipleValue && this.files.length > 0);
        if (this.hasLibraryPaneTarget) this.libraryPaneTarget.hidden = which !== 'library';
        if (this.hasLinkPaneTarget) this.linkPaneTarget.hidden = which !== 'link';

        for (const [name, tab] of [['upload', this.tabUploadTarget], ['library', this.hasTabLibraryTarget ? this.tabLibraryTarget : null], ['link', this.hasTabLinkTarget ? this.tabLinkTarget : null]]) {
            tab?.classList.toggle('cm-filepick__tab--on', name === which);
        }
    }

    showUpload() {
        this.showPane('upload');
    }

    showLibrary() {
        this.showPane('library');
        this.loadLibrary();
    }

    showLink() {
        this.showPane('link');
        this.linkInputTarget.focus();
    }

    searchLibrary() {
        clearTimeout(this.searchTimer);
        // Debounced: the field is typed into, and one request per keystroke would be a request per
        // keystroke.
        this.searchTimer = setTimeout(() => this.loadLibrary({ q: this.librarySearchTarget.value.trim() }), 250);
    }

    openLibraryFolder(event) {
        this.libraryFolderId = event.currentTarget.dataset.folderId ?? '';
        // Opening a folder opens it in the tree too, so its own sub-folders are reachable without a
        // second click on the triangle.
        if (this.libraryFolderId) this.expanded.add(this.libraryFolderId);
        this.librarySearchTarget.value = '';
        this.selection.clear();
        this.loadLibrary();
    }

    // The triangle. It folds a branch without navigating anywhere: opening a folder to see what is
    // under it and going into it are two different intentions, and the rail of every library screen
    // on this platform already separates them the same way.
    toggleBranch(event) {
        event.preventDefault();
        event.stopPropagation();

        const item = event.currentTarget.closest('.cm-filepick__library-node');
        const id = item?.dataset.folderId;

        if (!id) return;

        if (this.expanded.has(id)) this.expanded.delete(id);
        else this.expanded.add(id);

        item.classList.toggle('is-collapsed', !this.expanded.has(id));
        event.currentTarget.setAttribute('aria-expanded', this.expanded.has(id) ? 'true' : 'false');
    }

    async loadLibrary({ q = '' } = {}) {
        const url = new URL(this.libraryUrlValue, window.location.origin);
        this.selection.clear();
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
        const current = data.folderId == null ? '' : String(data.folderId);
        // The branch leading to the folder being browsed is open whatever the reader has folded: it
        // is the one branch that must show where they are. Computed while walking the tree, since
        // the answer is "does this subtree contain the current folder".
        this.openPath = new Set();
        (data.folders ?? []).forEach((folder) => this.markPath(folder, current));

        const tree = document.createElement('ul');
        tree.className = 'cm-filepick__library-list';
        tree.appendChild(this.buildRootRow(current));
        (data.folders ?? []).forEach((folder) => tree.appendChild(this.buildFolderNode(folder, current, 0)));
        this.libraryTreeTarget.replaceChildren(tree);

        this.paintLibraryFiles(data.files ?? []);
    }

    // True when this subtree holds the current folder; every folder on that way is remembered.
    markPath(folder, current) {
        const id = String(folder.id ?? '');
        const inside = (folder.children ?? []).map((child) => this.markPath(child, current)).some(Boolean);

        if (id === current || inside) this.openPath.add(id);

        return id === current || inside;
    }

    buildRootRow(current) {
        const item = this.el('li', 'cm-filepick__library-node');
        const row = this.el('div', 'cm-filepick__library-row');
        row.appendChild(this.el('span', 'cm-filepick__library-toggle cm-filepick__library-toggle--empty'));
        row.appendChild(this.folderButton({ id: '', name: this.labels.libraryRoot }, current === ''));
        item.appendChild(row);

        return item;
    }

    buildFolderNode(folder, current, depth) {
        const id = String(folder.id ?? '');
        const children = folder.children ?? [];
        // Collapsed unless the reader opened it, or it leads to the folder being browsed. That is
        // the rail's own rule (templates/file_library/_rail_node.html.twig), which this tab was the
        // only tree on the platform not to follow.
        const open = this.expanded.has(id) || this.openPath.has(id);
        const item = this.el('li', `cm-filepick__library-node${children.length > 0 && !open ? ' is-collapsed' : ''}`);
        item.dataset.folderId = id;

        const row = this.el('div', 'cm-filepick__library-row');
        row.style.paddingLeft = `${depth * 12}px`;

        if (children.length > 0) {
            const toggle = this.el('button', 'cm-filepick__library-toggle');
            toggle.type = 'button';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', this.labels.toggleBranch);
            toggle.innerHTML = '<svg width="9" height="9" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z"></path></svg>';
            toggle.addEventListener('click', (event) => this.toggleBranch(event));
            row.appendChild(toggle);
        } else {
            row.appendChild(this.el('span', 'cm-filepick__library-toggle cm-filepick__library-toggle--empty'));
        }

        row.appendChild(this.folderButton(folder, id === current));
        item.appendChild(row);

        if (children.length > 0) {
            const list = this.el('ul', 'cm-filepick__library-list cm-filepick__library-list--nested');
            children.forEach((child) => list.appendChild(this.buildFolderNode(child, current, depth + 1)));
            item.appendChild(list);
        }

        return item;
    }

    folderButton(folder, isCurrent) {
        const button = this.el('button', `cm-filepick__library-folder${isCurrent ? ' is-current' : ''}`, folder.name);
        button.type = 'button';
        button.dataset.folderId = folder.id ?? '';
        button.addEventListener('click', (event) => this.openLibraryFolder(event));

        return button;
    }

    // ---- The files of the folder ---------------------------------------------------------------

    paintLibraryFiles(files) {
        this.libraryFiles = files;

        if (this.hasLibraryBarTarget) this.libraryBarTarget.hidden = !this.multipleValue || files.length === 0;

        if (files.length === 0) {
            this.libraryFilesTarget.replaceChildren(this.el('p', 'cm-filepick__library-empty', this.labels.libraryEmpty));
            this.paintSelectionBar();

            return;
        }

        this.libraryFilesTarget.replaceChildren(...files.map((file) => this.buildLibraryFileRow(file)));
        this.paintSelectionBar();
    }

    // A single-file field keeps the one-click row it has always had: ticking a box then pressing
    // « Ajouter » to choose exactly one file is two gestures for what is one decision. The tick
    // boxes exist for the case they were asked for - filing a whole folder at once.
    buildLibraryFileRow(file) {
        if (!this.multipleValue) {
            const row = this.el('button', 'cm-filepick__library-file');
            row.type = 'button';
            row.appendChild(this.extensionChip(file.name));
            row.appendChild(this.el('span', 'cm-filepick__library-file-name', file.name));
            row.appendChild(this.el('span', 'cm-filepick__meta', file.size));
            row.addEventListener('click', () => this.link(file));

            return row;
        }

        const row = this.el('label', 'cm-filepick__library-file cm-filepick__library-file--pick');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'cm-filepick__library-check';
        box.checked = this.selection.has(String(file.id));
        box.addEventListener('change', () => {
            if (box.checked) this.selection.add(String(file.id));
            else this.selection.delete(String(file.id));
            this.paintSelectionBar();
        });

        row.appendChild(box);
        row.appendChild(this.extensionChip(file.name));
        row.appendChild(this.el('span', 'cm-filepick__library-file-name', file.name));
        row.appendChild(this.el('span', 'cm-filepick__meta', file.size));

        return row;
    }

    // « Tout sélectionner » ticks the folder's files; pressed again it unticks them. One button
    // rather than two, because the second gesture is always undoing the first.
    toggleAll() {
        const all = (this.libraryFiles ?? []).map((file) => String(file.id));
        const everything = all.length > 0 && all.every((id) => this.selection.has(id));

        this.selection = new Set(everything ? [] : all);
        this.libraryFilesTarget.querySelectorAll('.cm-filepick__library-check').forEach((box, index) => {
            box.checked = this.selection.has(all[index]);
        });
        this.paintSelectionBar();
    }

    addSelection() {
        for (const file of this.libraryFiles ?? []) {
            if (this.selection.has(String(file.id))) this.link(file, false);
        }

        this.selection.clear();
        this.render();
        this.showUpload();
    }

    paintSelectionBar() {
        if (!this.hasLibraryBarTarget || this.libraryBarTarget.hidden) return;

        const all = (this.libraryFiles ?? []).map((file) => String(file.id));
        const everything = all.length > 0 && all.every((id) => this.selection.has(id));

        this.selectAllTarget.textContent = everything ? this.labels.selectNone : this.labels.selectAll;
        this.addSelectionTarget.textContent = this.selection.size > 0
            ? `${this.labels.addSelection} (${this.selection.size})`
            : this.labels.addSelection;
        this.addSelectionTarget.disabled = this.selection.size === 0;
    }

    // Choosing a library file adds a chip **visibly not the same thing** as an uploaded one: the gold
    // pill says so, because deleting that file from the library later will remove it from here.
    // `paint` is false when a whole selection is being added: repainting the list once per file
    // would rebuild it as many times as there are ticked boxes, and adding the same file twice is
    // now something a "tout sélectionner" makes easy - hence the guard, which the one-click row
    // never needed.
    link(file, paint = true) {
        const token = this.constructor.LIBRARY_PREFIX + file.id;

        if (!this.multipleValue) this.files = [];
        else if (this.files.some((row) => row.token === token)) return;

        this.files.push({
            token,
            name: file.name,
            size: 0,
            sizeLabel: file.size,
            state: 'library',
        });

        if (!paint) return;

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
        // row's. A reply with no message never reached the application at all - see refusalMessage().
        this.settle(row, 'refused', data?.message ?? this.refusalMessage(request, data));
    }

    /**
     * What to say when the reply carries no message of its own.
     *
     * A 413 is the reverse proxy in front of the application, not the application: production sits
     * behind nginx, whose `client_max_body_size` bounds a request body before FrankenPHP ever sees
     * it (measured 2026-09-05: every upload over 1 Mo was turned away this way, while
     * `upload_max_filesize` said 200M). A body that does not parse is the same class of answer -
     * the 200-with-an-HTML-page FrankenPHP gives a POST over `post_max_size` - and both mean the
     * bytes never reached a controller. Saying "check your connection" there sends the reader after
     * a problem they do not have.
     */
    refusalMessage(request, data) {
        return 413 === request.status || null === data ? this.labels.serverRefused : this.labels.networkError;
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
