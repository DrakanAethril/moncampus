import L from 'leaflet';

/**
 * What every e-CO map has in common: the OpenStreetMap tiles, the fullscreen button, the tight
 * framing on the checkpoints and the multi-line tooltips.
 *
 * Extracted so the three maps of the module - the runner's route (1i), the parcours checkpoints
 * (1e) and the live safety map (1h) - never drift apart on tiles, zoom or fullscreen behaviour.
 * Leaflet's default marker icons are never used: every marker is a divIcon, so no map needs the
 * PNGs that ship with the CSS and would 404 through AssetMapper's hashed filenames.
 */
export function createMap(element) {
    const map = L.map(element, { scrollWheelZoom: false, attributionControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap',
    }).addTo(map);

    return map;
}

/**
 * Frames the map on the points given, as tight as they allow, with just enough padding for a 26px
 * marker not to touch the edge. Falls back to the second list when the first is empty.
 */
export function frameOn(map, points, fallbackPoints = []) {
    const bounds = L.latLngBounds(points.length > 0 ? points : fallbackPoints);
    if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [20, 20] });
    }
}

/**
 * Merges checkpoints that sit on the same spot into a single "D/A" marker. A loop has its Départ
 * and its Arrivée at the same place, where one marker would sit on top of the other and hide it.
 */
export function mergeCoLocated(checkpoints) {
    const merged = [];

    checkpoints.forEach((checkpoint) => {
        const twin = merged.find(
            (other) =>
                Math.abs(other.latitude - checkpoint.latitude) < 1e-6
                && Math.abs(other.longitude - checkpoint.longitude) < 1e-6,
        );

        if (!twin) {
            merged.push({ ...checkpoint, lines: [...checkpoint.lines] });

            return;
        }

        twin.label += `/${checkpoint.label}`;
        twin.isAnchor = twin.isAnchor || checkpoint.isAnchor;
        twin.lines = [...twin.lines, '', ...checkpoint.lines];
    });

    return merged;
}

/**
 * A multi-line tooltip built out of text nodes: checkpoint names and runner pseudos come from user
 * input, so they are never handed to innerHTML.
 */
export function tooltipElement(lines) {
    const element = document.createElement('div');

    lines.forEach((line, index) => {
        if (index > 0) {
            element.appendChild(document.createElement('br'));
        }
        element.appendChild(document.createTextNode(line));
    });

    return element;
}

/**
 * A trace read over a whole wood is cramped in a 380px card. The button asks the browser for real
 * fullscreen and falls back to a fixed overlay where that is refused (a Safari iframe, a
 * policy-restricted browser) - either way the map has to be told its size changed.
 *
 * Returns a teardown to call from the controller's disconnect().
 */
export function addFullscreenControl(map, element, { expandLabel, collapseLabel }) {
    let button = null;

    const setExpanded = (expanded) => {
        element.classList.toggle('eco-map--expanded', expanded);
        button.title = expanded ? collapseLabel : expandLabel;
        setTimeout(() => map.invalidateSize(), 100);
    };

    const toggleFullscreen = () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();

            return;
        }

        if (element.classList.contains('eco-map--expanded')) {
            setExpanded(false);

            return;
        }

        if (typeof element.requestFullscreen === 'function') {
            element.requestFullscreen().catch(() => setExpanded(true));

            return;
        }

        setExpanded(true);
    };

    const control = L.control({ position: 'topright' });

    control.onAdd = () => {
        const container = L.DomUtil.create('div', 'leaflet-bar eco-map__fullscreen');
        button = L.DomUtil.create('a', '', container);
        button.href = '#';
        button.title = expandLabel;
        button.setAttribute('role', 'button');
        button.textContent = '⤢';

        L.DomEvent.on(button, 'click', (event) => {
            L.DomEvent.stop(event);
            toggleFullscreen();
        });

        return container;
    };

    control.addTo(map);

    // Covers the Escape key and the browser's own exit button, not just our own toggle.
    const listener = () => {
        if (!document.fullscreenElement) {
            setExpanded(false);

            return;
        }

        button.title = collapseLabel;
        setTimeout(() => map.invalidateSize(), 100);
    };
    document.addEventListener('fullscreenchange', listener);

    return () => document.removeEventListener('fullscreenchange', listener);
}
