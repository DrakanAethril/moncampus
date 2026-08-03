import { Controller } from '@hotwired/stimulus';

// Carnet de notes - formulaire d'évaluation (design/design_handoff_carnet_de_notes, écran 2).
// Le seul comportement à piloter est la visibilité pour les élèves : les créas la présentent en
// segment Immédiate/Programmée, alors que le formulaire Symfony porte une case à cocher
// (hasScheduledVisibility) doublée d'un champ date-heure. Le segment est donc une paire de radios
// d'affichage qui recopie son état dans la case réellement soumise et montre ou cache le champ.
// Le reste du formulaire (cartes de type, segments modalité/statut) tient en CSS :has(), sans JS.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['scheduledFields', 'scheduledHint', 'scheduledCheckbox'];

    setVisibility(event) {
        const scheduled = event.target.value === 'scheduled';
        this.scheduledCheckboxTarget.checked = scheduled;
        this.scheduledFieldsTarget.hidden = !scheduled;
        this.scheduledHintTarget.hidden = !scheduled;
    }
}
