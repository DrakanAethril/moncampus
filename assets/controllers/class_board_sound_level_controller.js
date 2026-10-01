import WidgetController from '../class_board/widget_controller.js';

// Niveau sonore of the virtual board: a RELATIVE level read from the computer's microphone through
// an AnalyserNode - not decibels, and the screen says so by never printing a unit. Nothing is
// recorded and nothing leaves the computer: the samples are read, reduced to one number, dropped.
// The microphone is asked for on the button, never on opening the board; a refusal says how to
// give it back.
const CELLS = 24;

/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['state', 'cell', 'mark', 'threshold', 'start', 'message'];

    setup() {
        this.level = 0;
        this.threshold = this.config.threshold || 70;
        this.renderMark();
    }

    teardown() {
        this.stop();
    }

    async start() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } });
        } catch (error) {
            const denied = error && (error.name === 'NotAllowedError' || error.name === 'SecurityError');
            this.messageTarget.textContent = denied ? this.messageTarget.dataset.denied : this.messageTarget.dataset.unavailable;
            return;
        }
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        this.audio = new AudioContextClass();
        this.analyser = this.audio.createAnalyser();
        this.analyser.fftSize = 1024;
        this.audio.createMediaStreamSource(this.stream).connect(this.analyser);
        this.samples = new Float32Array(this.analyser.fftSize);
        this.startTarget.hidden = true;
    }

    stop() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.audio?.close().catch(() => {});
        this.stream = null;
        this.audio = null;
        this.analyser = null;
    }

    tick() {
        if (!this.analyser) {
            return;
        }
        this.analyser.getFloatTimeDomainData(this.samples);
        let sum = 0;
        for (const sample of this.samples) {
            sum += sample * sample;
        }
        // RMS on a logarithmic scale, mapped onto 0-100: -60 dBFS reads as silence, 0 as the top.
        const rms = Math.sqrt(sum / this.samples.length);
        const relative = Math.max(0, Math.min(100, ((20 * Math.log10(rms || 1e-6)) + 60) * (100 / 60)));
        this.level += (relative - this.level) * 0.35;
        this.render();
    }

    setThreshold() {
        this.threshold = parseInt(this.thresholdTarget.value, 10);
        this.store({ threshold: this.threshold });
        this.renderMark();
    }

    renderMark() {
        this.markTarget.style.left = `calc(${this.threshold}% - .12em)`;
    }

    render() {
        const lit = Math.round((this.level / 100) * CELLS);
        this.cellTargets.forEach((cell, index) => {
            cell.className = index < lit ? `is-on ${index < CELLS / 2 ? 'is-green' : index < CELLS * 0.75 ? 'is-orange' : 'is-red'}` : '';
        });
        const loud = this.level >= this.threshold;
        const rising = !loud && this.level >= this.threshold - 15;
        const labels = this.stateTarget.closest('.cm-cb-sound').dataset;
        this.stateTarget.textContent = loud ? labels.loud : rising ? labels.rising : labels.calm;
        this.stateTarget.className = `cm-cb-sound__state${loud ? ' is-loud' : rising ? ' is-rising' : ''}`;
        this.element.classList.toggle('is-loud', loud);
    }
}
