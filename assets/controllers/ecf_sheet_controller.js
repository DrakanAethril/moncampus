import { Controller } from '@hotwired/stimulus';

/*
 * One part of an ECF activity sheet (templates/ufa/ecf/_sheet_form.html.twig): adds and removes
 * evaluation lines, numbers them, fills the empty ones from the référentiel's performance criteria,
 * and shows the « non satisfait » zones only under that result. The server decides everything else.
 */
export default class extends Controller {
    static targets = ['rows', 'row', 'template', 'notSatisfied', 'addButton'];

    static values = { maxRows: Number, proposals: Array };

    connect() {
        this.nextIndex = this.rowTargets.length;
        this.refresh();
        this.toggle();
    }

    addRow() {
        if (this.maxRowsValue > 0 && this.rowTargets.length >= this.maxRowsValue) {
            return null;
        }
        const html = this.templateTarget.innerHTML.replaceAll('__ROW__', String(this.nextIndex));
        this.nextIndex += 1;
        this.rowsTarget.insertAdjacentHTML('beforeend', html);
        this.refresh();

        return this.rowTargets[this.rowTargets.length - 1];
    }

    removeRow(event) {
        const row = event.target.closest('[data-ecf-sheet-target="row"]');
        if (!row) {
            return;
        }
        if (this.rowTargets.length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('textarea, input[type="date"]').forEach((field) => { field.value = ''; });
            row.querySelectorAll('input[type="checkbox"]').forEach((box) => { box.checked = false; });
        }
        this.refresh();
    }

    // Only lines whose description is empty receive a proposal: nothing typed is ever overwritten.
    propose() {
        const proposals = this.proposalsValue;
        proposals.forEach((proposal, index) => {
            const row = this.rowTargets[index] ?? this.addRow();
            if (!row) {
                return;
            }
            const description = row.querySelector('textarea');
            if (description.value.trim() !== '') {
                return;
            }
            description.value = proposal.description;
            row.querySelectorAll('input[type="checkbox"]').forEach((box) => {
                if (Number(box.value) === proposal.competence) {
                    box.checked = true;
                }
            });
        });
    }

    toggle() {
        if (!this.hasNotSatisfiedTarget) {
            return;
        }
        const checked = this.element.querySelector('input[name="result"]:checked');
        this.notSatisfiedTarget.hidden = !checked || checked.value !== 'not_satisfied';
    }

    refresh() {
        this.rowTargets.forEach((row, index) => {
            const number = row.querySelector('[data-ecf-sheet-target="number"]');
            if (number) {
                number.textContent = String(index + 1);
            }
        });
        if (this.hasAddButtonTarget) {
            this.addButtonTarget.disabled = this.maxRowsValue > 0 && this.rowTargets.length >= this.maxRowsValue;
        }
    }
}
