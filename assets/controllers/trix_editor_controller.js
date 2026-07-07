import { Controller } from '@hotwired/stimulus';
import 'trix';

// Progressively enhances a hidden Symfony textarea into a Trix WYSIWYG editor - Trix reads/writes
// the element's value directly via its "input" attribute, so the underlying form field (and its
// Symfony validation/errors) work unchanged.
export default class extends Controller {
    connect() {
        const editor = document.createElement('trix-editor');
        editor.setAttribute('input', this.element.id);
        this.element.insertAdjacentElement('afterend', editor);
    }
}
