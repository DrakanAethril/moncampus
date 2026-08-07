import { Controller } from '@hotwired/stimulus';

// The student's audio player for a Listening assignment, and the listen tracking behind it
// (design_handoff_enregistrements_audio, "Tracking d'écoute"). One instance per file of the brief.
//
// Two rules live here, and the mobile player applies exactly the same ones - the server only keeps
// the maximum it is handed (App\Entity\AudioListenProgress):
//
//  1. "Le seek en avant ne compte pas comme écouté." What is tracked is not the position but the
//     furthest point reached CONTIGUOUSLY: a tick only counts when the playhead is still within
//     reach of what has already been heard. Jumping ahead - mid-playback or by pausing, dragging and
//     playing on, which looks perfectly ordinary tick by tick - lands beyond that point and earns
//     nothing until the student comes back and listens through the gap. Rewinding costs nothing:
//     replaying covers ground already credited, and crediting resumes on passing the furthest point.
//
//  2. Crediting resumes from what the server already knows, not from zero: a student who listened to
//     half yesterday would otherwise never reach 100% without replaying the whole file in one go.
//
// Reporting is throttled to ~5s of playback rather than every timeupdate tick, which fires several
// times a second. Throttling alone loses the tail - a student who stops between two ticks was
// credited up to the last one - so pausing, reaching the end and hiding the page all flush, and the
// flush that goes out while the page is disappearing uses keepalive, the browser being free to drop
// an ordinary request at that point.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['player', 'bar', 'state'];

    static values = {
        playbackUrl: String,
        progressUrl: String,
        csrfToken: String,
        percent: Number,
        labels: Object,
    };

    // How far ahead of the furthest point heard a tick may land and still count. Wide enough for the
    // gap between two timeupdate events, which do not fire on a fixed beat; far short of a seek.
    static CONTIGUITY_TOLERANCE_SECONDS = 1.5;

    connect() {
        this.maxPercent = this.percentValue;
        this.sentPercent = this.percentValue;
        this.creditedSeconds = null;
        this.lastReportedAt = 0;
        this.onVisibilityChange = () => {
            if (document.visibilityState === 'hidden') this.flush(true);
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.paint();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    // The source is fetched on first play rather than laid into the page: an individualised recording
    // would otherwise hand every student's address to everybody.
    async started() {
        if (this.playerTarget.src) return;

        let data;
        try {
            const response = await fetch(this.playbackUrlValue);
            if (!response.ok) return;
            data = await response.json();
        } catch (e) {
            return;
        }

        this.playerTarget.src = data.url;
        this.playerTarget.play();
    }

    tick() {
        const player = this.playerTarget;
        if (!player.duration || player.paused) return;

        // Rule 2: what was already credited, expressed in this file's own seconds. Only knowable
        // once the duration is, hence here rather than in connect().
        if (this.creditedSeconds === null) {
            this.creditedSeconds = (this.maxPercent / 100) * player.duration;
        }

        // Rule 1: a position beyond what has been heard, plus the tolerance, was jumped to.
        if (player.currentTime > this.creditedSeconds + this.constructor.CONTIGUITY_TOLERANCE_SECONDS) {
            return;
        }

        if (player.currentTime > this.creditedSeconds) {
            this.creditedSeconds = player.currentTime;
        }

        const percent = Math.floor((this.creditedSeconds / player.duration) * 100);
        if (percent > this.maxPercent) this.maxPercent = percent;

        // The last fraction of a second rarely produces a timeupdate: a file played to the very end
        // must reach 100, or no assignment would ever complete.
        if (player.duration - this.creditedSeconds <= 0.25) this.maxPercent = 100;

        this.paint();

        const now = Date.now();
        if (now - this.lastReportedAt < 5000 && this.maxPercent < 100) return;

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

    paint() {
        this.barTarget.style.width = `${this.maxPercent}%`;
        this.barTarget.classList.toggle('cm-audio-bar__fill--done', this.maxPercent >= 100);
        this.stateTarget.textContent = this.maxPercent >= 100
            ? this.labelsValue.complete
            : (this.maxPercent > 0 ? this.labelsValue.progress.replace('%percent%', this.maxPercent) : this.labelsValue.notStarted);
    }
}
