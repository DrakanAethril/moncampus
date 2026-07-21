import { Controller } from '@hotwired/stimulus';

// Carnet de notes - barème (rubric) editor (design's barAddSec()/barAddQ()/barDelSec()/barDelQ()).
// Unlike EvaluationPeriodGroupType's CollectionType-driven periods (see that form's docblock),
// this is a genuinely two-level dynamic structure (sections containing questions) - plain
// form-field inputs named sections[i][name]/sections[i][questions][j][label]/[maxPoints], resolved
// manually server-side (App\Controller\ProgramGradebookController::applyRubricSubmission()), same
// reasoning as QuizQuestionType's answers list. Index counters only ever increase - removed
// rows leave gaps, which is fine since PHP iterates whatever keys are actually present.
export default class extends Controller {
    static targets = ['sections'];

    static values = {
        sections: Array,
        labels: Object,
    };

    connect() {
        this.sectionIndex = 0;
        const initial = this.sectionsValue.length ? this.sectionsValue : [{ name: '', questions: [{ label: '1', maxPoints: 1 }] }];
        for (const section of initial) {
            this.sectionsTarget.appendChild(this.buildSection(section));
        }
    }

    addSection() {
        this.sectionsTarget.appendChild(this.buildSection({ name: '', questions: [{ label: '1', maxPoints: 1 }] }));
    }

    buildSection(section) {
        const sIndex = this.sectionIndex++;
        const wrapper = document.createElement('div');
        wrapper.className = 'border rounded p-3 mb-3';
        wrapper.dataset.questionIndex = '0';

        const head = document.createElement('div');
        head.className = 'd-flex gap-2 align-items-center mb-2';
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'form-control';
        nameInput.name = `sections[${sIndex}][name]`;
        nameInput.value = section.name;
        nameInput.placeholder = this.labelsValue.sectionNameLabel;
        head.appendChild(nameInput);

        const removeSectionBtn = document.createElement('button');
        removeSectionBtn.type = 'button';
        removeSectionBtn.className = 'btn btn-outline-danger btn-sm';
        removeSectionBtn.title = this.labelsValue.removeSectionLabel;
        removeSectionBtn.textContent = '✕';
        removeSectionBtn.addEventListener('click', () => wrapper.remove());
        head.appendChild(removeSectionBtn);
        wrapper.appendChild(head);

        const questionsContainer = document.createElement('div');
        wrapper.appendChild(questionsContainer);

        for (const question of section.questions) {
            questionsContainer.appendChild(this.buildQuestion(wrapper, sIndex, question));
        }

        const addQuestionBtn = document.createElement('button');
        addQuestionBtn.type = 'button';
        addQuestionBtn.className = 'btn btn-outline-primary btn-sm';
        addQuestionBtn.textContent = this.labelsValue.addQuestionLabel;
        addQuestionBtn.addEventListener('click', () => {
            questionsContainer.appendChild(this.buildQuestion(wrapper, sIndex, { label: '', maxPoints: 1 }));
        });
        wrapper.appendChild(addQuestionBtn);

        return wrapper;
    }

    buildQuestion(sectionEl, sIndex, question) {
        const qIndex = Number(sectionEl.dataset.questionIndex);
        sectionEl.dataset.questionIndex = String(qIndex + 1);

        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center mb-2';

        const labelCol = document.createElement('div');
        labelCol.className = 'col-3';
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = 'form-control form-control-sm';
        labelInput.name = `sections[${sIndex}][questions][${qIndex}][label]`;
        labelInput.value = question.label;
        labelInput.placeholder = this.labelsValue.questionNumLabel;
        labelCol.appendChild(labelInput);
        row.appendChild(labelCol);

        const pointsCol = document.createElement('div');
        pointsCol.className = 'col-3';
        const pointsInput = document.createElement('input');
        pointsInput.type = 'number';
        pointsInput.step = '0.5';
        pointsInput.min = '0.5';
        pointsInput.className = 'form-control form-control-sm';
        pointsInput.name = `sections[${sIndex}][questions][${qIndex}][maxPoints]`;
        pointsInput.value = question.maxPoints;
        pointsInput.placeholder = this.labelsValue.questionPointsLabel;
        pointsCol.appendChild(pointsInput);
        row.appendChild(pointsCol);

        const removeCol = document.createElement('div');
        removeCol.className = 'col-auto';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger btn-sm';
        removeBtn.title = this.labelsValue.removeQuestionLabel;
        removeBtn.textContent = '✕';
        removeBtn.addEventListener('click', () => row.remove());
        removeCol.appendChild(removeBtn);
        row.appendChild(removeCol);

        return row;
    }
}
