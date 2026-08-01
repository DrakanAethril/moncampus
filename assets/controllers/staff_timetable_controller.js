import { Controller } from '@hotwired/stimulus';

/**
 * The staff dashboard's all-classes day matrix (staff-a): the legend doubles as a per-class
 * show/hide filter, and the date in the header opens a real calendar.
 *
 * Day navigation itself is NOT here - the ‹ / › arrows are plain links into the card's
 * <turbo-frame>, because only the server knows which day actually has classes next. This
 * controller only has to hand the picked date to that same frame.
 *
 * Deliberately separate from dashboard_filter_controller.js rather than an extension of it: that
 * one is a single-value <select> ("n'afficher qu'une formation") still used by the student
 * dashboard, whereas this is a multi-toggle where any subset can be hidden at once.
 */
export default class extends Controller {
    static targets = ['row', 'legend', 'dateInput', 'form', 'viewForm'];

    // Every class is shown on load, and a frame navigation re-renders the card from scratch - so
    // the hidden set lives in the DOM (aria-pressed) rather than in a value that would have to be
    // kept in sync with markup the server just replaced.
    toggleRow(event) {
        const button = event.currentTarget;
        const key = button.dataset.rowKey;
        const hidden = button.getAttribute('aria-pressed') === 'false';

        button.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        button.classList.toggle('is-off', !hidden);

        this.rowTargets
            .filter((row) => row.dataset.rowKey === key)
            .forEach((row) => row.classList.toggle('cm-filter-hidden', !hidden));
    }

    // Le sélecteur Formations/Salles : même mécanique que le sélecteur de date, un GET dans le
    // turbo-frame - le regroupement des séances est l'affaire du serveur.
    submitView() {
        this.viewFormTarget.requestSubmit();
    }

    pickDate() {
        // showPicker() is the only way to open the native calendar from another element. Guarded
        // because it throws on browsers that don't implement it, and on a cross-origin iframe -
        // falling back to focusing the input, which at least lets the keyboard through.
        try {
            this.dateInputTarget.showPicker();
        } catch {
            this.dateInputTarget.focus();
        }
    }

    submitDate() {
        // A GET form inside the turbo-frame: Turbo navigates the frame with ?day=... rather than
        // the whole page. requestSubmit() (not submit()) so that interception actually happens.
        this.formTarget.requestSubmit();
    }
}
