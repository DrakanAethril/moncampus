import { Controller } from '@hotwired/stimulus';

// A collaborative wall (design/design_handoff_murs_collaboratifs, screens 3 to 7).
//
// The page draws nothing of its own. Every gesture is a request, and every answer is the same
// document - the wall as this person now reads it: its revision, the canvas in the view this
// browser is in, the card it has open (App\Service\Wall\WallResponder). apply() swaps those in,
// whoever caused the change: this browser, or a colleague's, announced over Mercure and then asked
// for like any other reading. So there is no client-side model to keep in step with the server.
//
// What does live here is what a redraw would lose and the server cannot know: which inline form is
// open, what is being dragged, the view this person switched to, the slide being projected.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'title', 'board', 'viewButton', 'addButton', 'exitFullscreen', 'fullscreenLabel',
        'cardDialog', 'transferDialog', 'transferTitle', 'transferModes', 'transferBody', 'transferSubmit',
        'shareDialog', 'settingsDialog', 'settingTitle', 'ground', 'groundImage', 'switch',
        'projection', 'projectionList', 'projectionCount', 'projectionPrevious', 'projectionNext', 'slide',
        'fileInput', 'toast', 'toastMessage', 'toastLink',
    ];

    static values = {
        stateUrl: String,
        settingsUrl: String,
        destinationsUrl: String,
        projectionUrl: String,
        indexUrl: String,
        stageUrl: String,
        stageCsrf: String,
        csrf: String,
        revision: Number,
        view: String,
        manages: Boolean,
        mercureUrl: String,
        topic: String,
        labels: Object,
        settings: Object,
    };

    connect() {
        this.labels = this.labelsValue;
        this.revision = this.revisionValue;
        this.view = this.viewValue;
        this.openCardId = null;
        this.addingListId = null;
        this.addingList = false;
        this.drag = null;
        // The newest revision announced over Mercure, and how many of this browser's own requests
        // are still out: see catchUp().
        this.announced = this.revision;
        this.inFlight = 0;

        this.onDragStart = this.dragStart.bind(this);
        this.onDragOver = this.dragOver.bind(this);
        this.onDrop = this.drop.bind(this);
        this.onDragEnd = this.dragEnd.bind(this);
        this.boardTarget.addEventListener('dragstart', this.onDragStart);
        this.boardTarget.addEventListener('dragover', this.onDragOver);
        this.boardTarget.addEventListener('drop', this.onDrop);
        this.boardTarget.addEventListener('dragend', this.onDragEnd);

        this.onDocumentClick = (event) => {
            if (!event.target.closest('.cm-wall-menu')) {
                this.closeMenus();
            }
        };
        this.onDocumentKey = (event) => {
            if ('Escape' === event.key) {
                this.closeMenus();
            }
        };
        this.onFullscreenChange = this.fullscreenChanged.bind(this);
        this.onFocusOut = () => setTimeout(() => this.catchUp(), 0);
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onDocumentKey);
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
        this.element.addEventListener('focusout', this.onFocusOut);

        this.syncSettings(this.settingsValue);
        this.listen();
    }

    disconnect() {
        this.boardTarget.removeEventListener('dragstart', this.onDragStart);
        this.boardTarget.removeEventListener('dragover', this.onDragOver);
        this.boardTarget.removeEventListener('drop', this.onDrop);
        this.boardTarget.removeEventListener('dragend', this.onDragEnd);
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onDocumentKey);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        this.element.removeEventListener('focusout', this.onFocusOut);
        this.source?.close();
        clearTimeout(this.toastTimer);
    }

    // --- The one request, the one answer ---------------------------------------------------

    async request(url, { method = 'POST', body = null } = {}) {
        const target = new URL(url, window.location.origin);
        target.searchParams.set('view', this.view);
        if (null !== this.openCardId) {
            target.searchParams.set('card', this.openCardId);
        }

        let response;
        this.inFlight += 1;
        try {
            response = await fetch(target, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
                body: 'GET' === method ? undefined : JSON.stringify(body ?? {}),
            });
        } catch {
            this.inFlight -= 1;
            this.flash(this.labels.error, { error: true });

            return null;
        }
        this.inFlight -= 1;

        // The wall is gone, or is no longer shared with this person: there is nothing left to show.
        if (404 === response.status && target.pathname === new URL(this.stateUrlValue, window.location.origin).pathname) {
            window.location.assign(this.indexUrlValue);

            return null;
        }

        const state = await response.json().catch(() => null);
        if (!response.ok || null === state) {
            this.flash(state?.message ?? this.labels.error, { error: true });
            // The page may be showing something the server just refused to change: draw it again.
            if (target.pathname !== new URL(this.stateUrlValue, window.location.origin).pathname) {
                this.refresh();
            }

            return null;
        }

        this.apply(state);
        this.catchUp();

        return state;
    }

    refresh() {
        return this.request(this.stateUrlValue, { method: 'GET' });
    }

    apply(state) {
        // Two answers may cross; the older one must not undo the newer - nor may an answer drawn
        // for the view this browser has since left.
        if (state.revision < this.revision || state.view !== this.view) {
            return;
        }
        this.revision = state.revision;

        this.titleTarget.textContent = state.title;
        if (this.hasAddButtonTarget) {
            this.addButtonTarget.hidden = !state.mayAdd;
        }
        this.viewButtonTargets.forEach((button) => {
            const active = button.dataset.view === this.view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        const scroll = this.scrollOf();
        this.boardTarget.innerHTML = state.board;
        this.restoreScroll(scroll);
        this.reopenInlineForms();

        if ('card' in state) {
            if (null === state.card) {
                this.closeCard();
            } else {
                this.drawCard(state.card);
            }
        }

        this.syncSettings(state.settings);
        if (state.toast) {
            this.flash(state.toast.message, { url: state.toast.url });
        }
    }

    // --- Generic gestures --------------------------------------------------------------------

    // A button that is a request and nothing else: « Valider », a colour swatch, « Dupliquer »…
    // With a `confirm` param the first click only arms it - the label becomes the confirmation,
    // for four seconds - and the second one acts. No confirm() dialog, no accidental deletion.
    async post(event) {
        const button = event.currentTarget;
        const { url, body, confirm, close } = event.params;

        if (confirm && !this.armed(button, confirm)) {
            return;
        }
        if (close) {
            this.closeCard();
        }
        this.closeMenus();
        await this.request(url, { body: body ?? {} });
    }

    // The same two-step, for the one button that submits a plain form: « Supprimer le mur ».
    armSubmit(event) {
        if (this.armed(event.currentTarget, event.params.confirm)) {
            event.currentTarget.form.submit();
        }
    }

    armed(button, label) {
        if (button.classList.contains('is-armed')) {
            return true;
        }
        const original = button.textContent;
        button.textContent = label;
        button.classList.add('is-armed');
        setTimeout(() => {
            if (button.isConnected) {
                button.textContent = original;
                button.classList.remove('is-armed');
            }
        }, 4000);

        return false;
    }

    // A small form posted as the JSON object of its fields.
    async submit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const refocus = form.dataset.wallRefocus ?? null;
        const state = await this.request(form.action, { body: Object.fromEntries(new FormData(form)) });

        if (state && refocus) {
            this.cardDialogTarget.querySelector(`[data-wall-refocus="${refocus}"] input`)?.focus();
        }
    }

    submitField(event) {
        event.currentTarget.form.requestSubmit();
    }

    // Enter sends, Shift+Enter breaks the line.
    enterSubmits(event) {
        if (!event.shiftKey) {
            event.preventDefault();
            event.currentTarget.form.requestSubmit();
        }
    }

    grow(event) {
        const field = event.currentTarget;
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }

    // --- Menus ---------------------------------------------------------------------------------

    toggleMenu(event) {
        const menu = event.currentTarget.parentElement.querySelector('.cm-wall-menu');
        const opening = menu.hidden;
        this.closeMenus();
        menu.hidden = !opening;
    }

    keepMenu() {
        // The colour input sits inside the menu: its click must not close what holds it.
    }

    closeMenus() {
        this.element.querySelectorAll('.cm-wall-menu').forEach((menu) => {
            menu.hidden = true;
        });
    }

    pickColor(event) {
        this.closeMenus();
        this.request(event.params.url, { body: { color: event.currentTarget.value } });
    }

    // --- Lists ---------------------------------------------------------------------------------

    startRename(event) {
        const list = event.currentTarget.closest('.cm-wall-list');
        const form = list.querySelector('.cm-wall-list__rename');
        if (!form) {
            return;
        }
        this.closeMenus();
        form.hidden = false;
        list.querySelector('.cm-wall-list__title').hidden = true;
        const input = form.querySelector('input');
        input.dataset.original = input.value;
        input.focus();
        input.select();
    }

    // Leaving the field validates, like Enter - unless nothing changed, or Escape just cancelled.
    commitRename(event) {
        const input = event.currentTarget;
        if (input.form.hidden) {
            return;
        }
        if ('' === input.value.trim() || input.value === input.dataset.original) {
            this.hideRename(input);

            return;
        }
        input.form.requestSubmit();
    }

    cancelRename(event) {
        const input = event.currentTarget;
        input.value = input.dataset.original;
        this.hideRename(input);
    }

    hideRename(input) {
        input.form.hidden = true;
        input.closest('.cm-wall-list').querySelector('.cm-wall-list__title').hidden = false;
    }

    startAddList() {
        this.addingList = true;
        this.reopenInlineForms();
    }

    cancelAddList() {
        this.addingList = false;
        const form = this.boardTarget.querySelector('[data-wall-new-list]');
        if (form) {
            form.hidden = true;
            this.boardTarget.querySelector('.cm-wall-newlist').hidden = false;
        }
    }

    // --- Adding a card -------------------------------------------------------------------------

    startAdd(event) {
        this.closeMenus();
        this.openAdd(event.currentTarget.closest('.cm-wall-list').dataset.listId);
    }

    // « + Carte »: the first list's form - in the columns, which is where a card is written.
    async addToFirst() {
        if ('columns' !== this.view) {
            this.view = 'columns';
            await this.refresh();
        }
        const first = this.boardTarget.querySelector('.cm-wall-list[data-list-id]');
        if (first) {
            this.openAdd(first.dataset.listId);
        }
    }

    openAdd(listId) {
        this.addingListId = listId;
        this.reopenInlineForms();
    }

    cancelAdd() {
        this.addingListId = null;
        this.reopenInlineForms();
    }

    // The canvas has just been redrawn (or a form asked for): put back the one « Ajouter une
    // carte » form and the « Ajouter une liste » form that were open, emptied and focused, so that
    // several cards are written one after the other without reaching for the mouse.
    reopenInlineForms() {
        this.boardTarget.querySelectorAll('.cm-wall-list[data-list-id]').forEach((list) => {
            const form = list.querySelector('.cm-wall-add');
            if (!form) {
                return;
            }
            const open = list.dataset.listId === this.addingListId;
            const wasHidden = form.hidden;
            form.hidden = !open;
            list.querySelector('.cm-wall-list__addbtn').hidden = open;
            if (open && wasHidden) {
                form.querySelector('textarea').focus();
                form.scrollIntoView({ block: 'nearest' });
            }
        });

        const newList = this.boardTarget.querySelector('[data-wall-new-list]');
        if (newList) {
            const wasHidden = newList.hidden;
            newList.hidden = !this.addingList;
            this.boardTarget.querySelector('.cm-wall-newlist').hidden = this.addingList;
            if (this.addingList && wasHidden && null === this.addingListId) {
                newList.querySelector('input').focus();
                newList.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }
    }

    // --- View ----------------------------------------------------------------------------------

    // Whoever runs the wall changes its format for everybody; a participant only changes what
    // their own browser shows.
    setView(event) {
        const view = event.currentTarget.dataset.view;
        if (view === this.view) {
            return;
        }
        this.addingListId = null;
        this.addingList = false;
        this.view = view;
        if (this.managesValue) {
            this.request(this.settingsUrlValue, { body: { format: view } });
        } else {
            this.refresh();
        }
    }

    // --- Drag and drop -------------------------------------------------------------------------

    dragStart(event) {
        const card = event.target.closest?.('.cm-wall-card[draggable="true"]');
        if (!card) {
            return;
        }
        this.closeMenus();
        this.drag = { card, target: null };
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.cardId);
        // After the browser has taken its picture of the card, so the picture is not the faded one.
        setTimeout(() => card.classList.add('is-dragging'), 0);
    }

    dragOver(event) {
        if (!this.drag) {
            return;
        }
        const list = event.target.closest('.cm-wall-list[data-list-id]');
        if (!list) {
            return;
        }
        event.preventDefault();

        const over = event.target.closest('.cm-wall-card');
        let before = null;
        let marker = null;
        if (over && over !== this.drag.card) {
            const box = over.getBoundingClientRect();
            if (event.clientY < box.top + box.height / 2) {
                before = over;
                marker = { card: over, edge: 'before' };
            } else {
                before = this.nextCard(over);
                marker = { card: over, edge: 'after' };
            }
        } else if (!over) {
            const cards = [...list.querySelectorAll('.cm-wall-card')].filter((each) => each !== this.drag.card);
            if (cards.length > 0) {
                marker = { card: cards[cards.length - 1], edge: 'after' };
            }
        } else {
            before = this.nextCard(over);
        }

        this.clearDropMarks();
        list.classList.add('is-over');
        marker?.card.classList.add('before' === marker.edge ? 'is-drop-before' : 'is-drop-after');
        this.drag.target = { list, before: before === this.drag.card ? this.nextCard(before) : before };
    }

    drop(event) {
        if (!this.drag?.target) {
            return;
        }
        event.preventDefault();
        const { card, target } = this.drag;
        const holder = target.list.querySelector('.cm-wall-list__cards');

        // Moved on the page at once, then confirmed - or put back - by the server's answer.
        holder.insertBefore(card, target.before && target.before.parentElement === holder ? target.before : null);
        this.request(card.dataset.moveUrl, {
            body: { list: Number(target.list.dataset.listId), before: target.before ? Number(target.before.dataset.cardId) : null },
        });
        this.dragEnd();
    }

    dragEnd() {
        this.drag?.card.classList.remove('is-dragging');
        this.clearDropMarks();
        this.drag = null;
        this.catchUp();
    }

    nextCard(card) {
        let next = card.nextElementSibling;
        while (next && (!next.classList.contains('cm-wall-card') || next === this.drag?.card)) {
            next = next.nextElementSibling;
        }

        return next;
    }

    clearDropMarks() {
        this.boardTarget.querySelectorAll('.is-over, .is-drop-before, .is-drop-after').forEach((element) => {
            element.classList.remove('is-over', 'is-drop-before', 'is-drop-after');
        });
    }

    // --- The open card -------------------------------------------------------------------------

    openCard(event) {
        if (event.target.closest('button, a, input, textarea, form')) {
            return;
        }
        this.closeMenus();
        this.openCardId = event.currentTarget.dataset.cardId;
        this.refresh();
    }

    drawCard(html) {
        const dialog = this.cardDialogTarget;
        const scroll = dialog.scrollTop;
        dialog.innerHTML = html;
        if (!dialog.open) {
            dialog.showModal();
        }
        dialog.scrollTop = scroll;
        const title = dialog.querySelector('.cm-wall-open__title--edit');
        if (title) {
            title.style.height = `${title.scrollHeight}px`;
        }
    }

    closeCard() {
        if (this.cardDialogTarget.open) {
            this.cardDialogTarget.close();
        } else {
            this.cardClosed();
        }
    }

    cardClosed() {
        this.openCardId = null;
        this.cardDialogTarget.innerHTML = '';
        this.catchUp();
    }

    // A click on the dimmed page around a dialog closes it; a click inside does not.
    backdropClose(event) {
        if (event.target === event.currentTarget) {
            event.currentTarget.close();
        }
    }

    openEditor(event) {
        const name = event.params.editor;
        const dialog = this.cardDialogTarget;
        dialog.querySelectorAll('form[data-wall-editor]').forEach((form) => {
            form.hidden = form.dataset.wallEditor !== name;
        });
        dialog.querySelectorAll('[data-wall-shown]').forEach((block) => {
            block.hidden = block.dataset.wallShown === name;
        });

        const editor = dialog.querySelector(`[data-wall-editor="${name}"]`);
        editor.hidden = false;
        editor.querySelector('input:not([type="radio"]), textarea')?.focus();
        editor.scrollIntoView({ block: 'nearest' });
    }

    closeEditor(event) {
        const form = event.currentTarget.closest('form[data-wall-editor]');
        form.hidden = true;
        form.reset();
        this.cardDialogTarget.querySelectorAll('[data-wall-shown]').forEach((block) => {
            block.hidden = false;
        });
    }

    // --- Files: a picture, a file, the wall's ground --------------------------------------------

    // Bytes go to /uploads/stage like every upload of the platform; what the wall is then sent is
    // the signed token that answer hands back.
    pickFile(event) {
        const { kind, url } = event.params;
        this.pendingFile = { kind, url };
        this.fileInputTarget.accept = 'file' === kind ? '' : 'image/jpeg,image/png,image/webp';
        this.fileInputTarget.value = '';
        this.fileInputTarget.click();
    }

    async fileChosen() {
        const file = this.fileInputTarget.files[0];
        const pending = this.pendingFile;
        if (!file || !pending) {
            return;
        }

        this.flash(this.labels.uploading, { sticky: true });
        const payload = new FormData();
        payload.append('file', file);

        let staged = null;
        try {
            const response = await fetch(this.stageUrlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.stageCsrfValue }, body: payload });
            staged = await response.json();
            if (!response.ok) {
                this.flash(staged?.message ?? this.labels.error, { error: true });

                return;
            }
        } catch {
            this.flash(this.labels.error, { error: true });

            return;
        }

        const field = { file: 'file', image: 'image', background: 'backgroundImage' }[pending.kind];
        const state = await this.request(pending.url, { body: { [field]: staged.token } });
        if (state) {
            this.hideToast();
        }
    }

    // --- « Copier / Déplacer » -----------------------------------------------------------------

    async openTransfer(event) {
        const { kind, mode, title, url, modes } = event.params;
        this.closeMenus();
        this.transfer = { kind, mode, title, url };

        const source = new URL(this.destinationsUrlValue, window.location.origin);
        source.searchParams.set('kind', kind);
        const response = await fetch(source, { headers: { Accept: 'text/html' } });
        if (!response.ok) {
            this.flash(this.labels.error, { error: true });

            return;
        }
        this.transferBodyTarget.innerHTML = await response.text();

        // A participant may move their own card and never copy one: the switch offers what is theirs.
        const allowed = (modes ?? 'copy,move').split(',');
        this.transferModesTarget.hidden = allowed.length < 2;
        this.drawTransfer();
        this.transferDialogTarget.showModal();
    }

    setTransferMode(event) {
        this.transfer.mode = event.currentTarget.dataset.mode;
        this.drawTransfer();
    }

    drawTransfer() {
        const { kind, mode, title } = this.transfer;
        const key = `${mode}${'card' === kind ? 'Card' : 'List'}`;
        this.transferTitleTarget.textContent = this.labels[key].replace('__TITLE__', title);
        this.transferSubmitTarget.textContent = this.labels[mode];
        this.transferSubmitTarget.disabled = null === this.transferBodyTarget.querySelector('input[name="wall"]');
        this.transferModesTarget.querySelectorAll('button').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.mode === mode);
        });
    }

    pickDestination(event) {
        const wall = event.currentTarget.value;
        this.transferBodyTarget.querySelectorAll('[data-wall-lists-of]').forEach((group) => {
            group.hidden = group.dataset.wallListsOf !== wall;
        });
    }

    async confirmTransfer(event) {
        event.preventDefault();
        const wall = this.transferBodyTarget.querySelector('input[name="wall"]:checked')?.value;
        if (!wall) {
            return;
        }
        const body = { mode: this.transfer.mode, wall: Number(wall) };
        if ('card' === this.transfer.kind) {
            const list = this.transferBodyTarget.querySelector(`input[name="list-${wall}"]:checked`)?.value;
            if (!list) {
                return;
            }
            body.list = Number(list);
        }

        this.transferDialogTarget.close();
        await this.request(this.transfer.url, { body });
    }

    closeTransfer() {
        this.transferDialogTarget.close();
    }

    // --- « Partager » and « Paramètres » ---------------------------------------------------------

    openShare() {
        this.shareDialogTarget.showModal();
    }

    closeShare() {
        this.shareDialogTarget.close();
    }

    openSettings() {
        this.closeMenus();
        this.settingsDialogTarget.showModal();
    }

    closeSettings() {
        this.settingsDialogTarget.close();
    }

    setSwitch(event) {
        const input = event.currentTarget;
        this.request(this.settingsUrlValue, { body: { [input.dataset.setting]: input.checked } });
    }

    setGround(event) {
        this.request(this.settingsUrlValue, { body: { backgroundColor: event.currentTarget.dataset.ground } });
    }

    renameWall(event) {
        this.request(this.settingsUrlValue, { body: { title: event.currentTarget.value } });
    }

    // The panel follows the wall, not the other way round: a switch a colleague flips shows here.
    syncSettings(settings) {
        if (!settings) {
            return;
        }
        this.currentSettings = settings;

        this.switchTargets.forEach((input) => {
            input.checked = true === settings[input.dataset.setting];
        });
        if (this.hasSettingTitleTarget && document.activeElement !== this.settingTitleTarget) {
            this.settingTitleTarget.value = settings.title;
        }
        this.groundTargets.forEach((button) => {
            button.classList.toggle('is-current', null === settings.backgroundImage && button.dataset.ground === (settings.backgroundColor ?? ''));
        });
        if (this.hasGroundImageTarget) {
            this.groundImageTarget.classList.toggle('is-current', null !== settings.backgroundImage);
            this.groundImageTarget.style.backgroundImage = null === settings.backgroundImage ? '' : `url("${settings.backgroundImage}")`;
        }

        this.projectionTarget.classList.toggle('has-image', null !== settings.backgroundImage);
        this.projectionTarget.style.setProperty('--cm-wall-proj-image', null === settings.backgroundImage ? 'none' : `url("${settings.backgroundImage}")`);
    }

    // --- « Présenter » ---------------------------------------------------------------------------

    toggleFullscreen() {
        this.closeMenus();
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            this.element.requestFullscreen?.().catch(() => {});
        }
    }

    fullscreenChanged() {
        const full = document.fullscreenElement === this.element;
        this.exitFullscreenTarget.hidden = !full || !this.projectionTarget.hidden;
        this.fullscreenLabelTarget.textContent = full ? this.labels.exitFullscreen : this.labels.fullscreen;
        // Leaving the full screen with the browser's own key leaves the projection too.
        if (!full && !this.projectionTarget.hidden) {
            this.hideProjection();
        }
    }

    async enterProjection() {
        this.closeMenus();
        this.closeCard();
        const response = await fetch(this.projectionUrlValue, { headers: { Accept: 'text/html' } });
        if (!response.ok) {
            this.flash(this.labels.error, { error: true });

            return;
        }

        this.projectionTarget.innerHTML = await response.text();
        this.projectionTarget.hidden = false;
        this.projectionEnteredFullscreen = !document.fullscreenElement;
        if (this.projectionEnteredFullscreen) {
            await this.element.requestFullscreen?.().catch(() => {});
        }
        this.exitFullscreenTarget.hidden = true;
        this.showSlide(0);
        this.projectionTarget.focus();
    }

    exitProjection() {
        const leaveFullscreen = this.projectionEnteredFullscreen && document.fullscreenElement;
        this.hideProjection();
        if (leaveFullscreen) {
            document.exitFullscreen();
        } else {
            this.fullscreenChanged();
        }
    }

    hideProjection() {
        this.projectionTarget.hidden = true;
        this.projectionTarget.innerHTML = '';
        this.catchUp();
    }

    showSlide(index) {
        const slides = this.slideTargets;
        if (0 === slides.length) {
            this.projectionPreviousTarget.disabled = true;
            this.projectionNextTarget.disabled = true;

            return;
        }
        this.slideIndex = Math.max(0, Math.min(slides.length - 1, index));
        slides.forEach((slide, position) => {
            slide.hidden = position !== this.slideIndex;
        });
        this.projectionListTarget.textContent = slides[this.slideIndex].dataset.list;
        this.projectionCountTarget.textContent = this.labels.count.replace('__CURRENT__', this.slideIndex + 1).replace('__TOTAL__', slides.length);
        this.projectionPreviousTarget.disabled = 0 === this.slideIndex;
        this.projectionNextTarget.disabled = slides.length - 1 === this.slideIndex;
    }

    previousSlide() {
        this.showSlide(this.slideIndex - 1);
    }

    nextSlide() {
        this.showSlide(this.slideIndex + 1);
    }

    projectionKey(event) {
        if ('ArrowRight' === event.key || ' ' === event.key) {
            event.preventDefault();
            this.nextSlide();
        } else if ('ArrowLeft' === event.key) {
            event.preventDefault();
            this.previousSlide();
        } else if ('Escape' === event.key) {
            event.preventDefault();
            this.exitProjection();
        }
    }

    // --- Following the others --------------------------------------------------------------------

    // Mercure over the bundle's cookie mechanism, like the word cloud. The message only says the
    // wall has moved; what this person may read of it is asked for like any other reading.
    listen() {
        if (!this.mercureUrlValue || !this.topicValue) {
            return;
        }
        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);
        this.source = new EventSource(url, { withCredentials: true });
        this.source.onmessage = (event) => {
            const message = JSON.parse(event.data);
            if (message.deleted) {
                window.location.assign(this.indexUrlValue);
            } else {
                this.announced = Math.max(this.announced, message.revision);
                this.catchUp();
            }
        };
    }

    // Asks for the wall again when a revision was announced that this browser has not drawn.
    //
    // Not while one of its own requests is out: the hub is told before the answer leaves, so the
    // announcement of one's own gesture arrives first, and the answer on its way already carries
    // that revision. And not under somebody's hands: a redraw would take what they are typing or
    // dragging, so it waits for them to be done - request(), the end of a drag, a field left and a
    // dialog closed all come back here.
    catchUp() {
        if (this.announced <= this.revision || this.inFlight > 0 || this.busy()) {
            return;
        }
        this.refresh();
    }

    busy() {
        if (this.drag || !this.projectionTarget.hidden) {
            return true;
        }
        const active = document.activeElement;

        return null !== active && this.element.contains(active) && active.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select');
    }

    // --- Toast and scroll --------------------------------------------------------------------------

    flash(message, { url = null, error = false, sticky = false } = {}) {
        clearTimeout(this.toastTimer);
        this.toastMessageTarget.textContent = message;
        this.toastTarget.classList.toggle('is-error', error);
        this.toastLinkTarget.hidden = null === url;
        if (null !== url) {
            this.toastLinkTarget.href = url;
        }
        // Shown again rather than left shown: a popover joins the top layer when it opens, so
        // reopening it is what puts it above a dialog opened since.
        this.hideToast();
        this.toastTarget.showPopover?.();
        if (!sticky) {
            this.toastTimer = setTimeout(() => this.hideToast(), error ? 6000 : 3800);
        }
    }

    hideToast() {
        if (this.toastTarget.matches(':popover-open')) {
            this.toastTarget.hidePopover();
        }
    }

    scrollOf() {
        const columns = this.boardTarget.querySelector('.cm-wall-cols, .cm-wall-gridscroll');
        const lists = {};
        this.boardTarget.querySelectorAll('.cm-wall-list[data-list-id]').forEach((list) => {
            lists[list.dataset.listId] = list.querySelector('.cm-wall-list__cards').scrollTop;
        });

        return { left: columns?.scrollLeft ?? 0, top: columns?.scrollTop ?? 0, lists };
    }

    restoreScroll(scroll) {
        const columns = this.boardTarget.querySelector('.cm-wall-cols, .cm-wall-gridscroll');
        if (columns) {
            columns.scrollLeft = scroll.left;
            columns.scrollTop = scroll.top;
        }
        this.boardTarget.querySelectorAll('.cm-wall-list[data-list-id]').forEach((list) => {
            list.querySelector('.cm-wall-list__cards').scrollTop = scroll.lists[list.dataset.listId] ?? 0;
        });
    }
}
