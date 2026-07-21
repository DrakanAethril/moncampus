import { Controller } from '@hotwired/stimulus';

// Carnet de notes - teacher audio appreciation recorder (design Part C). A single modal shared by
// every grid cell, opened via the window-level 'gradebook:open-audio' CustomEvent dispatched from
// evaluation_grid_controller.js's per-cell mic button (kept decoupled from that controller rather
// than merged into it, since recording is a genuinely separate concern with its own MediaRecorder
// lifecycle). Opus/WebM ~24kbps mono, uploaded via a presigned PUT straight to S3
// (App\Service\GradeAudioCommentUploadService) - the recorded Blob never round-trips through PHP.
export default class extends Controller {
    static targets = ['backdrop', 'studentName', 'recordBtn', 'stopBtn', 'preview', 'saveBtn', 'deleteBtn', 'status'];

    static values = {
        requestUploadUrlTemplate: String,
        confirmUrlTemplate: String,
        deleteUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.onOpen = (event) => this.open(event.detail);
        window.addEventListener('gradebook:open-audio', this.onOpen);
    }

    disconnect() {
        window.removeEventListener('gradebook:open-audio', this.onOpen);
        this.stopStream();
    }

    open(detail) {
        this.current = detail;
        this.recordedBlob = null;
        this.studentNameTarget.textContent = detail.studentName;
        this.previewTarget.src = '';
        this.previewTarget.hidden = true;
        this.statusTarget.textContent = detail.listenStatusLabel ?? '';
        this.saveBtnTarget.disabled = true;
        this.deleteBtnTarget.hidden = !detail.hasAudio;
        this.stopBtnTarget.disabled = true;
        this.recordBtnTarget.disabled = false;
        this.backdropTarget.hidden = false;
    }

    close() {
        this.backdropTarget.hidden = true;
        this.stopStream();
    }

    stopStream() {
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }

    pickMime() {
        const candidates = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
        if (window.MediaRecorder) {
            for (const mime of candidates) {
                if (MediaRecorder.isTypeSupported(mime)) return mime;
            }
        }

        return '';
    }

    async startRecording() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            this.statusTarget.textContent = this.labelsValue.micDeniedMessage;

            return;
        }

        const mime = this.pickMime();
        this.mediaRecorder = new MediaRecorder(this.stream, mime ? { mimeType: mime, audioBitsPerSecond: 24000 } : { audioBitsPerSecond: 24000 });
        this.chunks = [];
        this.mediaRecorder.ondataavailable = (event) => { if (event.data && event.data.size) this.chunks.push(event.data); };
        this.mediaRecorder.onstop = () => {
            this.recordedBlob = new Blob(this.chunks, { type: mime || 'audio/webm' });
            this.previewTarget.src = URL.createObjectURL(this.recordedBlob);
            this.previewTarget.hidden = false;
            this.saveBtnTarget.disabled = false;
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
        };
        this.mediaRecorder.start();

        this.recordBtnTarget.disabled = true;
        this.stopBtnTarget.disabled = false;
        this.statusTarget.textContent = this.labelsValue.recordingMessage;
    }

    stopRecording() {
        this.mediaRecorder?.stop();
        this.stopBtnTarget.disabled = true;
        this.statusTarget.textContent = '';
    }

    async save() {
        if (!this.recordedBlob) return;

        this.saveBtnTarget.disabled = true;
        this.statusTarget.textContent = this.labelsValue.uploadingMessage;

        const { evaluationId, studentId } = this.current;
        const requestUrl = this.requestUploadUrlTemplateValue.replace('__EVAL_ID__', evaluationId).replace('__STUDENT_ID__', studentId);

        let uploadInfo;
        try {
            const response = await fetch(requestUrl, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue } });
            if (!response.ok) throw new Error('request-upload failed');
            uploadInfo = await response.json();

            const putResponse = await fetch(uploadInfo.uploadUrl, { method: 'PUT', headers: { 'Content-Type': 'audio/webm' }, body: this.recordedBlob });
            if (!putResponse.ok) throw new Error('S3 PUT failed');

            const confirmUrl = this.confirmUrlTemplateValue.replace('__EVAL_ID__', evaluationId).replace('__STUDENT_ID__', studentId);
            const confirmResponse = await fetch(confirmUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify({ fileSize: this.recordedBlob.size }),
            });
            if (!confirmResponse.ok) throw new Error('confirm failed');
        } catch (e) {
            this.statusTarget.textContent = this.labelsValue.networkErrorMessage;
            this.saveBtnTarget.disabled = false;

            return;
        }

        window.dispatchEvent(new CustomEvent('gradebook:audio-changed', { detail: { evaluationId, studentId, hasAudio: true } }));
        this.close();
    }

    async deleteAudio() {
        if (!window.confirm(this.labelsValue.deleteConfirmMessage)) return;

        const { evaluationId, studentId } = this.current;
        const url = this.deleteUrlTemplateValue.replace('__EVAL_ID__', evaluationId).replace('__STUDENT_ID__', studentId);

        try {
            const response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue } });
            if (!response.ok) throw new Error('delete failed');
        } catch (e) {
            this.statusTarget.textContent = this.labelsValue.networkErrorMessage;

            return;
        }

        window.dispatchEvent(new CustomEvent('gradebook:audio-changed', { detail: { evaluationId, studentId, hasAudio: false } }));
        this.close();
    }
}
