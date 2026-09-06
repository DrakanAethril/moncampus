import { Controller } from '@hotwired/stimulus';

// « Suivi par mot »: a row opens on its contributors, one at a time.
//
// One at a time on purpose - the contributors of two words side by side answer no question the list
// itself does not already answer better, and the panel would push the rest of the table off screen.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['group', 'panel', 'chevron'];

    toggle(event) {
        const group = event.currentTarget.closest('[data-word-cloud-follow-up-target="group"]');
        if (!group) return;

        const panel = group.querySelector('[data-word-cloud-follow-up-target="panel"]');
        const opening = panel.hidden;

        for (const other of this.groupTargets) this.#close(other);
        if (opening) this.#open(group);
    }

    #open(group) {
        // `hidden` rather than a style: Bootstrap's .d-flex and friends carry !important and would
        // win over a display of our own.
        group.querySelector('[data-word-cloud-follow-up-target="panel"]').hidden = false;
        group.querySelector('[data-word-cloud-follow-up-target="chevron"]').textContent = '▲';
        group.querySelector('[role="button"]').setAttribute('aria-expanded', 'true');
    }

    #close(group) {
        group.querySelector('[data-word-cloud-follow-up-target="panel"]').hidden = true;
        group.querySelector('[data-word-cloud-follow-up-target="chevron"]').textContent = '▼';
        group.querySelector('[role="button"]').setAttribute('aria-expanded', 'false');
    }
}
