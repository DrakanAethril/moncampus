import { Controller } from '@hotwired/stimulus';

// Carnet de notes - teacher grid (design/design_handoff_projet/designs/Carnet de notes.dc.html).
// All evaluations/grades for the current Matière (Topic) are loaded up front as JSON values (same
// convention as group_creation_controller.js) and rendered/filtered/sorted here; only individual
// cell edits round-trip to the server (App\Controller\ProgramGradebookController::saveGrade()).
// Server-side GradeStatus/normalization stay the source of truth (App\Service\
// EvaluationAverageCalculator) - this file mirrors that same math client-side purely so the grid
// can recompute averages instantly after an edit without a full page reload.
export default class extends Controller {
    static targets = ['table', 'thead', 'tbody', 'tfoot', 'periodSelect', 'typeSelect', 'modalitySelect', 'statusSelect'];

    static values = {
        evaluations: Array,
        roster: Array,
        grades: Object,
        periods: Array,
        saveUrlTemplate: String,
        editUrlTemplate: String,
        deactivateUrlTemplate: String,
        detailUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.grades = JSON.parse(JSON.stringify(this.gradesValue));
        // Stimulus Array/Object values re-read and re-parse their data-*-value attribute on
        // every access rather than caching it - a mutation on an object pulled from
        // this.evaluationsValue (e.g. updating classAverage after a grade edit) would otherwise
        // vanish the moment anything reads it again. Caching our own copy once as this.evaluations
        // and treating it as the mutable source of truth from here on avoids that trap.
        this.evaluations = JSON.parse(JSON.stringify(this.evaluationsValue));
        this.filters = { period: 'annee', type: 'all', modality: 'all', status: 'all' };
        this.sortEvalId = null;
        this.sortDir = 'desc';
        this.editing = null;
        this.render();

        this.onAudioChanged = (event) => {
            const { evaluationId, studentId, hasAudio } = event.detail;
            if (!this.grades[evaluationId]) this.grades[evaluationId] = {};
            if (!this.grades[evaluationId][studentId]) this.grades[evaluationId][studentId] = { status: null, value: null, normalizedValue: null, colorClass: 'cm-grade-band-none' };
            this.grades[evaluationId][studentId].hasAudio = hasAudio;
            this.render();
        };
        window.addEventListener('gradebook:audio-changed', this.onAudioChanged);
    }

    disconnect() {
        window.removeEventListener('gradebook:audio-changed', this.onAudioChanged);
    }

    navigateTopic(event) {
        window.location.href = event.target.value;
    }

    onFilterChange() {
        this.filters = {
            period: this.hasPeriodSelectTarget ? this.periodSelectTarget.value : 'annee',
            type: this.typeSelectTarget.value,
            modality: this.modalitySelectTarget.value,
            status: this.statusSelectTarget.value,
        };
        this.render();
    }

    visibleEvaluations() {
        const period = this.periodsValue.find((p) => p.id === this.filters.period);

        return this.evaluations.filter((e) => {
            if (this.filters.type !== 'all' && e.type !== this.filters.type) return false;
            if (this.filters.modality !== 'all' && e.modality !== this.filters.modality) return false;
            if (this.filters.status !== 'all' && e.status !== this.filters.status) return false;
            if (period && (e.date < period.startDate || e.date > period.endDate)) return false;

            return true;
        });
    }

    order(evals) {
        const idx = this.rosterValue.map((_, i) => i);
        const dir = this.sortDir === 'asc' ? 1 : -1;
        let key = null;

        if (this.sortEvalId === 'avg') {
            key = (i) => this.studentAverage(evals, this.rosterValue[i].id);
        } else if (this.sortEvalId) {
            const evaluation = evals.find((e) => e.id === this.sortEvalId);
            if (evaluation) key = (i) => (this.grades[evaluation.id]?.[this.rosterValue[i].id]?.normalizedValue ?? null);
        }
        if (!key) return idx;

        return idx.sort((a, b) => {
            const ka = key(a);
            const kb = key(b);
            if (ka == null && kb == null) return a - b;
            if (ka == null) return 1;
            if (kb == null) return -1;

            return (ka - kb) * dir;
        });
    }

    studentAverage(evals, studentId) {
        let sum = 0;
        let weight = 0;
        for (const e of evals) {
            const cell = this.grades[e.id]?.[studentId];
            if (cell && cell.status === 'normal' && cell.normalizedValue != null) {
                sum += cell.normalizedValue * e.coefficient;
                weight += e.coefficient;
            }
        }

        return weight ? sum / weight : null;
    }

