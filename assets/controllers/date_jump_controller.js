import { Controller } from '@hotwired/stimulus';

/**
 * A date label that opens the native calendar and reloads the page on the picked date.
 *
 * The navigation arrows sitting next to it are deliberately NOT handled here: they are plain
 * links, because only the server knows which day or week actually comes next once the empty ones
 * are skipped. This controller only has to hand the picked date over to the same route.
 *
 * Same job as staff_timetable_controller's pickDate/submitDate, extracted standalone so a card
 * that only needs the picker doesn't have to carry that controller's row/legend targets.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'form'];

    open() {
        // showPicker() is the only way to open the native calendar from another element. Guarded
        // because it throws on browsers that don't implement it, and on a cross-origin iframe -
        // falling back to focusing the input, which at least lets the keyboard through.
        try {
            this.inputTarget.showPicker();
        } catch {
            this.inputTarget.focus();
        }
    }

    submit() {
        // requestSubmit(), not submit(): only the former fires the submit event Turbo intercepts.
        this.formTarget.requestSubmit();
    }
}
