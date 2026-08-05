import { Controller } from '@hotwired/stimulus';

// Carnet de notes - student-side audio appreciation playback + listen tracking (design Part C).
// One instance per graded row that has an audio comment (see gradebook_student.html.twig).
// Progress is reported throttled to ~5s of playback (not every timeupdate tick, which fires
// several times a second) and only the furthest point reached is ever sent - the ratchet itself
// lives server-side (App\Entity\GradeAudioComment::registerListenProgress()), this just avoids
// spamming the endpoint.
//
// Throttling alone loses the tail: a student who stops, or leaves the page, between two ticks was
// credited only up to the last one - up to five seconds short, which on a short comment is the
// difference between "listened" and "half listened". So pausing, reaching the end and hiding the
// page all flush what has been heard, and the flush that goes out while the page is disappearing
// uses keepalive, the browser being free to drop an ordinary request at that point.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['player', 'playButton'];

    static values = {
        urlEndpoint: String,
        progressUrl: String,
        csrfToken: String,
        playLabel: String,
    };

    connect() {
        this.lastReportedAt = 0;
        this.maxPercent = 0;
        this.sentPercent = 0;
        this.onVisibilityChange = () => {
            if (document.visibilityState === 'hidden') this.flush(true);
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    async play() {
        if (!this.playerTarget.src) {
            let response;
            try {
                response = await fetch(this.urlEndpointValue);
            } catch (e) {
                return;
            }
            if (!response.ok) return;

            const data = await response.json();
            this.playerTarget.src = data.url;
            this.playerTarget.addEventListener('timeupdate', () => this.onTimeUpdate());
            this.playerTarget.addEventListener('pause', () => this.flush());
            this.playerTarget.addEventListener('ended', () => this.flush());
        }

        this.playerTarget.hidden = false;
        this.playButtonTarget.hidden = true;
        this.playerTarget.play();
    }

    onTimeUpdate() {
        const player = this.playerTarget;
        if (!player.duration) return;

        const percent = Math.round((player.currentTime / player.duration) * 100);
        this.maxPercent = Math.max(this.maxPercent, percent);

        const now = Date.now();
        if (now - this.lastReportedAt < 5000 && percent < 100) return;

        this.flush();
    }

    // Nothing to send when the furthest point reached has already been reported: the server-side
    // ratchet would ignore it anyway, and a paused player fires "pause" on every seek.
    flush(keepalive = false) {
        if (this.maxPercent <= this.sentPercent) return;

        this.lastReportedAt = Date.now();
        this.sentPercent = this.maxPercent;

        fetch(this.progressUrlValue, {
            method: 'POST',
            keepalive,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify({ percent: this.maxPercent }),
        }).catch(() => {});
    }
}
