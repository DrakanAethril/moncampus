import { Controller } from '@hotwired/stimulus';

// Carnet de notes - grille de classe (design/design_handoff_carnet_de_notes, écran 1).
// Volontairement pas un <table> : la colonne des élèves reste collée à gauche pendant que les
// colonnes d'évaluations défilent horizontalement, ce qu'un vrai tableau ne tient pas de façon
// fiable ; la grille est donc une rangée de colonnes flex, chacune peignant ses propres lignes de
// 44px (voir .cm-gb-* dans app.css, dont les hauteurs doivent rester alignées d'une colonne à
// l'autre).
// Toutes les évaluations/notes de la matière courante arrivent en JSON au chargement (même
// convention que group_creation_controller.js) et sont filtrées/triées/rendues ici ; seule
// l'édition d'une cellule fait un aller-retour serveur (ProgramGradebookController::saveGrade()).
// Le calcul des moyennes est refait ici à l'identique de App\Service\EvaluationAverageCalculator,
// uniquement pour rafraîchir la grille sans recharger la page - le serveur reste la référence.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['grid', 'periodSelect', 'confirmModal', 'confirmText'];

    static values = {
        // Faux pour un enseignant référent qui consulte la matière d'un collègue : même grille,
        // sans aucune affordance d'écriture. Par défaut faux, donc un attribut manquant ferme
        // l'écriture plutôt que de l'ouvrir - le serveur tranche de toute façon
        // (App\Security\Voter\EvaluationVoter::MANAGE).
        editable: Boolean,
        evaluations: Array,
        roster: Array,
        grades: Object,
        periods: Array,
        saveUrlTemplate: String,
        editUrlTemplate: String,
        deactivateUrlTemplate: String,
        entryUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.grades = JSON.parse(JSON.stringify(this.gradesValue));
        // Les valeurs Array/Object de Stimulus relisent et reparsent leur attribut data-*-value à
        // chaque accès au lieu de le mémoriser : une mutation faite sur un objet tiré de
        // this.evaluationsValue (mettre à jour classAverage après une saisie, par exemple)
        // disparaîtrait à la lecture suivante. D'où cette copie unique, seule source mutable.
        this.evaluations = JSON.parse(JSON.stringify(this.evaluationsValue));
        this.filters = { period: 'annee', type: 'all', modality: 'all', status: 'all' };
        this.sortEvalId = null;
        this.sortDir = 'desc';
        this.editing = null;
        this.pendingDeleteId = null;
        this.render();
    }

    navigateTopic(event) {
        window.location.href = event.target.value;
    }

    setFilter(event) {
        const button = event.currentTarget;
        const { filter, value } = button.dataset;
        this.filters[filter] = value;
        for (const sibling of button.parentElement.querySelectorAll('.cm-gb-chip')) {
            sibling.classList.toggle('is-active', sibling === button);
        }
        this.render();
    }

    onFilterChange() {
        this.filters.period = this.hasPeriodSelectTarget ? this.periodSelectTarget.value : 'annee';
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

    classAverage(evals) {
        const values = this.rosterValue
            .map((student) => this.studentAverage(evals, student.id))
            .filter((value) => value != null);

        return values.length ? values.reduce((a, b) => a + b, 0) / values.length : null;
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
        const order = this.order(evals);
        const columns = [this.buildStudentColumn(evals, order), this.buildAverageColumn(evals, order)];
        evals.forEach((e, colIndex) => columns.push(this.buildEvaluationColumn(e, colIndex, evals, order)));
        this.gridTarget.replaceChildren(...columns);
    }

    // ---- Colonnes -------------------------------------------------------------------------

    buildStudentColumn(evals, order) {
        const column = this.el('div', 'cm-gb-col--students');

        const head = this.el('div', 'cm-gb-head cm-gb-head--students');
        head.appendChild(this.el('span', 'cm-gb-label', `${this.labelsValue.studentsColumnLabel} · ${this.rosterValue.length}`));
        column.appendChild(head);

        for (const studentIndex of order) {
            const student = this.rosterValue[studentIndex];
            const row = this.el('div', 'cm-gb-row');
            row.appendChild(this.el('span', 'cm-gb-student', student.name));
            if (student.option) row.appendChild(this.optionTag(student));
            column.appendChild(row);
        }

        column.appendChild(this.el('div', 'cm-gb-foot cm-gb-foot--students', this.labelsValue.classAverageRowLabel));

        return column;
    }

    buildAverageColumn(evals, order) {
        const column = this.el('div', 'cm-gb-col--avg');

        const head = this.el('div', 'cm-gb-head cm-gb-head--avg');
        head.appendChild(this.el('span', 'cm-gb-label', this.labelsValue.averageColumnLabel));
        head.appendChild(this.sortButton('avg', this.labelsValue.sortByAverageTitle, true));
        column.appendChild(head);

        for (const studentIndex of order) {
            const average = this.studentAverage(evals, this.rosterValue[studentIndex].id);
            const row = this.el('div', 'cm-gb-row cm-gb-row--avg');
            row.appendChild(this.el('span', `cm-gb-avg ${this.bandClass(average)}`, average == null ? '—' : average.toFixed(2)));
            column.appendChild(row);
        }

        const classAverage = this.classAverage(evals);
        column.appendChild(this.el('div', 'cm-gb-foot cm-gb-foot--avg', classAverage == null ? '—' : classAverage.toFixed(2)));

        return column;
    }

    buildEvaluationColumn(evaluation, colIndex, evals, order) {
        const column = this.el('div', 'cm-gb-col--eval');
        column.appendChild(this.buildEvaluationHead(evaluation));

        order.forEach((studentIndex, rowIndex) => {
            column.appendChild(this.buildCell(evaluation, this.rosterValue[studentIndex], colIndex, rowIndex, evals, order));
        });

        column.appendChild(this.el('div', 'cm-gb-foot', evaluation.classAverage == null ? '—' : evaluation.classAverage.toFixed(2)));

        return column;
    }

    buildEvaluationHead(evaluation) {
        const head = this.el('div', 'cm-gb-head');

        const name = this.el('div', 'cm-gb-evname');
        // Pastille D/F/S du module Progression pédagogique - absente pour toute évaluation sans
        // nature, ce qui est le cas normal d'une évaluation créée depuis cet écran.
        if (evaluation.nature) {
            const pastille = this.el('span', `cm-prog-natureDot cm-prog-natureDot--${evaluation.nature}`, evaluation.natureInitial);
            pastille.title = this.labelsValue[`nature_${evaluation.nature}`] ?? '';
            name.appendChild(pastille);
        }
        name.append(evaluation.name);
        name.title = evaluation.name;
        name.addEventListener('click', () => this.toggleSort(evaluation.id));
        head.appendChild(name);

        head.appendChild(this.el('div', 'cm-gb-evmeta', `${evaluation.dateLabel} · /${evaluation.scale} · ${this.labelsValue.coefficientShortLabel} ${evaluation.coefficient}`));

        // Visible pour l'enseignant avec ce badge, invisible pour l'élève (et hors moyennes)
        // jusqu'à l'échéance - voir studentView() et evaluationJson() côté serveur.
        if (evaluation.isHidden) {
            const badge = this.el('div', 'cm-gb-evhidden');
            badge.title = evaluation.visibleAtLabel;
            badge.appendChild(this.icon('M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94|M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19|M1 1 23 23', 9));
            badge.append(evaluation.visibleAtLabel);
            head.appendChild(badge);
        }

        const actions = this.el('div', 'cm-gb-evactions');

        if (this.editableValue) {
            actions.appendChild(this.linkButton(
                this.entryUrlTemplateValue.replace('__EVAL_ID__', evaluation.id),
                'cm-gb-iconbtn cm-gb-iconbtn--primary',
                this.labelsValue.enterGradesTitle,
                'M3 5h18v14H3z|M7 9h.01M11 9h.01M15 9h.01M7 13h.01M11 13h.01M15 13h.01M7 17h10',
            ));
            actions.appendChild(this.linkButton(
                this.editUrlTemplateValue.replace('__EVAL_ID__', evaluation.id),
                'cm-gb-iconbtn',
                this.labelsValue.editEvaluationTitle,
                'M12 20h9|M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z',
            ));

            const del = this.el('button', 'cm-gb-iconbtn cm-gb-iconbtn--danger');
            del.type = 'button';
            del.title = this.labelsValue.deleteEvaluationTitle;
            del.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 12));
            del.addEventListener('click', () => this.askDelete(evaluation));
            actions.appendChild(del);
        }

        actions.appendChild(this.sortButton(evaluation.id, this.labelsValue.sortByEvaluationTitle, false));
        head.appendChild(actions);

        return head;
    }

    // ---- Cellules -------------------------------------------------------------------------

    buildCell(evaluation, student, colIndex, rowIndex, evals, order) {
        const td = this.el('div', `cm-gb-cell${this.editableValue ? '' : ' cm-gb-cell--readonly'}`);

        const key = `${evaluation.id}:${student.id}`;
        if (this.editing === key) {
            const input = this.el('input', 'cm-gb-cell__input');
            input.type = 'text';
            input.placeholder = this.labelsValue.cellPlaceholder;
            input.title = this.labelsValue.cellInputTitle;
            input.value = this.editingValue ?? '';
            input.addEventListener('input', (event) => { this.editingValue = event.target.value; });
            input.addEventListener('blur', () => this.commitCell(evaluation, student));
            input.addEventListener('keydown', (event) => this.onCellKeydown(event, evaluation, student, colIndex, rowIndex, evals, order));
            td.appendChild(input);
            requestAnimationFrame(() => { input.focus(); input.select(); });

            return td;
        }

        const entryUrl = this.entryUrlTemplateValue.replace('__EVAL_ID__', evaluation.id);
        if (evaluation.hasRubric) {
            // Une évaluation à barème ne se saisit pas dans la grille : la cellule ouvre l'écran
            // de saisie, où chaque question a sa case. Accessible en lecture seule également.
            td.addEventListener('click', () => { window.location.href = entryUrl; });
        } else if (this.editableValue) {
            td.addEventListener('click', () => this.openCell(evaluation, student));
        }

        const cell = this.grades[evaluation.id]?.[student.id];
        td.appendChild(this.cellValue(cell));

        // Enregistrer/réécouter un commentaire audio se fait dans l'écran de saisie : la grille
        // n'en montre que la présence, et y renvoie.
        if (this.editableValue && cell?.hasAudio) {
            const audio = this.el('button', 'cm-gb-cell__audio');
            audio.type = 'button';
            audio.title = this.labelsValue.audioCommentTitle;
            audio.appendChild(this.icon('M9 2h6v12H9z|M5 10a7 7 0 0 0 14 0M12 17v4', 9));
            audio.addEventListener('click', (event) => {
                event.stopPropagation();
                window.location.href = entryUrl;
            });
            td.appendChild(audio);
        }

        return td;
    }

    cellValue(cell) {
        if (!cell) return this.el('span', 'cm-gb-val cm-gb-val--empty', '·');
        if (cell.status === 'absent') return this.el('span', 'cm-gb-val cm-gb-val--abs', this.labelsValue.absentShortLabel);
        if (cell.status === 'not_evaluated') return this.el('span', 'cm-gb-val cm-gb-val--ne', this.labelsValue.notEvaluatedShortLabel);
        if (cell.status === 'not_tested') return this.el('span', 'cm-gb-val cm-gb-val--nt', this.labelsValue.notTestedShortLabel);
        if (cell.value == null) return this.el('span', 'cm-gb-val cm-gb-val--empty', '·');

        const display = Number.isInteger(cell.value) ? String(cell.value) : cell.value.toFixed(1);
        if (cell.status === 'excluded') {
            return this.el('span', `cm-gb-val cm-gb-val--paren ${cell.colorClass}`, `(${display})`);
        }

        return this.el('span', `cm-gb-val ${cell.colorClass}`, display);
    }

    openCell(evaluation, student) {
        if (!this.editableValue) return;

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

    onCellKeydown(event, evaluation, student, colIndex, rowIndex, evals, order) {
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
            if (nextCol < 0 || nextCol >= evals.length || nextRow < 0 || nextRow >= order.length) return;

            const nextEvaluation = evals[nextCol];
            const nextStudent = this.rosterValue[order[nextRow]];
            if (nextEvaluation.hasRubric) return;
            this.openCell(nextEvaluation, nextStudent);
        });
    }

    // ---- Suppression ----------------------------------------------------------------------

    askDelete(evaluation) {
        this.pendingDeleteId = evaluation.id;
        this.confirmTextTarget.textContent = this.labelsValue.deleteEvaluationConfirmMessage.replace('%name%', evaluation.name);
        this.confirmModalTarget.classList.add('show');
        this.confirmModalTarget.style.display = 'block';
    }

    closeConfirm() {
        this.pendingDeleteId = null;
        this.confirmModalTarget.classList.remove('show');
        this.confirmModalTarget.style.display = 'none';
    }

    async confirmDelete() {
        const evalId = this.pendingDeleteId;
        this.closeConfirm();
        if (!evalId) return;

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

    // ---- Fabriques d'éléments -------------------------------------------------------------

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

    // Nom court sur la couleur propre de l'Option, comme partout ailleurs dans l'application.
    optionTag(student) {
        const tag = this.el('span', 'cm-gb-tag', student.option);
        if (student.optionColor) tag.style.backgroundColor = student.optionColor;

        return tag;
    }

    // Les chemins sont séparés par des "|" : un seul argument suffit pour les icônes à plusieurs
    // tracés, sans avoir à passer un tableau à chaque appel.
    icon(paths, size) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', size);
        svg.setAttribute('height', size);
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.style.flex = 'none';
        for (const d of paths.split('|')) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        }

        return svg;
    }

    linkButton(href, className, title, paths) {
        const link = this.el('a', className);
        link.href = href;
        link.title = title;
        link.setAttribute('aria-label', title);
        link.appendChild(this.icon(paths, 12));

        return link;
    }

    // Les deux chevrons du tri : celui de la direction active est peint en bleu de marque, l'autre
    // reste gris - c'est le seul indicateur de tri de la grille.
    sortButton(sortKey, title, isAverage) {
        const button = this.el('button', `cm-gb-sortbtn${isAverage ? ' cm-gb-sortbtn--avg' : ''}`);
        button.type = 'button';
        button.title = title;
        button.setAttribute('aria-label', title);

        const active = this.sortEvalId === sortKey;
        for (const direction of ['asc', 'desc']) {
            const chevron = this.icon(direction === 'asc' ? 'M2 12l10-8 10 8' : 'M2 4l10 8 10-8', 9);
            chevron.setAttribute('viewBox', '0 0 24 16');
            chevron.setAttribute('height', '5');
            chevron.style.color = active && this.sortDir === direction ? 'var(--cm-brand)' : 'var(--cm-disabled)';
            button.appendChild(chevron);
        }

        button.addEventListener('click', () => this.toggleSort(sortKey));

        return button;
    }

    bandClass(average) {
        if (average == null) return 'cm-grade-band-none';
        if (average <= 5) return 'cm-grade-band-1';
        if (average <= 10) return 'cm-grade-band-2';
        if (average <= 15) return 'cm-grade-band-3';

        return 'cm-grade-band-4';
    }
}
