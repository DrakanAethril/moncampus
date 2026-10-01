import { Controller } from '@hotwired/stimulus';
import { readConfig } from '../class_board/widget_controller.js';

// The virtual board (design/validated/tableau-virtuel.md): the 16:9 surface, moving and resizing
// widgets, the dock, the backgrounds, full screen, the black screen, the keyboard shortcuts, and the
// automatic save.
//
// The board owns the document; each widget owns its own behaviour (one controller per widget type,
// assets/controllers/class_board_*_controller.js) and its configuration, which it writes onto its
// element. Saving collects every widget's position and configuration and sends the whole layout,
// 2 s after the last change: a board is read and rewritten in one piece, never one widget at a time.
//
// There is one clock loop, here, that every widget listens to (`class-board:tick` on the window),
// rather than one interval per timer, clock and stopwatch.
const SAVE_DELAY_MS = 2000;
const CHROME_IDLE_MS = 3000;
const TICK_MS = 200;
const MIN_WIDTH = 6;
const MIN_HEIGHT = 10;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['surface', 'inner', 'widget', 'chrome', 'dock', 'name', 'status', 'backgrounds', 'backgroundButton', 'notice', 'blackout'];

    static values = {
        revision: Number,
        csrf: String,
        saveUrl: String,
        nameUrl: String,
        newWidgetUrl: String,
        renderUrl: String,
        quitUrl: String,
        labels: Object,
    };

    connect() {
        this.labels = this.labelsValue;
        this.revision = this.revisionValue;
        this.dirty = false;
        this.saving = false;
        this.stale = false;
        this.saveTimer = null;
        this.currentName = this.nameTarget.textContent.trim();
        this.openPanel = null;

        this.tickInterval = setInterval(() => window.dispatchEvent(new CustomEvent('class-board:tick')), TICK_MS);

        this.onKeydown = (event) => this.keydown(event);
        this.onFullscreenChange = () => this.fullscreenChanged();
        this.onPointerActivity = () => this.wakeChrome();
        this.onBeforeUnload = (event) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.onChanged = () => this.markDirty();
        this.onRerender = (event) => this.rerender(event.target.closest('[data-widget-id]'));

        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
        this.element.addEventListener('pointermove', this.onPointerActivity);
        this.element.addEventListener('pointerdown', this.onPointerActivity);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        this.element.addEventListener('class-board:changed', this.onChanged);
        this.element.addEventListener('class-board:rerender', this.onRerender);

        // A touched widget comes to the front - delegated, so widgets added later need nothing.
        this.innerTarget.addEventListener('pointerdown', (event) => {
            const widget = event.target.closest('[data-widget-id]');
            if (widget) {
                this.bringToFront(widget);
            }
        });
        // The settings panels are read the same way for every widget: a field names the config key
        // it sets, and its kind.
        this.innerTarget.addEventListener('input', (event) => this.applySetting(event));
        this.innerTarget.addEventListener('change', (event) => this.applySetting(event));
    }

    disconnect() {
        clearInterval(this.tickInterval);
        clearTimeout(this.saveTimer);
        clearTimeout(this.chromeTimer);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.releaseWakeLock();
    }

    // ------------------------------------------------------------------ moving and resizing

    startMove(event) {
        if (event.button !== 0 || event.target.closest('button')) {
            return;
        }
        this.drag(event, false);
    }

    startResize(event) {
        if (event.button !== 0) {
            return;
        }
        this.drag(event, true);
    }

    drag(event, resizing) {
        event.preventDefault();
        const handle = event.currentTarget;
        const widget = handle.closest('[data-widget-id]');
        const surface = this.surfaceTarget.getBoundingClientRect();
        const pointer = { x: event.clientX, y: event.clientY };
        const origin = this.box(widget);
        handle.setPointerCapture(event.pointerId);

        const move = (moveEvent) => {
            const dx = ((moveEvent.clientX - pointer.x) / surface.width) * 100;
            const dy = ((moveEvent.clientY - pointer.y) / surface.height) * 100;
            const box = { ...origin };
            if (resizing) {
                box.w = Math.min(100 - origin.x, Math.max(MIN_WIDTH, origin.w + dx));
                box.h = Math.min(100 - origin.y, Math.max(MIN_HEIGHT, origin.h + dy));
            } else {
                box.x = Math.min(100 - origin.w, Math.max(0, origin.x + dx));
                box.y = Math.min(100 - origin.h, Math.max(0, origin.y + dy));
            }
            this.place(widget, box);
            if (this.openPanel?.widget === widget) {
                this.positionPanel(widget, this.openPanel.panel);
            }
        };
        const stop = () => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', stop);
            handle.removeEventListener('pointercancel', stop);
            const box = this.box(widget);
            if (box.x !== origin.x || box.y !== origin.y || box.w !== origin.w || box.h !== origin.h) {
                this.markDirty();
            }
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', stop);
        handle.addEventListener('pointercancel', stop);
    }

    box(widget) {
        return {
            x: parseFloat(widget.dataset.x) || 0,
            y: parseFloat(widget.dataset.y) || 0,
            w: parseFloat(widget.dataset.w) || 20,
            h: parseFloat(widget.dataset.h) || 20,
        };
    }

    place(widget, box) {
        const round = (value) => Math.round(value * 100) / 100;
        widget.dataset.x = round(box.x);
        widget.dataset.y = round(box.y);
        widget.dataset.w = round(box.w);
        widget.dataset.h = round(box.h);
        Object.assign(widget.style, {
            left: `${widget.dataset.x}%`,
            top: `${widget.dataset.y}%`,
            width: `${widget.dataset.w}%`,
            height: `${widget.dataset.h}%`,
        });
    }

    topZ() {
        return this.widgetTargets.reduce((max, widget) => Math.max(max, parseInt(widget.dataset.z, 10) || 0), 0);
    }

    bringToFront(widget) {
        const top = this.topZ();
        this.widgetTargets.forEach((other) => other.classList.toggle('is-front', other === widget));
        if ((parseInt(widget.dataset.z, 10) || 0) === top && this.widgetTargets.filter((other) => other.dataset.z === widget.dataset.z).length === 1) {
            return;
        }
        widget.dataset.z = String(top + 1);
        widget.style.zIndex = widget.dataset.z;
        this.markDirty();
    }

    // ------------------------------------------------------------------ adding, removing, redrawing

    async addWidget(event) {
        const button = event.currentTarget;
        if (button.disabled) {
            return;
        }
        const html = await this.post(this.newWidgetUrlValue, {
            type: button.dataset.type,
            id: `w${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
            count: this.widgetTargets.length,
            z: this.topZ() + 1,
        }, 'text');
        if (html === null) {
            this.setStatus(this.labels.failed);
            return;
        }
        const widget = this.insertWidget(html);
        if (widget) {
            this.bringToFront(widget);
            this.markDirty();
        }
    }

    insertWidget(html, replacing = null) {
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const widget = template.content.firstElementChild;
        if (!widget) {
            return null;
        }
        if (replacing) {
            replacing.replaceWith(widget);
        } else {
            this.innerTarget.insertBefore(widget, this.chromeTargets[0] ?? null);
        }
        return widget;
    }

    removeWidget(event) {
        const widget = event.currentTarget.closest('[data-widget-id]');
        if (this.openPanel?.widget === widget) {
            this.closeSettings();
        }
        widget.remove();
        this.markDirty();
    }

    async rerender(widget) {
        if (!widget) {
            return;
        }
        const wasOpen = this.openPanel?.widget === widget;
        const html = await this.post(this.renderUrlValue, { widget: this.serialize(widget) }, 'text');
        if (html === null) {
            this.setStatus(this.labels.failed);
            return;
        }
        if (wasOpen) {
            this.openPanel = null;
        }
        const fresh = this.insertWidget(html, widget);
        if (fresh && wasOpen) {
            this.showSettings(fresh);
        }
        this.markDirty();
    }

    // ------------------------------------------------------------------ settings panels

    toggleSettings(event) {
        const widget = event.currentTarget.closest('[data-widget-id]');
        if (this.openPanel?.widget === widget) {
            this.closeSettings();
            return;
        }
        this.closeSettings();
        this.showSettings(widget);
    }

    showSettings(widget) {
        const panel = widget.querySelector('[data-class-board-panel]');
        if (!panel) {
            return;
        }
        panel.hidden = false;
        widget.classList.add('has-panel');
        widget.querySelector('.cm-cb-w__head [aria-expanded]')?.setAttribute('aria-expanded', 'true');
        this.openPanel = { widget, panel };
        this.positionPanel(widget, panel);
        this.syncPanelFields(panel);
        this.bringToFront(widget);
    }

    closeSettings() {
        if (!this.openPanel) {
            return;
        }
        const { widget, panel } = this.openPanel;
        panel.hidden = true;
        widget.classList.remove('has-panel');
        widget.querySelector('.cm-cb-w__head [aria-expanded]')?.setAttribute('aria-expanded', 'false');
        this.openPanel = null;
    }

    // Beside the widget, on whichever side has room.
    positionPanel(widget, panel) {
        const box = this.box(widget);
        panel.classList.toggle('is-left', box.x + box.w + 24 > 100);
    }

    applySetting(event) {
        const field = event.target;
        const panel = field.closest('[data-class-board-panel]');
        // A form inside a panel - the quiz launch - is its own, never the widget's configuration.
        if (!panel || !field.name || field.closest('form')) {
            return;
        }
        // A text field writes as it is typed; a select, a box, a radio - and a field the server has
        // to redraw from, such as a video's address - answers its change.
        const live = field.tagName === 'INPUT' && ['text', 'url', 'time', 'number'].includes(field.type) && !('rerender' in field.dataset);
        if ((event.type === 'input') !== live) {
            return;
        }

        const widget = field.closest('[data-widget-id]');
        const config = readConfig(widget);
        if (field.type === 'checkbox') {
            config[field.name] = field.checked;
        } else if (field.type === 'radio') {
            if (!field.checked) {
                return;
            }
            config[field.name] = field.value;
        } else if (field.dataset.kind === 'id' || field.dataset.kind === 'int') {
            const number = parseInt(field.value, 10);
            config[field.name] = Number.isNaN(number) ? null : number;
        } else {
            config[field.name] = field.value;
        }
        widget.dataset.config = JSON.stringify(config);
        this.syncPanelFields(panel);

        if ('rerender' in field.dataset) {
            this.rerender(widget);
            return;
        }
        widget.dispatchEvent(new CustomEvent('class-board:configured'));
        this.markDirty();
    }

    // `data-enabled-when="mode=fixed"`: a field that only means something for one choice of another.
    syncPanelFields(panel) {
        panel.querySelectorAll('[data-enabled-when]').forEach((field) => {
            const [name, value] = field.dataset.enabledWhen.split('=');
            const chosen = panel.querySelector(`[name="${name}"]:checked`)?.value;
            field.disabled = chosen !== value;
        });
    }

    // The « Quiz live » launch, posted to the route of Outils › Concours live. That route answers by
    // a redirect: to the contest it created, or back to its own form with a flash when the choice
    // was refused - which is how the two are told apart here.
    async launchQuiz(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const widget = form.closest('[data-widget-id]');
        const message = form.querySelector('[data-class-board-launch-message]');
        message.hidden = true;
        try {
            const response = await fetch(form.dataset.createUrl, { method: 'POST', body: new FormData(form) });
            if (!response.ok || !/\/quiz\/live\/\d+$/.test(new URL(response.url).pathname)) {
                message.hidden = false;
                return;
            }
        } catch {
            message.hidden = false;
            return;
        }
        this.closeSettings();
        this.rerender(widget);
    }

    // ------------------------------------------------------------------ saving

    markDirty() {
        if (this.stale) {
            return;
        }
        this.dirty = true;
        this.setStatus(this.labels.dirty);
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.save(), SAVE_DELAY_MS);
    }

    saveNow() {
        clearTimeout(this.saveTimer);
        this.save(true);
    }

    serialize(widget) {
        const box = this.box(widget);
        return {
            id: widget.dataset.widgetId,
            type: widget.dataset.widgetType,
            x: box.x,
            y: box.y,
            w: box.w,
            h: box.h,
            z: parseInt(widget.dataset.z, 10) || 0,
            config: readConfig(widget),
        };
    }

    async save(force = false) {
        if (this.stale || (!this.dirty && !force)) {
            return true;
        }
        if (this.saving) {
            // One write at a time: the revision of the next one is the answer to this one.
            this.saveTimer = setTimeout(() => this.save(force), 300);
            return false;
        }
        this.saving = true;
        this.dirty = false;
        this.setStatus(this.labels.saving);

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
                body: JSON.stringify({
                    revision: this.revision,
                    background: this.element.dataset.background,
                    widgets: this.widgetTargets.map((widget) => this.serialize(widget)),
                }),
            });
            if (response.status === 409) {
                this.stale = true;
                this.noticeTarget.hidden = false;
                this.setStatus(this.labels.failed);
                return false;
            }
            if (!response.ok) {
                this.dirty = true;
                this.setStatus(this.labels.failed);
                return false;
            }
            const result = await response.json();
            this.revision = result.revision;
            if (!this.dirty) {
                this.setStatus(this.labels.saved.replace('__TIME__', result.savedAt));
            }
            return true;
        } catch {
            this.dirty = true;
            this.setStatus(this.labels.failed);
            return false;
        } finally {
            this.saving = false;
        }
    }

    reload() {
        this.dirty = false;
        window.location.reload();
    }

    async quit() {
        clearTimeout(this.saveTimer);
        if (this.dirty) {
            const saved = await this.save(true);
            if (!saved && !this.stale) {
                return;
            }
        }
        this.dirty = false;
        if (document.fullscreenElement) {
            await document.exitFullscreen().catch(() => {});
        }
        window.location.assign(this.quitUrlValue);
    }

    setStatus(text) {
        this.statusTarget.textContent = text;
    }

    // ------------------------------------------------------------------ name

    nameKey(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.nameTarget.blur();
        } else if (event.key === 'Escape') {
            this.nameTarget.textContent = this.currentName;
            this.nameTarget.blur();
        }
    }

    async rename() {
        const name = this.nameTarget.textContent.trim();
        if (name === this.currentName) {
            this.nameTarget.textContent = this.currentName;
            return;
        }
        const response = await fetch(this.nameUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
            body: JSON.stringify({ name }),
        }).catch(() => null);
        const result = response ? await response.json().catch(() => ({})) : {};
        if (response?.ok) {
            this.currentName = result.name;
            document.title = result.name;
        } else {
            this.setStatus(result.error === 'duplicate_name' ? this.labels.duplicateName : (result.error === 'empty_name' ? this.labels.emptyName : this.labels.failed));
        }
        this.nameTarget.textContent = this.currentName;
    }

    // ------------------------------------------------------------------ background, black screen, full screen

    toggleBackgrounds() {
        this.backgroundsTarget.hidden = !this.backgroundsTarget.hidden;
        this.backgroundButtonTarget.setAttribute('aria-expanded', String(!this.backgroundsTarget.hidden));
    }

    chooseBackground(event) {
        const value = event.currentTarget.dataset.background;
        this.element.dataset.background = value;
        this.backgroundsTarget.querySelectorAll('button').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.background === value));
        });
        this.markDirty();
    }

    toggleBlackout() {
        this.blackoutTarget.hidden = !this.blackoutTarget.hidden;
    }

    toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
            return;
        }
        this.element.requestFullscreen?.().catch(() => {});
    }

    fullscreenChanged() {
        const full = document.fullscreenElement === this.element;
        this.element.classList.toggle('is-fullscreen', full);
        if (full) {
            this.requestWakeLock();
            this.wakeChrome();
        } else {
            clearTimeout(this.chromeTimer);
            this.element.classList.remove('is-idle');
            this.releaseWakeLock();
        }
    }

    // In full screen, the two bars hide after 3 s without the mouse moving.
    wakeChrome() {
        this.element.classList.remove('is-idle');
        clearTimeout(this.chromeTimer);
        if (!this.element.classList.contains('is-fullscreen')) {
            return;
        }
        this.chromeTimer = setTimeout(() => {
            if (!this.openPanel && this.backgroundsTarget.hidden) {
                this.element.classList.add('is-idle');
            }
        }, CHROME_IDLE_MS);
    }

    // The projector must not fall asleep in the middle of a lesson; a refusal is tolerated.
    async requestWakeLock() {
        try {
            this.wakeLock = await navigator.wakeLock?.request('screen');
        } catch {
            this.wakeLock = null;
        }
    }

    releaseWakeLock() {
        this.wakeLock?.release?.().catch(() => {});
        this.wakeLock = null;
    }

    // ------------------------------------------------------------------ keyboard

    keydown(event) {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        // Inactive while typing: in a field, in the text widget, in the board's name.
        if (event.target.closest?.('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]')) {
            return;
        }
        const key = event.key;
        if (key === 'b' || key === 'B') {
            event.preventDefault();
            this.toggleBlackout();
        } else if (key === 'f' || key === 'F') {
            event.preventDefault();
            this.toggleFullscreen();
        } else if (key === 'Escape') {
            this.closeSettings();
            this.backgroundsTarget.hidden = true;
        } else if (key === ' ') {
            if (this.shortcut('timer', 'toggle')) {
                event.preventDefault();
            }
        } else if (key === 't' || key === 'T') {
            this.shortcut('random_draw', 'draw');
        } else if (key === 'ArrowRight' || key === 'ArrowLeft') {
            if (this.shortcut('session_plan', key === 'ArrowRight' ? 'next' : 'previous')) {
                event.preventDefault();
            }
        }
    }

    // With several widgets of one type, a shortcut acts on the last one touched - the one on top.
    shortcut(type, key) {
        const candidates = this.widgetTargets.filter((widget) => widget.dataset.widgetType === type && widget.hasAttribute('data-controller'));
        if (candidates.length === 0) {
            return false;
        }
        candidates.sort((a, b) => (parseInt(b.dataset.z, 10) || 0) - (parseInt(a.dataset.z, 10) || 0));
        candidates[0].dispatchEvent(new CustomEvent('class-board:shortcut', { detail: { key } }));
        return true;
    }

    // ------------------------------------------------------------------ requests

    async post(url, body, as = 'json') {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify(body),
            });
            if (!response.ok) {
                return null;
            }
            return as === 'text' ? await response.text() : await response.json();
        } catch {
            return null;
        }
    }
}
