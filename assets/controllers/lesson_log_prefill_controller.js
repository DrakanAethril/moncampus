import { Controller } from '@hotwired/stimulus';

/**
 * "Pré-remplir le cahier de texte" : recopie le contenu de la séance dans les trois champs du
 * formulaire, et rien de plus.
 *
 * Le bouton faisait auparavant un POST qui enregistrait le cahier de texte séance-comprise avant
 * même que l'enseignant ait relu quoi que ce soit. Pré-remplir n'est pas enregistrer : le contenu
 * de la séance voyage désormais avec la page (il est déjà résolu pour décider d'afficher le bouton)
 * et le clic ne touche qu'aux champs. Seul « Enregistrer » écrit en base.
 */
export default class extends Controller {
    static targets = ['contenu', 'avant', 'apres', 'hint'];
    static values = {
        contenu: { type: String, default: '' },
        avant: { type: String, default: '' },
        apres: { type: String, default: '' },
        confirm: { type: String, default: '' },
    };

    fill(event) {
        event.preventDefault();

        // Le pré-remplissage remplace ce qui est à l'écran : on demande confirmation tant qu'il y a
        // quelque chose à écraser, et on ne dérange pas l'enseignant quand les champs sont vides.
        if (this.hasContent() && '' !== this.confirmValue && !window.confirm(this.confirmValue)) {
            return;
        }

        this.write(this.contenuTarget, this.contenuValue);
        this.write(this.avantTarget, this.avantValue);
        this.write(this.apresTarget, this.apresValue);

        this.hintTarget?.classList.remove('d-none');
        this.contenuTarget.focus();
    }

    hasContent() {
        return [this.contenuTarget, this.avantTarget, this.apresTarget].some((field) => '' !== field.value.trim());
    }

    write(field, value) {
        field.value = value;
        // Pour tout ce qui écoute la saisie (compteurs, sauvegarde d'ébauche...) : une valeur posée
        // en JavaScript ne déclenche aucun évènement d'elle-même.
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
