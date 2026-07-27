import { Controller } from '@hotwired/stimulus';

// Below 1024px (design/design_handoff_messagerie #8), the folders pane becomes a slide-in drawer
// instead of its own grid column - see .cm-mail-app.is-drawer-open in assets/styles/app.css. This
// controller just owns that one class; the folders pane itself has no JS of its own.
export default class extends Controller {
    toggle() {
        this.element.classList.toggle('is-drawer-open');
    }

    // Bound to a click on the whole .cm-mail-app (the backdrop covers the full element via
    // ::before once .is-drawer-open is set) - closes unless the click landed inside the folders
    // pane itself, which has its own links/buttons that should keep working normally.
    backdropClick(event) {
        if (this.element.classList.contains('is-drawer-open') && !event.target.closest('.cm-mail-folders')) {
            this.element.classList.remove('is-drawer-open');
        }
    }
}
