import { Controller } from '@hotwired/stimulus';

// Carnet de notes - barème detail grid (one evaluation, per-question capped inputs per student -
// design screen 1c). Columns are questions (grouped under section header bands), rows are
// students; each cell is capped client-side at the question's maxPoints (mirroring
// App\Service\EvaluationAverageCalculator::computeRubricTotal() server-side) and the row's total
// column updates from the server's authoritative recomputed sum after every save. 2-axis keyboard
// nav: left/right across questions, up/down across students - same commit-then-move pattern as
// evaluation_grid_controller.js.
export default class extends Controller {
    static targets = ['tbody'];

    static values = {
        sections: Array,
        roster: Array,
        answers: Object,
        totals: Object,
        saveUrlTemplate: String,
        csrfToken: String,
        notTestedLabel: String,
        networkErrorMessage: String,
    };

    connect() {
        this.answers = JSON.parse(JSON.stringify(this.answersValue));
        this.totals = JSON.parse(JSON.stringify(this.totalsValue));
        this.questions = this.sectionsValue.flatMap((section) => section.questions);
        this.render();
    }

    render() {
        this.tbodyTarget.replaceChildren();

        this.rosterValue.forEach((student, rowIndex) => {
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            nameTd.textContent = student.name;
            tr.appendChild(nameTd);

            this.questions.forEach((question, colIndex) => {
                tr.appendChild(this.buildCell(student, question, rowIndex, colIndex));
            });

            const totalTd = document.createElement('td');
            totalTd.className = 'text-center fw-bold';
            totalTd.dataset.totalFor = student.id;
            const total = this.totals[student.id];
            totalTd.textContent = total == null ? '—' : total;
            tr.appendChild(totalTd);

            this.tbodyTarget.appendChild(tr);
        });
    }

    buildCell(student, question, rowIndex, colIndex) {
        const td = document.createElement('td');
        td.className = 'text-center';

        const raw = this.answers[student.id]?.[question.id];
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm text-center';
        input.style.width = '64px';
        input.style.display = 'inline-block';
        input.value = raw === 'nt' ? 'nt' : (raw ?? '');
        input.dataset.studentId = student.id;
        input.dataset.questionId = question.id;
        input.dataset.maxPoints = question.maxPoints;
        input.addEventListener('blur', () => this.commit(student, question, input));
        input.addEventListener('keydown', (event) => this.onKeydown(event, student, question, rowIndex, colIndex, input));
        td.appendChild(input);

        return td;
    }

    async commit(student, question, input) {
        const raw = input.value.trim();
        // Mirrors the server-side cap (App\Service\EvaluationAverageCalculator::computeRubricTotal())
        // so an over-limit value doesn't sit displayed as typed once accepted.
        if (raw !== '' && raw.toLowerCase() !== 'nt') {
            const numeric = parseFloat(raw.replace(',', '.'));
            if (!Number.isNaN(numeric)) {
                input.value = String(Math.max(0, Math.min(Number(input.dataset.maxPoints), numeric)));
            }
        }

        const url = this.saveUrlTemplateValue.replace('__STUDENT_ID__', student.id).replace('__QUESTION_ID__', question.id);

        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify({ raw }),
            });
        } catch (e) {
            window.alert(this.networkErrorMessageValue);

            return;
        }

        if (!response.ok) {
            window.alert(this.networkErrorMessageValue);

            return;
        }

        const data = await response.json();
        if (!this.answers[student.id]) this.answers[student.id] = {};
        this.answers[student.id][question.id] = raw === '' ? undefined : (raw.toLowerCase() === 'nt' ? 'nt' : parseFloat(raw.replace(',', '.')));
        this.totals[student.id] = data.total;

        const totalCell = this.tbodyTarget.querySelector(`[data-total-for="${student.id}"]`);
        if (totalCell) totalCell.textContent = data.total == null ? '—' : data.total;
    }

    onKeydown(event, student, question, rowIndex, colIndex, input) {
        const key = event.key;
        if (!['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown', 'Enter'].includes(key)) return;
        event.preventDefault();

        let nextRow = rowIndex;
        let nextCol = colIndex;
        if (key === 'ArrowRight') nextCol += 1;
        else if (key === 'ArrowLeft') nextCol -= 1;
        else if (key === 'ArrowDown' || key === 'Enter') nextRow += 1;
        else if (key === 'ArrowUp') nextRow -= 1;

        this.commit(student, question, input).then(() => {
            if (nextRow < 0 || nextRow >= this.rosterValue.length || nextCol < 0 || nextCol >= this.questions.length) return;

            const nextInput = this.tbodyTarget.querySelectorAll('tr')[nextRow]?.querySelectorAll('input')[nextCol];
            if (nextInput) {
                nextInput.focus();
                nextInput.select();
            }
        });
    }
}
