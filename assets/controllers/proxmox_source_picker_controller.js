import { Controller } from '@hotwired/stimulus';

/**
 * Step 1 of the creation wizard: keeps the hidden ISO field in step with whichever source is
 * picked.
 *
 * Templates and ISOs share one radio group on purpose - exactly one source can be chosen, and a
 * radio group is the only control that says so without any code. But a volume id is not a VMID, so
 * the ISO's identifier rides in a field of its own, and this is what fills or clears it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['isoVolumeId'];

    pick(event) {
        this.isoVolumeIdTarget.value = event.currentTarget.dataset.iso ?? '';
    }
}
