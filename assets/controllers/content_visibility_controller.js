import { Controller } from '@hotwired/stimulus';

/**
 * Shows the date field only for « Programmée », in the publication selector of a séquence or of one
 * of its séances (templates/program/_visibility_form.html.twig).
 *
 * The rule it mirrors is App\Enum\ContentVisibility::needsDate(): Masquée and Publiée answer on
 * their own, Programmée is the one choice that asks for something. The server decides again on
 * submit - a date sent alongside Publiée is dropped, and a Programmée with no usable date is read
 * as *not visible*, because publishing something by accident is the one mistake this feature must
 * not make.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['choice', 'date'];

    connect() {
        this.toggle();
    }

    toggle() {
        // `hidden` alone is not enough next to Bootstrap: .form-control carries no !important
        // display, but the attribute is the honest signal and the utility classes here do not fight
        // it. Kept as the attribute rather than a class so the field is also skipped by assistive
        // technology when it does not apply.
        this.dateTarget.hidden = this.choiceTarget.value !== 'scheduled';
    }
}
