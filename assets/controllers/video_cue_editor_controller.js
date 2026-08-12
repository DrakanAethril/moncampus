import { Controller } from '@hotwired/stimulus';

// The teacher's timeline editor (créas 5B, screen 3): click the timeline to place a marker, pick
// the question from the library, save.
//
// Everything is saved as it happens and redrawn from what the server answered, the same rule step 2
// follows: what is on screen is what is in the database rather than a hopeful copy of it. The one
// place that matters is the timecode - a marker drawn where the click landed but stored where the
// server rounded it would be a screen quietly lying about a lecture.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'player', 'timeline', 'cursor', 'clock', 'rows', 'warning',
        'timecode', 'template', 'question', 'pause', 'blocking',
        'panelTitle', 'deleteButton', 'resetButton',
    ];

    static values = {
        cuePoints: Array,
        duration: Number,
        playbackUrl: String,
        saveUrl: String,
        deleteUrlTemplate: String,
        libraryUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        // Stimulus Array/Object values re-parse on every access - read once, keep the copy.
        this.cuePoints = [...this.cuePointsValue];
        this.labels = this.labelsValue;
        this.selectedId = 0;
        this.paint();

        // The results screen links here with "#t=340": land the editor on the marker being looked
        // into rather than at the beginning of a twelve-minute lecture.
        const anchor = /^#t=(\d+)$/.exec(window.location.hash);
        if (anchor) this.timecodeTarget.value = this.clock(Number(anchor[1]));
    }

    // The source is fetched on first play, as everywhere else a video is shown: a screen opened to
    // read the list of questions must not cost the whole transfer.
    async started() {
        if (this.playerTarget.src) return;

        try {
            const response = await fetch(this.playbackUrlValue);
            if (!response.ok) return;
            const data = await response.json();
            this.playerTarget.src = data.url;
            this.playerTarget.play();
        } catch (e) {
            /* the poster stays, and the timeline still works: a marker is a number, not a frame */
        }
    }

    tick() {
        const duration = this.playerTarget.duration || this.durationValue || 0;
        if (!duration) return;

        this.cursorTarget.hidden = false;
        this.cursorTarget.style.left = `${Math.min(100, (this.playerTarget.currentTime / duration) * 100)}%`;
        this.clockTarget.textContent = `${this.clock(this.playerTarget.currentTime)} / ${this.clock(duration)}`;
    }

    // A click on the timeline is where the question goes - the créa's own instruction, and the one
    // gesture the screen is built around.
    place(event) {
        const duration = this.playerTarget.duration || this.durationValue || 0;
        if (!duration) return;

        const bounds = this.timelineTarget.getBoundingClientRect();
        const ratio = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));
        const at = Math.round(ratio * duration);

        this.timecodeTarget.value = this.clock(at);
        if (this.playerTarget.src) this.playerTarget.currentTime = at;
        this.tick();
    }

    // Typing a timecode moves the player too: the teacher is checking they are posting the question
    // after the passage that answers it, which they can only do by looking at the frame.
    timecodeTyped() {
        const at = this.seconds(this.timecodeTarget.value);
        if (at !== null && this.playerTarget.src) {
            this.playerTarget.currentTime = at;
            this.tick();
        }
    }

    async bankPicked() {
        const templateId = this.templateTarget.value;
        this.questionTarget.innerHTML = '';
        this.questionTarget.disabled = !templateId;

        if (!templateId) return;

        let data;
        try {
            const response = await fetch(this.libraryUrlTemplateValue.replace('__TEMPLATE_ID__', templateId));
            if (!response.ok) throw new Error('unreachable');
            data = await response.json();
        } catch (e) {
            this.warn(this.labels.networkError);

            return;
        }

        this.questionTarget.append(new Option(this.labels.noQuestion, ''));
        data.questions.forEach((question) => {
            this.questionTarget.append(new Option(`${question.type} · ${question.label}`, question.id));
        });
    }

    async save() {
        const timecode = this.seconds(this.timecodeTarget.value);
        if (timecode === null) return this.warn(this.labels.outOfRange);

        const questionId = Number(this.questionTarget.value || 0);
        if (!questionId && !this.selectedId) return this.warn(this.labels.noQuestion);

        const body = {
            cueId: this.selectedId,
            timecode,
            questionId,
            pauseVideo: this.pauseTarget.checked,
            blocking: this.blockingTarget.checked,
        };

        let data;
        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify(body),
            });
            data = await response.json();
            if (!response.ok) throw new Error(data.error || 'unreachable');
        } catch (e) {
            this.warn(this.labels[e.message] || this.labels.networkError);

            return;
        }

        this.cuePoints = this.cuePoints.filter((cue) => cue.id !== data.cuePoint.id);
        this.cuePoints.push(data.cuePoint);
        this.warn(null);
        this.reset();
    }

    select(event) {
        const cue = this.cuePoints.find((candidate) => candidate.id === Number(event.params.cue));
        if (!cue) return;

        this.selectedId = cue.id;
        this.timecodeTarget.value = this.clock(cue.timecode);
        this.pauseTarget.checked = cue.pauseVideo;
        this.blockingTarget.checked = cue.blocking;
        this.panelTitleTarget.textContent = this.labels.selected.replace('%timecode%', cue.formattedTimecode);
        this.deleteButtonTarget.hidden = false;
        this.resetButtonTarget.hidden = false;

        if (this.playerTarget.src) this.playerTarget.currentTime = cue.timecode;
        this.paint();
    }

    async remove() {
        if (!this.selectedId || !window.confirm(this.labels.deleteConfirm)) return;

        try {
            const response = await fetch(this.deleteUrlTemplateValue.replace('__CUE_ID__', this.selectedId), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
            });
            if (!response.ok) throw new Error('unreachable');
        } catch (e) {
            this.warn(this.labels.networkError);

            return;
        }

        this.cuePoints = this.cuePoints.filter((cue) => cue.id !== this.selectedId);
        this.reset();
    }

    reset() {
        this.selectedId = 0;
        this.panelTitleTarget.textContent = this.labels.newMarker;
        this.deleteButtonTarget.hidden = true;
        this.resetButtonTarget.hidden = true;
        this.paint();
    }

    paint() {
        const duration = this.playerTarget.duration || this.durationValue || 0;
        this.cuePoints.sort((a, b) => a.timecode - b.timecode);

        this.timelineTarget.querySelectorAll('.cm-video-timeline__marker').forEach((mark) => mark.remove());
        this.cuePoints.forEach((cue) => {
            const mark = document.createElement('button');
            mark.type = 'button';
            mark.className = `cm-video-timeline__marker${cue.id === this.selectedId ? ' is-selected' : ''}`;
            mark.style.left = duration ? `${Math.min(100, (cue.timecode / duration) * 100)}%` : '0%';
            mark.dataset.action = 'click->video-cue-editor#select';
            mark.dataset.videoCueEditorCueParam = cue.id;
            mark.title = `${cue.formattedTimecode} — ${cue.label ?? ''}`;
            mark.innerHTML = `<span class="cm-video-timeline__pin"></span><span class="cm-video-timeline__tag">${cue.formattedTimecode}</span>`;
            this.timelineTarget.appendChild(mark);
        });

        this.rowsTarget.innerHTML = '';
        if (this.cuePoints.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'cm-video-cue-row cm-video-cue-row--empty';
            empty.textContent = this.labels.empty;
            this.rowsTarget.appendChild(empty);

            return;
        }

        this.cuePoints.forEach((cue) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = `cm-video-cue-row${cue.id === this.selectedId ? ' is-selected' : ''}`;
            row.dataset.action = 'click->video-cue-editor#select';
            row.dataset.videoCueEditorCueParam = cue.id;

            const time = document.createElement('span');
            time.className = 'cm-video-cue-row__time';
            time.textContent = cue.formattedTimecode;

            const type = document.createElement('span');
            type.className = 'cm-badge cm-badge--blue cm-video-cue-row__type';
            type.textContent = this.labels.types[cue.type] ?? '';

            const label = document.createElement('span');
            label.className = 'cm-video-cue-row__label';
            label.textContent = cue.label ?? '';

            // textContent throughout rather than innerHTML: a statement is teacher-typed text, and
            // it is going into a row this controller builds by hand.
            row.append(time, type, label);
            // Both settings are always drawn, the inactive one greyed, as the créa has them: a row
            // showing only "Pause" leaves "is this one blocking?" to be answered by clicking it.
            row.append(this.flag(this.labels.pause, cue.pauseVideo), this.flag(this.labels.blocking, cue.blocking));

            this.rowsTarget.appendChild(row);
        });
    }

    flag(text, on) {
        const flag = document.createElement('span');
        flag.className = `cm-video-cue-row__flag${on ? ' is-on' : ''}`;
        flag.textContent = text;

        return flag;
    }

    warn(message) {
        this.warningTarget.hidden = !message;
        this.warningTarget.textContent = message || '';
    }

    // "5:40" and "1:05:40" - the same two shapes App\Util\Timecode reads and writes, so what the
    // teacher types here is what the CSV column accepts there.
    clock(seconds) {
        const rounded = Math.max(0, Math.round(seconds));
        const minutes = Math.floor((rounded % 3600) / 60);
        const rest = String(rounded % 60).padStart(2, '0');

        return rounded >= 3600
            ? `${Math.floor(rounded / 3600)}:${String(minutes).padStart(2, '0')}:${rest}`
            : `${minutes}:${rest}`;
    }

    seconds(raw) {
        const value = (raw || '').trim();
        if (!value) return null;

        if (/^\d+$/.test(value)) return Number(value);
        if (!/^\d+(:[0-5]\d){1,2}$/.test(value)) return null;

        return value.split(':').reduce((total, part) => total * 60 + Number(part), 0);
    }
}
