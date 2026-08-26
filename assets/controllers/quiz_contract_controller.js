import { Controller } from '@hotwired/stimulus';

// The entry contract of a supervised évaluation: the button stays inert until the box is ticked,
// and full screen is asked for inside the click handler.
//
// Inside the handler is the whole point: a browser only grants full screen during a user gesture,
// so asking for it after the navigation - on the question screen, on connect() - is asking for a
// refusal. A refusal is not fatal either way; the student composes all the same, and leaving full
// screen is journaled rather than blocked.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['accept', 'submit'];

    toggle() {
        this.submitTarget.disabled = !this.acceptTarget.checked;
    }

    begin() {
        if (!this.acceptTarget.checked) {
            return;
        }

        // Never awaited, never blocking: the form submits whatever the browser answers.
        document.documentElement.requestFullscreen?.().catch(() => {});
    }
}
