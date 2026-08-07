import { Controller } from '@hotwired/stimulus';
import { MicRecorder } from '../audio/mic_recorder.js';

// Audio recordings - step 2 "Fichiers audio" (design_handoff_enregistrements_audio, screen 3).
//
// The common files and, in individualised mode, one row per targeted student. Everything is painted
// here rather than rendered by Twig: recording, deleting or playing back must change a row's state
// without reloading the page - a reload in the middle of a run of recordings would lose track of
// who is left to do.
//
// The recorder itself is not here: it is the gradebook's, moved into assets/audio/mic_recorder.js,
// format included.
//
// One recording at a time: there is a single microphone, and two rows in progress would show two red
// dots for one voice.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['commonList', 'commonButton', 'commonButtonLabel', 'studentList', 'covered', 'warning', 'player'];

    static values = {
        individual: Boolean,
        commonFiles: Array,
        students: Array,
        coveredCount: Number,
        audienceCount: Number,
        uploadUrl: String,
        deleteUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        // Stimulus Array/Object values re-parse on every access: a working copy is taken at connect(),
        // or every mutation would be silently lost.
        this.commonFiles = JSON.parse(JSON.stringify(this.commonFilesValue));
        this.students = JSON.parse(JSON.stringify(this.studentsValue));
        this.covered = this.coveredCountValue;
        this.recordingFor = undefined;
        this.renderCommon();
        this.renderStudents();
        this.renderCovered();
    }

    disconnect() {
        this.recorder?.stop();
    }

    // ---- Rendering ----------------------------------------------------------------------------

    renderCommon() {
        this.commonListTarget.replaceChildren(...this.commonFiles.map((file) => this.buildFileRow(file)));

        // `recordingFor` is null during a common recording, a student's id during theirs, and
        // undefined when the mic is free - hence the strict comparison, a `== null` would conflate the
        // first two.
        const recording = this.recordingFor === null;
        this.commonButtonTarget.classList.toggle('is-recording', recording);
        this.commonButtonTarget.disabled = !recording && this.recordingFor !== undefined;
        this.commonButtonLabelTarget.textContent = recording ? this.labelsValue.stopLabel : this.labelsValue.recordLabel;
    }

    // A common file's row: play button, name, duration, bin. It is the only shape that names the
    // file - a student's chip says neither its name nor its duration (mockup).
    buildFileRow(file) {
        const row = this.el('div', 'cm-audio-file');

        const play = this.el('button', 'cm-audio-file__play');
        play.type = 'button';
        play.setAttribute('aria-label', this.labelsValue.replayLabel);
        play.appendChild(this.icon('m8 5 11 7-11 7z', 12));
        play.addEventListener('click', () => this.play(file));
        row.appendChild(play);

        row.appendChild(this.el('span', 'cm-audio-file__name', file.name));
        row.appendChild(this.el('span', 'cm-audio-file__duration', file.duration));

        const remove = this.el('button', 'cm-audio-file__delete');
        remove.type = 'button';
        remove.title = this.labelsValue.deleteTitle;
        remove.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 13));
        remove.addEventListener('click', () => this.deleteFile(file, null));
        row.appendChild(remove);

        return row;
    }

    renderStudents() {
        if (!this.hasStudentListTarget) return;
        this.studentListTarget.replaceChildren(...this.students.map((student) => this.buildStudentRow(student)));
    }

    buildStudentRow(student) {
        const row = this.el('div', 'cm-audio-student');
        row.appendChild(this.el('span', 'cm-audio-student__name', student.name));

        const tags = this.el('span', 'cm-audio-student__tags');
        for (const option of student.options) {
            const tag = this.el('span', 'cm-audio-tag', option.shortName);
            if (option.color) tag.style.backgroundColor = option.color;
            tags.appendChild(tag);
        }
        row.appendChild(tags);

        const slot = this.el('div', 'cm-audio-student__slot');

        if (this.recordingFor === student.id) {
            // No duration shown while a student is being recorded: the mockup shows a red dot and a
            // label, nothing more.
            const state = this.el('span', 'cm-audio-live');
            state.appendChild(this.el('span', 'cm-audio-live__dot'));
            state.append(this.labelsValue.recordingLabel);
            slot.appendChild(state);

            const stop = this.el('button', 'cm-audio-stop', this.labelsValue.stopLabel);
            stop.type = 'button';
            stop.addEventListener('click', () => this.stopRecording());
            slot.appendChild(stop);
        } else if (student.files.length > 0) {
            for (const file of student.files) slot.appendChild(this.buildReplayChip(file, student));
        }

        if (this.recordingFor !== student.id) {
            const record = this.el('button', 'cm-audio-record');
            record.type = 'button';
            record.disabled = this.recordingFor !== undefined;
            record.appendChild(this.el('span', 'cm-audio-record__dot'));
            record.append(this.labelsValue.recordLabel);
            record.addEventListener('click', () => this.startRecording(student.id));
            slot.appendChild(record);
        }

        row.appendChild(slot);

        return row;
    }

    // "▶ Réécouter ✕": neither file name nor duration, the mockup is explicit about that.
    buildReplayChip(file, student) {
        const chip = this.el('span', 'cm-audio-chip');

        const play = this.el('button', 'cm-audio-chip__play');
        play.type = 'button';
        play.appendChild(this.icon('m8 5 11 7-11 7z', 10));
        play.append(this.labelsValue.replayLabel);
        play.addEventListener('click', () => this.play(file));
        chip.appendChild(play);

        const remove = this.el('button', 'cm-audio-chip__remove', '✕');
        remove.type = 'button';
        remove.title = this.labelsValue.deleteTitle;
        remove.addEventListener('click', () => this.deleteFile(file, student));
        chip.appendChild(remove);

        return chip;
    }

    renderCovered() {
        if (!this.hasCoveredTarget) return;
        this.coveredTarget.textContent = this.labelsValue.coveredLabel
            .replace('%covered%', this.covered)
            .replace('%total%', this.audienceCountValue);
    }

    // ---- Recording ----------------------------------------------------------------------------

    toggleCommon() {
        if (this.recordingFor === null) {
            this.stopRecording();

            return;
        }

        this.startRecording(null);
    }

    async startRecording(studentId) {
        if (this.recordingFor !== undefined) return;

        this.recorder = new MicRecorder({
            onStop: (blob, seconds) => this.upload(studentId, blob, seconds),
            onDenied: () => this.warn(this.labelsValue.micDeniedMessage),
        });

        if (!await this.recorder.start()) {
            this.recorder = null;

            return;
        }

        this.warningTarget.hidden = true;
        this.recordingFor = studentId;
        this.repaint();
    }

    stopRecording() {
        this.recorder?.stop();
    }

    async upload(studentId, blob, seconds) {
        this.recordingFor = undefined;
        this.recorder = null;

        const payload = new FormData();
        payload.append('audio', blob, 'enregistrement.webm');
        payload.append('duration', String(seconds));
        if (studentId !== null) payload.append('student', String(studentId));

        let data;
        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
                body: payload,
            });
            if (!response.ok) throw new Error('upload failed');
            data = await response.json();
        } catch (e) {
            this.warn(this.labelsValue.networkErrorMessage);
            this.repaint();

            return;
        }

        if (studentId === null) {
            this.commonFiles.push(data.file);
        } else {
            this.students.find((student) => student.id === studentId)?.files.push(data.file);
        }

        this.covered = data.coveredCount;
        this.repaint();
    }

    async deleteFile(file, student) {
        if (!window.confirm(this.labelsValue.deleteConfirmMessage)) return;

        let data;
        try {
            const response = await fetch(this.deleteUrlTemplateValue.replace('__FILE_ID__', file.id), {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
            });
            if (!response.ok) throw new Error('delete failed');
            data = await response.json();
        } catch (e) {
            this.warn(this.labelsValue.networkErrorMessage);

            return;
        }

        if (student === null) {
            this.commonFiles = this.commonFiles.filter((candidate) => candidate.id !== file.id);
        } else {
            const owner = this.students.find((candidate) => candidate.id === student.id);
            if (owner) owner.files = owner.files.filter((candidate) => candidate.id !== file.id);
        }

        this.covered = data.coveredCount;
        this.repaint();
    }

    play(file) {
        this.playerTarget.src = file.playbackUrl;
        this.playerTarget.play();
    }

    // ---- Helpers ------------------------------------------------------------------------------

    repaint() {
        this.renderCommon();
        this.renderStudents();
        this.renderCovered();
    }

    warn(message) {
        this.warningTarget.textContent = message;
        this.warningTarget.hidden = false;
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

    icon(paths, size) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', size);
        svg.setAttribute('height', size);
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        for (const d of paths.split('|')) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        }

        return svg;
    }
}
