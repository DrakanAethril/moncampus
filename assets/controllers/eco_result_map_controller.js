import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { addFullscreenControl, createMap, frameOn, tooltipElement } from './eco_map.js';

/**
 * The runner's route on the results screen (App\Controller\EcoCourseController::results): their
 * own GPS trace, arrows showing which way they went, and one marker per checkpoint.
 *
 * Tiles, framing, fullscreen and tooltips are shared with the module's other two maps - see
 * eco_map.js.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        trace: Array,
        checkpoints: Array,
        stops: Array,
        refusedScans: Array,
        legs: Array,
        compared: Object,
        expandLabel: String,
        collapseLabel: String,
    };

    // One arrow per ~120 m of trace: enough to read the direction on each leg, few enough not to
    // bury the line under chevrons.
    static ARROW_SPACING_METERS = 120;

    connect() {
        // Stimulus Array values are re-parsed on every access, so read them once (see the
        // gradebook controller's note on the same trap).
        const trace = this.traceValue;
        const checkpoints = this.checkpointsValue;
        const stops = this.stopsValue;
        const refusedScans = this.refusedScansValue;
        const legs = this.legsValue;
        const compared = this.comparedValue;

        this.map = createMap(this.element);

        // The compared runner goes under, so the runner being analysed stays the readable one.
        const comparedTrace = compared?.trace ?? [];
        if (comparedTrace.length > 1) {
            L.polyline(comparedTrace, { color: '#6B4F8C', weight: 3, opacity: 0.55, dashArray: '6 6' }).addTo(this.map);
        }

        if (trace.length > 1) {
            L.polyline(trace, { color: '#1B6BA8', weight: 3, opacity: 0.9 }).addTo(this.map);
            this.drawArrows(trace);
        }

        // Over the continuous trace, under the markers: one line per leg, so each can be hovered.
        legs.forEach((leg) => this.drawLeg(leg));
        stops.forEach((stop) => this.drawStop(stop));
        refusedScans.forEach((scan) => this.drawRefusedScan(scan));
        checkpoints.forEach((checkpoint) => this.drawCheckpoint(checkpoint));

        // Framed on the checkpoints alone, as tight as they allow. Including the trace would give
        // away zoom to a wide loop, and including a refused scan made a kilometre off would zoom
        // the whole parcours down to a dot - that scan stays on the map, at the end of its dashed
        // line, one zoom-out away. Falls back to the trace when no checkpoint was ever located.
        frameOn(this.map, checkpoints.map((checkpoint) => [checkpoint.latitude, checkpoint.longitude]), trace);

        this.removeFullscreenControl = addFullscreenControl(this.map, this.element, {
            expandLabel: this.expandLabelValue,
            collapseLabel: this.collapseLabelValue,
        });

        // The card is often laid out (or revealed) after connect(); without this the tiles only
        // cover the size the container had at that moment.
        setTimeout(() => this.map.invalidateSize(), 100);
    }

    disconnect() {
        this.removeFullscreenControl?.();
        this.map?.remove();
    }

    /** Chevrons laid along the trace, each turned to the heading the runner was following. */
    drawArrows(trace) {
        let sinceLastArrow = 0;

        for (let i = 1; i < trace.length; i += 1) {
            const from = L.latLng(trace[i - 1]);
            const to = L.latLng(trace[i]);
            sinceLastArrow += from.distanceTo(to);

            if (sinceLastArrow < this.constructor.ARROW_SPACING_METERS) {
                continue;
            }
            sinceLastArrow = 0;

            const midpoint = L.latLng((from.lat + to.lat) / 2, (from.lng + to.lng) / 2);
            L.marker(midpoint, {
                interactive: false,
                keyboard: false,
                icon: L.divIcon({
                    className: 'eco-map__arrow',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7],
                    html: `<svg viewBox="0 0 14 14" style="transform: rotate(${this.bearing(from, to)}deg)">
                        <path d="M7 1.5 11 12 7 9.6 3 12Z" fill="#12507E"></path>
                    </svg>`,
                }),
            }).addTo(this.map);
        }
    }

    drawCheckpoint(checkpoint) {
        const validated = checkpoint.validated === true;
        const marker = L.marker([checkpoint.latitude, checkpoint.longitude], {
            icon: L.divIcon({
                className: 'eco-map__checkpoint',
                iconSize: [26, 26],
                iconAnchor: [13, 13],
                html: `<span class="eco-map__checkpoint-dot ${validated ? 'is-validated' : ''}">${checkpoint.label}</span>`,
            }),
        }).addTo(this.map);

        marker.bindTooltip(tooltipElement(checkpoint.lines));
    }

    /** Where the runner stood still long enough for it to be worth asking why. */
    drawStop(stop) {
        L.circleMarker([stop.latitude, stop.longitude], {
            radius: 6,
            color: '#B0722A',
            weight: 2,
            fillColor: '#F5E9CF',
            fillOpacity: 0.95,
        })
            .addTo(this.map)
            .bindTooltip(tooltipElement(stop.lines));
    }

    /**
     * One leg. Every one is drawn - that is what gives them all a tooltip - but only the worst and
     * the straightest detour take a colour of their own; the rest stay the trace's blue.
     */
    drawLeg(leg) {
        const color = { worst: '#B8493D', best: '#1F7A54' }[leg.kind] ?? '#1B6BA8';

        L.polyline(leg.points, { color, weight: 5, opacity: leg.kind ? 0.85 : 0.75 })
            .addTo(this.map)
            .bindTooltip(tooltipElement(leg.lines), { sticky: true });
    }

    /**
     * A scan that did not count, drawn where it was actually made and tied to the checkpoint it
     * claimed - the dashed line is the whole point: it shows the gap the numbers only state.
     */
    drawRefusedScan(scan) {
        if (scan.checkpointLatitude !== null && scan.checkpointLongitude !== null) {
            L.polyline(
                [
                    [scan.latitude, scan.longitude],
                    [scan.checkpointLatitude, scan.checkpointLongitude],
                ],
                { color: '#B8493D', weight: 1.5, opacity: 0.8, dashArray: '4 5' },
            ).addTo(this.map);
        }

        L.marker([scan.latitude, scan.longitude], {
            // Leaflet stacks markers by latitude, not by the order they were added: without this
            // a refused scan made a few metres north of a checkpoint hides that checkpoint.
            zIndexOffset: -1000,
            icon: L.divIcon({
                className: 'eco-map__refused',
                iconSize: [20, 20],
                iconAnchor: [10, 10],
                html: '<span class="eco-map__refused-mark">✕</span>',
            }),
        })
            .addTo(this.map)
            .bindTooltip(scan.tooltip);
    }

    /** Compass bearing from one point to the next, in degrees, for the chevron's rotation. */
    bearing(from, to) {
        const toRadians = (degrees) => (degrees * Math.PI) / 180;
        const deltaLng = toRadians(to.lng - from.lng);
        const fromLat = toRadians(from.lat);
        const toLat = toRadians(to.lat);

        const y = Math.sin(deltaLng) * Math.cos(toLat);
        const x = Math.cos(fromLat) * Math.sin(toLat) - Math.sin(fromLat) * Math.cos(toLat) * Math.cos(deltaLng);

        return ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
    }
}
