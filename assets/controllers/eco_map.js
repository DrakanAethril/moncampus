import L from 'leaflet';

/**
 * The IGN's Géoplateforme, in the Web-Mercator tile grid Leaflet already speaks: `{z}/{y}/{x}` of
 * an ordinary tile URL are WMTS's TILEMATRIX/TILEROW/TILECOL. No key, no rate limit on WMTS, and
 * the Etalab 2.0 licence asks for one thing only - the IGN named on the map.
 */
const IGN_WMTS = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&STYLE=normal'
    + '&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';

const IGN_ATTRIBUTION = '© IGN – Géoplateforme';

/**
 * The layers every e-CO map offers. Two base maps, one at a time: the Plan IGN reads paths and
 * place names, the aerial photograph reads clearings, edges and undergrowth. Two overlays, on top
 * of either: the contour lines are the relief of an orienteering map, and the LiDAR HD shading
 * shows the banks, ditches and gullies a 1:25 000 map smooths away. The shading is opaque grey, so
 * it is multiplied into the base map (see .eco-map__relief) instead of covering it.
 */
const LAYERS = {
    plan: { layer: 'GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2', format: 'image/png', nativeZoom: 19 },
    photo: { layer: 'ORTHOIMAGERY.ORTHOPHOTOS', format: 'image/jpeg', nativeZoom: 19 },
    contours: { layer: 'ELEVATION.CONTOUR.LINE', format: 'image/png', nativeZoom: 18, minZoom: 6 },
    relief: {
        layer: 'IGNF_LIDAR-HD_MNT_ELEVATION.ELEVATIONGRIDCOVERAGE.SHADOW',
        format: 'image/png',
        nativeZoom: 18,
        opacity: 0.75,
        className: 'eco-map__relief',
    },
};

const BASE_LAYERS = ['plan', 'photo'];
const OVERLAYS = ['contours', 'relief'];

// Which layers the viewer last chose, so every e-CO map opens the way they left the previous one.
// A per-viewer convenience only: without storage the map simply opens on the Plan IGN.
const STORAGE_KEY = 'eco-map-layers';

function ignLayer(key) {
    const definition = LAYERS[key];

    return L.tileLayer(`${IGN_WMTS}&LAYER=${definition.layer}&FORMAT=${definition.format}`, {
        maxZoom: 19,
        maxNativeZoom: definition.nativeZoom,
        minZoom: definition.minZoom ?? 0,
        opacity: definition.opacity ?? 1,
        className: definition.className ?? '',
        attribution: IGN_ATTRIBUTION,
    });
}

function readStoredLayers() {
    try {
        const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null');

        return {
            base: BASE_LAYERS.includes(stored?.base) ? stored.base : 'plan',
            overlays: Array.isArray(stored?.overlays) ? stored.overlays.filter((key) => OVERLAYS.includes(key)) : [],
        };
    } catch {
        return { base: 'plan', overlays: [] };
    }
}

function storeLayers(state) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // Private window or blocked storage: the choice just is not remembered.
    }
}

/**
 * What every e-CO map has in common: the IGN layers and their switcher, the fullscreen button, the
 * tight framing on the checkpoints and the multi-line tooltips.
 *
 * Extracted so the three maps of the module - the runner's route (1i), the parcours checkpoints
 * (1e) and the live safety map (1h) - never drift apart on tiles, zoom or fullscreen behaviour.
 * Leaflet's default marker icons are never used: every marker is a divIcon, so no map needs the
 * PNGs that ship with the CSS and would 404 through AssetMapper's hashed filenames.
 *
 * The switcher's wording is display text, so it comes from the page: `data-eco-map-layers` on the
 * map element (templates/eco/_map_layers_attribute.html.twig).
 */
export function createMap(element) {
    const map = L.map(element, { scrollWheelZoom: false, attributionControl: true });
    const labels = readLabels(element);
    const state = readStoredLayers();

    const bases = {};
    BASE_LAYERS.forEach((key) => {
        const layer = ignLayer(key);
        layer.ecoKey = key;
        bases[labels[key] ?? key] = layer;
        if (key === state.base) {
            layer.addTo(map);
        }
    });

    const overlays = {};
    OVERLAYS.forEach((key) => {
        const layer = ignLayer(key);
        layer.ecoKey = key;
        overlays[labels[key] ?? key] = layer;
        if (state.overlays.includes(key)) {
            layer.addTo(map);
        }
    });

    L.control.layers(bases, overlays, { position: 'topright', collapsed: true }).addTo(map);

    const remember = () => {
        const active = [];
        map.eachLayer((layer) => {
            if (layer.ecoKey) {
                active.push(layer.ecoKey);
            }
        });
        storeLayers({
            base: active.find((key) => BASE_LAYERS.includes(key)) ?? 'plan',
            overlays: active.filter((key) => OVERLAYS.includes(key)),
        });
    };
    map.on('baselayerchange overlayadd overlayremove', remember);

    return map;
}

function readLabels(element) {
    try {
        return JSON.parse(element.dataset.ecoMapLayers ?? '{}');
    } catch {
        return {};
    }
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
