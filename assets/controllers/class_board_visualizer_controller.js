import WidgetController from '../class_board/widget_controller.js';

// Visualiseur of the virtual board: the computer's camera, live, mirrored or not - the mirror is
// the only thing saved. The camera is chosen at every opening (device ids differ from one computer
// to the next), asked for on the button, and nothing is recorded or sent anywhere.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['video', 'idle', 'message', 'devices', 'mirror'];

    setup() {
        this.applyMirror();
    }

    teardown() {
        this.stop();
    }

    async start(deviceId = null) {
        this.stop();
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: deviceId ? { deviceId: { exact: deviceId } } : { width: { ideal: 1920 }, height: { ideal: 1080 } },
                audio: false,
            });
        } catch (error) {
            const denied = error && (error.name === 'NotAllowedError' || error.name === 'SecurityError');
            this.messageTarget.textContent = denied ? this.messageTarget.dataset.denied : this.messageTarget.dataset.unavailable;
            return;
        }
        this.videoTarget.srcObject = this.stream;
        this.videoTarget.hidden = false;
        this.idleTarget.hidden = true;
        await this.listDevices(this.stream.getVideoTracks()[0]?.getSettings().deviceId);
    }

    // Device labels are only readable once a camera has been granted, hence after start().
    async listDevices(current) {
        const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
        this.devicesTarget.replaceChildren(...devices.map((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.textContent = device.label || `${index + 1}`;
            option.selected = device.deviceId === current;
            return option;
        }));
        this.devicesTarget.hidden = devices.length < 2;
    }

    choose() {
        this.start(this.devicesTarget.value);
    }

    stop() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }

    toggleMirror() {
        this.store({ mirror: this.mirrorTarget.checked });
        this.applyMirror();
    }

    applyMirror() {
        this.videoTarget.style.transform = this.mirrorTarget.checked ? 'scaleX(-1)' : '';
    }
}
