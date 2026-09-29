import { Controller } from '@hotwired/stimulus';

/*
 * « Modifier l'entreprise »: shows what the typed SIRET designates as soon as it has fourteen
 * digits, by pointing a Turbo Frame at /ufa/siret/lookup (design/validated/siret-entreprises.md
 * §4.3). The frame is rendered by the server - this controller only decides when to ask.
 */
export default class extends Controller {
    static targets = ['input', 'frame'];

    static values = { url: String };

    connect() {
        this.asked = null;
        this.update();
    }

    update() {
        const digits = this.inputTarget.value.replace(/[\s.\- ]/g, '');

        if (!/^\d{14}$/.test(digits)) {
            this.asked = null;
            this.frameTarget.removeAttribute('src');
            this.frameTarget.replaceChildren();

            return;
        }

        if (digits === this.asked) {
            return;
        }

        this.asked = digits;
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('siret', digits);
        this.frameTarget.src = url.toString();
    }
}
