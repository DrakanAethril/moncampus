import { Controller } from '@hotwired/stimulus';

/**
 * Pastilles de raccourci au-dessus d'un champ date-heure (maquette 2b : « Prochaine séance ·
 * 11 août », « Date et heure… »).
 *
 * Le champ natif reste la source de vérité - c'est lui qui part au serveur, et il continue de
 * fonctionner seul si ce contrôleur ne se charge pas. Les pastilles ne font que le remplir, et
 * celle qui est active se déduit de sa valeur plutôt que d'un état gardé à côté, qui pourrait
 * diverger dès que l'utilisateur saisit une date à la main.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['field', 'preset', 'custom'];

    connect() {
        this.refresh();
    }

    apply(event) {
        const { value } = event.currentTarget.dataset;

        this.fieldTarget.value = value;
        this.refresh();
    }

    // « Date et heure… » ne pose aucune valeur : elle ouvre le champ pour que l'utilisateur écrive.
    reveal() {
        this.customTarget.classList.remove('d-none');
        this.fieldTarget.focus();
        this.refresh();
    }

    refresh() {
        const current = this.fieldTarget.value;
        let matched = false;

        this.presetTargets.forEach((preset) => {
            const isActive = preset.dataset.value === current;
            preset.classList.toggle('is-selected', isActive);
            matched ||= isActive;
        });

        // Une date qui ne correspond à aucune pastille est forcément une saisie libre : le champ
        // reste alors visible, sans quoi l'utilisateur ne verrait plus ce qu'il a écrit.
        if (!matched) {
            this.customTarget.classList.remove('d-none');
        }
    }
}
