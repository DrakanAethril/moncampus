import { Controller } from '@hotwired/stimulus';

/**
 * Reloads a turbo-frame every 10 s while what it shows is still moving - a reminder batch running
 * or paused (templates/ufa/reminder_batch/show.html.twig). The frame says itself when to stop: once
 * its fresh content carries data-live="false", the batch is over and the loop ends.
 *
 * The loop only watches. Closing the tab stops nothing: the batch is sent by the scheduled command.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['frame'];
    static values = { url: String, intervalMs: { type: Number, default: 10000 } };

    connect() {
        this.onLoad = () => {
            if (this.frameTarget.dataset.live === 'false') {
                clearInterval(this.timer);
            }
        };
        this.frameTarget.addEventListener('turbo:frame-load', this.onLoad);
        this.timer = setInterval(() => this.tick(), this.intervalMsValue);
    }

    disconnect() {
        clearInterval(this.timer);
        this.frameTarget.removeEventListener('turbo:frame-load', this.onLoad);
    }

    tick() {
        // Not while the page is hidden: nobody is reading, and the next visible tick catches up.
        if (document.hidden) {
            return;
        }

        if (this.frameTarget.getAttribute('src')) {
            this.frameTarget.reload();
        } else {
            this.frameTarget.src = this.urlValue;
        }
    }
}
