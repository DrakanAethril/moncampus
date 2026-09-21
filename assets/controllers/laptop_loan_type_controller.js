import { Controller } from '@hotwired/stimulus';

/**
 * Reacts to the borrower being picked on the lend form: it pre-selects "Type de prêt", and it says
 * out loud when that person is already holding machines.
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
 * The running-loans line is a remark and never a refusal: lending a second machine to the same
 * person is ordinary, and the operator only needs to know it is happening.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type', 'notice'];
    static values = { url: String };

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

            const { loanType, activeLoansNotice } = await response.json();

            if (loanType && this.hasTypeTarget) {
                this.typeTarget.value = loanType;
                this.typeTarget.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.#showNotice(activeLoansNotice);
        } catch {
            // A failed suggestion is not a failed form: the field simply stays on whatever it
            // showed, and the operator picks the type themselves as they did before.
        }
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
