import { Controller } from '@hotwired/stimulus';

/**
 * « Importer depuis une séance » : dépose le contenu d'une autre séance - ou de la séance de
 * bibliothèque dont le créneau est issu - dans les trois éditeurs du cahier de texte.
 *
 * Rien n'est enregistré : reprendre le travail d'un collègue est une proposition, pas une décision,
 * et c'est le bouton Enregistrer qui tranche, comme pour une saisie à la main. Le serveur ne rend
 * donc que les trois textes ; les documents et les travaux, qui sont des objets à part entière, ne
 * se préremplissent pas et ne sont pas repris.
 *
 * Le menu vit dans les actions de la page et les champs dans le formulaire, deux endroits sans
 * ancêtre commun accessible d'ici : les champs sont retrouvés par leur attribut plutôt que par des
 * cibles Stimulus, qui doivent descendre de l'élément portant le contrôleur.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { confirm: String };

    async importFrom(event) {
        event.preventDefault();

        if (this.hasContent() && '' !== this.confirmValue && !window.confirm(this.confirmValue)) {
            return;
        }

        const response = await fetch(event.currentTarget.dataset.url, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }

        const content = await response.json();

        ['before', 'during', 'after'].forEach((section) => this.write(section, content[section] ?? ''));
        this.editorFor(this.field('during'))?.focus();
    }

    field(section) {
        return document.querySelector(`[data-lesson-log-field="${section}"]`);
    }

    // Les champs sont enrichis par HugeRTE : c'est l'éditeur qui porte le contenu à l'écran, le
    // textarea d'origine ne se resynchronisant qu'à l'enregistrement. Écrire dans l'un sans l'autre
    // donnerait soit un champ qui n'affiche rien, soit un champ qui n'enverra pas ce qu'il affiche.
    editorFor(field) {
        const editors = window.hugerte?.get?.();

        return (Array.isArray(editors) ? editors : []).find((editor) => editor.targetElm === field) ?? null;
    }

    hasContent() {
        return ['before', 'during', 'after'].some((section) => {
            const field = this.field(section);

            return field && '' !== (this.editorFor(field)?.getContent() ?? field.value).trim();
        });
    }

    write(section, value) {
        const field = this.field(section);
        if (!field) {
            return;
        }

        field.value = value;
        this.editorFor(field)?.setContent(value);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
