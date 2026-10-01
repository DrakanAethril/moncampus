import WidgetController from '../class_board/widget_controller.js';

// Texte of the virtual board: rich text written on the board itself. The server sanitizes it on
// save with the library's sanitizer; the page only keeps what is typed.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['editor'];

    edit() {
        clearTimeout(this.pending);
        this.pending = setTimeout(() => this.store({ html: this.editorTarget.innerHTML }), 400);
    }

    teardown() {
        clearTimeout(this.pending);
    }
}
