import { Controller } from '@hotwired/stimulus';

// The « note négative sur erreurs » block of « Lancer un quiz ». Two nested conditions, both of
// them cosmetic: the settings only make sense under the checkbox, and each penalty field only
// under its own mode.
//
// None of this is the rule. The fields stay in the form whether or not they are on screen, and
// App\Entity\QuizInstance::penaltyFor() reads the checkbox before anything else - a penalty left
// behind in a hidden field costs nobody a point.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toggle', 'settings', 'mode', 'fixed', 'scale'];

    connect() {
        this.refresh();
    }

    refresh() {
        // `d-none` rather than the `hidden` attribute: Bootstrap's own `.d-flex` is `!important`
        // and wins over `hidden`, so a hidden flex column would stay on screen.
        this.settingsTarget.classList.toggle('d-none', !this.toggleTarget.checked);
        this.fixedTarget.classList.toggle('d-none', this.modeTarget.value !== 'fixed');
        this.scaleTarget.classList.toggle('d-none', this.modeTarget.value !== 'scale');
    }
}
