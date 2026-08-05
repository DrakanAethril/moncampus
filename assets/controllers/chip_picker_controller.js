import { Controller } from '@hotwired/stimulus';

/*
 * Keeps a Tom Select dropdown under the field's own input rather than under the whole card
 * (design_handoff_workflow_postulation, screen 7c: the validators row).
 *
 * The chips wrap, so the "+ Ajouter" input moves around as names are added or removed - which is
 * why its offset is measured rather than assumed. Tom Select positions its dropdown against the
 * wrapper, so all that is needed is to hand the wrapper the left offset to use, as a custom
 * property the stylesheet reads.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.align = this.align.bind(this);
        this.element.addEventListener('focusin', this.align);
        this.element.addEventListener('input', this.align);
        // Chips added or removed reflow the row, and the dropdown may already be open.
        this.observer = new MutationObserver(this.align);
        this.observer.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        this.element.removeEventListener('focusin', this.align);
        this.element.removeEventListener('input', this.align);
        this.observer?.disconnect();
    }

    align() {
        const input = this.element.querySelector('.ts-control > input');
        const control = this.element.querySelector('.ts-control');

        if (!input || !control) {
            return;
        }

        const left = input.getBoundingClientRect().left - control.getBoundingClientRect().left;
        this.element.style.setProperty('--cm-chip-dropdown-left', `${Math.max(0, Math.round(left))}px`);
    }
}
