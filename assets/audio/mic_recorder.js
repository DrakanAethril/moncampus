// The platform's microphone recorder, moved as-is out of the gradebook's evaluation entry screen
// (assets/controllers/evaluation_entry_controller.js), where it served the audio comments - those
// are gone and the "Enregistrements audio" tool took over.
//
// The format does not move, that is the handoff's constraint: MediaRecorder at ~24 kbps mono,
// WebM/Opus on Chrome, Ogg/Opus on Firefox, and the Blob goes as-is to the app which writes it to
// the bucket (App\Service\AudioUploadService). The handoff writes .mp3 in its mockup labels: that
// is placeholder data, not a format instruction.
//
// A module rather than a Stimulus controller: recording is driven from a controller that has plenty
// else to do (a row per student, a counter, deletions), and a second controller bound to the same
// elements would only have moved the problem.

/** The best type the browser can produce, or '' to let it choose on its own. */
function pickMime() {
    const candidates = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
    if (window.MediaRecorder) {
        for (const mime of candidates) {
            if (MediaRecorder.isTypeSupported(mime)) return mime;
        }
    }

    return '';
}

export class MicRecorder {
    /**
     * @param {object}   handlers
     * @param {Function} handlers.onTick     called every second with the elapsed seconds
     * @param {Function} handlers.onStop     called with (blob, seconds) once the recording is closed
     * @param {Function} handlers.onDenied   called when the mic is refused or unavailable
     */
    constructor({ onTick, onStop, onDenied }) {
        this.onTick = onTick ?? (() => {});
        this.onStop = onStop ?? (() => {});
        this.onDenied = onDenied ?? (() => {});
        this.seconds = 0;
    }

    get isRecording() {
        return Boolean(this.mediaRecorder) && this.mediaRecorder.state !== 'inactive';
    }

    async start() {
        if (this.isRecording) this.stop();

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            this.onDenied();

            return false;
        }

        const mime = pickMime();
        // ~24 kbps mono: the bitrate kept for speech, inherited from the gradebook.
        this.mediaRecorder = new MediaRecorder(this.stream, mime ? { mimeType: mime, audioBitsPerSecond: 24000 } : { audioBitsPerSecond: 24000 });
        this.chunks = [];
        this.mediaRecorder.ondataavailable = (event) => { if (event.data && event.data.size) this.chunks.push(event.data); };
        this.mediaRecorder.onstop = () => {
            const seconds = this.seconds;
            this.releaseStream();
            this.onStop(new Blob(this.chunks, { type: mime || 'audio/webm' }), seconds);
        };
        this.mediaRecorder.start();

        this.seconds = 0;
        this.timer = window.setInterval(() => {
            this.seconds += 1;
            this.onTick(this.seconds);
        }, 1000);

        return true;
    }

    stop() {
        window.clearInterval(this.timer);
        this.timer = null;
        if (this.isRecording) {
            this.mediaRecorder.stop();

            return;
        }

        // Nothing was running: nobody is waiting for onstop, but the stream may still be keeping the
        // microphone light on.
        this.releaseStream();
    }

    releaseStream() {
        window.clearInterval(this.timer);
        this.timer = null;
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }
}
