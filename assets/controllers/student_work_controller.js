import { Controller } from '@hotwired/stimulus';

// The three interactions of the student's "Travail à faire" screen (design_handoff_travail_a_faire,
// screens 3a/3b/3c): fetching the consigne modal, confirming "Ignorer" in a popover anchored to the
// button that asked, and firing a row's hidden file input.
//
// The modal is hand-rolled rather than window.tabler.Modal: the mockup fixes its width, radius and
// shadow, and Tabler's own dialog chrome would have to be undone anyway. Visibility is carried by a
// class and never by the `hidden` attribute, which Bootstrap's !important display utilities win over.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['modal', 'modalPanel', 'popover'];

    connect() {
        document.addEventListener('click', this._closeOnOutsideClick);
    }

    disconnect() {
        document.removeEventListener('click', this._closeOnOutsideClick);
    }

    // A confirmation left open on another row would read as if it applied to the one just clicked.
    _closeOnOutsideClick = (event) => {
        if (!event.target.closest('.cm-tw-dismiss')) {
            this.closePopovers();
        }
    };

    // "voir la consigne" / "Déposer" on an assignment asking for several productions: the brief is
    // fetched every time rather than cached, its deposits changing as soon as one is handed in.
    openBrief(event) {
        event.preventDefault();
        this.closePopovers();

        fetch(event.currentTarget.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => response.text())
            .then((html) => {
                this.modalPanelTarget.innerHTML = html;
                this.modalTarget.classList.add('is-open');
            });
    }

    closeBrief() {
        if (this.hasModalTarget) {
            this.modalTarget.classList.remove('is-open');
            this.modalPanelTarget.innerHTML = '';
        }
    }

    // Only the backdrop closes; a click inside the panel must not.
    backdropClose(event) {
        if (event.target === this.modalTarget) {
            this.closeBrief();
        }
    }

    askDismiss(event) {
        const popover = event.currentTarget.parentElement.querySelector('.cm-tw-pop');
        const wasOpen = popover.classList.contains('is-open');

        this.closePopovers();

        if (!wasOpen) {
            popover.classList.add('is-open');
        }
    }

    cancelDismiss() {
        this.closePopovers();
    }

    pickFile(event) {
        event.currentTarget.parentElement.querySelector('input[type="file"]').click();
    }

    // The mockup shows a button, not a field: picking a file is the whole gesture, so the form goes
    // as soon as one is there.
    fileChosen(event) {
        if (event.currentTarget.files.length > 0) {
            event.currentTarget.form.submit();
        }
    }

    closePopovers() {
        this.popoverTargets.forEach((popover) => popover.classList.remove('is-open'));
    }
}
