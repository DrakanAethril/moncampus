import { Controller } from '@hotwired/stimulus';

/**
 * Offers "Durée indéfinie" on the lend form, and only on the loan types that may run without a
 * return date - today the internal loan alone (App\Enum\LaptopLoanType::allowsIndefiniteDuration()).
 * The list of those types is handed down from the form rather than spelled out here, so the rule
 * stays in the enum.
 *
 * Three things follow from the box, and they are all it does:
 *   - it is only shown for a type that allows it, and is ticked by default the moment such a type is
 *     picked - an internal loan usually has no end date, which is the whole point of the feature;
 *   - while it is ticked, the return date is emptied and disabled: there is nothing to enter;
 *   - while it is not, the return date carries its asterisk and its required attribute back.
 *
 * None of this decides anything: the server empties the date itself (LaptopLoanLendType's PRE_SUBMIT
 * listener) and refuses a missing one on a type that owes a convention (LaptopLoan::validateDueDate).
 * What happens here is only what the operator sees while filling the form in.
 *
 * connect() applies the state without touching the box itself, because after a validation error the
 * server re-renders the choice that was actually made - re-ticking it would undo an operator who
 * had deliberately unticked it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type', 'wrapper', 'checkbox', 'dueAt', 'dueAtLabel'];
    static values = { indefiniteTypes: Array };

    connect() {
        this.#render();
    }

    // The type select fires this, including when laptop_loan_type_controller.js pre-selects a type
    // after a borrower is picked - it dispatches a real change event for exactly that reason.
    typeChanged() {
        if (this.hasCheckboxTarget) {
            this.checkboxTarget.checked = this.#allowsIndefinite();
        }

        this.#render();
    }

    toggle() {
        this.#render();
    }

    #allowsIndefinite() {
        // Read once into a local: a Stimulus Array value re-parses its attribute on every access.
        const allowed = this.indefiniteTypesValue;

        return this.hasTypeTarget && allowed.includes(this.typeTarget.value);
    }

    #render() {
        const allowed = this.#allowsIndefinite();
        const indefinite = allowed && this.hasCheckboxTarget && this.checkboxTarget.checked;

        if (this.hasWrapperTarget) {
            this.wrapperTarget.hidden = !allowed;
        }

        if (this.hasDueAtTarget) {
            this.dueAtTarget.disabled = indefinite;
            this.dueAtTarget.required = !indefinite;

            if (indefinite) {
                this.dueAtTarget.value = '';
            }
        }

        if (this.hasDueAtLabelTarget) {
            this.dueAtLabelTarget.classList.toggle('required', !indefinite);
        }
    }
}
