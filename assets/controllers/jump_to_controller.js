import { Controller } from '@hotwired/stimulus';

/**
 * A select whose options are URLs: choosing one goes there.
 *
 * Exists for the Proxmox console's « Aller à… » box, where it is not decoration. That area has no
 * entry in any application menu, so its local navigation bar is the only way between its screens,
 * and the per-host ones would otherwise be reachable only by knowing their address.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    go(event) {
        const url = event.target.value;

        if (url) {
            window.location.href = url;
        }
    }
}
