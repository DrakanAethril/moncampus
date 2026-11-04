import { Controller } from '@hotwired/stimulus';

/*
 * The targeting tree (templates/form/structure_targets.html.twig, App\Form\StructureTargetsType).
 *
 * Says « inclus » beside a box that a ticked section or filière above it already covers - and does
 * nothing else: it never ticks or unticks a box. Ticking a formation under a ticked section is
 * harmless and stays the person's choice; what a targeting reaches is decided on the server
 * (App\Service\StructureTargeting\StructureTargetRule).
 */
export default class extends Controller {
    static targets = ['box'];

    connect() {
        this.refresh();
    }

    refresh() {
        const checked = new Set(this.boxTargets.filter((box) => box.checked).map((box) => box.id));

        this.boxTargets.forEach((box) => {
            const ancestors = (box.dataset.ancestors || '').split(' ').filter((id) => id !== '');
            const covered = ancestors.some((id) => checked.has(id));
            box.closest('.cm-targets__box')?.classList.toggle('is-covered', covered);
        });
    }
}
