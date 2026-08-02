import { Controller } from '@hotwired/stimulus';

// Loads the 34c relance panel's HTML into a Bootstrap modal via fetch, from either the suivi
// page's banner button or a per-period "Relancer" link (see ufa/alternance/show.html.twig) - both
// triggers just carry their own data-url, this controller wraps the whole page body so a single
// instance handles all of them. Uses window.tabler.Modal, not window.bootstrap - see
// weekly_template_controller.js's own comment on why (Tabler's bundled JS, no ESM "bootstrap"
// package wired via AssetMapper in this project).
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['modal', 'content'];

    open(event) {
        event.preventDefault();

        fetch(event.currentTarget.dataset.url)
            .then((response) => response.text())
            .then((html) => {
                this.contentTarget.innerHTML = html;
                this.modal().show();
            });
    }

    // "Envoyer la relance" button inside the fetched panel (34c) - the panel is injected via
    // innerHTML above, so this listens via the modal's own data-action, not a separate controller.
    send(event) {
        const button = event.currentTarget;
        const cc = Array.from(this.contentTarget.querySelectorAll('input[name="cc[]"]:checked')).map((input) => input.value);
        const body = new URLSearchParams();
        cc.forEach((value) => body.append('cc[]', value));

        fetch(button.dataset.url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': button.dataset.token, 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        }).then(() => window.location.reload());
    }

    modal() {
        this._modal ??= new window.tabler.Modal(this.modalTarget);

        return this._modal;
    }
}
