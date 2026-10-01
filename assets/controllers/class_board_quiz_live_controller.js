import WidgetController from '../class_board/widget_controller.js';

// Quiz live of the virtual board: the number of students connected to the contest under way,
// followed on the contest's host topic - the same messages the projector screen reads.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['live', 'count'];

    setup() {
        if (!this.hasLiveTarget) {
            return;
        }
        const { hubUrl, topic } = this.liveTarget.dataset;
        if (!hubUrl || !topic) {
            return;
        }
        const url = new URL(hubUrl, window.location.origin);
        url.searchParams.append('topic', topic);
        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventSource.onmessage = (event) => {
            const message = JSON.parse(event.data);
            if (typeof message.participantCount === 'number') {
                this.countTarget.textContent = String(message.participantCount);
            }
        };
    }

    teardown() {
        this.eventSource?.close();
    }
}
