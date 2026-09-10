import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'shownIcon', 'hiddenIcon'];

    toggle(event) {
        event.preventDefault();

        // The button always shows the gesture it offers, never the state it is in: a masked
        // password carries the open eye ("show it"), a revealed one the struck-through eye
        // ("hide it again"). Both classList calls take the same flag, since the icon that
        // disappears is exactly the one the other replaces.
        const willReveal = 'password' === this.inputTarget.type;
        this.inputTarget.type = willReveal ? 'text' : 'password';
        this.shownIconTarget.classList.toggle('d-none', willReveal);
        this.hiddenIconTarget.classList.toggle('d-none', !willReveal);
        event.currentTarget.setAttribute('aria-pressed', willReveal ? 'true' : 'false');
    }
}
