import { Controller } from '@hotwired/stimulus';

/**
 * One seating plan (design/validated/plans-de-classe.md): the room, the rules and the plan, three
 * steps of one page.
 *
 * The page keeps the document in hand - the room, the rules, the two preferences, who sits where,
 * the locked seats, the seed - and sends it **whole** to the server, which is the one to check it,
 * to propose a plan and to save it (App\Controller\SeatingPlan\PlanController). What this
 * controller knows of the rules is nothing: the red seats and the checklist are the server's
 * answer, asked again after every move. What it does know is the room's geometry, because the
 * room is drawn and edited here: a piece covers whole cells, never another piece, never a wall.
 *
 * The room is redrawn whole after each change, but never while something is being dragged - a
 * drag whose source left the page is a drag the browser cancels: during a drag only the ghost and
 * the hovered seat change.
 */
const CELL = 58;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'counts', 'dirty', 'printLink', 'saveButton', 'stepButton', 'section',
        'paletteItem', 'armedNote', 'armedLabel', 'paletteHint', 'inspector', 'inspectorName', 'inspectorPosition', 'rotateButton',
        'columns', 'rows', 'roomEditor', 'meter',
        'ruleType', 'ruleStudents', 'ruleNeed', 'addRuleButton', 'mixOptions', 'frontFirst', 'suggestion', 'suggestionTitle', 'suggestionNames', 'noRules', 'ruleList',
        'planHeading', 'flipButton', 'planRoom', 'staleBanner', 'scoreBanner', 'proposeButton', 'proposeLabel',
        'selection', 'selectionName', 'selectionInfo', 'pinButton', 'pinLabel', 'unseatButton',
        'noChecks', 'checks', 'pool', 'poolCount', 'poolEmpty',
        'shareDialog', 'shareSelect', 'leaveDialog', 'toast',
    ];

    static values = { config: Object, csrf: String, urls: Object, labels: Object, name: String };

    connect() {
        const config = this.configValue;
        this.labels = this.labelsValue;
        this.urls = this.urlsValue;
        this.doc = this.normalize(config.document);
        this.students = new Map(config.roster.map((student) => [student.id, student]));
        this.options = config.options;
        this.furniture = Object.fromEntries(config.furniture.map((item) => [item.type, item]));
        this.ruleTypes = Object.fromEntries(config.ruleTypes.map((type) => [type.type, type]));
        this.templates = config.templates;
        this.roomLayouts = config.roomLayouts;
        this.limits = config.limits;
        this.canEdit = config.canEdit;
        this.revision = config.revision;
        this.check = config.check;
        this.step = config.step;
        this.planName = this.nameValue;
        this.ruleType = config.ruleTypes[0].type;
        this.flipped = false;
        this.selectedId = null;
        this.armed = null;
        this.pick = null;
        this.drag = null;
        this.dirty = false;
        this.stale = 0 === Object.keys(this.doc.seats).length;
        this.counter = 0;

        this.beforeUnload = (event) => {
            if (this.dirty && !this.leaving) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.beforeVisit = (event) => {
            if (this.dirty && !this.leaving) {
                event.preventDefault();
                this.pendingUrl = event.detail.url;
                this.leaveDialogTarget.showModal();
            }
        };
        // The name field is the page's heading, outside this controller's element.
        this.nameInput = document.getElementById('sp-name');
        this.onRename = () => this.rename();
        this.nameInput?.addEventListener('input', this.onRename);
        window.addEventListener('beforeunload', this.beforeUnload);
        document.addEventListener('turbo:before-visit', this.beforeVisit);

        if (this.canEdit) {
            this.mixOptionsTarget.checked = this.doc.preferences.mixOptions;
            this.frontFirstTarget.checked = this.doc.preferences.frontFirst;
            this.updateRuleNeed();
        }
        this.renderAll();
    }

    disconnect() {
        this.nameInput?.removeEventListener('input', this.onRename);
        window.removeEventListener('beforeunload', this.beforeUnload);
        document.removeEventListener('turbo:before-visit', this.beforeVisit);
        clearTimeout(this.toastTimer);
        clearTimeout(this.checkTimer);
    }

    // ---------------------------------------------------------------- geometry

    normalize(document) {
        const copy = JSON.parse(JSON.stringify(document));
        if (Array.isArray(copy.seats)) {
            copy.seats = {};
        }
        copy.pins = copy.pins || [];
        copy.rules = copy.rules || [];

        return copy;
    }

    dims(table) {
        const item = this.furniture[table.type];

        return table.rotated ? { w: item.height, h: item.width } : { w: item.width, h: item.height };
    }

    seatsOf(table) {
        if (0 === this.furniture[table.type].seats) {
            return [];
        }
        const { w, h } = this.dims(table);
        const seats = [];
        for (let j = 0; j < h; j++) {
            for (let i = 0; i < w; i++) {
                seats.push({ id: `${table.id}-${j * w + i}`, x: table.x + i, y: table.y + j, tableId: table.id });
            }
        }

        return seats;
    }

    allSeats() {
        return this.doc.room.tables.flatMap((table) => this.seatsOf(table));
    }

    fits(candidate, exceptId = null) {
        const room = this.doc.room;
        const { w, h } = this.dims(candidate);
        if (candidate.x < 0 || candidate.y < 0 || candidate.x + w > room.columns || candidate.y + h > room.rows) {
            return false;
        }
        const taken = new Set();
        room.tables.forEach((table) => {
            if (table.id !== exceptId) {
                const d = this.dims(table);
                for (let j = 0; j < d.h; j++) for (let i = 0; i < d.w; i++) taken.add(`${table.x + i},${table.y + j}`);
            }
        });
        for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) if (taken.has(`${candidate.x + i},${candidate.y + j}`)) return false;

        return true;
    }

    ranks() {
        const ys = [...new Set(this.allSeats().map((seat) => seat.y))].sort((a, b) => a - b);

        return (seat) => ys.indexOf(seat.y);
    }

    // The seats a moved or removed table took with it: their students go back to « Sans place ».
    prune() {
        const ids = new Set(this.allSeats().map((seat) => seat.id));
        Object.keys(this.doc.seats).forEach((seatId) => {
            if (!ids.has(seatId)) delete this.doc.seats[seatId];
        });
        this.doc.pins = this.doc.pins.filter((pin) => undefined !== this.doc.seats[pin]);
    }

    newId(prefix) {
        this.counter += 1;

        return `${prefix}${Date.now().toString(36)}${this.counter.toString(36)}`;
    }

    // ---------------------------------------------------------------- state

    changed({ room = false, rules = false } = {}) {
        this.dirty = true;
        if (room || rules) {
            this.stale = true;
        }
        if (room) {
            this.prune();
        }
        this.needsCheck = true;
        this.renderAll();
    }

    rename() {
        this.planName = this.nameInput.value;
        this.dirty = true;
        this.renderDirty();
    }

    goStep(event) {
        const step = event.currentTarget.dataset.step;
        const propose = 'plan' === step && (this.stale || 0 === Object.keys(this.doc.seats).length) && ('1' === event.currentTarget.dataset.propose || 0 === Object.keys(this.doc.seats).length);
        this.step = step;
        this.pick = null;
        this.selectedId = null;
        this.armed = null;
        this.renderAll();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (propose && this.students.size > 0 && this.allSeats().length > 0) {
            this.propose();
        } else if ('plan' === step && this.needsCheck) {
            this.requestCheck();
        }
    }

    // ---------------------------------------------------------------- rendering

    renderAll() {
        this.sectionTargets.forEach((section) => { section.hidden = section.dataset.step !== this.step; });
        this.stepButtonTargets.forEach((button) => {
            const current = button.dataset.step === this.step;
            button.classList.toggle('is-current', current);
            button.setAttribute('aria-current', current ? 'step' : 'false');
        });
        const seatCount = this.allSeats().length;
        this.element.querySelectorAll('[data-step-count]').forEach((span) => {
            if ('room' === span.dataset.stepCount) span.textContent = this.fmt('seats', { count: seatCount });
            if ('rules' === span.dataset.stepCount) span.textContent = this.doc.rules.length ? String(this.doc.rules.length) : '';
        });
        this.countsTarget.textContent = `${this.countsTarget.textContent.split(' · ')[0]} · ${this.fmt('seats', { count: seatCount })}`;

        if (this.canEdit) {
            this.renderRoomEditor();
            this.renderRules();
        }
        this.renderPlan();
        this.renderDirty();
    }

    renderDirty() {
        this.dirtyTarget.hidden = !this.dirty;
    }

    el(tag, className = '', text = null) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (null !== text) node.textContent = text;

        return node;
    }

    renderRoomEditor() {
        const room = this.doc.room;
        const wrapper = this.el('div', 'cm-sp-room');
        wrapper.append(this.el('div', 'cm-sp-board', this.labels.board ?? 'Tableau'));
        const floor = this.el('div', `cm-sp-floor cm-sp-floor--grid${this.armed ? ' is-armed' : ''}`);
        floor.style.width = `${room.columns * CELL}px`;
        floor.style.height = `${room.rows * CELL}px`;
        floor.append(this.el('span', 'cm-sp-window cm-sp-window--right'), this.el('span', 'cm-sp-door cm-sp-door--bottom-left'));
        floor.addEventListener('dragover', (event) => this.floorOver(event));
        floor.addEventListener('drop', (event) => this.floorDrop(event));
        floor.addEventListener('dragleave', (event) => this.floorLeave(event));
        floor.addEventListener('click', (event) => this.floorClick(event));
        floor.addEventListener('mousemove', (event) => this.floorMove(event));
        floor.addEventListener('mouseleave', () => { if (!this.drag) this.hideGhost(); });

        room.tables.forEach((table) => {
            const { w, h } = this.dims(table);
            const item = this.furniture[table.type];
            const button = this.el('button', `cm-sp-table-shape${0 === item.seats ? ' cm-sp-table-shape--desk' : ''}${this.selectedId === table.id ? ' is-selected' : ''}`);
            button.type = 'button';
            button.draggable = true;
            button.dataset.id = table.id;
            Object.assign(button.style, { left: `${table.x * CELL + 4}px`, top: `${table.y * CELL + 4}px`, width: `${w * CELL - 8}px`, height: `${h * CELL - 8}px` });
            button.setAttribute('aria-label', `${item.label}, ${this.fmt('tableAt', { column: table.x + 1, rank: table.y + 1 })}`);
            if (0 === item.seats) {
                button.textContent = this.labels.desk;
            }
            this.seatsOf(table).forEach((seat) => {
                const chair = this.el('span', 'cm-sp-chair');
                chair.style.left = `${(seat.x - table.x) * CELL + CELL / 2 - 4 - 9}px`;
                chair.style.top = `${(seat.y - table.y) * CELL + CELL / 2 - 4 - 9}px`;
                button.append(chair);
            });
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                this.selectedId = table.id;
                this.armed = null;
                this.renderRoomEditor();
                this.focusTable(table.id);
            });
            button.addEventListener('keydown', (event) => this.tableKey(event, table.id));
            button.addEventListener('dragstart', (event) => {
                const rect = button.getBoundingClientRect();
                this.drag = { kind: 'move', id: table.id, gx: Math.floor((event.clientX - rect.left + 4) / CELL), gy: Math.floor((event.clientY - rect.top + 4) / CELL) };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', table.id);
            });
            button.addEventListener('dragend', () => this.dragEnd());
            floor.append(button);
        });

        wrapper.append(floor);
        this.floor = floor;
        this.ghost = null;
        this.roomEditorTarget.replaceChildren(wrapper);

        this.columnsTarget.textContent = room.columns;
        this.rowsTarget.textContent = room.rows;

        this.paletteItemTargets.forEach((item) => {
            const on = item.dataset.type === this.armed;
            item.classList.toggle('is-armed', on);
            item.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        this.armedNoteTarget.hidden = !this.armed;
        this.paletteHintTarget.hidden = !!this.armed;
        if (this.armed) {
            this.armedLabelTarget.textContent = this.furniture[this.armed].label;
        }

        const selected = room.tables.find((table) => table.id === this.selectedId);
        this.inspectorTarget.hidden = !selected;
        if (selected) {
            const item = this.furniture[selected.type];
            this.inspectorNameTarget.textContent = item.label + (selected.rotated ? this.labels.rotated : '');
            this.inspectorPositionTarget.textContent = this.fmt('tableAt', { column: selected.x + 1, rank: selected.y + 1 });
            this.rotateButtonTarget.disabled = !item.canRotate;
        }

        const seats = this.allSeats().length;
        const students = this.students.size;
        this.meterTarget.classList.toggle('is-short', seats < students);
        this.meterTarget.textContent = seats < students
            ? this.fmt('meterShort', { seats, students, missing: students - seats })
            : this.fmt(seats > students ? 'meterFree' : 'meterOk', { seats, students, free: seats - students });
    }

    focusTable(id) {
        this.floor?.querySelector(`[data-id="${CSS.escape(id)}"]`)?.focus();
    }

    cellAt(event) {
        const rect = this.floor.getBoundingClientRect();

        return { x: Math.floor((event.clientX - rect.left - 2) / CELL), y: Math.floor((event.clientY - rect.top - 2) / CELL) };
    }

    showGhost(candidate, exceptId) {
        const { w, h } = this.dims(candidate);
        if (!this.ghost) {
            this.ghost = this.el('div', 'cm-sp-ghost');
            this.floor.append(this.ghost);
        }
        this.ghost.classList.toggle('is-blocked', !this.fits(candidate, exceptId));
        Object.assign(this.ghost.style, { left: `${candidate.x * CELL + 2}px`, top: `${candidate.y * CELL + 2}px`, width: `${w * CELL - 4}px`, height: `${h * CELL - 4}px` });
    }

    hideGhost() {
        this.ghost?.remove();
        this.ghost = null;
    }

    candidateFromDrag(event) {
        const cell = this.cellAt(event);
        if ('new' === this.drag.kind) {
            return { candidate: { id: '_', type: this.drag.type, x: cell.x, y: cell.y, rotated: false }, exceptId: null };
        }
        const table = this.doc.room.tables.find((item) => item.id === this.drag.id);

        return table ? { candidate: { ...table, x: cell.x - this.drag.gx, y: cell.y - this.drag.gy }, exceptId: table.id } : null;
    }

    floorOver(event) {
        if (!this.drag || !['new', 'move'].includes(this.drag.kind)) return;
        event.preventDefault();
        const found = this.candidateFromDrag(event);
        if (found) this.showGhost(found.candidate, found.exceptId);
    }

    floorDrop(event) {
        if (!this.drag || !['new', 'move'].includes(this.drag.kind)) return;
        event.preventDefault();
        const found = this.candidateFromDrag(event);
        const drag = this.drag;
        this.drag = null;
        this.hideGhost();
        if (!found) return;
        if ('new' === drag.kind) {
            this.place(drag.type, found.candidate.x, found.candidate.y, false);
        } else {
            this.moveTable(drag.id, found.candidate.x, found.candidate.y);
        }
    }

    floorLeave(event) {
        if (event.relatedTarget && this.floor.contains(event.relatedTarget)) return;
        this.hideGhost();
    }

    floorMove(event) {
        if (!this.armed || this.drag) return;
        const cell = this.cellAt(event);
        this.showGhost({ id: '_', type: this.armed, x: cell.x, y: cell.y, rotated: false }, null);
    }

    floorClick(event) {
        if (this.armed) {
            const cell = this.cellAt(event);
            this.place(this.armed, cell.x, cell.y, true);
        } else if (this.selectedId) {
            this.selectedId = null;
            this.renderRoomEditor();
        }
    }

    arm(event) {
        const type = event.currentTarget.dataset.type;
        this.armed = this.armed === type ? null : type;
        this.selectedId = null;
        this.renderRoomEditor();
    }

    disarm() {
        this.armed = null;
        this.renderRoomEditor();
    }

    paletteDragStart(event) {
        this.drag = { kind: 'new', type: event.currentTarget.dataset.type };
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', this.drag.type);
    }

    dragEnd() {
        this.drag = null;
        this.hideGhost();
        this.element.querySelectorAll('.is-over').forEach((node) => node.classList.remove('is-over'));
    }

    place(type, x, y, keepArmed) {
        const table = { id: this.newId('t'), type, x, y, rotated: false };
        if (!this.fits(table)) {
            this.toast(this.labels.occupied);
            return;
        }
        this.doc.room.tables.push(table);
        this.selectedId = keepArmed ? null : table.id;
        this.armed = keepArmed ? type : null;
        this.changed({ room: true });
    }

    moveTable(id, x, y) {
        const table = this.doc.room.tables.find((item) => item.id === id);
        if (!table || (table.x === x && table.y === y)) return;
        if (!this.fits({ ...table, x, y }, id)) {
            this.toast(this.labels.occupied);
            return;
        }
        table.x = x;
        table.y = y;
        this.selectedId = id;
        this.changed({ room: true });
        this.focusTable(id);
    }

    tableKey(event, id) {
        const table = this.doc.room.tables.find((item) => item.id === id);
        if (!table) return;
        const move = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
        if (move) {
            event.preventDefault();
            this.moveTable(id, table.x + move[0], table.y + move[1]);
        } else if ('r' === event.key || 'R' === event.key) {
            event.preventDefault();
            this.rotate(id);
        } else if ('d' === event.key || 'D' === event.key) {
            event.preventDefault();
            this.duplicate(id);
        } else if ('Delete' === event.key || 'Backspace' === event.key) {
            event.preventDefault();
            this.remove(id);
        } else if ('Escape' === event.key) {
            this.selectedId = null;
            this.renderRoomEditor();
        }
    }

    rotate(id) {
        const table = this.doc.room.tables.find((item) => item.id === id);
        if (!table) return;
        if (!this.furniture[table.type].canRotate) {
            this.toast(this.labels.noRotate);
            return;
        }
        if (!this.fits({ ...table, rotated: !table.rotated }, id)) {
            this.toast(this.labels.noRoomToRotate);
            return;
        }
        table.rotated = !table.rotated;
        this.changed({ room: true });
        this.focusTable(id);
    }

    duplicate(id) {
        const table = this.doc.room.tables.find((item) => item.id === id);
        if (!table) return;
        let best = null;
        let bestDistance = Infinity;
        for (let y = 0; y < this.doc.room.rows; y++) {
            for (let x = 0; x < this.doc.room.columns; x++) {
                const candidate = { ...table, id: '_', x, y };
                if (!this.fits(candidate)) continue;
                // Beside it on the same rank first, then the ranks behind it.
                const distance = Math.abs(x - table.x) + Math.abs(y - table.y) * 1.5 + (y < table.y ? 3 : 0);
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = candidate;
                }
            }
        }
        if (!best) {
            this.toast(this.labels.roomFull);
            return;
        }
        const copy = { ...best, id: this.newId('t') };
        this.doc.room.tables.push(copy);
        this.selectedId = copy.id;
        this.changed({ room: true });
        this.focusTable(copy.id);
    }

    remove(id) {
        this.doc.room.tables = this.doc.room.tables.filter((item) => item.id !== id);
        this.selectedId = null;
        this.changed({ room: true });
    }

    rotateSelected() { if (this.selectedId) this.rotate(this.selectedId); }

    duplicateSelected() { if (this.selectedId) this.duplicate(this.selectedId); }

    removeSelected() { if (this.selectedId) this.remove(this.selectedId); }

    applyLayout(event) {
        const { source, index } = event.currentTarget.dataset;
        const layout = ('roomLayouts' === source ? this.roomLayouts : this.templates)[Number(index)];
        this.doc.room = JSON.parse(JSON.stringify(layout.room));
        this.doc.seats = {};
        this.doc.pins = [];
        this.selectedId = null;
        this.armed = null;
        this.changed({ room: true });
        this.toast(this.fmt('layoutApplied', { name: layout.label }));
    }

    clearRoom() {
        this.doc.room.tables = [];
        this.selectedId = null;
        this.changed({ room: true });
    }

    resize(event) {
        const room = this.doc.room;
        const columns = room.columns + Number(event.currentTarget.dataset.columns || 0);
        const rows = room.rows + Number(event.currentTarget.dataset.rows || 0);
        if (columns < this.limits.minColumns || columns > this.limits.maxColumns || rows < this.limits.minRows || rows > this.limits.maxRows) return;
        const outside = room.tables.some((table) => {
            const { w, h } = this.dims(table);

            return table.x + w > columns || table.y + h > rows;
        });
        if (outside) {
            this.toast(this.labels.tablesOutside);
            return;
        }
        room.columns = columns;
        room.rows = rows;
        this.changed({ room: true });
    }

    async saveRoomLayout() {
        const answer = await this.post(this.urls.roomLayout, { room: this.doc.room });
        if (answer) {
            this.toast(this.fmt('roomLayoutSaved', { room: answer.room }));
        }
    }

    // ---------------------------------------------------------------- rules

    pickRuleType(event) {
        this.ruleType = event.currentTarget.value;
        this.ruleTypeTargets.forEach((input) => input.closest('.cm-sp-radio')?.classList.toggle('is-checked', input.checked));
        this.updateRuleNeed();
    }

    ruleStudentsChanged() {
        this.updateRuleNeed();
    }

    selectedRuleStudents() {
        const select = this.ruleStudentsTarget;
        const values = select.tomselect ? select.tomselect.getValue() : [...select.selectedOptions].map((option) => option.value);

        return (Array.isArray(values) ? values : [values]).filter((value) => '' !== value).map(Number);
    }

    updateRuleNeed() {
        const type = this.ruleTypes[this.ruleType];
        const count = this.selectedRuleStudents().length;
        this.ruleNeedTarget.textContent = type.need;
        this.addRuleButtonTarget.disabled = count < type.min || count > type.max;
    }

    addRule() {
        const type = this.ruleTypes[this.ruleType];
        const students = this.selectedRuleStudents();
        if (students.length < type.min || students.length > type.max) return;
        this.doc.rules.push({ id: this.newId('r'), type: type.type, students });
        this.ruleStudentsTarget.tomselect?.clear();
        this.updateRuleNeed();
        this.changed({ rules: true });
        this.toast(this.fmt('ruleAdded', { rule: type.label }));
    }

    removeRule(id) {
        this.doc.rules = this.doc.rules.filter((rule) => rule.id !== id);
        this.changed({ rules: true });
    }

    frontPending() {
        const named = new Set(this.doc.rules.filter((rule) => 'front' === rule.type).flatMap((rule) => rule.students));

        return [...this.students.values()].filter((student) => student.front && !named.has(student.id));
    }

    addFrontRule() {
        const pending = this.frontPending().slice(0, this.ruleTypes.front.max);
        if (0 === pending.length) return;
        this.doc.rules.push({ id: this.newId('r'), type: 'front', students: pending.map((student) => student.id) });
        this.changed({ rules: true });
        this.toast(this.fmt('ruleAdded', { rule: this.ruleTypes.front.label }));
    }

    togglePreference(event) {
        this.doc.preferences[event.currentTarget.dataset.preference] = event.currentTarget.checked;
        this.changed({ rules: true });
    }

    renderRules() {
        const pending = this.frontPending();
        this.suggestionTarget.hidden = 0 === pending.length;
        if (pending.length) {
            this.suggestionTitleTarget.textContent = this.fmt('frontSuggestion', { count: pending.length });
            this.suggestionNamesTarget.textContent = pending.map((student) => `${student.firstName} ${student.lastName}`.trim()).join(', ');
        }

        this.noRulesTarget.hidden = this.doc.rules.length > 0;
        const cards = this.doc.rules.map((rule) => {
            const type = this.ruleTypes[rule.type];
            const card = this.el('div', 'cm-sp-rule');
            const body = this.el('div', 'cm-sp-rule__body');
            const head = this.el('div', 'cm-sp-rule__head');
            head.append(this.el('span', `cm-badge cm-badge--${type.tone}`, type.label), this.el('span', 'cm-sp-rule__desc', type.description));
            const tags = this.el('div', 'cm-sp-tags');
            rule.students.forEach((id) => {
                const student = this.students.get(id);
                const tag = this.el('span', 'cm-sp-tag');
                const index = student?.option ? this.options.indexOf(student.option) : -1;
                if (index >= 0) tag.append(this.el('span', `cm-sp-dot cm-sp-dot--${index % 4}`));
                tag.append(document.createTextNode(student ? student.shortName : '?'));
                tags.append(tag);
            });
            body.append(head, tags);
            const remove = this.el('button', 'cm-sp-icon');
            remove.type = 'button';
            remove.setAttribute('aria-label', this.labels.removeRule);
            remove.title = this.labels.removeRule;
            remove.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"></path></svg>';
            remove.addEventListener('click', () => this.removeRule(rule.id));
            card.append(body, remove);

            return card;
        });
        this.ruleListTarget.replaceChildren(...cards);
    }

    // ---------------------------------------------------------------- plan

    renderPlan() {
        const room = this.doc.room;
        const flip = this.flipped;
        const X = (x, w) => (flip ? room.columns - x - w : x);
        const Y = (y, h) => (flip ? room.rows - y - h : y);
        const faulty = new Set(this.check?.faultySeats ?? []);
        const pins = new Set(this.doc.pins);
        const rankOf = this.ranks();

        const wrapper = this.el('div', 'cm-sp-room');
        const board = this.el('div', `cm-sp-board${flip ? ' cm-sp-board--bottom' : ''}`, this.labels.board ?? 'Tableau');
        const floor = this.el('div', 'cm-sp-floor cm-sp-floor--plan');
        floor.style.width = `${room.columns * CELL}px`;
        floor.style.height = `${room.rows * CELL}px`;
        floor.append(this.el('span', `cm-sp-window ${flip ? 'cm-sp-window--left' : 'cm-sp-window--right'}`), this.el('span', `cm-sp-door ${flip ? 'cm-sp-door--top-right' : 'cm-sp-door--bottom-left'}`));

        room.tables.forEach((table) => {
            const { w, h } = this.dims(table);
            const desk = 0 === this.furniture[table.type].seats;
            const shape = this.el('div', `cm-sp-table-shape${desk ? ' cm-sp-table-shape--desk' : ''}`, desk ? this.labels.desk : null);
            Object.assign(shape.style, { left: `${X(table.x, w) * CELL + 4}px`, top: `${Y(table.y, h) * CELL + 4}px`, width: `${w * CELL - 8}px`, height: `${h * CELL - 8}px` });
            floor.append(shape);
        });

        this.allSeats().forEach((seat) => {
            const studentId = this.doc.seats[seat.id];
            const student = undefined === studentId ? null : this.students.get(studentId);
            const node = this.el(this.canEdit ? 'button' : 'div', 'cm-sp-seat');
            node.classList.toggle('is-empty', !student);
            node.classList.toggle('is-faulty', faulty.has(seat.id));
            node.classList.toggle('is-picked', this.pick?.seat === seat.id);
            node.classList.toggle('is-readonly', !this.canEdit);
            Object.assign(node.style, { left: `${X(seat.x, 1) * CELL + 3}px`, top: `${Y(seat.y, 1) * CELL + 3}px`, width: `${CELL - 6}px`, height: `${CELL - 6}px` });
            if (student) {
                node.append(this.el('span', 'cm-sp-seat__first', student.firstName), this.el('span', 'cm-sp-seat__last', student.lastName ? `${student.lastName.charAt(0)}.` : ''));
                const index = student.option ? this.options.indexOf(student.option) : -1;
                if (index >= 0) node.append(this.el('span', `cm-sp-dot cm-sp-dot--${index % 4}`));
                if (pins.has(seat.id)) {
                    const lock = this.el('span', 'cm-sp-seat__lock');
                    lock.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 0 1 8 0v3"></path></svg>';
                    node.append(lock);
                }
                node.title = `${student.firstName} ${student.lastName}`.trim();
            }
            if (this.canEdit) {
                node.type = 'button';
                node.setAttribute('aria-label', student ? `${student.firstName} ${student.lastName}`.trim() : this.labels.free);
                node.draggable = !!student && !pins.has(seat.id);
                node.addEventListener('click', () => this.seatClick(seat.id));
                node.addEventListener('dragstart', (event) => {
                    this.drag = { kind: 'seat', seat: seat.id };
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', seat.id);
                });
                node.addEventListener('dragend', () => this.dragEnd());
                node.addEventListener('dragover', (event) => {
                    if (!this.drag || !['seat', 'pool'].includes(this.drag.kind)) return;
                    event.preventDefault();
                    node.classList.add('is-over');
                });
                node.addEventListener('dragleave', () => node.classList.remove('is-over'));
                node.addEventListener('drop', (event) => {
                    event.preventDefault();
                    const drag = this.drag;
                    this.drag = null;
                    if (!drag) return;
                    this.moveTo('seat' === drag.kind ? { seat: drag.seat } : { pool: drag.student }, seat.id);
                });
            }
            floor.append(node);
        });

        if (flip) {
            wrapper.append(floor, board);
        } else {
            wrapper.append(board, floor);
        }
        this.planRoomTarget.replaceChildren(wrapper);

        this.flipButtonTargets.forEach((button) => {
            const on = ('1' === button.dataset.flip) === flip;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        this.printLinkTarget.href = flip ? `${this.urls.print}?view=desk` : this.urls.print;

        this.renderChecks();
        this.renderPool();

        if (!this.canEdit) return;

        const seated = Object.keys(this.doc.seats).length;
        this.staleBannerTarget.hidden = !(this.stale || 0 === seated);
        this.staleBannerTarget.textContent = 0 === seated ? this.labels.staleEmpty : this.labels.staleChanged;
        this.proposeLabelTarget.textContent = 0 === seated ? this.labels.propose : this.labels.proposeAgain;

        const rules = this.check?.ruleCount ?? 0;
        const broken = this.check?.brokenRules ?? 0;
        this.scoreBannerTarget.hidden = 0 === seated;
        this.scoreBannerTarget.className = `cm-sp-banner ${broken ? 'cm-sp-banner--ko' : 'cm-sp-banner--ok'}`;
        this.scoreBannerTarget.textContent = 0 === rules
            ? this.labels.scoreNone
            : (broken ? this.fmt('scoreKo', { count: broken, total: rules }) : this.fmt('scoreOk', { count: rules }));

        let picked = null;
        if (this.pick?.seat && undefined !== this.doc.seats[this.pick.seat]) {
            const seat = this.allSeats().find((item) => item.id === this.pick.seat);
            picked = { student: this.students.get(this.doc.seats[this.pick.seat]), info: seat ? this.fmt('rank', { rank: rankOf(seat) + 1 }) : '' };
        } else if (this.pick?.pool) {
            picked = { student: this.students.get(this.pick.pool), info: this.labels.withoutSeat };
        }
        this.selectionTarget.hidden = !picked?.student;
        if (picked?.student) {
            const { student } = picked;
            this.selectionNameTarget.textContent = `${student.firstName} ${student.lastName}`.trim();
            this.selectionInfoTarget.textContent = [student.option, student.front ? this.labels.front : null, picked.info].filter(Boolean).join(' · ');
            const onSeat = !!this.pick.seat;
            this.pinButtonTarget.hidden = !onSeat;
            this.unseatButtonTarget.hidden = !onSeat;
            this.pinLabelTarget.textContent = onSeat && pins.has(this.pick.seat) ? this.labels.unlock : this.labels.lock;
        }
    }

    renderChecks() {
        const results = this.check?.results ?? [];
        this.noChecksTarget.hidden = results.length > 0;
        this.checksTarget.replaceChildren(...results.map((result) => {
            const item = this.el('li', 'cm-sp-check');
            const icon = this.el('span', `cm-sp-check__icon cm-sp-check__icon--${result.ok ? 'ok' : (result.preference ? 'soft' : 'ko')}`, result.ok ? '✓' : '!');
            icon.setAttribute('aria-hidden', 'true');
            const body = this.el('div');
            const title = this.el('div', 'cm-sp-check__title', result.title);
            if (result.who) {
                title.append(document.createTextNode(' '), this.el('span', 'cm-sp-check__who', result.who));
            }
            body.append(title, ...result.problems.map((problem) => this.el('div', `cm-sp-check__problem${result.preference ? ' cm-sp-check__problem--soft' : ''}`, problem)));
            item.append(icon, body);

            return item;
        }));
    }

    renderPool() {
        const seated = new Set(Object.values(this.doc.seats));
        const standing = [...this.students.values()].filter((student) => !seated.has(student.id));
        this.poolCountTarget.textContent = standing.length ? this.fmt('noSeatCount', { count: standing.length }) : '';
        this.poolEmptyTarget.hidden = standing.length > 0;
        this.poolTarget.replaceChildren(...standing.map((student) => {
            const chip = this.el(this.canEdit ? 'button' : 'span', `cm-sp-chip${this.pick?.pool === student.id ? ' is-picked' : ''}`);
            const index = student.option ? this.options.indexOf(student.option) : -1;
            if (index >= 0) chip.append(this.el('span', `cm-sp-dot cm-sp-dot--${index % 4}`));
            chip.append(document.createTextNode(student.shortName));
            if (this.canEdit) {
                chip.type = 'button';
                chip.draggable = true;
                chip.addEventListener('click', () => {
                    this.pick = this.pick?.pool === student.id ? null : { pool: student.id };
                    this.renderPlan();
                });
                chip.addEventListener('dragstart', (event) => {
                    this.drag = { kind: 'pool', student: student.id };
                    event.dataTransfer.setData('text/plain', String(student.id));
                });
                chip.addEventListener('dragend', () => this.dragEnd());
            }

            return chip;
        }));
    }

    seatClick(seatId) {
        if (!this.pick) {
            if (undefined !== this.doc.seats[seatId]) {
                this.pick = { seat: seatId };
                this.renderPlan();
            }
            return;
        }
        if (this.pick.seat === seatId) {
            this.pick = null;
            this.renderPlan();
            return;
        }
        this.moveTo(this.pick, seatId);
    }

    moveTo(source, target) {
        const pins = this.doc.pins;
        if (pins.includes(target) || (source.seat && pins.includes(source.seat))) {
            this.pick = null;
            this.toast(this.labels.locked);
            this.renderPlan();
            return;
        }
        if (source.seat === target) return;
        const seats = this.doc.seats;
        if (source.seat) {
            const moving = seats[source.seat];
            const there = seats[target];
            if (undefined === there) delete seats[source.seat]; else seats[source.seat] = there;
            if (undefined === moving) delete seats[target]; else seats[target] = moving;
        } else if (source.pool) {
            seats[target] = source.pool;
        }
        this.pick = null;
        this.afterMove();
    }

    afterMove() {
        this.dirty = true;
        this.needsCheck = true;
        this.renderPlan();
        this.renderDirty();
        this.requestCheck();
    }

    poolOver(event) {
        if (!this.drag || 'seat' !== this.drag.kind) return;
        event.preventDefault();
        this.poolTarget.classList.add('is-over');
    }

    poolLeave(event) {
        if (event.relatedTarget && this.poolTarget.contains(event.relatedTarget)) return;
        this.poolTarget.classList.remove('is-over');
    }

    poolDrop(event) {
        event.preventDefault();
        this.poolTarget.classList.remove('is-over');
        const drag = this.drag;
        this.drag = null;
        if (!drag || 'seat' !== drag.kind) return;
        if (this.doc.pins.includes(drag.seat)) {
            this.toast(this.labels.locked);
            return;
        }
        delete this.doc.seats[drag.seat];
        this.afterMove();
    }

    togglePin() {
        const seat = this.pick?.seat;
        if (!seat || undefined === this.doc.seats[seat]) return;
        const locked = this.doc.pins.includes(seat);
        this.doc.pins = locked ? this.doc.pins.filter((pin) => pin !== seat) : [...this.doc.pins, seat];
        this.pick = null;
        this.dirty = true;
        this.renderPlan();
        this.renderDirty();
        this.toast(locked ? this.labels.lockedOff : this.labels.lockedOn);
    }

    unseat() {
        const seat = this.pick?.seat;
        if (!seat) return;
        delete this.doc.seats[seat];
        this.doc.pins = this.doc.pins.filter((pin) => pin !== seat);
        this.pick = null;
        this.afterMove();
    }

    cancelPick() {
        this.pick = null;
        this.renderPlan();
    }

    flip(event) {
        this.flipped = '1' === event.currentTarget.dataset.flip;
        this.renderPlan();
    }

    async propose() {
        this.proposeButtonTarget.disabled = true;
        const kept = this.doc.pins.length;
        const answer = await this.post(this.urls.propose, { document: this.doc });
        this.proposeButtonTarget.disabled = false;
        if (!answer) return;
        this.doc.seats = Array.isArray(answer.seats) ? {} : answer.seats;
        this.doc.seed = answer.seed;
        this.check = answer.check;
        this.stale = false;
        this.needsCheck = false;
        this.dirty = true;
        this.pick = null;
        this.renderPlan();
        this.renderDirty();
        this.toast(kept ? this.fmt('proposedKept', { count: kept }) : this.labels.proposed);
    }

    requestCheck() {
        clearTimeout(this.checkTimer);
        this.checkTimer = setTimeout(async () => {
            const answer = await this.post(this.urls.check, { document: this.doc });
            if (answer) {
                this.check = answer.check;
                this.needsCheck = false;
                if (!this.drag) this.renderPlan();
            }
        }, 150);
    }

    // ---------------------------------------------------------------- saving, sharing, leaving

    async save() {
        if (this.nameInput && '' === this.planName.trim()) {
            this.toast(this.labels.emptyName);
            return false;
        }
        const answer = await this.post(this.urls.save, { name: this.planName, revision: this.revision, document: this.doc });
        if (!answer) return false;
        this.revision = answer.revision;
        this.dirty = false;
        this.renderDirty();
        document.title = answer.name;
        this.toast(this.labels.saved);

        return true;
    }

    async post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify(body),
            });
            if (409 === response.status) {
                this.toast(this.labels.stale);
                return null;
            }
            if (!response.ok) {
                this.toast(this.labels.failed);
                return null;
            }

            return await response.json();
        } catch {
            this.toast(this.labels.failed);
            return null;
        }
    }

    openShare() {
        this.shareDialogTarget.showModal();
    }

    closeShare() {
        this.shareDialogTarget.close();
    }

    quickShare(event) {
        const { id, name } = event.currentTarget.dataset;
        const select = this.shareSelectTarget.tomselect;
        if (select) {
            select.addOption({ id, text: name });
            select.addItem(id);
        }
        event.currentTarget.remove();
    }

    // Sharing posts the form and leaves the page: unsaved work is saved first, never lost.
    async beforeShare(event) {
        if (!this.dirty) {
            this.leaving = true;
            return;
        }
        event.preventDefault();
        if (await this.save()) {
            this.leaving = true;
            event.target.requestSubmit();
        }
    }

    leaveWithoutSaving() {
        this.leaving = true;
        this.leaveDialogTarget.close();
        this.visit(this.pendingUrl);
    }

    async saveAndLeave() {
        this.leaveDialogTarget.close();
        if (await this.save()) {
            this.leaving = true;
            this.visit(this.pendingUrl);
        }
    }

    visit(url) {
        if (window.Turbo) {
            window.Turbo.visit(url);
        } else {
            window.location.assign(url);
        }
    }

    /**
     * A label with its %placeholders% filled. A label of two forms (« %count% place|%count% places »)
     * is the translator's plural: the second form from two on, as French counts.
     */
    fmt(key, values = {}) {
        const forms = this.labels[key].split('|');
        const count = values.count;
        let text = undefined !== count && Number(count) > 1 && forms[1] ? forms[1] : forms[0];
        Object.entries(values).forEach(([name, value]) => { text = text.split(`%${name}%`).join(String(value)); });

        return text;
    }

    toast(text) {
        clearTimeout(this.toastTimer);
        this.toastTarget.textContent = text;
        this.toastTarget.hidden = false;
        this.toastTimer = setTimeout(() => { this.toastTarget.hidden = true; }, 2800);
    }
}
