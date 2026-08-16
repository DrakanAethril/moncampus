import { Controller } from '@hotwired/stimulus';

// Carnet de notes - rubric editor (design/design_handoff_carnet_de_notes, screen 3).
// A two-level structure (sections containing questions), hence not a CollectionType: plain named
// fields sections[i][name] / sections[i][questions][j][label]|[maxPoints], read back by hand on the
// server side (App\Controller\ProgramGradebookController::applyRubricSubmission()) - same reasoning
// as the answer list of QuizQuestionType. The index counters only ever grow: a deleted row leaves a
// gap, with no consequence since PHP iterates the keys actually present.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['sections', 'addTile', 'count', 'total'];

    static values = {
        sections: Array,
        labels: Object,
    };

    connect() {
        this.sectionIndex = 0;
        const initial = this.sectionsValue.length ? this.sectionsValue : [{ name: '', questions: [{ label: '1', maxPoints: 1 }] }];
        for (const section of initial) {
            this.insertSection(section);
        }
        this.refreshTotals();
    }

    addSection() {
        this.insertSection({ name: '', questions: [{ label: '1', maxPoints: 1 }] });
        this.refreshTotals();
    }

    // The « + Ajouter une section » tile is a cell of the grid: any new section is inserted before
    // it, so it stays last.
    insertSection(section) {
        this.sectionsTarget.insertBefore(this.buildSection(section), this.addTileTarget);
    }

    // Footer from the designs: « N questions · X points — barème de l'évaluation : /20 ». Recomputed
    // on every keystroke, it is the only way for the teacher to see that their rubric adds up.
    refreshTotals() {
        const points = [...this.element.querySelectorAll('[data-rubric-points]')];
        this.countTarget.textContent = String(points.length);
        // Rounded to the hundredth: adding quarter points in floating point would otherwise display
        // 20.000000000000004.
        const total = points.reduce((sum, input) => sum + (parseFloat(String(input.value).replace(',', '.')) || 0), 0);
        this.totalTarget.textContent = String(Math.round(total * 100) / 100);
    }

    buildSection(section) {
        const sIndex = this.sectionIndex++;
        const wrapper = this.el('div', 'cm-gb-bar-section');
        wrapper.dataset.questionIndex = '0';

        const head = this.el('div', 'd-flex align-items-center gap-2');
        const nameInput = this.el('input', 'cm-gb-bar-name');
        nameInput.type = 'text';
        nameInput.name = `sections[${sIndex}][name]`;
        nameInput.value = section.name;
        nameInput.placeholder = this.labelsValue.sectionNameLabel;
        head.appendChild(nameInput);

        const removeSection = this.el('button', 'cm-gb-iconbtn cm-gb-iconbtn--danger');
        removeSection.type = 'button';
        removeSection.title = this.labelsValue.removeSectionLabel;
        removeSection.setAttribute('aria-label', this.labelsValue.removeSectionLabel);
        removeSection.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 13));
        removeSection.addEventListener('click', () => { wrapper.remove(); this.refreshTotals(); });
        head.appendChild(removeSection);
        wrapper.appendChild(head);

        const questions = this.el('div', 'd-flex flex-column gap-2');
        wrapper.appendChild(questions);
        for (const question of section.questions) {
            questions.appendChild(this.buildQuestion(wrapper, sIndex, question));
        }

        const addQuestion = this.el('button', 'cm-gb-dashed', this.labelsValue.addQuestionLabel);
        addQuestion.type = 'button';
        addQuestion.addEventListener('click', () => {
            questions.appendChild(this.buildQuestion(wrapper, sIndex, { label: '', maxPoints: 1 }));
            this.refreshTotals();
        });
        wrapper.appendChild(addQuestion);

        return wrapper;
    }

    buildQuestion(sectionEl, sIndex, question) {
        const qIndex = Number(sectionEl.dataset.questionIndex);
        sectionEl.dataset.questionIndex = String(qIndex + 1);

        const row = this.el('div', 'cm-gb-bar-qrow');
        row.appendChild(this.el('span', 'cm-gb-bar-qlabel', this.labelsValue.questionLabel));

        const labelInput = this.el('input', 'cm-gb-bar-input');
        labelInput.type = 'text';
        labelInput.style.width = '110px';
        labelInput.name = `sections[${sIndex}][questions][${qIndex}][label]`;
        labelInput.value = question.label;
        labelInput.placeholder = this.labelsValue.questionNumLabel;
        row.appendChild(labelInput);

        row.appendChild(this.el('span', 'cm-gb-bar-qlabel', this.labelsValue.questionPointsLabel));

        const pointsInput = this.el('input', 'cm-gb-bar-input');
        pointsInput.type = 'number';
        // Quarter point: that is the grading step in use, and it applies to the points of a rubric
        // question as much as to the grade itself.
        pointsInput.step = '0.25';
        pointsInput.min = '0.25';
        pointsInput.style.cssText = 'width: 76px; text-align: center;';
        pointsInput.name = `sections[${sIndex}][questions][${qIndex}][maxPoints]`;
        pointsInput.value = question.maxPoints;
        pointsInput.dataset.rubricPoints = '';
        pointsInput.addEventListener('input', () => this.refreshTotals());
        row.appendChild(pointsInput);

        const remove = this.el('button', 'btn btn-link text-secondary p-1');
        remove.type = 'button';
        remove.title = this.labelsValue.removeQuestionLabel;
        remove.setAttribute('aria-label', this.labelsValue.removeQuestionLabel);
        remove.appendChild(this.icon('M18 6 6 18M6 6l12 12', 13));
        remove.addEventListener('click', () => { row.remove(); this.refreshTotals(); });
        row.appendChild(remove);

        return row;
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

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
        for (const d of paths.split('|')) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        }

        return svg;
    }
}
