import { Controller } from '@hotwired/stimulus';

/**
 * Montre un bloc tant qu'un choix précis est sélectionné dans un groupe de radios - le quiz à
 * dérouler, qui n'a de sens que pour la nature « Quiz en ligne » (maquette 2b).
 *
 * Même parti pris que checkbox_reveal_controller : purement une affordance, le bloc reste dans le
 * DOM (une classe, pas `hidden`) et c'est le serveur qui décide ce qu'il retient - ici, remettre le
 * quiz à null quand la nature a changé.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['radio', 'panel'];
    static values = { value: String };

    connect() {
        this.refresh();
    }

    refresh() {
        const active = this.radioTargets.some((radio) => radio.checked && radio.value === this.valueValue);

        this.panelTargets.forEach((panel) => panel.classList.toggle('d-none', !active));
    }
}
