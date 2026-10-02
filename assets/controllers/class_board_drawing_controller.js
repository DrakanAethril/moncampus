import WidgetController from '../class_board/widget_controller.js';

// Dessin of the virtual board: freehand on a canvas, five colours, an eraser, « Tout effacer ». The
// picture is sent whole as a PNG a moment after each stroke; the server answers with its new
// storage key, which this widget keeps in its configuration like any other setting. The previous
// key goes along, so the drawing it replaces is scheduled for deletion.
const SAVE_DELAY_MS = 800;

/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['canvas', 'tool', 'status'];

    setup() {
        this.pen = this.canvasTarget.getContext('2d');
        this.color = this.toolTargets[0]?.dataset.color || '#12344d';
        this.drawing = false;
        const url = this.canvasTarget.dataset.imageUrl;
        if (url) {
            const image = new Image();
            image.onload = () => this.pen.drawImage(image, 0, 0, this.canvasTarget.width, this.canvasTarget.height);
            image.src = url;
        }
    }

    teardown() {
        clearTimeout(this.saveTimer);
    }

    pick(event) {
        this.color = event.currentTarget.dataset.color;
        this.toolTargets.forEach((tool) => tool.setAttribute('aria-pressed', String(tool === event.currentTarget)));
    }

    clear() {
        this.pen.clearRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);
        this.scheduleSave();
    }

    point(event) {
        const box = this.canvasTarget.getBoundingClientRect();
        return [
            ((event.clientX - box.left) * this.canvasTarget.width) / box.width,
            ((event.clientY - box.top) * this.canvasTarget.height) / box.height,
        ];
    }

    down(event) {
        if (event.button !== 0) {
            return;
        }
        this.drawing = true;
        this.last = this.point(event);
        this.canvasTarget.setPointerCapture(event.pointerId);
    }

    move(event) {
        if (!this.drawing) {
            return;
        }
        const next = this.point(event);
        const eraser = this.color === 'eraser';
        this.pen.globalCompositeOperation = eraser ? 'destination-out' : 'source-over';
        this.pen.strokeStyle = eraser ? '#000' : this.color;
        this.pen.lineWidth = eraser ? 40 : 7;
        this.pen.lineCap = 'round';
        this.pen.lineJoin = 'round';
        this.pen.beginPath();
        this.pen.moveTo(...this.last);
        this.pen.lineTo(...next);
        this.pen.stroke();
        this.last = next;
    }

    up() {
        if (!this.drawing) {
            return;
        }
        this.drawing = false;
        this.scheduleSave();
    }

    scheduleSave() {
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.save(), SAVE_DELAY_MS);
    }

    save() {
        this.canvasTarget.toBlob(async (blob) => {
            if (!blob) {
                return;
            }
            const board = this.element.closest('[data-controller~="class-board"]');
            const url = new URL(this.canvasTarget.dataset.uploadUrl, window.location.origin);
            if (this.config.key) {
                url.searchParams.set('replaced', this.config.key);
            }
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'image/png', 'X-CSRF-Token': board?.dataset.classBoardCsrfValue || '' },
                    body: blob,
                });
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                const result = await response.json();
                this.statusTarget.textContent = '';
                this.store({ key: result.key });
            } catch {
                this.statusTarget.textContent = this.statusTarget.dataset.failed;
            }
        }, 'image/png');
    }
}