    toggleSort(evalId) {
        if (this.sortEvalId !== evalId) {
            this.sortEvalId = evalId;
            this.sortDir = 'desc';
        } else if (this.sortDir === 'desc') {
            this.sortDir = 'asc';
        } else {
            this.sortEvalId = null;
        }
        this.render();
    }

    render() {
        const evals = this.visibleEvaluations();
        this.renderHead(evals);
        this.renderBody(evals);
        this.renderFoot(evals);
    }

    // "Moyenne d'évaluation en bas de colonne, moyenne de classe en bas de la colonne Moy." -
    // the class-wide average per evaluation (already computed server-side, recomputed live after
    // each cell edit - see commitCell()) and the class's overall average across every visible
    // evaluation for the row currently on screen.
    renderFoot(evals) {
        const tr = document.createElement('tr');

        const labelTd = document.createElement('td');
        labelTd.className = 'text-secondary small';
        labelTd.textContent = this.labelsValue.classAverageRowLabel;
        tr.appendChild(labelTd);

        const classOverall = this.classAverage(evals);
        const avgTd = document.createElement('td');
        avgTd.className = 'text-center fw-bold';
        avgTd.textContent = classOverall == null ? '—' : classOverall.toFixed(2);
        tr.appendChild(avgTd);

        for (const e of evals) {
            const td = document.createElement('td');
            td.className = 'text-center text-secondary small';
            td.textContent = e.classAverage == null ? '—' : e.classAverage.toFixed(2);
            tr.appendChild(td);
        }

        this.tfootTarget.replaceChildren(tr);
    }

    classAverage(evals) {
        const values = this.rosterValue
            .map((_, index) => this.studentAverage(evals, this.rosterValue[index].id))
            .filter((value) => value != null);

        return values.length ? values.reduce((a, b) => a + b, 0) / values.length : null;
    }

    renderHead(evals) {
        const tr = document.createElement('tr');

        const studentTh = document.createElement('th');
        studentTh.textContent = '';
        tr.appendChild(studentTh);

        const avgTh = document.createElement('th');
        avgTh.className = 'text-center';
        avgTh.style.cursor = 'pointer';
        avgTh.textContent = this.labelsValue.averageColumnLabel;
        avgTh.addEventListener('click', () => this.toggleSort('avg'));
        tr.appendChild(avgTh);

        for (const e of evals) {
            const th = document.createElement('th');
            th.className = 'text-center';
            th.style.minWidth = '150px';

            const nameEl = document.createElement('div');
            nameEl.style.cursor = 'pointer';
            nameEl.style.fontWeight = '700';
            nameEl.textContent = e.name + (e.hasRubric ? ' \u{1F4CB}' : '');
            nameEl.addEventListener('click', () => this.toggleSort(e.id));
            th.appendChild(nameEl);

            const meta = document.createElement('div');
            meta.className = 'text-secondary fw-normal';
            meta.style.fontSize = '11px';
            meta.textContent = `${e.date} · /${e.scale} · coef ${e.coefficient}`;
            th.appendChild(meta);

            // Acceptance criterion 4 - visible to the teacher with the badge, invisible (and
            // excluded from averages) to the student until visibleAt (see studentView()'s
            // isVisibleAt() filter and evaluationJson()'s visibleAtLabel).
            if (e.isHidden) {
                const hiddenBadge = document.createElement('div');
                hiddenBadge.className = 'badge bg-secondary-lt';
                hiddenBadge.style.fontSize = '10px';
                hiddenBadge.textContent = `\u{1F441} ${e.visibleAtLabel}`;
                th.appendChild(hiddenBadge);
            }

            const actions = document.createElement('div');
            actions.className = 'cm-actions justify-content-center';

            if (e.hasRubric) {
                const detailLink = document.createElement('a');
                detailLink.href = this.detailUrlTemplateValue.replace('__EVAL_ID__', e.id);
                detailLink.className = 'cm-action--neutral';
                detailLink.title = this.labelsValue.viewDetailTitle;
                detailLink.textContent = '\u{1F4CB}';
                actions.appendChild(detailLink);
            }

            const editLink = document.createElement('a');
            editLink.href = this.editUrlTemplateValue.replace('__EVAL_ID__', e.id);
            editLink.className = 'cm-action--warning';
            editLink.title = this.labelsValue.editEvaluationTitle;
            editLink.textContent = '✎';
            actions.appendChild(editLink);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'cm-action--danger';
            deleteBtn.title = this.labelsValue.deleteEvaluationTitle;
            deleteBtn.textContent = '✕';
            deleteBtn.addEventListener('click', () => this.deleteEvaluation(e.id));
            actions.appendChild(deleteBtn);

            th.appendChild(actions);
            tr.appendChild(th);
        }

        this.theadTarget.replaceChildren(tr);
    }

