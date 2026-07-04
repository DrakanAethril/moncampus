import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'shownIcon', 'hiddenIcon'];

    toggle(event) {
        event.preventDefault();

        const isHidden = this.inputTarget.type === 'password';
        this.inputTarget.type = isHidden ? 'text' : 'password';
        this.shownIconTarget.classList.toggle('d-none', !isHidden);
        this.hiddenIconTarget.classList.toggle('d-none', isHidden);
    }
}
