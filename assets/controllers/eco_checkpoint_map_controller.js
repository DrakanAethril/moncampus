import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { Controller } from '@hotwired/stimulus';
import { addFullscreenControl, createMap, frameOn, mergeCoLocated, tooltipElement } from './eco_map.js';

/**
 * The parcours map on the configuration screen (1e): where each checkpoint of a parcours actually
 * stands, as captured from the mobile app.
 *
 * Only located checkpoints get a marker - one that has never been scanned on the ground has no
 * coordinates to put it at, which is exactly what the "à localiser" counter in the legend is for.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        checkpoints: Array,
        expandLabel: String,
        collapseLabel: String,
    };

    connect() {
        // Stimulus Array values are re-parsed on every access, so read them once.
        const checkpoints = mergeCoLocated(this.checkpointsValue);

        this.map = createMap(this.element);

        checkpoints.forEach((checkpoint) => this.drawCheckpoint(checkpoint));
        frameOn(this.map, checkpoints.map((checkpoint) => [checkpoint.latitude, checkpoint.longitude]));

        this.removeFullscreenControl = addFullscreenControl(this.map, this.element, {
            expandLabel: this.expandLabelValue,
            collapseLabel: this.collapseLabelValue,
        });

        setTimeout(() => this.map.invalidateSize(), 100);
    }

    disconnect() {
        this.removeFullscreenControl?.();
        this.map?.remove();
    }

    drawCheckpoint(checkpoint) {
        // Départ and Arrivée keep the navy of the anchors; every numbered balise that has been
        // located reads green, as on the table beside the map.
        const modifier = checkpoint.isAnchor ? 'eco-map__checkpoint-dot--anchor' : 'eco-map__checkpoint-dot--located';

        L.marker([checkpoint.latitude, checkpoint.longitude], {
            icon: L.divIcon({
                className: 'eco-map__checkpoint',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
                html: `<span class="eco-map__checkpoint-dot ${modifier}">${checkpoint.label}</span>`,
            }),
        })
            .addTo(this.map)
            .bindTooltip(tooltipElement(checkpoint.lines));
    }
}
