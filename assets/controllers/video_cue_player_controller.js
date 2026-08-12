import { Controller } from '@hotwired/stimulus';

// The student's side of an interactive video (créas 5B, screen 4): the question that appears over
// the player when its marker is reached, and what happens once it is answered.
//
// It sits alongside video_watch_controller.js on the same <section> rather than inside it. The two
// answer different questions - one measures what was really watched, the other asks the questions
// posted along the way - and folding them together would have the watching rules and the answering
// rules share a state machine they have no reason to share.
//
// Three rules the screen rests on, all of them decided rather than fallen into:
//
//  1. A marker fires when the playhead REACHES it, and never when it is dragged past. What is
//     asked is what was watched, and a student scrubbing to the end must not collect four
//     questions in a row about a lecture they have not seen.
//
//  2. A marker fires once. The answer that counts is the first one (App\Entity\VideoCueAnswer keeps
//     it and no other), so asking again on a second viewing would ask for an answer that changes
//     nothing - the marker is drawn, and left alone.
//
//  3. Nothing is blocking unless the teacher said so. On an ordinary marker the video is paused so
//     the question can be read, "Passer" is offered, and a wrong answer runs on with its correction
//     showing.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['player', 'overlay', 'panel'];

    static values = {
        cuesUrl: String,
        questionUrlTemplate: String,
        fileId: Number,
    };

    // How far past a marker the playhead may already be and still fire it. A timeupdate fires a few
    // times a second, so the marker's own second is rarely landed on exactly; anything wider would
    // start catching markers that were seeked over.
    static REACH_TOLERANCE_SECONDS = 1.5;

    async connect() {
        this.cuePoints = [];
        this.pending = null;

        let data;
        try {
            const response = await fetch(this.cuesUrlValue);
            if (!response.ok) return;
            data = await response.json();
        } catch (e) {
            return;
        }

        this.cuePoints = (data.cuePoints || [])
            .filter((cue) => cue.fileId === this.fileIdValue)
            .map((cue) => ({ ...cue, fired: cue.answered }));
        this.paintMarkers();
    }

    // Called from the player's own timeupdate: rule 1, the playhead has to walk onto the marker.
    tick() {
        if (this.pending) return;

        const at = this.playerTarget.currentTime;
        const cue = this.cuePoints.find(
            (candidate) => !candidate.fired
                && at >= candidate.timecode
                && at - candidate.timecode <= this.constructor.REACH_TOLERANCE_SECONDS,
        );

        if (cue) this.ask(cue);
    }

    async ask(cue) {
        cue.fired = true;
        this.pending = cue;

        if (cue.pauseVideo) this.playerTarget.pause();

        let html;
        try {
            const response = await fetch(this.questionUrlTemplateValue.replace('__CUE_ID__', cue.id));
            if (!response.ok) throw new Error('unreachable');
            html = await response.text();
        } catch (e) {
            // A question that cannot be fetched must not take the lecture down with it: the video
            // simply runs on, and the marker stays fired so it is not retried every tick.
            this.pending = null;
            if (cue.pauseVideo) this.playerTarget.play();

            return;
        }

        this.panelTarget.innerHTML = html;
        this.overlayTarget.hidden = false;
    }

    async submit(event) {
        event.preventDefault();
        const form = event.target;

        let data;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
            if (!response.ok) return;
            data = await response.json();
        } catch (e) {
            return;
        }

        this.panelTarget.innerHTML = data.html;
    }

    // "Revoir le passage": the answer to a wrong answer is the video itself, which already explains
    // it. Playing rather than only seeking - the point is to watch the stretch again.
    replay(event) {
        this.close();
        this.playerTarget.currentTime = Number(event.params.from || 0);
        this.playerTarget.play();
    }

    skip() {
        this.close();
        this.playerTarget.play();
    }

    resume() {
        this.close();
        this.playerTarget.play();
    }

    close() {
        this.overlayTarget.hidden = true;
        this.panelTarget.innerHTML = '';
        this.pending = null;
        this.paintMarkers();
    }

    // The markers drawn on the watching bar, so a student can see a question coming rather than be
    // interrupted by one. Redrawn on every close: one of them has just been answered.
    paintMarkers() {
        const track = this.element.querySelector('.cm-video-track');
        const duration = this.playerTarget.duration || 0;
        if (!track) return;

        track.querySelectorAll('.cm-video-track__cue').forEach((mark) => mark.remove());
        if (!duration) return;

        this.cuePoints.forEach((cue) => {
            const mark = document.createElement('span');
            mark.className = `cm-video-track__cue${cue.fired ? ' cm-video-track__cue--done' : ''}`;
            mark.style.left = `${Math.min(100, (cue.timecode / duration) * 100)}%`;
            track.appendChild(mark);
        });
    }
}
