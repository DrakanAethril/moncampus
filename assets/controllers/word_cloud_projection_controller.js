import { Controller } from '@hotwired/stimulus';

// « Projeter » / « Reprojeter »: the board asks the browser for real full screen as soon as it
// opens, and leaving it puts the teacher back where they came from.
//
// requestFullscreen() only works from a user gesture, and arriving on this page is one - the click
// that navigated here. Browsers disagree about whether that gesture still counts after the
// navigation, so the request is attempted and its refusal ignored: the board fills the window
// either way, and the exit link is there whether the browser granted full screen or not.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.onChange = () => {
            // Escape, or the browser's own exit control: the two must do what the link does, or the
            // board would stay on screen with no visible way out.
            if (!document.fullscreenElement && this.wasFullscreen) this.leave();
            this.wasFullscreen = Boolean(document.fullscreenElement);
        };
        document.addEventListener('fullscreenchange', this.onChange);

        document.documentElement.requestFullscreen?.().then(
            () => { this.wasFullscreen = true; },
            () => { this.wasFullscreen = false; },
        );
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onChange);
    }

    leave(event) {
        event?.preventDefault();
        if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
        window.location.assign(this.element.querySelector('.cm-wc-board__exit').href);
    }
}
