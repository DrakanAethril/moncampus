import { Controller } from '@hotwired/stimulus';

// Carnet de notes - quick entry of an evaluation (design/design_handoff_carnet_de_notes, screen 4).
// One row per student, in one of the handoff's two modes: overall grade (input + Abs/N.É./( )
// buttons) or one box per rubric question with an automatic total.
//
// The per-student audio comment, which used to be laid here, has left the gradebook: the microphone
// recorder became the "Enregistrements audio" tool (assets/audio/mic_recorder.js), from where
// audio_recording_files_controller.js drives it.
//
// The rows are built once on connect() then updated in place (status, total, header counters)
// rather than redrawn: a full re-render on every save would lose the focus in the middle of a
// keyboard entry.
//
// Saving is automatic and per cell; there is no global « save » button.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'entered', 'average', 'progress', 'sortLabel'];

    static values = {
        editable: Boolean,
        rows: Array,
        sections: Array,
        scale: Number,
        countsOutOf20: Boolean,
        saveGradeUrlTemplate: String,
        saveRubricUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.rows = JSON.parse(JSON.stringify(this.rowsValue));
        this.sections = JSON.parse(JSON.stringify(this.sectionsValue));
        this.questions = this.sections.flatMap((section) => section.questions);
        this.hasRubric = this.questions.length > 0;
        this.nodes = {};
        this.sortDescending = false;
        this.render();
        this.refreshCounters();
    }

    // ---- Rendu ----------------------------------------------------------------------------

    // The server already sends the class in lastname order, so the first click reverses it and the
    // next one puts the alphabet back - the rank numbers follow the order shown, never the roster.
    toggleSort() {
        this.sortDescending = !this.sortDescending;
        const direction = this.sortDescending ? -1 : 1;
        this.rows.sort((a, b) => direction * a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));

        if (this.hasSortLabelTarget) {
            this.sortLabelTarget.textContent = this.sortDescending ? this.labelsValue.sortDescLabel : this.labelsValue.sortAscLabel;
        }

        this.render();
    }

    render() {
        const children = [];
        if (this.hasRubric) children.push(this.buildQuestionHead());
        this.rows.forEach((row, index) => children.push(this.buildRow(row, index)));
        this.listTarget.replaceChildren(...children);
    }

    buildQuestionHead() {
        const head = this.el('div', 'cm-gb-qhead');

        // Section band, aligned above the columns of their own questions.
        const sectionsRow = this.el('div', 'cm-gb-qhead__sections');
        sectionsRow.appendChild(this.spacer(22));
        sectionsRow.appendChild(this.spacer(230));
        for (const section of this.sections) {
            const band = this.el('div', 'cm-gb-qhead__section', section.name);
            band.title = section.name;
            // 54px per question + the 10px gutter between two columns.
            band.style.width = `${section.questions.length * 54 + (section.questions.length - 1) * 10}px`;
            sectionsRow.appendChild(band);
        }
        head.appendChild(sectionsRow);

        const columnsRow = this.el('div', 'cm-gb-qhead__cols');
        columnsRow.appendChild(this.spacer(22));
        const pointsLabel = this.el('div', 'cm-gb-label', this.labelsValue.pointsPerQuestionLabel);
        pointsLabel.style.cssText = 'width: 230px; flex: none;';
        columnsRow.appendChild(pointsLabel);
        for (const question of this.questions) {
            const column = this.el('div', 'cm-gb-qhead__q');
            column.appendChild(this.el('span', 'cm-gb-qhead__num', question.label));
            column.appendChild(this.el('span', 'cm-gb-qhead__pts', `/${question.maxPoints}`));
            columnsRow.appendChild(column);
        }
        const total = this.el('div', 'cm-gb-qhead__total cm-gb-label', `${this.labelsValue.totalLabel} /${this.rubricTotalPoints()}`);
        columnsRow.appendChild(total);
        head.appendChild(columnsRow);

        return head;
    }

    buildRow(row, index) {
        const node = this.el('div', 'cm-gb-entry__row');
        node.appendChild(this.el('span', 'cm-gb-entry__rank', String(index + 1)));

        const name = this.el('div', 'cm-gb-entry__name');
        name.appendChild(this.el('span', null, row.name));
        if (row.option) {
            const tag = this.el('span', 'cm-gb-tag', row.option);
            if (row.optionColor) tag.style.backgroundColor = row.optionColor;
            name.appendChild(tag);
        }
        node.appendChild(name);

        const refs = { node };

        if (this.hasRubric) {
            refs.inputs = {};
            for (const question of this.questions) {
                const input = this.el('input', 'cm-gb-qinput');
                input.inputMode = 'decimal';
                input.placeholder = '–';
                input.value = this.answerDisplay(row.answers?.[question.id]);
                input.disabled = !this.editableValue;
                input.dataset.lastValid = input.value;
                input.addEventListener('blur', () => this.commitAnswer(row, question, input));
                input.addEventListener('keydown', (event) => this.onRubricKeydown(event, row, question, input));
                refs.inputs[question.id] = input;
                node.appendChild(input);
            }
            refs.total = this.el('span', 'cm-gb-qtotal');
            const totalCell = this.el('div', 'cm-gb-qhead__total');
            totalCell.appendChild(refs.total);
            node.appendChild(totalCell);
        } else {
            refs.status = this.el('span', 'cm-gb-entry__status');
            node.appendChild(refs.status);

            const inputWrap = this.el('div', 'd-flex align-items-center gap-1 flex-none');
            refs.input = this.el('input', 'cm-gb-entry__input');
            refs.input.inputMode = 'decimal';
            refs.input.placeholder = '—';
            refs.input.value = this.rawValueFor(row);
            refs.input.disabled = !this.editableValue;
            refs.input.addEventListener('blur', () => this.commitGrade(row, refs.input.value));
            refs.input.addEventListener('keydown', (event) => this.onGradeKeydown(event, row, refs.input));
            inputWrap.appendChild(refs.input);
            inputWrap.appendChild(this.el('span', 'cm-gb-entry__scale', `/${this.scaleValue}`));
            node.appendChild(inputWrap);

            if (this.editableValue) {
                const quick = this.el('div', 'cm-gb-quick');
                quick.appendChild(this.quickButton('cm-gb-quick--abs', this.labelsValue.absentShortLabel, this.labelsValue.absentLabel, () => this.commitGrade(row, 'abs')));
                quick.appendChild(this.quickButton('cm-gb-quick--ne', this.labelsValue.notEvaluatedShortLabel, this.labelsValue.notEvaluatedLabel, () => this.commitGrade(row, 'ne')));
                // Putting a grade in parentheses only makes sense on one already entered: the value
                // stays, only its counting towards the average changes.
                quick.appendChild(this.quickButton('', this.labelsValue.excludedButtonLabel, this.labelsValue.excludedButtonTitle, () => {
                    if (row.value != null) this.commitGrade(row, `(${row.value})`);
                }));
                node.appendChild(quick);
            }
        }

        this.nodes[row.id] = refs;
        this.paintRow(row);

        return node;
    }

    quickButton(className, label, title, onClick) {
        const button = this.el('button', className, label);
        button.type = 'button';
        button.title = title;
        button.tabIndex = -1;
        button.addEventListener('click', onClick);

        return button;
    }

    // Status, total and color of a row - refreshed after every save.
    paintRow(row) {
        const refs = this.nodes[row.id];
        if (!refs) return;

        refs.node.classList.toggle('is-done', this.isEntered(row));

        if (this.hasRubric) {
            refs.total.className = `cm-gb-qtotal ${row.value == null ? '' : row.colorClass}`;
            refs.total.textContent = row.value == null ? '—' : `${this.formatGrade(row.value)}`;
        } else {
            const [text, modifier] = this.statusDisplay(row);
            refs.status.className = `cm-gb-entry__status ${modifier}`;
            refs.status.textContent = text;
        }
    }

    statusDisplay(row) {
        if (row.status === 'absent') return [this.labelsValue.absentLabel, 'cm-gb-val--abs'];
        if (row.status === 'not_evaluated') return [this.labelsValue.notEvaluatedLabel, 'cm-gb-val--ne'];
        if (row.status === 'not_tested') return [this.labelsValue.notTestedShortLabel, 'cm-gb-val--nt'];
        if (row.status === 'excluded') return [`(${this.formatGrade(row.value)})`, 'fst-italic'];
        if (row.status === 'normal' && row.value != null) return [`${this.formatGrade(row.value)}/${this.scaleValue}`, row.colorClass];

        return [this.labelsValue.toEnterLabel, 'cm-gb-entry__status--empty'];
    }

    // ---- Enregistrement -------------------------------------------------------------------

    async commitGrade(row, raw) {
        if (!this.editableValue) return;

        const url = this.saveGradeUrlTemplateValue.replace('__STUDENT_ID__', row.id);
        const data = await this.post(url, { raw: String(raw ?? '') });
        if (!data) return;

        if (data.cleared) {
            row.status = null;
            row.value = null;
            row.normalizedValue = null;
            row.colorClass = 'cm-grade-band-none';
        } else {
            row.status = data.status;
            row.value = data.value;
            row.normalizedValue = data.normalizedValue;
            row.colorClass = data.colorClass;
        }

        this.nodes[row.id].input.value = this.rawValueFor(row);
        this.paintRow(row);
        this.refreshCounters();
    }

    async commitAnswer(row, question, input) {
        if (!this.editableValue) return;
        const raw = input.value.trim();
        if (raw === input.dataset.lastValid) return;

        const url = this.saveRubricUrlTemplateValue
            .replace('__STUDENT_ID__', row.id)
            .replace('__QUESTION_ID__', question.id);

        const response = await this.post(url, { raw }, true);
        // A value above the question's maximum is refused as such, not clamped (the designs' qSet()):
        // the last value actually saved is put back.
        if (response === 422) {
            input.value = input.dataset.lastValid;
            input.classList.add('is-invalid');
            setTimeout(() => input.classList.remove('is-invalid'), 1500);

            return;
        }
        if (!response) return;

        input.dataset.lastValid = raw;
        row.answers[question.id] = raw === '' ? undefined : (raw.toLowerCase() === 'nt' ? 'nt' : parseFloat(raw.replace(',', '.')));
        row.value = response.total;
        row.normalizedValue = response.normalizedValue;
        row.colorClass = response.colorClass;
        this.paintRow(row);
        this.refreshCounters();
    }

    async post(url, payload, allow422 = false) {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify(payload),
            });
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);

            return null;
        }

        if (allow422 && response.status === 422) return 422;
        if (!response.ok) {
            window.alert(this.labelsValue.networkErrorMessage);

            return null;
        }

        return response.json();
    }

    // ---- Navigation clavier ---------------------------------------------------------------

    onGradeKeydown(event, row, input) {
        if (!['Enter', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
        event.preventDefault();

        const index = this.rows.indexOf(row);
        const next = this.rows[index + (event.key === 'ArrowUp' ? -1 : 1)];
        this.commitGrade(row, input.value).then(() => {
            if (next) this.nodes[next.id]?.input?.focus();
        });
    }

    onRubricKeydown(event, row, question, input) {
        if (!['Enter', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        // Left/right only take over on a box that is already empty or entirely selected, otherwise
        // they must stay the caret movement inside the field.
        const atEdge = input.selectionStart === input.selectionEnd
            && (event.key !== 'ArrowLeft' || input.selectionStart === 0)
            && (event.key !== 'ArrowRight' || input.selectionStart === input.value.length);
        if (['ArrowLeft', 'ArrowRight'].includes(event.key) && !atEdge) return;
        event.preventDefault();

        const rowIndex = this.rows.indexOf(row);
        const colIndex = this.questions.indexOf(question);
        let nextRow = rowIndex;
        let nextCol = colIndex;
        if (event.key === 'ArrowLeft') nextCol -= 1;
        else if (event.key === 'ArrowRight') nextCol += 1;
        else if (event.key === 'ArrowUp') nextRow -= 1;
        else nextRow += 1;

        this.commitAnswer(row, question, input).then(() => {
            const target = this.rows[nextRow];
            const targetQuestion = this.questions[nextCol];
            if (target && targetQuestion) this.nodes[target.id]?.inputs?.[targetQuestion.id]?.focus();
        });
    }

    // ---- Header counters ------------------------------------------------------------------

    isEntered(row) {
        return null != row.status || (this.hasRubric && row.value != null);
    }

    refreshCounters() {
        const entered = this.rows.filter((row) => this.isEntered(row)).length;
        this.enteredTarget.textContent = String(entered);
        this.progressTarget.style.width = this.rows.length ? `${Math.round((entered / this.rows.length) * 100)}%` : '0';

        const values = this.rows
            .filter((row) => row.status === 'normal' && row.normalizedValue != null)
            .map((row) => row.normalizedValue);
        this.averageTarget.textContent = values.length
            ? (values.reduce((a, b) => a + b, 0) / values.length).toFixed(2)
            : '—';
    }

    // ---- Utilitaires ----------------------------------------------------------------------

    // Rounding: adding quarter points in floating point quickly gives 20.000000000000004.
    rubricTotalPoints() {
        return Math.round(this.questions.reduce((sum, question) => sum + question.maxPoints, 0) * 100) / 100;
    }

    answerDisplay(value) {
        if (value === 'nt') return 'nt';

        return value == null ? '' : String(value);
    }

    rawValueFor(row) {
        if (row.status === 'absent') return 'abs';
        if (row.status === 'not_evaluated') return 'ne';
        if (row.status === 'not_tested') return 'nt';
        if (row.value == null) return '';

        return row.status === 'excluded' ? `(${this.formatGrade(row.value)})` : String(this.formatGrade(row.value));
    }

    // Two decimals at most, with no useless zero: 12 → « 12 », 12.5 → « 12.5 », 12.25 → « 12.25 ».
    // Rounding to a tenth made the quarter point impossible to enter: the field redisplayed 12.3 and
    // it was that value that went back to the server on the next blur.
    formatGrade(value) {
        return String(Math.round(value * 100) / 100);
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

    spacer(width) {
        const node = this.el('span');
        node.style.width = `${width}px`;
        node.style.flex = 'none';

        return node;
    }
}
