import { Controller } from '@hotwired/stimulus';

// The "Outils > Tirage au sort" classroom roulette (design/design_campus_manager/reference/
// Tirage au sort.dc.html) - picks a random student, optionally filtered by Option and with/
// without replacement, with a decelerating slot-machine animation and a confetti winner overlay.
// Ported from the créa's React state machine into plain DOM/Stimulus since this app has no client
// framework - state lives on `this` instead of React state, and each mutation re-renders only the
// specific DOM bits that changed instead of a full re-render.
//
// On top of that, « Tirages enregistrés » (design/design_handoff_choix_aleatoire_saved): a draw can
// be saved under a name and picked up later with its Option, its replacement setting and the
// ordered list of students already called. Everything about a saved draw lives on the server
// (App\Entity\RandomDraw) and nothing in localStorage - a teacher changes room between two lessons,
// and the memory of who has already been called has to follow them, not the machine.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'card', 'slot', 'optionSelect', 'nameFormatSelect', 'repeatSwitch', 'remaining', 'fsRemaining',
        'winnerOverlay', 'winnerName', 'confetti',
        'savesBar', 'savesChips', 'drawnBlock', 'drawnChips', 'autosaveNotice', 'autosaveText',
        'saveLine', 'nameInput', 'saveButton', 'closeButton', 'toast',
    ];

    static values = {
        students: Array,
        labels: Object,
        draws: Array,
        saveUrl: String,
        renameUrl: String,
        updateUrl: String,
        deleteUrl: String,
        csrfToken: String,
        durationSeconds: { type: Number, default: 4 },
    };

    connect() {
        this.allowRepeat = false;
        // An ordered list rather than a Set: « Déjà tirés » shows the students in the order they
        // were called, and that order is half of what a saved draw is worth.
        this.drawnIds = [];
        this.spinning = false;
        this.winner = null;
        this.optionFilter = 'all';
        // Shortened names by default: read across a classroom, "Célia L." carries further than
        // "Célia Larousse", and a class knows its own first names.
        this.nameFormat = 'short';
        this.spinTimeout = null;

        // A copy: the Stimulus Array value re-parses its attribute on every access, so mutating
        // what it hands back would change nothing.
        this.draws = this.drawsValue.map((draw) => ({ ...draw }));
        this.currentDrawId = null;
        this.editingDrawId = null;

        // The trail is rendered by templates/_breadcrumb.html.twig, outside this controller's
        // element - resuming a draw never reloads the page, so the fifth segment (« … › Tirage au
        // sort › Oral anglais ») can only be written from here. There is exactly one breadcrumb
        // per screen, and the link carrying .current on arrival is the one the draw hangs off.
        this.breadcrumbNav = document.querySelector('.cm-breadcrumb');
        this.breadcrumbBaseLink = this.breadcrumbNav ? this.breadcrumbNav.querySelector('a.current') : null;
        this.breadcrumbExtra = [];

        this.onFullscreenChange = () => {
            if (!document.fullscreenElement) {
                this.cardTarget.classList.remove('cm-draw-card--fs');
            }
        };
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        this.renderSlot();
        this.renderRemaining();
        this.renderSavesBar();
        this.renderDrawnBlock();
        this.renderSaveLine();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        clearTimeout(this.spinTimeout);
        clearTimeout(this._toastTimeout);
        this.clearBreadcrumbSegment();
    }

    // this.optionFilter/this.allowRepeat/this.drawnIds are plain instance fields, not Stimulus
    // `static values` - they change many times per second during the spin animation, and going
    // through setXxxValue()'s attribute write (plus its xxxValueChanged() callback) on every tick
    // would be wasteful for state that's purely internal to this controller.
    // Students, not names: the pool has to survive a change of name format mid-draw, and two
    // classmates can perfectly well both shorten to "Célia L." - already-drawn is tracked by id.
    get pool() {
        const students = this.scopedStudents(this.optionFilter);

        return this.allowRepeat ? students : students.filter((student) => !this.drawnIds.includes(student.id));
    }

    scopedStudents(optionFilter) {
        return optionFilter === 'all' || optionFilter === null
            ? this.studentsValue
            : this.studentsValue.filter((student) => student.optionIds.includes(Number(optionFilter)));
    }

    label(student) {
        return (this.nameFormat === 'full' ? student.name : student.shortName) || student.name;
    }

    setNameFormat(event) {
        this.nameFormat = event.target.value;

        // A winner already on screen is rewritten in place rather than cleared: the toggle changes
        // how a name is written, not who was drawn.
        if (this.winner) {
            this.slotTarget.textContent = this.label(this.winner);
            this.winnerNameTarget.textContent = this.label(this.winner);
        }
        this.renderDrawnBlock();
    }

    setOption(event) {
        if (this.spinning) {
            return;
        }
        this.optionFilter = event.target.value;
        this.winner = null;
        this.renderSlot();
        this.renderRemaining();
        this.syncCurrentDraw();
    }

    toggleRepeat() {
        if (this.spinning) {
            return;
        }
        this.allowRepeat = !this.allowRepeat;
        this.repeatSwitchTarget.classList.toggle('cm-draw-switch--on', this.allowRepeat);
        this.renderRemaining();
        this.renderDrawnBlock();
        this.syncCurrentDraw();
    }

    reset() {
        if (this.spinning) {
            return;
        }
        this.drawnIds = [];
        this.winner = null;
        this.renderSlot();
        this.renderRemaining();
        this.renderDrawnBlock();
        this.syncCurrentDraw();
    }

    toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
            return;
        }
        this.cardTarget.classList.add('cm-draw-card--fs');
        if (this.cardTarget.requestFullscreen) {
            this.cardTarget.requestFullscreen().catch(() => this.cardTarget.classList.remove('cm-draw-card--fs'));
        }
    }

    draw() {
        if (this.spinning || this.winner) {
            return;
        }
        const pool = this.pool;
        if (!pool.length) {
            return;
        }

        const winner = pool[Math.floor(Math.random() * pool.length)];
        this.spinning = true;

        const total = this.durationSecondsValue * 750;
        let elapsed = 0;
        let delay = 55;
        const tick = () => {
            if (elapsed >= total) {
                this.slotTarget.textContent = this.label(winner);
                this.spinTimeout = setTimeout(() => this.finish(winner), 400);
                return;
            }
            this.slotTarget.textContent = this.label(pool[Math.floor(Math.random() * pool.length)]);
            delay *= 1.07;
            elapsed += delay;
            this.spinTimeout = setTimeout(tick, delay);
        };
        this.slotTarget.classList.add('cm-draw-slot--spinning');
        tick();
    }

    finish(winner) {
        if (!this.drawnIds.includes(winner.id)) {
            this.drawnIds.push(winner.id);
        }
        this.spinning = false;
        this.winner = winner;
        this.slotTarget.classList.remove('cm-draw-slot--spinning');
        this.renderRemaining();
        this.renderDrawnBlock();
        this.syncCurrentDraw();
        this.showWinnerOverlay(winner);
    }

    showWinnerOverlay(winner) {
        this.winnerNameTarget.textContent = this.label(winner);
        this.buildConfetti();
        this.winnerOverlayTarget.hidden = false;
    }

    closeWinner() {
        this.winnerOverlayTarget.hidden = true;
        this.winner = null;
        this.renderSlot();
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    }

    buildConfetti() {
        const colors = ['#c9a04e', '#1B6BA8', '#ffffff', '#7d99b0'];
        const pieces = document.createDocumentFragment();
        for (let i = 0; i < 28; i += 1) {
            const piece = document.createElement('div');
            piece.className = 'cm-draw-confetti-piece';
            piece.style.left = `${(i * 3.6) + 1}%`;
            piece.style.background = colors[i % colors.length];
            piece.style.animationDuration = `${2.4 + (i % 5) * 0.5}s`;
            piece.style.animationDelay = `${(i % 7) * 0.35}s`;
            piece.style.transform = `rotate(${i * 37}deg)`;
            pieces.appendChild(piece);
        }
        this.confettiTarget.replaceChildren(pieces);
    }

    renderSlot() {
        this.slotTarget.classList.remove('cm-draw-slot--spinning');
        this.slotTarget.textContent = this.pool.length ? this.labelsValue.ready : this.labelsValue.allDrawn;
    }

    renderRemaining() {
        const pool = this.pool;
        const total = this.studentsValue.length;
        const done = this.drawnIds.length;
        let text;

        if (this.allowRepeat) {
            text = this.labelsValue.remainingWithRepeat.replace('%total%', total);
        } else if (!pool.length) {
            text = this.labelsValue.remainingAllDrawn;
        } else {
            const studentWord = pool.length > 1 ? this.labelsValue.studentWordPlural : this.labelsValue.studentWordSingular;
            const drawnWord = done > 1 ? this.labelsValue.drawnWordPlural : this.labelsValue.drawnWordSingular;
            text = this.labelsValue.remainingPoolTemplate
                .replace('%count%', pool.length)
                .replace('%studentWord%', studentWord)
                .replace('%done%', done)
                .replace('%drawnWord%', drawnWord);
        }

        this.remainingTarget.textContent = text;
        this.fsRemainingTarget.textContent = text;
    }

    // ---------- Tirages enregistrés ----------

    get currentDraw() {
        return this.draws.find((draw) => draw.id === this.currentDrawId) ?? null;
    }

    student(studentId) {
        return this.studentsValue.find((candidate) => candidate.id === studentId) ?? null;
    }

    optionLabel(optionId) {
        if (optionId === null || optionId === undefined || !this.hasOptionSelectTarget) {
            return this.labelsValue.allStudentsOption;
        }

        const entry = Array.from(this.optionSelectTarget.options).find((candidate) => candidate.value === String(optionId));

        return entry ? entry.textContent : this.labelsValue.allStudentsOption;
    }

    drawMeta(draw) {
        const option = this.optionLabel(draw.optionId);
        if (draw.allowRepeat) {
            return `${option} · ${this.labelsValue.drawMetaWithRepeat}`;
        }

        return `${option} · ${this.labelsValue.drawMetaTemplate
            .replace('%done%', draw.drawnIds.length)
            .replace('%total%', this.scopedStudents(draw.optionId ?? 'all').length)}`;
    }

    renderSavesBar() {
        this.savesBarTarget.hidden = this.draws.length === 0;
        this.savesChipsTarget.replaceChildren();

        for (const draw of this.draws) {
            this.savesChipsTarget.appendChild(
                this.editingDrawId === draw.id ? this.buildEditingChip(draw) : this.buildChip(draw),
            );
        }
    }

    buildChip(draw) {
        const chip = document.createElement('span');
        chip.className = 'cm-draw-save-chip';
        chip.classList.toggle('is-active', draw.id === this.currentDrawId);

        const load = document.createElement('button');
        load.type = 'button';
        load.className = 'cm-draw-save-chip__load';
        load.title = this.labelsValue.loadDrawTitle;
        const name = document.createElement('span');
        name.className = 'cm-draw-save-chip__name';
        name.textContent = draw.name;
        const meta = document.createElement('span');
        meta.className = 'cm-draw-save-chip__meta';
        meta.textContent = this.drawMeta(draw);
        load.append(name, meta);
        load.addEventListener('click', () => this.loadDraw(draw));
        chip.appendChild(load);

        chip.appendChild(this.buildIconButton(
            'cm-draw-save-chip__icon',
            this.labelsValue.renameDrawTitle,
            draw.name,
            '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path>',
            () => this.startRename(draw),
        ));
        chip.appendChild(this.buildIconButton(
            'cm-draw-save-chip__icon cm-draw-save-chip__icon--danger',
            this.labelsValue.deleteDrawTitle,
            draw.name,
            '<path d="M3 6h18"></path><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path>',
            () => this.deleteDraw(draw),
        ));

        return chip;
    }

    // The tooltip says what the icon does, the aria-label says what it does *to* - « Renommer ce
    // tirage » read out four times in a row tells a screen-reader user nothing about which chip
    // they are on.
    buildIconButton(className, title, drawName, paths, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.title = title;
        button.setAttribute('aria-label', `${title} : ${drawName}`);
        button.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
        button.addEventListener('click', onClick);

        return button;
    }

    buildEditingChip(draw) {
        const chip = document.createElement('span');
        chip.className = 'cm-draw-save-chip cm-draw-save-chip--editing';
        // Renaming does not suspend « this is the draw you are on »: the gold stays while the
        // field is open, or the banner would look as though the draw had been closed.
        chip.classList.toggle('is-active', draw.id === this.currentDrawId);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'cm-draw-save-chip__input';
        input.value = this.editingName ?? draw.name;
        input.setAttribute('aria-label', this.labelsValue.renameFieldLabel);
        input.addEventListener('input', () => { this.editingName = input.value; });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.confirmRename(draw);
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.cancelRename();
            }
        });
        chip.appendChild(input);

        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'cm-draw-save-chip__ok';
        ok.textContent = this.labelsValue.renameConfirmLabel;
        ok.addEventListener('click', () => this.confirmRename(draw));
        chip.appendChild(ok);

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'cm-draw-save-chip__cancel';
        cancel.textContent = this.labelsValue.renameCancelLabel;
        cancel.addEventListener('click', () => this.cancelRename());
        chip.appendChild(cancel);

        return chip;
    }

    renderDrawnBlock() {
        const visible = !this.allowRepeat && this.drawnIds.length > 0;
        this.drawnBlockTarget.hidden = !visible;
        if (!visible) {
            this.drawnChipsTarget.replaceChildren();

            return;
        }

        const chips = document.createDocumentFragment();
        for (const studentId of this.drawnIds) {
            const student = this.student(studentId);
            if (!student) {
                continue;
            }
            const chip = document.createElement('span');
            chip.className = 'cm-draw-drawn__chip';
            chip.textContent = this.label(student);
            chips.appendChild(chip);
        }
        this.drawnChipsTarget.replaceChildren(chips);
    }

    renderSaveLine() {
        const draw = this.currentDraw;

        this.autosaveNoticeTarget.hidden = draw === null;
        if (draw) {
            this.autosaveTextTarget.textContent = this.labelsValue.autosaveTemplate.replace('%name%', draw.name);
        }

        this.saveButtonTarget.textContent = draw ? this.labelsValue.saveDrawAsNewButton : this.labelsValue.saveDrawButton;
        this.closeButtonTarget.hidden = draw === null;
        // The rule above the save row moves to the autosave line as soon as there is one: two rules
        // in a row would read as an empty band.
        this.saveLineTarget.classList.toggle('cm-draw-saveline--attached', draw !== null);
    }

    renderBreadcrumbSegment() {
        if (!this.breadcrumbNav || !this.breadcrumbBaseLink) {
            return;
        }

        this.clearBreadcrumbSegment();

        const draw = this.currentDraw;
        if (!draw) {
            return;
        }

        this.breadcrumbBaseLink.classList.remove('current');
        const separator = document.createElement('span');
        separator.className = 'sep';
        separator.setAttribute('aria-hidden', 'true');
        separator.textContent = '›';
        const link = document.createElement('a');
        link.className = 'current';
        link.href = window.location.href;
        link.textContent = draw.name;
        this.breadcrumbNav.append(separator, link);
        this.breadcrumbExtra = [separator, link];
    }

    clearBreadcrumbSegment() {
        this.breadcrumbExtra.forEach((node) => node.remove());
        this.breadcrumbExtra = [];
        if (this.breadcrumbBaseLink) {
            this.breadcrumbBaseLink.classList.add('current');
        }
    }

    // ---------- Reprise, enregistrement, renommage, suppression ----------

    loadDraw(draw) {
        if (this.spinning) {
            return;
        }

        this.optionFilter = draw.optionId === null || draw.optionId === undefined ? 'all' : String(draw.optionId);
        if (this.hasOptionSelectTarget) {
            this.optionSelectTarget.value = this.optionFilter;
            // An Option deleted from the structure since the draw was saved leaves the select with
            // nothing to select: the pool widens back to the whole class rather than emptying.
            if (this.optionSelectTarget.selectedIndex === -1) {
                this.optionSelectTarget.value = 'all';
                this.optionFilter = 'all';
            }
        }

        this.allowRepeat = draw.allowRepeat;
        this.repeatSwitchTarget.classList.toggle('cm-draw-switch--on', this.allowRepeat);
        // A student who left the class between two lessons is dropped without a word - the server
        // filters too, this is the same rule applied to what is already on screen.
        this.drawnIds = draw.drawnIds.filter((studentId) => this.student(studentId) !== null);
        this.winner = null;
        this.currentDrawId = draw.id;
        this.nameInputTarget.value = '';

        this.renderSlot();
        this.renderRemaining();
        this.renderDrawnBlock();
        this.renderSavesBar();
        this.renderSaveLine();
        this.renderBreadcrumbSegment();
        this.showToast(this.labelsValue.drawResumedToast.replace('%name%', draw.name));
    }

    // « Fermer le tirage » - the saved row is let go of, what is on screen is not. The teacher goes
    // on drawing from where they are; only the autosave stops.
    closeDraw() {
        this.currentDrawId = null;
        this.nameInputTarget.value = '';
        this.renderSavesBar();
        this.renderSaveLine();
        this.renderBreadcrumbSegment();
    }

    async saveDraw() {
        const response = await this.post(this.saveUrlValue, {
            name: this.nameInputTarget.value.trim(),
            ...this.state(),
        });
        if (!response) {
            return;
        }

        this.draws.push(response);
        this.currentDrawId = response.id;
        this.nameInputTarget.value = '';
        this.renderSavesBar();
        this.renderSaveLine();
        this.renderBreadcrumbSegment();
        this.showToast(this.labelsValue.drawSavedToast.replace('%name%', response.name));
    }

    startRename(draw) {
        this.editingDrawId = draw.id;
        this.editingName = draw.name;
        this.renderSavesBar();
        const input = this.savesChipsTarget.querySelector('.cm-draw-save-chip__input');
        if (input) {
            input.focus();
            input.select();
        }
    }

    cancelRename() {
        this.editingDrawId = null;
        this.editingName = null;
        this.renderSavesBar();
    }

    async confirmRename(draw) {
        const name = (this.editingName ?? '').trim();
        // An empty field or the name it already carries: nothing was asked for, so nothing is said.
        if (name === '' || name === draw.name) {
            this.cancelRename();

            return;
        }

        const response = await this.post(this.renameUrlValue.replace('__DRAW_ID__', String(draw.id)), { name }, true);
        if (!response) {
            return;
        }

        draw.name = response.name;
        this.editingDrawId = null;
        this.editingName = null;
        this.renderSavesBar();
        this.renderSaveLine();
        this.renderBreadcrumbSegment();
        this.showToast(this.labelsValue.drawRenamedToast.replace('%name%', response.name));
    }

    async deleteDraw(draw) {
        const response = await this.post(this.deleteUrlValue.replace('__DRAW_ID__', String(draw.id)), {});
        if (!response) {
            return;
        }

        this.draws = this.draws.filter((candidate) => candidate.id !== draw.id);
        // Deleting the open draw closes it and nothing more: the pool, the setting and who has
        // already been called stay on screen, they have simply stopped being written down.
        if (this.currentDrawId === draw.id) {
            this.currentDrawId = null;
        }
        this.renderSavesBar();
        this.renderSaveLine();
        this.renderBreadcrumbSegment();
        this.showToast(this.labelsValue.drawDeletedToast.replace('%name%', draw.name));
    }

    // The autosave: every draw, reset, option change and replacement toggle, immediately. Nothing
    // is queued and nothing is debounced - a lesson ends when it ends, and the state that reaches
    // the next one has to be the last gesture made, not the last one a timer got round to.
    async syncCurrentDraw() {
        const draw = this.currentDraw;
        if (!draw) {
            return;
        }

        const response = await this.post(this.updateUrlValue.replace('__DRAW_ID__', String(draw.id)), this.state());
        if (!response) {
            return;
        }

        Object.assign(draw, response);
        this.renderSavesBar();
    }

    /** The state of the tool as a saved draw records it. */
    state() {
        return {
            optionId: this.optionFilter === 'all' ? null : Number(this.optionFilter),
            allowRepeat: this.allowRepeat,
            drawnIds: this.drawnIds,
        };
    }

    async post(url, body, reportDuplicateName = false) {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify(body),
            });
        } catch (e) {
            this.showToast(this.labelsValue.networkErrorMessage);

            return null;
        }

        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.error) {
            // The one error the teacher can act on: the field stays open so they can type another
            // name, instead of being closed under a message about the network.
            this.showToast(reportDuplicateName && data?.error === 'duplicate_name'
                ? this.labelsValue.drawDuplicateNameToast
                : this.labelsValue.networkErrorMessage);

            return null;
        }

        return data;
    }

    showToast(message) {
        this.toastTarget.textContent = message;
        this.toastTarget.hidden = false;
        clearTimeout(this._toastTimeout);
        this._toastTimeout = setTimeout(() => { this.toastTarget.hidden = true; }, 2600);
    }
}
