import { Controller } from '@hotwired/stimulus';

/**
 * The player of an online course's material (design/validated/cours-en-ligne.md, §7): full screen,
 * and - on the full-page screen - the floating bar that folds away.
 *
 * Two things it has to live with, both because the course runs in a frame from another origin:
 *
 * - **the page hears nothing that happens over the frame**: no mouse move, no key. So the bar does
 *   not come back « when the mouse moves »; it folds into a small handle that stays within reach,
 *   and wakes when that handle is hovered, focused or touched;
 * - **full screen is asked for on the frame's container**, never on the frame's document, which is
 *   not this page's to ask. Where the browser has no full screen for an element (iOS), the button
 *   is taken away rather than left to do nothing: the full page is what stands in for it there.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['stage', 'bar', 'fullscreen'];
    static values = { restAfter: { type: Number, default: 3000 } };

    connect() {
        const root = this.hasStageTarget ? this.stageTarget : this.element;

        if (!document.fullscreenEnabled || typeof root.requestFullscreen !== 'function') {
            this.fullscreenTargets.forEach((button) => { button.hidden = true; });
        }

        if (this.hasBarTarget) {
            this.rest();
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    fullscreen() {
        const root = this.hasStageTarget ? this.stageTarget : this.element;

        if (document.fullscreenElement) {
            document.exitFullscreen();

            return;
        }

        // A refusal - a browser setting, an embedding that forbids it - leaves the page as it was.
        root.requestFullscreen().catch(() => {});
    }

    wake() {
        clearTimeout(this.timer);
        this.barTarget.classList.remove('is-resting');
    }

    rest() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.barTarget.classList.add('is-resting'), this.restAfterValue);
    }
}
