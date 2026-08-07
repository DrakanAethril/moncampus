import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { Controller } from '@hotwired/stimulus';
import { addFullscreenControl, createMap, frameOn, mergeCoLocated, tooltipElement } from './eco_map.js';

/**
 * The live safety map of screen 1h: the parcours checkpoints, and one pill per runner at their
 * last known position - blue while everything is normal, gold on a stale signal, red on an SOS.
 *
 * Reads the same endpoint as the runner table beside it (EcoCourseController::liveData) on the
 * same 10 s beat, and moves the pills in place rather than redrawing the map: a teacher watching
 * the field should never see the view jump back to its initial framing under them.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        checkpoints: Array,
        intervalMs: { type: Number, default: 10000 },
        expandLabel: String,
        collapseLabel: String,
    };

    connect() {
        const checkpoints = mergeCoLocated(this.checkpointsValue);

        this.map = createMap(this.element);
        this.runnerMarkers = new Map();

        checkpoints.forEach((checkpoint) => this.drawCheckpoint(checkpoint));
        frameOn(this.map, checkpoints.map((checkpoint) => [checkpoint.latitude, checkpoint.longitude]));

        this.removeFullscreenControl = addFullscreenControl(this.map, this.element, {
            expandLabel: this.expandLabelValue,
            collapseLabel: this.collapseLabelValue,
        });

        setTimeout(() => this.map.invalidateSize(), 100);

        this.poll();
        this.interval = setInterval(() => this.poll(), this.intervalMsValue);
    }

    disconnect() {
        clearInterval(this.interval);
        this.removeFullscreenControl?.();
        this.map?.remove();
    }

    poll() {
        fetch(this.urlValue, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data) => this.applyRunners(data.runners))
            .catch(() => {});
    }

    applyRunners(runners) {
        const seen = new Set();

        runners.forEach((runner) => {
            // A runner who has finished, or who has never sent a position, has nothing to place.
            if (runner.latitude === null || runner.longitude === null || runner.mapState === 'finished') {
                return;
            }

            seen.add(runner.id);
            const position = [runner.latitude, runner.longitude];
            const existing = this.runnerMarkers.get(runner.id);

            if (existing) {
                existing.setLatLng(position);
                existing.setIcon(this.runnerIcon(runner));

                return;
            }

            this.runnerMarkers.set(
                runner.id,
                L.marker(position, { icon: this.runnerIcon(runner), zIndexOffset: 500 }).addTo(this.map),
            );
        });

        // A runner who crossed the line between two polls stops being a pill on the map.
        this.runnerMarkers.forEach((marker, id) => {
            if (seen.has(id)) {
                return;
            }
            marker.remove();
            this.runnerMarkers.delete(id);
        });
    }

    /**
     * The pill is built with textContent, never innerHTML: the label carries a pseudo the runner
     * typed in themselves.
     */
    runnerIcon(runner) {
        const pill = document.createElement('span');
        pill.className = `eco-map__runner-pill eco-map__runner-pill--${runner.mapState}`;
        pill.textContent = runner.mapLabel;

        return L.divIcon({
            className: 'eco-map__runner',
            iconSize: null,
            html: pill.outerHTML,
        });
    }

    drawCheckpoint(checkpoint) {
        const modifier = checkpoint.isAnchor ? 'eco-map__checkpoint-dot--anchor' : '';

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
