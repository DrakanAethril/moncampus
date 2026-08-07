import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

/**
 * The runner's route on the results screen (App\Controller\EcoCourseController::results): their
 * own GPS trace, arrows showing which way they went, and one marker per checkpoint.
 *
 * Leaflet's default marker icons are never used - every marker here is a divIcon, so the map needs
 * none of the PNGs that ship with the CSS and would 404 through AssetMapper's hashed filenames.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        trace: Array,
        checkpoints: Array,
        stops: Array,
        refusedScans: Array,
        compared: Object,
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
        const compared = this.comparedValue;

        this.map = L.map(this.element, { scrollWheelZoom: false, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap',
        }).addTo(this.map);

        // The compared runner goes under, so the runner being analysed stays the readable one.
        const comparedTrace = compared?.trace ?? [];
        if (comparedTrace.length > 1) {
            L.polyline(comparedTrace, { color: '#6B4F8C', weight: 3, opacity: 0.55, dashArray: '6 6' }).addTo(this.map);
        }

        if (trace.length > 1) {
            L.polyline(trace, { color: '#1B6BA8', weight: 3, opacity: 0.9 }).addTo(this.map);
            this.drawArrows(trace);
        }

        stops.forEach((stop) => this.drawStop(stop));
        refusedScans.forEach((scan) => this.drawRefusedScan(scan));
        checkpoints.forEach((checkpoint) => this.drawCheckpoint(checkpoint));

        const bounds = L.latLngBounds([
            ...trace,
            ...comparedTrace,
            ...refusedScans.map((scan) => [scan.latitude, scan.longitude]),
            ...checkpoints.map((checkpoint) => [checkpoint.latitude, checkpoint.longitude]),
        ]);
        if (bounds.isValid()) {
            this.map.fitBounds(bounds, { padding: [28, 28] });
        }

        // The card is often laid out (or revealed) after connect(); without this the tiles only
        // cover the size the container had at that moment.
        setTimeout(() => this.map.invalidateSize(), 100);
    }

    disconnect() {
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

        marker.bindTooltip(checkpoint.tooltip);
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
            .bindTooltip(`${stop.at} · ${Math.round(stop.seconds)} s`);
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
