import { Controller } from '@hotwired/stimulus';

// The "Outils > Création de groupes" tool (design/design_campus_manager/
// PROMPT_CLAUDE_CODE_groupes.md) - repartition params + absentees + separate/together
// constraints drive a server-side placement (App\Service\GroupCreationService, reached via
// generateUrlValue) whose result is rendered here as draggable/lockable group cards. State lives
// on `this` (same convention as random_draw_controller.js), mutated by each action and re-drawn
// through the specific render*() a change actually affects, never a full teardown/rebuild.
export default class extends Controller {
    static targets = [
        'stepperValue', 'stepperUnit', 'modeSizeBtn', 'modeCountBtn', 'optionSelect',
        'absentInput', 'absentSuggestions', 'absentTags',
        'mixiteFreeBtn', 'mixiteMixedBtn', 'mixiteHomoBtn',
        'pairASelect', 'pairBSelect', 'pairChips',
        'error', 'reshuffleBtn', 'summary',
        'toolbar', 'lotNameInput', 'grid', 'dndHint', 'emptyState',
        'lotsBar', 'lotsChips',
        'fullscreen', 'fullscreenTitle', 'fullscreenGrid',
        'confirmModal', 'confirmModalBody', 'toast',
        'pdfForm', 'pdfFormGroups', 'pdfFormLotName',
        'messageForm', 'messageFormGroups', 'messageFormLotName',
    ];

    static values = {
        students: Array,
        options: Array,
        lots: Array,
        generateUrl: String,
        saveLotUrl: String,
        deleteLotUrl: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.gMode = 'taille';
        this.gValue = 3;
        this.gOption = 'all';
        this.absentIds = new Set();
        this.mixite = 'libre';
        this.pairsSep = [];
        this.pairsTog = [];
        this.groups = null;
        this.lockedIndices = new Set();
        this.dragId = null;
        this.lots = [...this.lotsValue];
        this.pendingDeleteLotId = null;

        this.optionsById = new Map(this.optionsValue.map((option) => [option.id, option]));

        this.onFullscreenChange = () => {
            if (!document.fullscreenElement) {
                this.fullscreenTarget.hidden = true;
            }
        };
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        this.renderStepperLimits();
        this.renderStepper();
        this.renderAbsentTags();
        this.renderPairOptions();
        this.renderPairChips();
        this.renderSummary();
        this.renderGroups();
        this.renderLotsBar();
        this.setActiveSegment(this.modeSizeBtnTarget, true);
        this.setActiveSegment(this.modeCountBtnTarget, false);
        this.setActiveSegment(this.mixiteFreeBtnTarget, true);
        this.setActiveSegment(this.mixiteMixedBtnTarget, false);
        this.setActiveSegment(this.mixiteHomoBtnTarget, false);
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        clearTimeout(this._toastTimeout);
    }

    // ---------- Répartition ----------

    setModeSize() {
        this.gMode = 'taille';
        this.gValue = Math.min(Math.max(this.gValue, 2), 8);
        this.setActiveSegment(this.modeSizeBtnTarget, true);
        this.setActiveSegment(this.modeCountBtnTarget, false);
        this.renderStepperLimits();
        this.renderStepper();
    }

    setModeCount() {
        this.gMode = 'nombre';
        this.gValue = Math.min(Math.max(this.gValue, 2), 10);
        this.setActiveSegment(this.modeSizeBtnTarget, false);
        this.setActiveSegment(this.modeCountBtnTarget, true);
        this.renderStepperLimits();
        this.renderStepper();
    }

    incrementValue() {
        const max = this.gMode === 'taille' ? 8 : 10;
        this.gValue = Math.min(max, this.gValue + 1);
        this.renderStepper();
    }

    decrementValue() {
        this.gValue = Math.max(2, this.gValue - 1);
        this.renderStepper();
    }

    setOption(event) {
        this.gOption = event.target.value;
        this.renderPairOptions();
        this.renderSummary();
    }

    // ---------- Absents ----------

    scopedStudents() {
        return this.studentsValue.filter((student) => this.gOption === 'all' || student.optionIds.includes(Number(this.gOption)));
    }

    availableStudents() {
        return this.scopedStudents().filter((student) => !this.absentIds.has(student.id));
    }