    renderBody(evals) {
        this.tbodyTarget.replaceChildren();
        const order = this.order(evals);

        for (const studentIndex of order) {
            const student = this.rosterValue[studentIndex];
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            nameTd.textContent = student.name;
            tr.appendChild(nameTd);

            const avgTd = document.createElement('td');
            avgTd.className = 'text-center fw-bold';
            const avg = this.studentAverage(evals, student.id);
            avgTd.textContent = avg == null ? '—' : avg.toFixed(2);
            tr.appendChild(avgTd);

            evals.forEach((e, colIndex) => {
                tr.appendChild(this.buildCell(e, student, colIndex, studentIndex, evals));
            });

            this.tbodyTarget.appendChild(tr);
        }

        if (order.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 2 + evals.length;
            td.className = 'text-center text-secondary';
            td.textContent = '—';
            tr.appendChild(td);
            this.tbodyTarget.appendChild(tr);
        }
    }

    buildCell(evaluation, student, colIndex, rowIndex, evals) {
        const td = document.createElement('td');
        td.className = 'text-center position-relative';
        td.dataset.evalId = evaluation.id;
        td.dataset.studentId = student.id;

        const key = `${evaluation.id}:${student.id}`;
        if (this.editing === key) {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm text-center';
            input.style.width = '84px';
            input.style.display = 'inline-block';
            input.placeholder = this.labelsValue.cellPlaceholder;
            input.value = this.editingValue ?? '';
            input.addEventListener('input', (event) => { this.editingValue = event.target.value; });
            input.addEventListener('blur', () => this.commitCell(evaluation, student));
            input.addEventListener('keydown', (event) => this.onCellKeydown(event, evaluation, student, colIndex, rowIndex, evals));
            td.appendChild(input);
            requestAnimationFrame(() => { input.focus(); input.select(); });

            return td;
        }

        if (evaluation.hasRubric) {
            td.style.cursor = 'pointer';
            td.addEventListener('click', () => { window.location.href = this.detailUrlTemplateValue.replace('__EVAL_ID__', evaluation.id); });
        } else {
            td.style.cursor = 'pointer';
            td.addEventListener('click', () => this.openCell(evaluation, student));
        }

        const cell = this.grades[evaluation.id]?.[student.id];
        const badge = document.createElement('span');
        badge.className = `cm-grade-band-${cell ? this.colorSuffix(cell) : 'none'} px-2 py-1 rounded`;
        badge.style.fontWeight = '600';
        badge.textContent = this.cellDisplay(cell);
        td.appendChild(badge);

        const audioButton = document.createElement('span');
        audioButton.className = 'position-absolute';
        audioButton.style.cssText = 'bottom: 2px; right: 3px; font-size: 11px; cursor: pointer;';
        // Acceptance criterion 7 - "Non écoutée / Écoutée X % / Écoutée" surfaced to the teacher
        // right here, not just inside the recorder modal.
        audioButton.title = cell?.hasAudio ? this.listenStatusLabel(cell.audioListenPercent) : this.labelsValue.audioCommentTitle;
        audioButton.textContent = cell?.hasAudio ? '\u{1F3A7}' : '\u{1F3A4}';
        audioButton.style.opacity = cell?.hasAudio ? '1' : '0.35';
        if (cell?.hasAudio && (cell.audioListenPercent ?? 0) < 90) {
            const dot = document.createElement('span');
            dot.style.cssText = 'position: absolute; top: -3px; right: -3px; width: 6px; height: 6px; border-radius: 50%; background: #e0483a;';
            audioButton.style.position = 'relative';
            audioButton.appendChild(dot);
        }
        audioButton.addEventListener('click', (event) => {
            event.stopPropagation();
            window.dispatchEvent(new CustomEvent('gradebook:open-audio', {
                detail: {
                    evaluationId: evaluation.id,
                    studentId: student.id,
                    studentName: student.name,
                    hasAudio: !!cell?.hasAudio,
                    listenStatusLabel: cell?.hasAudio ? this.listenStatusLabel(cell.audioListenPercent) : null,
                },
            }));
        });
        td.appendChild(audioButton);

        return td;
    }

