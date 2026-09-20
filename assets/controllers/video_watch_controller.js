import { Controller } from '@hotwired/stimulus';

// The student's video player for a Watching assignment, and the watch tracking behind it. One
// instance per file of the set.
//
// Deliberately the same two rules as audio_listen_controller.js, restated here rather than shared:
// the mobile player applies them too, and the server only ever keeps the maximum it is handed
// (App\Entity\VideoWatchProgress).
//
//  1. "Le seek en avant ne compte pas comme vu." What is tracked is not the position but the
//     furthest point reached CONTIGUOUSLY: a tick only counts when the playhead is still within
//     reach of what has already been watched. Jumping ahead - mid-playback or by pausing, dragging
//     and playing on, which looks perfectly ordinary tick by tick - lands beyond that point and
//     earns nothing until the student comes back and watches through the gap. Rewinding costs
//     nothing: replaying covers ground already credited, and crediting resumes on passing the
//     furthest point.
//
//  2. Crediting resumes from what the server already knows, not from zero: a student who watched
//     half yesterday would otherwise never reach 100 % without replaying the whole file in one go.
//
// What this controller has that the audio one does not is the drawing of rule 1. A video runs long
// enough that a student can genuinely wonder why they are at 32 % with the cursor two thirds along,
// and an unexplained percentage reads as a bug - so the bar shows the credited stretch, the stretch
// seen after a jump that earns nothing, and the playhead.
//
// Beside the percentage, the player reports how the video was watched, for the teacher's statistics
// (App\Service\VideoWatchReport) - none of it can complete anything:
//
//  - the wall-clock time the video really spent playing, which set against the running time says
//    whether it was watched at double speed;
//  - each skip: a forward jump landing beyond what had been watched, with where it left and where it
//    landed. A drag of the scrubber fires "seeking" many times, so the jump is only settled once
//    playback resumes, from wherever the student let go;
//  - each loss of focus. The video must not run in a page the student is not looking at, so the
//    moment the page is hidden or the window loses the focus, a playing video is paused and the
//    pause recorded. Picture-in-picture is disabled on the element for the same reason: it is
//    precisely a way of watching from elsewhere.
//
// Reporting is throttled to ~5s of playback rather than every timeupdate tick, which fires several
// times a second. Throttling alone loses the tail - a student who stops between two ticks was
// credited up to the last one - so pausing, reaching the end and hiding the page all flush, and the
// flush that goes out while the page is disappearing uses keepalive, the browser being free to drop
// an ordinary request at that point.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['player', 'bar', 'skipped', 'cursor', 'state', 'note', 'clock'];

    static values = {
        playbackUrl: String,
        progressUrl: String,
        csrfToken: String,
        percent: Number,
        labels: Object,
    };

    // How far ahead of the furthest point watched a tick may land and still count. Wide enough for
    // the gap between two timeupdate events, which do not fire on a fixed beat; far short of a seek.
    static CONTIGUITY_TOLERANCE_SECONDS = 1.5;

    connect() {
        this.maxPercent = this.percentValue;
        this.sentPercent = this.percentValue;
        this.creditedSeconds = null;
        this.lastReportedAt = 0;
        // What the next report carries beside the percentage.
        this.pendingWatchedSeconds = 0;
        this.pendingEvents = [];
        // Where the playhead last was during ordinary playback, where a seek started from, and the
        // wall clock of the previous tick - the three readings the detail is built from.
        this.lastPlayhead = null;
        this.seekFrom = null;
        this.lastTickAt = null;
        this.pausedForFocus = false;
        // The media could not be fetched or could not be decoded. Said on screen rather than left
        // to the console: a player that simply never starts reads as "the platform is broken", and
        // the student has no way of telling that apart from a video they are not allowed to watch.
        this.unavailable = false;

        this.onVisibilityChange = () => {
            if (document.visibilityState === 'hidden') this.focusLost();
        };
        this.onBlur = () => this.focusLost();
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        window.addEventListener('blur', this.onBlur);
        this.paint();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        window.removeEventListener('blur', this.onBlur);
    }

    // The page is no longer the one in front of the student: a playing video stops there, and the
    // pause is recorded. Whatever was pending goes out now, with keepalive - a hidden page may be a
    // page being closed.
    focusLost() {
        const player = this.playerTarget;

        if (!player.paused && !player.ended) {
            this.pendingEvents.push({ type: 'focus_loss', at: player.currentTime });
            this.pausedForFocus = true;
            player.pause();
            this.paint();
        }

        this.flush(true);
    }

    // A playhead set moving while the page is not in front of the student - a media key, a headset
    // button - is stopped at once: the rule is about where the student is, not about how play was
    // pressed.
    pageHasFocus() {
        return document.visibilityState === 'visible' && document.hasFocus();
    }

    // The source is fetched on first play rather than laid into the page: a video weighs ten to a
    // hundred times an audio file, so a page opened and left would cost its whole transfer.
    async started() {
        if (!this.pageHasFocus()) {
            this.playerTarget.pause();

            return;
        }

        this.pausedForFocus = false;
        this.paint();

        if (this.playerTarget.src) return;

        let data;
        try {
            const response = await fetch(this.playbackUrlValue);
            if (!response.ok) return this.failed();
            data = await response.json();
        } catch (e) {
            return this.failed();
        }

        this.playerTarget.src = data.url;
        this.playerTarget.play();
    }

    // The media itself answered an error - the object is gone from the bucket, or the address it is
    // served under no longer resolves. Fired by the <video>'s own `error` event as well as by a
    // refused playback address.
    failed() {
        this.unavailable = true;
        this.paint();
    }

    // A seek starts: remember where the playhead was when it began. A drag fires this many times,
    // and only the first one knows where the student came from.
    seeking() {
        if (this.seekFrom === null) this.seekFrom = this.lastPlayhead ?? 0;
        this.lastTickAt = null;
    }

    paused() {
        this.lastTickAt = null;
        this.flush();
    }

    tick() {
        const player = this.playerTarget;
        if (!player.duration || player.paused || player.seeking) return;

        // Rule 2: what was already credited, expressed in this file's own seconds. Only knowable
        // once the duration is, hence here rather than in connect().
        if (this.creditedSeconds === null) {
            this.creditedSeconds = (this.maxPercent / 100) * player.duration;
        }

        // Playback resumed after a seek: it is settled here, from where the student let go. Only
        // the stretch beyond both the starting point and what was already credited was missed -
        // jumping around inside what has been watched skips nothing.
        if (this.seekFrom !== null) {
            const from = Math.max(this.seekFrom, this.creditedSeconds);
            if (player.currentTime > from + this.constructor.CONTIGUITY_TOLERANCE_SECONDS) {
                this.pendingEvents.push({ type: 'skip', from, to: player.currentTime });
            }
            this.seekFrom = null;
        }
        this.lastPlayhead = player.currentTime;

        // Wall-clock time really spent playing. A gap longer than two seconds between ticks is not
        // playing - a stalled network, a machine asleep - and is not counted.
        const nowTick = performance.now();
        if (this.lastTickAt !== null) {
            const elapsed = (nowTick - this.lastTickAt) / 1000;
            if (elapsed > 0 && elapsed < 2) this.pendingWatchedSeconds += elapsed;
        }
        this.lastTickAt = nowTick;

        // Rule 1: a position beyond what has been watched, plus the tolerance, was jumped to. The
        // gap is remembered rather than discarded - it is what the bar draws as "vu après un saut".
        if (player.currentTime > this.creditedSeconds + this.constructor.CONTIGUITY_TOLERANCE_SECONDS) {
            this.skippedFrom = this.creditedSeconds;
            this.paint();

            return;
        }

        if (player.currentTime > this.creditedSeconds) {
            this.creditedSeconds = player.currentTime;
            // Caught up with the gap: there is nothing uncredited left ahead of the playhead.
            this.skippedFrom = null;
        }

        const percent = Math.floor((this.creditedSeconds / player.duration) * 100);
        if (percent > this.maxPercent) this.maxPercent = percent;

        // The last fraction of a second rarely produces a timeupdate: a file played to the very end
        // must reach 100, or no assignment would ever complete.
        if (player.duration - this.creditedSeconds <= 0.25) this.maxPercent = 100;

        this.paint();

        const now = Date.now();
        if (now - this.lastReportedAt < 5000 && (this.maxPercent < 100 || this.sentPercent >= 100)) return;

        this.flush();
    }

    // Nothing to send when there is nothing new: no further point reached, under a second played
    // since the last report, and no event - a paused player fires "pause" on every seek.
    flush(keepalive = false) {
        const watchedSeconds = Math.floor(this.pendingWatchedSeconds);
        if (this.maxPercent <= this.sentPercent && watchedSeconds < 1 && this.pendingEvents.length === 0) return;

        const body = { percent: this.maxPercent, watchedSeconds, events: this.pendingEvents };

        this.lastReportedAt = Date.now();
        this.sentPercent = this.maxPercent;
        // The fraction of a second stays pending: flooring every report would lose a second in
        // every five.
        this.pendingWatchedSeconds -= watchedSeconds;
        this.pendingEvents = [];

        fetch(this.progressUrlValue, {
            method: 'POST',
            keepalive: keepalive === true,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body: JSON.stringify(body),
        }).catch(() => {});
    }

    paint() {
        const player = this.playerTarget;
        const duration = player.duration || 0;

        this.barTarget.style.width = `${this.maxPercent}%`;
        this.barTarget.classList.toggle('cm-video-track__fill--done', this.maxPercent >= 100);

        this.stateTarget.textContent = this.maxPercent >= 100
            ? this.labelsValue.complete
            : (this.maxPercent > 0 ? this.labelsValue.progress.replace('%percent%', this.maxPercent) : this.labelsValue.notStarted);

        if (duration > 0) {
            this.cursorTarget.hidden = false;
            this.cursorTarget.style.left = `${Math.min(100, (player.currentTime / duration) * 100)}%`;
            this.clockTarget.textContent = `${this.clock(player.currentTime)} / ${this.clock(duration)}`;
        }

        // The stretch played past the credited point: seen, and counting for nothing until the gap
        // itself has been watched. Saying so is the whole reason this screen is a page.
        const skipping = this.skippedFrom != null && duration > 0 && player.currentTime > this.skippedFrom;
        this.skippedTarget.hidden = !skipping;

        if (skipping) {
            const from = (this.skippedFrom / duration) * 100;
            this.skippedTarget.style.left = `${from}%`;
            this.skippedTarget.style.width = `${Math.max(0, Math.min(100, (player.currentTime / duration) * 100 - from))}%`;
        }

        this.noteTarget.textContent = this.noteFor(duration, skipping);
    }

    noteFor(duration, skipping) {
        if (this.unavailable) return this.labelsValue.unavailable;

        if (this.pausedForFocus) return this.labelsValue.focusPaused;

        if (skipping) {
            return this.labelsValue.skipped
                .replace('%from%', this.clock(this.skippedFrom))
                .replace('%to%', this.clock(this.playerTarget.currentTime));
        }

        if (duration === 0 || this.maxPercent >= 100) return '';

        return this.labelsValue.remaining.replace('%time%', this.clock(duration * (1 - this.maxPercent / 100)));
    }

    clock(seconds) {
        const rounded = Math.max(0, Math.round(seconds));

        return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
    }
}