    searchAbsent() {
        const query = this.absentInputTarget.value.trim().toLowerCase();
        this.absentSuggestionsTarget.replaceChildren();

        if (!query) {
            this.absentSuggestionsTarget.hidden = true;

            return;
        }

        const matches = this.scopedStudents()
            .filter((student) => !this.absentIds.has(student.id) && student.name.toLowerCase().includes(query))
            .slice(0, 6);

        this.absentSuggestionsTarget.hidden = matches.length === 0;
        for (const student of matches) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cm-grp-suggestion';
            button.textContent = student.name;
            button.addEventListener('click', () => {
                this.absentIds.add(student.id);
                this.absentInputTarget.value = '';
                this.absentSuggestionsTarget.replaceChildren();
                this.absentSuggestionsTarget.hidden = true;
                this.renderAbsentTags();
                this.renderPairOptions();
                this.renderSummary();
            });
            this.absentSuggestionsTarget.appendChild(button);
        }
    }

    renderAbsentTags() {
        this.absentTagsTarget.replaceChildren();
        for (const id of this.absentIds) {
            const student = this.studentsValue.find((s) => s.id === id);
            if (!student) continue;

            const tag = document.createElement('span');
            tag.className = 'cm-grp-tag';
            tag.textContent = student.name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '✕';
            remove.addEventListener('click', () => {
                this.absentIds.delete(id);
                this.renderAbsentTags();
                this.renderPairOptions();
                this.renderSummary();
            });
            tag.appendChild(remove);
            this.absentTagsTarget.appendChild(tag);
        }
    }

    // ---------- Contraintes ----------

    setMixiteFree() {
        this.mixite = 'libre';
        this.setActiveSegment(this.mixiteFreeBtnTarget, true);
        this.setActiveSegment(this.mixiteMixedBtnTarget, false);
        this.setActiveSegment(this.mixiteHomoBtnTarget, false);
    }

    setMixiteMixed() {
        this.mixite = 'mixte';
        this.setActiveSegment(this.mixiteFreeBtnTarget, false);
        this.setActiveSegment(this.mixiteMixedBtnTarget, true);
        this.setActiveSegment(this.mixiteHomoBtnTarget, false);
    }

    setMixiteHomo() {
        this.mixite = 'homogene';
        this.setActiveSegment(this.mixiteFreeBtnTarget, false);
        this.setActiveSegment(this.mixiteMixedBtnTarget, false);
        this.setActiveSegment(this.mixiteHomoBtnTarget, true);
    }

    renderPairOptions() {
        const names = this.availableStudents();
        for (const select of [this.pairASelectTarget, this.pairBSelectTarget]) {
            const current = select.value;
            const placeholder = select.options[0];
            select.replaceChildren(placeholder);
            for (const student of names) {
                const option = document.createElement('option');
                option.value = String(student.id);
                option.textContent = student.name;
                select.appendChild(option);
            }
            select.value = names.some((s) => String(s.id) === current) ? current : '';
        }
    }

    addSeparatePair() {
        this.addPair(this.pairsSep);
    }

    addTogetherPair() {
        this.addPair(this.pairsTog);
    }

    addPair(list) {
        const a = Number(this.pairASelectTarget.value);
        const b = Number(this.pairBSelectTarget.value);
        if (!a || !b || a === b) {
            return;
        }
        if (list.some(([x, y]) => (x === a && y === b) || (x === b && y === a))) {
            return;
        }
        list.push([a, b]);
        this.pairASelectTarget.value = '';
        this.pairBSelectTarget.value = '';
        this.renderPairChips();
    }

    renderPairChips() {
        this.pairChipsTarget.replaceChildren();
        const nameOf = (id) => this.studentsValue.find((s) => s.id === id)?.name.split(' ')[0] ?? '?';

        const addChip = (pair, index, list, symbol) => {
            const chip = document.createElement('span');
            chip.className = 'cm-grp-pair-chip';
            chip.textContent = `${nameOf(pair[0])} ${symbol} ${nameOf(pair[1])}`;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '✕';
            remove.addEventListener('click', () => {
                list.splice(index, 1);
                this.renderPairChips();
            });
            chip.appendChild(remove);
            this.pairChipsTarget.appendChild(chip);
        };

        this.pairsSep.forEach((pair, index) => addChip(pair, index, this.pairsSep, '✕'));
        this.pairsTog.forEach((pair, index) => addChip(pair, index, this.pairsTog, '+'));
    }

    // ---------- Stepper / segmented rendering ----------

    renderStepperLimits() {
        this.stepperUnitTarget.textContent = this.gMode === 'taille' ? this.labelsValue.perGroupUnit : this.labelsValue.groupsUnit;
    }

    renderStepper() {
        this.stepperValueTarget.textContent = this.gValue;
    }

    setActiveSegment(element, active) {
        element.classList.toggle('is-active', active);
    }

    // ---------- Create / reshuffle ----------

    renderSummary() {
        const poolCount = this.availableStudents().length;
        const groupCount = this.gMode === 'nombre' ? this.gValue : Math.max(1, Math.ceil(poolCount / this.gValue));
        this.summaryTarget.textContent = this.labelsValue.summaryTemplate
            .replace('%count%', poolCount)
            .replace('%studentWord%', poolCount > 1 ? this.labelsValue.studentWordPlural : this.labelsValue.studentWordSingular)
            .replace('%groups%', groupCount)
            .replace('%groupWord%', groupCount > 1 ? this.labelsValue.groupWordPlural : this.labelsValue.groupWordSingular);
    }

    async createGroups() {
        await this.runGenerate(false);
    }

    async reshuffleGroups() {
        await this.runGenerate(true);
    }

    async runGenerate(rebrasser) {
        this.renderError(null);

        const payload = {
            mode: this.gMode,
            value: this.gValue,
            option: this.gOption,
            absentIds: [...this.absentIds],
            mixite: this.mixite,
            separatePairs: this.pairsSep,
            togetherPairs: this.pairsTog,
            rebrasser,
        };

        if (rebrasser && this.groups) {
            payload.existingGroups = this.groups.map((group) => group.map((s) => s.id));
            payload.lockedIndices = [...this.lockedIndices];
        }

        let response;
        try {
            response = await fetch(this.generateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify(payload),
            });
        } catch (e) {
            this.renderError(this.labelsValue.networkErrorMessage);

            return;
        }

        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.error) {
            this.renderError(data?.error ?? this.labelsValue.networkErrorMessage);

            return;
        }

        this.groups = data.groups;
        if (!rebrasser) {
            this.lockedIndices = new Set();
        }
        this.renderGroups();
        this.renderSummary();
    }

    renderError(message) {
        this.errorTarget.hidden = !message;
        this.errorTarget.textContent = message ?? '';
    }

    // ---------- Groups grid ----------

    renderGroups() {
        const hasGroups = Array.isArray(this.groups) && this.groups.length > 0;
        this.emptyStateTarget.hidden = hasGroups;
        this.toolbarTarget.hidden = !hasGroups;
        this.dndHintTarget.hidden = !hasGroups;
        this.reshuffleBtnTarget.hidden = !hasGroups;
        this.gridTarget.replaceChildren();

        if (!hasGroups) {
            return;
        }

        this.groups.forEach((group, index) => {
            this.gridTarget.appendChild(this.buildGroupCard(group, index));
        });
    }

    buildGroupCard(members, index) {
        const locked = this.lockedIndices.has(index);

        const card = document.createElement('div');
        card.className = 'cm-grp-card' + (locked ? ' is-locked' : '');
        card.dataset.index = String(index);

        const head = document.createElement('div');
        head.className = 'cm-grp-card__head';

        const title = document.createElement('b');
        title.className = 'cm-grp-card__title';
        title.textContent = this.labelsValue.groupTitleTemplate.replace('%n%', index + 1);
        head.appendChild(title);

        const count = document.createElement('span');
        count.className = 'cm-grp-card__count';
        count.textContent = `· ${members.length}`;
        head.appendChild(count);

        const lock = document.createElement('span');
        lock.className = 'cm-grp-card__lock';
        lock.textContent = locked ? this.labelsValue.lockedLabel : this.labelsValue.lockLabel;
        lock.addEventListener('click', () => {
            if (locked) {
                this.lockedIndices.delete(index);
            } else {
                this.lockedIndices.add(index);
            }
            this.renderGroups();
        });
        head.appendChild(lock);

        card.appendChild(head);

        const body = document.createElement('div');
        body.className = 'cm-grp-card__body';
        body.addEventListener('dragover', (event) => event.preventDefault());
        body.addEventListener('drop', () => this.dropOnGroup(index));

        for (const member of members) {
            body.appendChild(this.buildMemberRow(member));
        }

        card.appendChild(body);

        return card;
    }

    buildMemberRow(member) {
        const row = document.createElement('div');
        row.className = 'cm-grp-member';
        row.draggable = true;
        row.dataset.studentId = String(member.id);

        const option = this.optionsById.get(member.optionId);
        if (option) {
            const tag = document.createElement('span');
            tag.className = 'cm-grp-member__tag';
            tag.textContent = option.shortName;
            tag.style.background = option.color;
            tag.style.color = '#fff';
            row.appendChild(tag);
        }

        row.appendChild(document.createTextNode(member.name));

        row.addEventListener('dragstart', () => {
            this.dragId = member.id;
            row.classList.add('is-dragging');
        });
        row.addEventListener('dragend', () => row.classList.remove('is-dragging'));

        return row;
    }

    dropOnGroup(targetIndex) {
        if (this.dragId === null || !this.groups || this.lockedIndices.has(targetIndex)) {
            this.dragId = null;

            return;
        }

        const fromIndex = this.groups.findIndex((group) => group.some((m) => m.id === this.dragId));
        if (fromIndex === -1 || fromIndex === targetIndex || this.lockedIndices.has(fromIndex)) {
            this.dragId = null;

            return;
        }

        const memberIndex = this.groups[fromIndex].findIndex((m) => m.id === this.dragId);
        const [member] = this.groups[fromIndex].splice(memberIndex, 1);
        this.groups[targetIndex].push(member);
        this.dragId = null;
        this.renderGroups();
    }

    // ---------- Lots ----------

    renderLotsBar() {
        this.lotsBarTarget.hidden = this.lots.length === 0;
        this.lotsChipsTarget.replaceChildren();

        for (const lot of this.lots) {
            const chip = document.createElement('span');
            chip.className = 'cm-grp-lot-chip';

            const load = document.createElement('button');
            load.type = 'button';
            load.className = 'cm-grp-lot-chip__load';
            load.title = this.labelsValue.loadLotTitle;
            load.textContent = lot.name;
            load.addEventListener('click', () => this.loadLot(lot));
            chip.appendChild(load);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'cm-grp-lot-chip__del';
            del.title = this.labelsValue.deleteLotTitle;
            del.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path></svg>';
            del.addEventListener('click', () => this.openDeleteLotModal(lot));
            chip.appendChild(del);

            this.lotsChipsTarget.appendChild(chip);
        }
    }

    loadLot(lot) {
        this.groups = lot.groups.map((group) => group.map((student) => ({ ...student, optionId: student.optionIds?.[0] ?? student.optionId ?? null })));
        this.lockedIndices = new Set();
        this.lotNameInputTarget.value = lot.name;
        this.renderGroups();
    }

    async saveLot() {
        if (!this.groups) return;

        const name = this.lotNameInputTarget.value.trim();
        let response;
        try {
            response = await fetch(this.saveLotUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify({ name, groups: this.groups.map((group) => group.map((m) => m.id)) }),
            });
        } catch (e) {
            this.renderError(this.labelsValue.networkErrorMessage);

            return;
        }

        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.error) {
            this.renderError(data?.error ?? this.labelsValue.networkErrorMessage);

            return;
        }

        const existingIndex = this.lots.findIndex((lot) => lot.id === data.id);
        const savedLot = { id: data.id, name: data.name, groups: this.groups };
        if (existingIndex === -1) {
            this.lots.push(savedLot);
        } else {
            this.lots[existingIndex] = savedLot;
        }
        this.lotNameInputTarget.value = data.name;
        this.renderLotsBar();
        this.showToast(this.labelsValue.lotSavedToast.replace('%name%', data.name));
    }

    openDeleteLotModal(lot) {
        this.pendingDeleteLotId = lot.id;
        this.confirmModalBodyTarget.innerHTML = this.labelsValue.deleteLotConfirmBody.replace('%name%', `<b>${this.escapeHtml(lot.name)}</b>`);
        this.confirmModalTarget.hidden = false;
    }

    cancelDeleteLot() {
        this.pendingDeleteLotId = null;
        this.confirmModalTarget.hidden = true;
    }

    async confirmDeleteLot() {
        const lotId = this.pendingDeleteLotId;
        if (lotId === null) return;

        const url = this.deleteLotUrlValue.replace('__LOT_ID__', String(lotId));
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
            });
        } catch (e) {
            response = null;
        }

        this.confirmModalTarget.hidden = true;
        this.pendingDeleteLotId = null;

        if (!response || !response.ok) {
            this.renderError(this.labelsValue.networkErrorMessage);

            return;
        }

        const deletedLot = this.lots.find((lot) => lot.id === lotId);
        this.lots = this.lots.filter((lot) => lot.id !== lotId);
        this.renderLotsBar();
        if (deletedLot) {
            this.showToast(this.labelsValue.lotDeletedToast.replace('%name%', deletedLot.name));
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;

        return div.innerHTML;
    }

    showToast(message) {
        this.toastTarget.textContent = message;
        this.toastTarget.hidden = false;
        clearTimeout(this._toastTimeout);
        this._toastTimeout = setTimeout(() => { this.toastTarget.hidden = true; }, 2600);
    }

    // ---------- Fullscreen projector ----------

    goFullscreen() {
        if (!this.groups) return;

        this.fullscreenTitleTarget.textContent = this.lotNameInputTarget.value.trim() || this.labelsValue.fullscreenTitleFallback;
        this.fullscreenGridTarget.replaceChildren();
        this.groups.forEach((members, index) => {
            const card = document.createElement('div');
            card.className = 'cm-grp-fs-card';
            const title = document.createElement('div');
            title.className = 'cm-grp-fs-card__title';
            title.textContent = this.labelsValue.groupTitleTemplate.replace('%n%', index + 1);
            card.appendChild(title);
            for (const member of members) {
                const name = document.createElement('div');
                name.className = 'cm-grp-fs-card__member';
                name.textContent = member.name;
                card.appendChild(name);
            }
            this.fullscreenGridTarget.appendChild(card);
        });

        this.fullscreenTarget.hidden = false;
        // Fullscreen this.fullscreenTarget itself (the .cm-grp-fs overlay), not this.element (the
        // whole controller root, which also wraps the editor panel/results grid/modal/toast/forms
        // above it in normal flow) - .cm-grp-fs is only position:relative, not a fixed overlay, so
        // fullscreening the wrapper left all of that visible above the projector view instead of
        // replacing it, same real-Fullscreen-API-on-one-element pattern as .cm-draw-card (Tirage
        // au sort, assets/styles/app.css).
        if (this.fullscreenTarget.requestFullscreen) {
            this.fullscreenTarget.requestFullscreen().catch(() => {});
        } else if (document.documentElement.requestFullscreen) {
            document.documentElement.requestFullscreen().catch(() => {});
        }
    }

    exitFullscreen(event) {
        event.preventDefault();
        this.fullscreenTarget.hidden = true;
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    }

    // ---------- Export / messaging (real form submits - see the two hidden <form> elements) ----------

    exportPdf() {
        if (!this.groups) return;

        this.pdfFormGroupsTarget.value = JSON.stringify(this.serializeGroupsForExport());
        this.pdfFormLotNameTarget.value = this.lotNameInputTarget.value.trim();
        this.pdfFormTarget.submit();
    }

    sendMessage() {
        if (!this.groups) return;

        this.messageFormGroupsTarget.value = JSON.stringify(this.serializeGroupsForExport());
        this.messageFormLotNameTarget.value = this.lotNameInputTarget.value.trim();
        this.messageFormTarget.submit();
    }

    serializeGroupsForExport() {
        return this.groups.map((members, index) => ({
            title: this.labelsValue.groupTitleTemplate.replace('%n%', index + 1),
            members: members.map((m) => ({ name: m.name, tag: this.optionsById.get(m.optionId)?.shortName ?? '' })),
        }));
    }
}
