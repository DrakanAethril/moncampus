import { Controller } from '@hotwired/stimulus';

/*
 * A Google map on « Trouver une entreprise » and the vivier's fiches
 * (design/validated/vivier-entreprises.md §8.1).
 *
 * Google's script is loaded only after the person has agreed - loading it hands their IP address
 * to Google - and the answer is kept a year in a cookie, the way matomo_consent_controller.js keeps
 * its own. Declining keeps the page whole: every place is an address and a link as well.
 *
 * The info window is built with textContent, never innerHTML: a company name comes from the
 * register and is not ours to trust.
 */
const COOKIE_NAME = 'google_maps_consent';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60;
const CALLBACK = '__cmGoogleMapsReady';

let loader = null;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['consent', 'declined', 'canvas'];
    static values = { apiKey: String, markers: Array };

    connect() {
        const consent = readCookie(COOKIE_NAME);

        if (consent === 'granted') {
            this.draw();
        } else if (consent === 'denied') {
            this.declinedTarget.hidden = false;
        } else {
            this.consentTarget.hidden = false;
        }
    }

    accept() {
        writeCookie(COOKIE_NAME, 'granted');
        this.draw();
    }

    decline() {
        writeCookie(COOKIE_NAME, 'denied');
        this.consentTarget.hidden = true;
        this.declinedTarget.hidden = false;
    }

    ask() {
        this.declinedTarget.hidden = true;
        this.consentTarget.hidden = false;
    }

    async draw() {
        this.consentTarget.hidden = true;
        this.declinedTarget.hidden = true;
        this.canvasTarget.hidden = false;

        try {
            await loadGoogleMaps(this.apiKeyValue);
        } catch (error) {
            this.canvasTarget.hidden = true;
            this.declinedTarget.hidden = false;
            return;
        }

        const markers = this.markersValue.filter((marker) => Number.isFinite(marker.latitude) && Number.isFinite(marker.longitude));
        if (markers.length === 0) {
            this.canvasTarget.hidden = true;
            return;
        }

        const { maps } = window.google;
        const map = new maps.Map(this.canvasTarget, {
            center: { lat: markers[0].latitude, lng: markers[0].longitude },
            zoom: 14,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
        });
        const bounds = new maps.LatLngBounds();
        const infoWindow = new maps.InfoWindow();

        for (const data of markers) {
            const position = { lat: data.latitude, lng: data.longitude };
            const marker = new maps.Marker({ position, map, title: data.title, icon: pinFor(maps, data.tone) });
            bounds.extend(position);
            marker.addListener('click', () => {
                infoWindow.setContent(infoContent(data));
                infoWindow.open({ map, anchor: marker });
            });
        }

        if (markers.length > 1) {
            map.fitBounds(bounds, 40);
        }
    }
}

function loadGoogleMaps(apiKey) {
    if (window.google && window.google.maps && window.google.maps.Map) {
        return Promise.resolve();
    }

    if (loader === null) {
        loader = new Promise((resolve, reject) => {
            window[CALLBACK] = () => resolve();
            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey)
                + '&callback=' + CALLBACK + '&language=fr&region=FR';
            script.async = true;
            script.onerror = () => {
                loader = null;
                reject(new Error('Google Maps could not be loaded.'));
            };
            document.head.appendChild(script);
        });
    }

    return loader;
}

function pinFor(maps, tone) {
    const colours = { known: '#25543c', mine: '#9a7729' };
    const fill = colours[tone] || '#a43e2e';

    return {
        path: 'M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7z',
        fillColor: fill,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 1.5,
        scale: 1.4,
        anchor: new maps.Point(12, 22),
    };
}

function infoContent(data) {
    const box = document.createElement('div');
    box.className = 'cm-cs-map__info';

    const title = document.createElement('strong');
    title.textContent = data.title || '';
    box.appendChild(title);

    if (data.subtitle) {
        const subtitle = document.createElement('div');
        subtitle.textContent = data.subtitle;
        box.appendChild(subtitle);
    }

    if (data.url) {
        const link = document.createElement('a');
        link.href = data.url;
        link.textContent = data.linkLabel || data.url;
        box.appendChild(link);
    }

    return box;
}

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : null;
}

function writeCookie(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + COOKIE_MAX_AGE + '; samesite=lax';
}
