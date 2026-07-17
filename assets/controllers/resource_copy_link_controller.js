import { Controller } from '@hotwired/stimulus';

/**
 * "Copier le lien" button on the Ressources > Application mobile/e-CO download cards
 * (templates/resources/_platform_card.html.twig) - the URL itself is never printed in the DOM
 * text (design/design_campus_manager README.md 11a/11b: "l'URL complète n'est JAMAIS affichée"),
 * only held in the urlValue and pushed straight to the clipboard.
 */
export default class extends Controller {
    static targets = ['label'];
    static values = {
        url: String,
        copiedLabel: String,
    };

    async copy(event) {
        event.preventDefault();

        await navigator.clipboard.writeText(this.urlValue);

        const originalLabel = this.labelTarget.textContent;
        this.labelTarget.textContent = this.copiedLabelValue;
        window.setTimeout(() => {
            this.labelTarget.textContent = originalLabel;
        }, 2000);
    }
}
