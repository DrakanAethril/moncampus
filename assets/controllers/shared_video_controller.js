import { Controller } from '@hotwired/stimulus';

// The player of a video shared to a class (templates/student_shared_document/watch.html.twig).
//
// The address is fetched rather than laid into the page: the page never carries it, and the route
// that signs it is where the right to watch is checked again. It is set on the element as soon as
// the screen opens - a <video> with no source has disabled controls, so waiting for the first play
// would wait for a gesture the browser refuses. `preload="none"` is what keeps that free: the
// address is resolved, and no byte of video is read until play is pressed.
//
// The context menu is refused on the element: « Enregistrer la vidéo sous… » is the download entry
// every browser has, and `controlslist="nodownload"` only removes Chrome's other one.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['player', 'unavailable'];

    static values = { playbackUrl: String };

    connect() {
        this.loadSource();
    }

    async loadSource() {
        if (this.playerTarget.src) return;

        try {
            const response = await fetch(this.playbackUrlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) return this.failed();
            const data = await response.json();
            this.playerTarget.src = data.url;
        } catch (e) {
            this.failed();
        }
    }

    refuseMenu(event) {
        event.preventDefault();
    }

    // Also the <video>'s own `error`: the object is gone, or the signed address has run out.
    failed() {
        this.unavailableTarget.hidden = false;
    }
}
