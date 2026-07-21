import { Controller } from '@hotwired/stimulus';

// Carnet de notes - student-side audio appreciation playback + listen tracking (design Part C).
// One instance per graded row that has an audio comment (see gradebook_student.html.twig).
// Progress is reported throttled to ~5s of playback (not every timeupdate tick, which fires
// several times a second) and only the furthest point reached is ever sent - the ratchet itself
// lives server-side (App\Entity\GradeAudioComment::registerListenProgress()), this just avoids
// spamming the endpoint.
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
        this.lastReportedAt = now;

        fetch(this.progressUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify({ percent: this.maxPercent }),
        }).catch(() => {});
    }
}
