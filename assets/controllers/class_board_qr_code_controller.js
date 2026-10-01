import WidgetController from '../class_board/widget_controller.js';

// QR code of the virtual board: the code of the address typed under it, drawn by the platform.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['image', 'empty', 'input'];

    change() {
        const url = this.inputTarget.value.trim();
        this.store({ url });
        this.imageTarget.hidden = url === '';
        this.emptyTarget.hidden = url !== '';
        if (url !== '') {
            this.imageTarget.src = `${this.imageTarget.dataset.url}?${new URLSearchParams({ text: url })}`;
        }
    }
}
