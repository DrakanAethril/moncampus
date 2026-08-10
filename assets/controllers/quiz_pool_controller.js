import { Controller } from '@hotwired/stimulus';

/*
 * The "rajouter un quiz" rows shared by the two launch screens - Bibliothèque > Lancer (1c) and
 * Outils > Concours live > Nouveau. Both merge several QuizTemplates into one question pool
 * (App\Service\QuizInstantiationService), so both need the same three things: add a row, drop a
 * row, and keep a running total of the pool size.
 *
 * The total is announced with a `quiz-pool:change` event rather than written into the page here:
 * screen 1c's own controller (quiz_launch_controller.js) owns the draw recap and needs the number,
 * while the live screen just prints it. Whoever listens decides what it means.
 *
 * Screen 1c has a fixed base quiz (the page's own) and passes its size as `baseCount`; the live
 * screen picks its base from a select, which is simply one more `select` target and leaves
 * `baseCount` at 0. Nothing else differs between the two.
 */
export default class extends Controller {
    static targets = ['rows', 'select', 'total', 'addButton'];

    static values = {
        // templateId -> { questions, defaultCount }: the quiz's bank size, and the number of
        // questions it draws by default (screen 1n). Summing the second is what lets the launch
        // screen widen its draw when quizzes are merged - see quiz_launch_controller.js.
        counts: Object,
        baseCount: { type: Number, default: 0 },
        baseDefaultCount: { type: Number, default: 0 },
        prototype: String,
        // Rows are cheap but a merge of every quiz in a library is not what this is for; it also
        // keeps the <select> count bounded on a page that renders every option in every row.
        max: { type: Number, default: 20 },
    };

    connect() {
        this.update();
    }

    add(event) {
        event.preventDefault();

        if (this.rowCount >= this.maxValue) {
            return;
        }

        // Symfony's collection prototype names its placeholder __quiz__ (App\Form\QuizLaunchType);
        // the live screen has no Symfony form at all and passes plain markup with no placeholder,
        // so the replace is a no-op there rather than a special case.
        const index = this.rowsTarget.querySelectorAll('[data-quiz-pool-row]').length;
        const markup = this.prototypeValue.replace(/__quiz__/g, String(index));

        const holder = document.createElement('div');
        holder.innerHTML = markup.trim();
        const row = holder.firstElementChild;
        if (row === null) {
            return;
        }

        this.rowsTarget.appendChild(row);
        this.update();

        const select = row.querySelector('select');
        if (select !== null) {
            select.focus();
        }
    }

    remove(event) {
        event.preventDefault();

        const row = event.currentTarget.closest('[data-quiz-pool-row]');
        if (row !== null) {
            row.remove();
        }

        this.update();
    }

    // Named `update` rather than bound to a specific event: a row's own select fires it on change,
    // add()/remove() call it directly, and connect() uses it for the initial render.
    update() {
        // Object values re-parse on every access - read once (see CLAUDE.md's Stimulus gotcha).
        const counts = this.countsValue;

        let total = this.baseCountValue;
        let defaultTotal = this.baseDefaultCountValue;
        this.selectTargets.forEach((select) => {
            const entry = counts[select.value];
            if (entry !== undefined) {
                total += entry.questions;
                defaultTotal += entry.defaultCount;
            }
        });

        if (this.hasTotalTarget) {
            this.totalTarget.textContent = String(total);
        }

        if (this.hasAddButtonTarget) {
            this.addButtonTarget.disabled = this.rowCount >= this.maxValue;
        }

        this.dispatch('change', { detail: { total, defaultTotal } });
    }

    get rowCount() {
        return this.hasRowsTarget ? this.rowsTarget.querySelectorAll('[data-quiz-pool-row]').length : 0;
    }
}
