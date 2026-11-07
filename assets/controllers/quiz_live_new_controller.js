import { Controller } from '@hotwired/stimulus';

/**
 * The new live contest form: a contest of no class is always public (nobody else could join it), so
 * choosing « Aucune classe » ticks « Concours public » and holds it there - the server forces it
 * anyway (App\Entity\QuizLiveSession's constructor).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['program', 'public'];

    connect() {
        this.sync();
    }

    sync() {
        if (!this.hasProgramTarget || !this.hasPublicTarget) {
            return;
        }
        const classless = '' === this.programTarget.value;
        if (classless) {
            this.publicTarget.checked = true;
        }
        // Not `disabled`: a disabled box would not be posted.
        this.publicTarget.toggleAttribute('aria-disabled', classless);
        this.publicTarget.closest('label')?.classList.toggle('is-locked', classless);
        this.publicTarget.onclick = classless ? (event) => event.preventDefault() : null;
    }
}
