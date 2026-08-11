import { Controller } from '@hotwired/stimulus';

/**
 * The Zone/Légende panel of the question editor (templates/library/_quiz_zone_editor.html.twig).
 * Client-side twin of App\Util\ZoneTextParser: parses the support's [[id|texte]] markers on every
 * keystroke and rebuilds one row per zone - correct/hint checkboxes and a feedback input for a
 * Zone question, a label input for a Légende - preserving what the teacher already typed. Rows
 * are submitted as raw zones[...] fields, resolved by QuizLibraryController::applyZones().
 *
 * The image kind swaps the textarea for a draw-on-the-picture surface: press-drag draws a
 * rectangle (normalized 0..1 coordinates so any display size works), each rectangle becomes a
 * zone row, and the whole list rides in one hidden JSON field. No drag library - pointer events
 * on the wrapper are enough for rectangles.
 *
 * Custom per-question markers (an imported JS support full of literal "[[") are honoured when
 * parsing but have no editing UI - see applyZones()'s note server-side.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['kindInput', 'textPanel', 'imagePanel', 'languageRow', 'contentInput', 'count', 'rows', 'rowsHint', 'imageCanvas', 'imageZonesInput', 'distractorsPanel', 'pointsHint'];
    static values = {
        correct: Array, hint: Array, feedback: Object, labels: Object, markers: Object,
        countTemplate: String, countEmptyText: String, feedbackPlaceholder: String,
        labelPlaceholder: String, hintZoneText: String, hintLegendeText: String, removeLabel: String,
    };

    connect() {
        // Read the Stimulus values once - they re-parse on every access (see memory) - and keep
        // them as the live state the rows are rebuilt from.
        this.state = {
            correct: new Set(this.correctValue),
            hint: new Set(this.hintValue),
            feedback: { ...this.feedbackValue },
            labels: { ...this.labelsValue },
        };
        this.imageZones = this.parseImageZones();
        this.drawing = null;
        this.boundPointerMove = (event) => this.pointerMove(event);
        this.boundPointerUp = (event) => this.pointerUp(event);
        this.refresh();
    }

    typeChanged() {
        this.refresh();
    }

    kindChanged() {
        this.dispatch('kindChanged');
        this.refresh();
    }

    // --- shared rendering ---

    questionType() {
        const select = document.querySelector('[data-quiz-question-editor-target="typeSelect"]');
        return select ? select.value : 'zone';
    }

    currentKind() {
        const checked = this.kindInputTargets.find((input) => input.checked);
        return checked ? checked.value : 'texte';
    }

    zonesFromContent() {
        const open = this.markersValue.open || '[[';
        const close = this.markersValue.close || ']]';
        const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const pattern = new RegExp(`${escape(open)}([A-Za-z0-9_-]{1,32})\\|((?:(?!${escape(close)})[\\s\\S])*)${escape(close)}`, 'g');

        const zones = [];
        const seen = new Set();
        for (const match of this.contentInputTarget.value.matchAll(pattern)) {
            if (!seen.has(match[1])) {
                seen.add(match[1]);
                zones.push({ id: match[1], text: match[2] });
            }
        }
        return zones;
    }

    refresh() {
        const type = this.questionType();
        if (type !== 'zone' && type !== 'legende') {
            return;
        }

        const isImage = this.currentKind() === 'image';
        this.textPanelTarget.classList.toggle('d-none', isImage);
        this.imagePanelTarget.classList.toggle('d-none', !isImage);
        this.languageRowTarget.classList.toggle('d-none', this.currentKind() !== 'code');
        this.distractorsPanelTarget.classList.toggle('d-none', type !== 'legende');
        this.rowsHintTarget.textContent = `— ${type === 'legende' ? this.hintLegendeTextValue : this.hintZoneTextValue}`;

        const zones = isImage
            ? this.imageZones.map((zone) => ({ id: zone.id, text: zone.id }))
            : this.zonesFromContent();

        if (!isImage) {
            this.countTarget.textContent = zones.length
                ? this.countTemplateValue.replace('%count%', String(zones.length))
                : this.countEmptyTextValue;
        }

        this.renderRows(zones, type, isImage);
        if (isImage) {
            this.renderImageZones();
        }
    }

    renderRows(zones, type, isImage) {
        this.rowsTarget.innerHTML = '';

        zones.forEach((zone) => {
            const row = document.createElement('div');
            row.className = 'cm-zone-editor__row';

            const id = document.createElement('span');
            id.className = 'cm-zone-editor__id';
            id.textContent = zone.id;
            row.appendChild(id);

            const text = document.createElement('span');
            text.className = 'cm-zone-editor__text';
            text.textContent = zone.text;
            row.appendChild(text);

            if (type === 'zone') {
                row.appendChild(this.checkbox('zones[correct][]', zone.id, this.state.correct, '✓'));
                row.appendChild(this.checkbox('zones[hint][]', zone.id, this.state.hint, '?'));
                row.appendChild(this.textField(`zones[feedback][${zone.id}]`, this.state.feedback, zone.id, this.feedbackPlaceholderValue));
            } else {
                row.appendChild(this.textField(`zones[labels][${zone.id}]`, this.state.labels, zone.id, this.labelPlaceholderValue));
            }

            if (isImage) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'cm-answer-remove';
                remove.textContent = '✕';
                remove.title = this.removeLabelValue;
                remove.addEventListener('click', () => {
                    this.imageZones = this.imageZones.filter((candidate) => candidate.id !== zone.id);
                    this.syncImageZonesField();
                    this.refresh();
                });
                row.appendChild(remove);
            }

            this.rowsTarget.appendChild(row);
        });
    }

    checkbox(name, zoneId, stateSet, mark) {
        const label = document.createElement('label');
        label.className = 'cm-answer-toggle' + (stateSet.has(zoneId) ? ' is-correct' : '');
        label.title = mark;
        label.textContent = mark;
        label.style.cursor = 'pointer';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = name;
        input.value = zoneId;
        input.checked = stateSet.has(zoneId);
        input.hidden = true;
        label.appendChild(input);

        label.addEventListener('click', (event) => {
            event.preventDefault();
            input.checked = !input.checked;
            if (input.checked) {
                stateSet.add(zoneId);
            } else {
                stateSet.delete(zoneId);
            }
            label.classList.toggle('is-correct', input.checked);
        });

        return label;
    }

    textField(name, stateMap, zoneId, placeholder) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'cm-answer-input';
        input.name = name;
        input.value = stateMap[zoneId] || '';
        input.placeholder = placeholder;
        input.addEventListener('input', () => {
            stateMap[zoneId] = input.value;
        });

        return input;
    }

    // --- image kind: draw rectangles over the picture ---

    parseImageZones() {
        try {
            const parsed = JSON.parse(this.imageZonesInputTarget.value || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    }

    syncImageZonesField() {
        this.imageZonesInputTarget.value = JSON.stringify(this.imageZones);
    }

    imageCanvasTargetConnected(canvas) {
        canvas.addEventListener('pointerdown', (event) => this.pointerDown(event));
    }

    pointerDown(event) {
        if (!this.imageCanvasTarget.querySelector('img') || event.target.closest('.cm-zone--area')) {
            return;
        }
        event.preventDefault();

        const bounds = this.imageCanvasTarget.getBoundingClientRect();
        this.drawing = {
            startX: (event.clientX - bounds.left) / bounds.width,
            startY: (event.clientY - bounds.top) / bounds.height,
            bounds,
            box: document.createElement('div'),
        };
        this.drawing.box.className = 'cm-zone cm-zone--area';
        this.drawing.box.style.pointerEvents = 'none';
        this.imageCanvasTarget.appendChild(this.drawing.box);

        window.addEventListener('pointermove', this.boundPointerMove);
        window.addEventListener('pointerup', this.boundPointerUp);
    }

    pointerMove(event) {
        if (!this.drawing) {
            return;
        }
        const rect = this.rectFromEvent(event);
        Object.assign(this.drawing.box.style, {
            left: `${rect.x * 100}%`,
            top: `${rect.y * 100}%`,
            width: `${rect.w * 100}%`,
            height: `${rect.h * 100}%`,
        });
    }

    pointerUp(event) {
        window.removeEventListener('pointermove', this.boundPointerMove);
        window.removeEventListener('pointerup', this.boundPointerUp);
        if (!this.drawing) {
            return;
        }

        const rect = this.rectFromEvent(event);
        this.drawing.box.remove();
        this.drawing = null;

        // A sub-1% rectangle is a stray click, not a zone.
        if (rect.w > 0.01 && rect.h > 0.01) {
            this.imageZones.push({ id: this.nextZoneId(), ...rect });
            this.syncImageZonesField();
        }
        this.refresh();
    }

    rectFromEvent(event) {
        const { startX, startY, bounds } = this.drawing;
        const clamp = (v) => Math.min(1, Math.max(0, v));
        const endX = clamp((event.clientX - bounds.left) / bounds.width);
        const endY = clamp((event.clientY - bounds.top) / bounds.height);

        return {
            x: Number(Math.min(startX, endX).toFixed(4)),
            y: Number(Math.min(startY, endY).toFixed(4)),
            w: Number(Math.abs(endX - startX).toFixed(4)),
            h: Number(Math.abs(endY - startY).toFixed(4)),
        };
    }

    nextZoneId() {
        let index = this.imageZones.length + 1;
        while (this.imageZones.some((zone) => zone.id === `z${index}`)) {
            index += 1;
        }
        return `z${index}`;
    }

    renderImageZones() {
        this.imageCanvasTarget.querySelectorAll('.cm-zone--area').forEach((area) => area.remove());
        this.imageZones.forEach((zone) => {
            const area = document.createElement('button');
            area.type = 'button';
            area.className = 'cm-zone cm-zone--area';
            area.disabled = true;
            Object.assign(area.style, {
                left: `${zone.x * 100}%`,
                top: `${zone.y * 100}%`,
                width: `${zone.w * 100}%`,
                height: `${zone.h * 100}%`,
            });
            const tag = document.createElement('span');
            tag.className = 'cm-zone__placed';
            tag.textContent = zone.id;
            area.appendChild(tag);
            this.imageCanvasTarget.appendChild(area);
        });
    }
}
