import { Controller } from '@hotwired/stimulus';

/**
 * The Numérique / Calculée panel of the question editor
 * (templates/library/_quiz_numeric_editor.html.twig).
 *
 * Its one real job is the variable table of a calculée: the {name} placeholders in the *statement*
 * are what decide which variables exist, so the rows are rebuilt from the text on every keystroke -
 * same contract as quiz_blanks_editor_controller.js for the "..." blanks and
 * quiz_zone_editor_controller.js for the [[id|texte]] zones. Whatever the teacher had already typed
 * for a variable survives a re-render, because a stray keystroke in the statement must not wipe a
 * range they spent time on.
 *
 * It also reports whether the formula reads any variable the statement does not provide - the one
 * mistake that produces a question nobody can answer, and one that is invisible until a student
 * sits it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['rows', 'count', 'formulaInput', 'formulaState'];
    static values = {
        variables: Object,
        countTemplate: String,
        countEmptyText: String,
        minLabel: String,
        maxLabel: String,
        stepLabel: String,
        decimalsLabel: String,
    };

    connect() {
        // What was saved, then whatever the teacher edits on top - the row set is rebuilt from the
        // statement, this is only the memory of each row's numbers.
        this.values = { ...this.variablesValue };
        this.statementInput = this.element.closest('form')?.querySelector('[data-quiz-blanks-editor-target="input"]');
        this.onStatementInput = () => this.refresh();
        this.statementInput?.addEventListener('input', this.onStatementInput);
        this.refresh();
    }

    disconnect() {
        this.statementInput?.removeEventListener('input', this.onStatementInput);
    }

    refresh() {
        this.captureRows();
        this.renderRows(this.statementNames());
        this.renderFormulaState();
    }

    /** The {name} placeholders of the statement, in reading order and without repeats. */
    statementNames() {
        const text = this.statementInput?.value ?? '';
        const names = [];
        for (const match of text.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)) {
            if (!names.includes(match[1])) {
                names.push(match[1]);
            }
        }

        return names;
    }

    // Reads the rows currently on screen back into this.values, so a re-render keeps them.
    captureRows() {
        if (!this.hasRowsTarget) {
            return;
        }

        this.rowsTarget.querySelectorAll('[data-variable-name]').forEach((row) => {
            const name = row.dataset.variableName;
            const read = (field) => row.querySelector(`[data-field="${field}"]`)?.value ?? '';
            this.values[name] = {
                name,
                min: read('min'),
                max: read('max'),
                step: read('step'),
                decimals: read('decimals'),
            };
        });
    }

    renderRows(names) {
        if (!this.hasRowsTarget) {
            return;
        }

        this.rowsTarget.innerHTML = '';
        names.forEach((name, index) => {
            const saved = this.values[name] ?? {};
            const row = document.createElement('div');
            row.className = 'cm-zone-editor__row';
            row.dataset.variableName = name;
            row.innerHTML = `
                <span class="cm-zone-editor__id">{${name}}</span>
                <input type="hidden" name="numeric[variables][${index}][name]" value="${name}">
                <label class="cm-numeric-editor__field">${this.minLabelValue}
                    <input type="number" step="any" class="form-control form-control-sm" data-field="min"
                           name="numeric[variables][${index}][min]" value="${saved.min ?? 1}"></label>
                <label class="cm-numeric-editor__field">${this.maxLabelValue}
                    <input type="number" step="any" class="form-control form-control-sm" data-field="max"
                           name="numeric[variables][${index}][max]" value="${saved.max ?? 10}"></label>
                <label class="cm-numeric-editor__field">${this.stepLabelValue}
                    <input type="number" step="any" min="0" class="form-control form-control-sm" data-field="step"
                           name="numeric[variables][${index}][step]" value="${saved.step ?? 1}"></label>
                <label class="cm-numeric-editor__field">${this.decimalsLabelValue}
                    <input type="number" min="0" max="6" class="form-control form-control-sm" data-field="decimals"
                           name="numeric[variables][${index}][decimals]" value="${saved.decimals ?? 0}"></label>
            `;
            this.rowsTarget.appendChild(row);
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = names.length
                ? this.countTemplateValue.replace('%count%', String(names.length))
                : this.countEmptyTextValue;
        }
    }

    /**
     * Names the formula reads that the statement never draws. Deliberately a plain scan for
     * identifiers minus the known function and constant names: the server has the real parser
     * (App\Util\FormulaEvaluator) and re-validates on save, so this only has to be right enough to
     * catch a typo while it is still on screen.
     */
    renderFormulaState() {
        if (!this.hasFormulaStateTarget || !this.hasFormulaInputTarget) {
            return;
        }

        const known = new Set([
            'abs', 'sqrt', 'exp', 'ln', 'log10', 'log', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan',
            'floor', 'ceil', 'round', 'pow', 'min', 'max', 'pi', 'e',
        ]);
        const drawn = new Set(this.statementNames());
        const missing = [];

        for (const match of this.formulaInputTarget.value.matchAll(/[a-zA-Z_][a-zA-Z0-9_]*/g)) {
            const name = match[0];
            if (known.has(name.toLowerCase()) || drawn.has(name) || missing.includes(name)) {
                continue;
            }
            missing.push(name);
        }

        this.formulaStateTarget.textContent = missing.length
            ? this.formulaStateTarget.dataset.missingTemplate?.replace('%names%', missing.join(', ')) ?? `⚠ ${missing.join(', ')}`
            : '';
        this.formulaStateTarget.classList.toggle('cm-numeric-editor__warning', missing.length > 0);
    }
}
