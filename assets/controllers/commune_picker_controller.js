import { Controller } from '@hotwired/stimulus';

/*
 * « Autour d'une commune » on « Trouver une entreprise »: a commune, never an address
 * (vivier spec, R5). The Géoplateforme completes what was typed; a pick fills the hidden latitude
 * and longitude and switches the « Où » choice to the commune. Typing again clears the point, so a
 * search is never run around a commune the field no longer names.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'list', 'latitude', 'longitude'];
    static values = { url: String };

    connect() {
        this.timer = null;
    }

    lookup() {
        clearTimeout(this.timer);
        this.latitudeTarget.value = '';
        this.longitudeTarget.value = '';
        this.selectCommuneMode();

        const term = this.inputTarget.value.trim();
        if (term.length < 3) {
            this.close();
            return;
        }

        this.timer = setTimeout(async () => {
            const response = await fetch(this.urlValue + '?term=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                this.close();
                return;
            }
            this.show(await response.json());
        }, 250);
    }

    show(places) {
        this.listTarget.replaceChildren();
        for (const place of places) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = place.label + (place.postalCode ? ' (' + place.postalCode + ')' : '');
            button.addEventListener('click', () => this.choose(place.label, place.latitude, place.longitude));
            li.appendChild(button);
            this.listTarget.appendChild(li);
        }
        this.listTarget.hidden = places.length === 0;
    }

    /** « Autour de l'école »: a ready-made point carried by the button itself. */
    pick(event) {
        const { label, latitude, longitude } = event.currentTarget.dataset;
        this.choose(label, latitude, longitude);
    }

    choose(label, latitude, longitude) {
        this.inputTarget.value = label;
        this.latitudeTarget.value = latitude;
        this.longitudeTarget.value = longitude;
        this.selectCommuneMode();
        this.close();
    }

    selectCommuneMode() {
        const radio = this.element.closest('form')?.querySelector('input[name="where"][value="commune"]');
        if (radio) {
            radio.checked = true;
        }
    }

    close() {
        this.listTarget.hidden = true;
        this.listTarget.replaceChildren();
    }
}
