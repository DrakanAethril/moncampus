import { Controller } from '@hotwired/stimulus';

/**
 * Reacts to the borrower being picked on the lend form: it pre-selects "Type de prêt", it fills in
 * the formation the convention will print, and it says out loud when that person is already
 * holding machines.
 *
 * The rule behind the pre-selection lives server-side, on App\Enum\LaptopLoanType::suggestFor() -
 * an apprentice borrows under the UFA convention, another student under the CFC one, and anyone who
 * is not a student borrows internally, with nothing to sign.
 *
 * It is a pre-selection and nothing more - the operator can change it afterwards, and the answer is
 * re-applied on every borrower change because the borrower is what the answer depends on. A blank
 * answer (no borrower, or an unresolvable one) leaves the field exactly as it was rather than
 * clearing a choice already made.
 *
 * The formation - name, start and end dates - is proposed the same way and for the same reason:
 * it is the borrower's, so picking another borrower replaces it, a borrower with no programme
 * empties it, and between two picks it belongs to the operator, who types over it whatever the
 * paper must say. The three fields are shown on the loan types that print a convention and on no
 * other; that list comes down from App\Enum\LaptopLoanType through the form. Hiding them decides
 * nothing: the server drops the formation of a loan with no convention (LaptopLoanLendType).
 *
 * The running-loans line is a remark and never a refusal: lending a second machine to the same
 * person is ordinary, and the operator only needs to know it is happening.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type', 'notice', 'program', 'programName', 'programStartDate', 'programEndDate'];
    static values = { url: String, conventionTypes: Array };

    // After a validation error the server re-renders the type that was submitted: the formation
    // fields have to follow it without waiting for a change.
    connect() {
        this.#renderProgram();
    }

    // The type select fires this, including when suggest() below pre-selects a type.
    typeChanged() {
        this.#renderProgram();
    }

    async suggest(event) {
        const borrower = event.target.value;

        if ('' === borrower) {
            this.#showNotice(null);

            return;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('borrower', borrower);

        try {
            const response = await fetch(url);

            if (!response.ok) {
                return;
            }

            const { loanType, activeLoansNotice, program } = await response.json();

            if (loanType && this.hasTypeTarget) {
                this.typeTarget.value = loanType;
                this.typeTarget.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.#fillProgram(program);

            this.#showNotice(activeLoansNotice);
        } catch {
            // A failed suggestion is not a failed form: the field simply stays on whatever it
            // showed, and the operator picks the type themselves as they did before.
        }
    }

    #fillProgram(program) {
        if (this.hasProgramNameTarget) {
            this.programNameTarget.value = program?.name ?? '';
        }

        if (this.hasProgramStartDateTarget) {
            this.programStartDateTarget.value = program?.startDate ?? '';
        }

        if (this.hasProgramEndDateTarget) {
            this.programEndDateTarget.value = program?.endDate ?? '';
        }
    }

    #renderProgram() {
        if (!this.hasProgramTarget) {
            return;
        }

        // Read once into a local: a Stimulus Array value re-parses its attribute on every access.
        const conventionTypes = this.conventionTypesValue;

        this.programTarget.hidden = !this.hasTypeTarget || !conventionTypes.includes(this.typeTarget.value);
    }

    #showNotice(text) {
        if (!this.hasNoticeTarget) {
            return;
        }

        this.noticeTarget.textContent = text ?? '';
        // Bootstrap's .d-flex would override the plain hidden attribute, so the element the
        // templates give this target is a plain block.
        this.noticeTarget.hidden = !text;
    }
}
