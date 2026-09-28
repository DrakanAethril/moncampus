import { Controller } from '@hotwired/stimulus';

/**
 * « Conseillé : 30 m » under a flag's tolerance on the parcours screen: fills the field with the
 * radius the canopy calls for (App\Service\Eco\EcoToleranceAdvisor). It only fills - the teacher
 * still saves, and can change the figure first.
 */
export default class extends Controller {
    apply({ params: { input, meters } }) {
        const field = document.getElementById(input);
        if (!field) {
            return;
        }

        field.value = meters;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.focus();
        this.element.hidden = true;
    }
}