    colorSuffix(cell) {
        return cell.colorClass ? cell.colorClass.replace('cm-grade-band-', '') : 'none';
    }

    cellDisplay(cell) {
        if (!cell) return '—';
        if (cell.status === 'absent') return this.labelsValue.absentShortLabel;
        if (cell.status === 'not_evaluated') return this.labelsValue.notEvaluatedShortLabel;
        if (cell.status === 'not_tested') return this.labelsValue.notTestedShortLabel;
        if (cell.value == null) return '—';

        const display = Number.isInteger(cell.value) ? String(cell.value) : cell.value.toFixed(1);

        return cell.status === 'excluded' ? `(${display})` : display;
    }

    listenStatusLabel(percent) {
        if (!percent) return this.labelsValue.audioUnlistenedLabel;
        if (percent >= 90) return this.labelsValue.audioListenedLabel;

        return this.labelsValue.audioListenedPercentLabel.replace('%percent%', percent);
    }

    openCell(evaluation, student) {
        const cell = this.grades[evaluation.id]?.[student.id];
        this.editing = `${evaluation.id}:${student.id}`;
        this.editingValue = this.rawValueFor(cell);
        this.render();
    }

    rawValueFor(cell) {
        if (!cell) return '';
        if (cell.status === 'absent') return 'abs';
        if (cell.status === 'not_evaluated') return 'ne';
        if (cell.status === 'not_tested') return 'nt';
        if (cell.value == null) return '';

        return cell.status === 'excluded' ? `(${cell.value})` : String(cell.value);
    }

    async commitCell(evaluation, student) {
        if (this.editing !== `${evaluation.id}:${student.id}`) return;
        const raw = this.editingValue ?? '';
        this.editing = null;

        const url = this.saveUrlTemplateValue.replace('__EVAL_ID__', evaluation.id).replace('__STUDENT_ID__', student.id);
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify({ raw }),
            });
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);
            this.render();

            return;
        }

        if (!response.ok) {
            window.alert(this.labelsValue.networkErrorMessage);
            this.render();

            return;
        }

        const data = await response.json();
        if (!this.grades[evaluation.id]) this.grades[evaluation.id] = {};
        if (data.cleared) {
            delete this.grades[evaluation.id][student.id];
        } else {
            this.grades[evaluation.id][student.id] = {
                status: data.status,
                value: data.value,
                normalizedValue: data.normalizedValue,
                colorClass: data.colorClass,
                hasAudio: this.grades[evaluation.id][student.id]?.hasAudio ?? false,
            };
        }

        const evalObj = this.evaluations.find((e) => e.id === evaluation.id);
        if (evalObj) evalObj.classAverage = data.evaluationAverage;

        this.render();
    }

    onCellKeydown(event, evaluation, student, colIndex, rowIndex, evals) {
        const key = event.key;
        if (!['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(key)) return;
        event.preventDefault();

        if (key === 'Escape') {
            this.editing = null;
            this.render();

            return;
        }

        let nextCol = colIndex;
        let nextRow = rowIndex;
        if (key === 'ArrowRight') nextCol += 1;
        else if (key === 'ArrowLeft') nextCol -= 1;
        else if (key === 'ArrowDown' || key === 'Enter') nextRow += 1;
        else if (key === 'ArrowUp') nextRow -= 1;

        this.commitCell(evaluation, student).then(() => {
            const order = this.order(evals);
            if (nextCol < 0 || nextCol >= evals.length || nextRow < 0 || nextRow >= order.length) return;

            const nextEvaluation = evals[nextCol];
            const nextStudent = this.rosterValue[order[nextRow]];
            if (nextEvaluation.hasRubric) return;
            this.openCell(nextEvaluation, nextStudent);
        });
    }

    async deleteEvaluation(evalId) {
        if (!window.confirm(this.labelsValue.deleteEvaluationConfirmMessage)) return;

        const url = this.deactivateUrlTemplateValue.replace('__EVAL_ID__', evalId);
        let response;
        try {
            response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue } });
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);

            return;
        }

        if (!response.ok) {
            window.alert(this.labelsValue.networkErrorMessage);

            return;
        }

        this.evaluations = this.evaluations.filter((e) => e.id !== evalId);
        this.render();
    }
}
