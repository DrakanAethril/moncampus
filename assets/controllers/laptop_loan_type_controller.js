import { Controller } from '@hotwired/stimulus';

/**
 * Pre-selects "Type de prêt" from the borrower on the two lend forms: a student following the
 * alternance modality borrows under the UFA convention, anyone else under the CFC one (the rule
 * itself lives server-side, on App\Enum\LaptopLoanType::forAlternance()).
 *
 * It is a pre-selection and nothing more - the operator can change it afterwards, and the answer is
 * re-applied on every borrower change because the borrower is what the answer depends on. A blank
 * answer (no borrower, or an unresolvable one) leaves the field exactly as it was rather than
 * clearing a choice already made.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type'];
    static values = { url: String };

    async suggest(event) {
        const borrower = event.target.value;

        if (!this.hasTypeTarget || '' === borrower) {
            return;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('borrower', borrower);

        try {
            const response = await fetch(url);

            if (!response.ok) {
                return;
            }

            const { loanType } = await response.json();

            if (loanType) {
                this.typeTarget.value = loanType;
                this.typeTarget.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } catch {
            // A failed suggestion is not a failed form: the field simply stays on whatever it
            // showed, and the operator picks the type themselves as they did before.
        }
    }
}
